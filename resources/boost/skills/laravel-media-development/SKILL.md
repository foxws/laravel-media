---
name: laravel-media-development
description: Probe and process audio and video with foxws/laravel-media (ffprobe and ffmpeg), including typed stream and chapter info, clips, frames, subtitle extraction, encoding presets, and exporting to local or S3 disks. Use when working with the Media facade, Foxws\Media classes, config/media.php, or replacing pbmedia/laravel-ffmpeg and php-ffmpeg.
---

# Media with laravel-media

`foxws/laravel-media` runs ffprobe and ffmpeg (and later Shaka Packager and ab-av1) on files from any Laravel disk. It builds commands directly. There is no php-ffmpeg underneath, so new ffmpeg options never wait on a package release.

## Opening and probing

```php
use Foxws\Media\Facades\Media;

$media = Media::fromDisk('s3')->open('videos/clip.mp4');   // or Media::open(...) for the default disk

$probe = $media->probe();

$probe->duration();          // float, seconds
$probe->hasVideo();          // ignores cover art (attached pictures)
$probe->videoStream()?->height;
$probe->videoStream()?->frameRate;
$probe->audioStreams();      // list<AudioStream> (channels, sampleRate, language)
$probe->subtitleStreams();   // list<SubtitleStream> (language, forced())
$probe->chapters();          // list<Chapter> (title, start, end)
$probe->format()->bitRate;
$probe->stream(2)?->get('tags.title');   // any raw ffprobe field via dot notation
```

- `open()` accepts several paths. `probe($path)` probes one (the first by default), and `probeAll()` returns them keyed by path. Results are cached on the opener.
- On disks that provide temporary URLs (S3), ffprobe and ffmpeg read a short-lived signed URL instead of downloading the file. Set `media.remote_inputs.enabled` to false to download to the temporary root instead.

## Running ffmpeg

```php
use Foxws\Media\Encoding\Format;

$result = Media::fromDisk('s3')
    ->open('videos/clip.mp4')
    ->ffmpeg()
    ->clip(from: 12.5, to: 40)
    ->inFormat(Format::h264(crf: 22))
    ->toDisk('clips')                // defaults to the source disk
    ->withVisibility('private')
    ->afterSaving(fn ($builder, $result) => $clip->markAsReady($result->path()))
    ->save('intro/clip.mp4');

$result->disk();   // target Disk
$result->paths();  // every written path
```

- `frame(at: 5.0)->save('thumb.jpg')` grabs one frame as JPEG.
- `map('0:2')->inFormat(Format::webVtt())->save('captions/nld.vtt')` extracts a subtitle stream.
- `addArgs([...])` adds output options (for example `['-vf', 'scale=1280:-2']`), and `addInputArgs([...])` adds options placed before every input.
- `clip()` seeks on the input. With `Format::copy()` the clip starts at the keyframe before `from`.
- `beforeSaving(fn ($builder) => ...)` can still change the command. `afterSaving(fn ($builder, $result) => ...)` runs once, only after the files are on the target disk.
- `command('out.mp4')` returns the full command line with keys redacted, without running it.
- Call `$media->cleanupTemporaryFiles()` in `finally` when remote inputs were downloaded, because queue workers are long-lived.

## Formats

`Format::copy()`, `h264()`, `hevc()`, `av1()`, `vp9()`, `webVtt()` and `jpeg()` are presets. They're immutable, so `->withArguments([...])` and `->withoutAudio()` return copies. Use `new Format(container: ..., videoCodec: VideoCodec::..., audioCodec: AudioCodec::...)` for anything else.

## Exporting

`save()` writes to a temporary directory, then copies the result to the target disk:
- **S3 disks** (needs `league/flysystem-aws-s3-v3`): files upload concurrently, and large files use multipart uploads that are aborted on failure.
- **Local disks:** files are moved with `rename()`.

Failed copies throw `ExportFailedException`, whose `failures` property lists each file and its error.

## Executables

Each executable resolves lazily: an absolute path from config, or a command name found in the `PATH` or the project root. Only the tools you call need to be installed. Run `php artisan media:info` to see which are found, with their paths and versions. A missing one throws `ExecutableNotFoundException`, which names the env key to set.

## Configuration

Publish with `php artisan vendor:publish --tag=media-config`.

| Key | Purpose |
| --- | --- |
| `disk` | Default disk for `Media::open()` (`MEDIA_DISK`) |
| `executables.ffmpeg`, `.ffprobe`, `.packager`, `.ab-av1` | Path or command name (`MEDIA_FFMPEG_PATH`, …) |
| `timeout` | Process timeout in seconds; keep it at or below the queue job's `$timeout` |
| `log_channel` | Log channel, `false` to disable |
| `remote_inputs.enabled`, `.url_lifetime` | Read remote disks through signed URLs |
| `temporary_files.root`, `.cache_root` | Where downloads and outputs are written; the cache root is for small files (e.g. `/dev/shm`) |
| `temporary_files.min_free`, `.size_multiplier`, `.cache_min_free` | Fail fast with `InsufficientStorageException` when a root is too full |
| `uploads.concurrency`, `.multipart_threshold`, `.multipart_part_size`, `.multipart_concurrency` | S3 upload tuning |

## Events and exceptions

- **Events:** `Process\Events\ProcessStarted`, `ProcessCompleted` and `ProcessFailed` are dispatched for every ffmpeg/ffprobe run, with the command redacted.
- **Exceptions:** all live in `Foxws\Media\Exceptions`. `ProcessFailedException` carries the `Result` with the exit code and error output.

## Testing

Fake processes, and point the executable at any executable file, because resolution still checks it exists:

```php
config(['media.executables.ffmpeg' => '/usr/bin/env']);
Process::fake(['*' => Process::result()]);
Storage::fake('videos');

Media::fromDisk('videos')->open('video.mp4')->ffmpeg()->frame(at: 1)->save('thumb.jpg');

Process::assertRan(fn ($process) => in_array('-frames:v', $process->command, true));
```

A faked ffmpeg writes no output file, so the export has nothing to copy. Write the file in a `Process::fake` closure (using `end($process->command)` as the path) when the test asserts the target disk.
