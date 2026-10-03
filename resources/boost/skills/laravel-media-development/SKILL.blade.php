---
name: laravel-media-development
description: Probe and process audio and video with foxws/laravel-media (ffprobe and ffmpeg), including typed stream and chapter info, clips, frames, subtitle extraction, several outputs in one run, seek-preview thumbnail sprites with WebVTT, filters (scale, crop, fade, loudnorm, watermark), encoding presets with bitrate and two-pass control, audio-only output, and exporting to local or S3 disks. Use when working with the Media facade, Foxws\Media classes, config/media.php, or replacing pbmedia/laravel-ffmpeg and php-ffmpeg.
license: MIT
metadata:
  author: foxws
---
@php
/** @var \Laravel\Boost\Install\GuidelineAssist $assist */
@endphp

# Media with laravel-media

`foxws/laravel-media` runs ffprobe and ffmpeg (and later Shaka Packager and ab-av1) on files from any Laravel disk. It builds commands directly. There is no php-ffmpeg underneath, so new ffmpeg options never wait on a package release.

## Opening and probing

@boostsnippet("Opening and probing", "php")
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
@endboostsnippet

- `open()` accepts several paths. `probe($path)` probes one (the first by default), and `probeAll()` returns them keyed by path. Results are cached on the opener.
- On disks that provide temporary URLs (S3), ffprobe and ffmpeg read a short-lived signed URL instead of downloading the file. Set `media.remote_inputs.enabled` to false to download to the temporary root instead.

## Running ffmpeg

@boostsnippet("Running ffmpeg", "php")
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
@endboostsnippet

- `frame(at: 5.0)->save('thumb.jpg')` grabs one frame as JPEG.
- `map('0:2')->inFormat(Format::webVtt())->save('captions/nld.vtt')` extracts a subtitle stream.
- `addArgs([...])` adds output options (for example `['-vf', 'scale=1280:-2']`), and `addInputArgs([...])` adds options placed before every input.
- `clip()` seeks on the input, so it applies to every output. With `Format::copy()` the clip starts at the keyframe before `from`.
- `beforeSaving(fn ($builder) => ...)` can still change the command. `afterSaving(fn ($builder, $result) => ...)` runs once, only after the files are on the target disk.
- `command('out.mp4')` returns the full command line with keys redacted, without running it.
- Call `$media->cleanupTemporaryFiles()` in `finally` when remote inputs were downloaded, because queue workers are long-lived.

## Several outputs in one run

`addOutput($path, fn (Output $output) => ...)` writes another file from the same ffmpeg run. Each output has its own `map()`, `inFormat()`, `addFilter()` and `addArgs()`. The inputs are read and decoded once, which is much faster than one run per file.

@boostsnippet("Every subtitle track in one run", "php")
use Foxws\Media\FFMpeg\Output;

$media = Media::fromDisk('s3')->open('videos/movie.mkv');
$builder = $media->ffmpeg()->toDisk('captions');

foreach ($media->probe()->subtitleStreams() as $stream) {
    $builder->addOutput(
        "{$movie->id}/{$stream->index}_{$stream->language}.vtt",
        fn (Output $output) => $output->map("0:{$stream->index}")->inFormat(Format::webVtt()),
    );
}

$result = $builder->save();   // without a path, only the added outputs are written
$result->paths();             // in the order they were declared
@endboostsnippet

- `save('main.mp4')` writes the builder's own output first, followed by the added ones. Relative paths, including folders, are kept on the target disk.
- Two-pass encoding and `watermark()` only work with a single output, and throw when combined with `addOutput()`.
- `save()` without a path and without added outputs throws `MediaNotFoundException`.

## Thumbnail sprites and WebVTT

`thumbnails()` samples the first opened video into sprite sheets plus a WebVTT file whose cues point at each tile (`sheet.jpg#xywh=x,y,w,h`). Players use it for seek previews.

@boostsnippet("Seek preview thumbnails", "php")
$result = Media::fromDisk('s3')->open('videos/movie.mp4')->thumbnails()
    ->every(10)                       // or ->count(100, minimumInterval: 5); the default is about 100 thumbnails, at least 1s apart
    ->size(160, 90)                   // letterboxed, keeps the aspect ratio
    ->grid(10, 10)                    // per sheet; more thumbnails continue on the next sheet
    ->format('webp', quality: 75)     // or 'jpg' (the default)
    ->toDisk('storyboards')
    ->withUrl(fn (string $sprite) => Storage::disk('storyboards')->url($sprite))   // optional
    ->save("{$movie->id}/storyboard");

$result->sprites;   // ["1/storyboard_001.webp", ...]
$result->vtt;       // "1/storyboard.vtt"
$result->interval;  // seconds between thumbnails
@endboostsnippet

- It's one ffmpeg run with time-based sampling (`fps`), so it doesn't depend on the frame rate.
- Without `withUrl()`, cues use the sheet's file name relative to the VTT file, so keep them together.
- Media without a video stream or a known duration throws `InvalidMediaException`. `beforeSaving()`/`afterSaving()` are available, and `afterSaving` receives the `ThumbnailsResult`.

## Filters

Filters are ffmpeg (libavfilter) only. They're plain value objects in `Foxws\Media\Filters` that render filter graph strings, so they don't depend on the builder.

@boostsnippet("Filters and watermarks", "php")
use Foxws\Media\Filters\{Fade, Loudnorm, Position, Scale, Volume};

$media->ffmpeg()
    ->addFilter(Scale::fit(1080, 1920), Fade::in(1), Fade::out(1, start: 29))   // video chain, in order
    ->addFilter(new Loudnorm, Fade::audioIn(0.5))                                // audio chain, in order
    ->watermark('logo.png', disk: 'branding', position: Position::BottomRight, margin: 24, width: 160)
    ->inFormat(Format::h264())
    ->save('reels/1.mp4');
@endboostsnippet

- **Video filters:**
  - `Scale::to($width, $height)` (a missing side keeps the aspect ratio)
  - `Scale::fit()` (letterbox to an exact size) and `Scale::fill()` (crop to an exact size)
  - `new Crop(...)`, `new Pad(...)`, `Rotate::clockwise()`/`counterClockwise()`/`upsideDown()`/`flipHorizontally()`/`flipVertically()`
  - `new Fps(30)`, `Fade::in()`/`out()`
- **Audio filters:** `Fade::audioIn()`/`audioOut()`, `Volume::times(0.5)`/`decibels(-6)`, `new Loudnorm(-16, -1.5, 11)`.
- `Custom::video('hqdn3d')` or `Custom::audio('atempo=1.25')` covers any other filter.
- Without a watermark, filters become `-vf`/`-af`. A watermark switches to `-filter_complex` with mapped outputs, so it can't be combined with `map()` (that throws `InvalidFilterException`). The watermark is read from the source disk unless another disk is given.

## Formats

Presets: `Format::copy()`, `h264()`, `hevc()`, `av1()`, `vp9()`, audio-only `aac()`, `mp3()`, `opus()`, `flac()`, plus `webVtt()` and `jpeg()`. Formats are immutable, so every method returns a changed copy:

@boostsnippet("Rate control and streams", "php")
use Foxws\Media\Encoding\Format;

Format::h264()->crf(20)->preset('slow');                    // constant quality
Format::h264()->bitrate(2500, max: 3000, buffer: 6000);       // kbit/s; -b:v, -maxrate, -bufsize
Format::h264()->bitrate(2500)->twoPass();                     // two passes, libx264 or libvpx-vp9 only
Format::vp9(crf: 31)->bitrate(1800);                           // VP9 constrained quality
Format::h264()->audioBitrate(128)->audioChannels(2)->sampleRate(48000);
Format::h264()->withoutAudio();                                // also withoutVideo(), withoutSubtitles()
Format::mp3(256);                                              // audio only
@endboostsnippet

- Two-pass needs `bitrate()`. Unsupported codecs or a missing bitrate throw `InvalidFormatException` before ffmpeg runs. The first pass's log file never ends up on the target disk.
- `withArguments([...])` appends raw output options. For anything else, use `new Format(container: ..., videoCodec: VideoCodec::..., audioCodec: AudioCodec::...)` with named arguments.

## Exporting

`save()` writes to a temporary directory, then copies the result to the target disk:
- **S3 disks** (needs `league/flysystem-aws-s3-v3`): files upload concurrently, and large files use multipart uploads that are aborted on failure.
- **Local disks:** files are moved with `rename()`.

Failed copies throw `ExportFailedException`, whose `failures` property lists each file and its error.

## Executables

Each executable resolves lazily: an absolute path from config, or a command name found in the `PATH` or the project root. Only the tools you call need to be installed. Run `{{ $assist->artisanCommand('media:info') }}` to see which are found, with their paths and versions. A missing one throws `ExecutableNotFoundException`, which names the env key to set.

## Configuration

Publish with `{{ $assist->artisanCommand('vendor:publish --tag=media-config') }}`.

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

@boostsnippet("Testing with fakes", "php")
config(['media.executables.ffmpeg' => '/usr/bin/env']);
Process::fake(['*' => Process::result()]);
Storage::fake('videos');

Media::fromDisk('videos')->open('video.mp4')->ffmpeg()->frame(at: 1)->save('thumb.jpg');

Process::assertRan(fn ($process) => in_array('-frames:v', $process->command, true));
@endboostsnippet

A faked ffmpeg writes no output file, so the export has nothing to copy. Write the file in a `Process::fake` closure (using `end($process->command)` as the path) when the test asserts the target disk.
