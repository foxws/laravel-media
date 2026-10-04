---
name: laravel-media-development
description: Probe and process audio and video with foxws/laravel-media (ffprobe and ffmpeg), including typed stream and chapter info, progress reporting, clips, frames, subtitle extraction, scene detection, clip reels and concatenation, several outputs in one run, seek-preview thumbnail sprites with WebVTT, filters (scale, crop, fade, loudnorm, watermark, HDR to SDR tone mapping), encoding presets with bitrate and two-pass control, audio-only output, and exporting to local or S3 disks. Use when working with the Media facade, Foxws\Media classes, config/media.php, or replacing pbmedia/laravel-ffmpeg and php-ffmpeg.
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

## Scenes, clips and reels

@boostsnippet("A reel from scenes", "php")
use Foxws\Media\FFMpeg\Clip;
use Foxws\Media\FFMpeg\Scene;

$media = Media::fromDisk('s3')->open(['videos/a.mp4', 'videos/b.mp4']);

$scenes = $media->scenes(threshold: 0.3);           // list<Scene> (start, end, score), cached per threshold
$clips = collect($scenes)
    ->sortByDesc('score')
    ->take(5)
    ->sortBy('start')
    ->map(fn (Scene $scene) => $scene->toClip(maximumDuration: 4))
    ->push(Clip::make(10, 14, 'videos/b.mp4'))      // clips can come from any opened file
    ->values()
    ->all();

$media->ffmpeg()
    ->clips($clips, width: 1080, height: 1920, fps: 30)   // vertical reel; clips are letterboxed to fit
    ->addFilter(Fade::in(0.5), new Loudnorm)              // filters apply to the joined video
    ->inFormat(Format::h264())
    ->save('reels/1.mp4');
@endboostsnippet

- **`clips()` re-encodes.** Each clip is a separate input seeked with `-ss`/`-t`, so cuts are frame-accurate. Without a size, clips from several files are fitted to the first file's size. If any file has no audio, the reel is silent.
- **`clips()` builds its own inputs and graph,** so it can't be combined with `map()`, `watermark()`, `addOutput()`, `clip()`, `frame()` or `addInputArgs()` (`InvalidFilterException`).
- **`concat()` joins whole files without re-encoding,** using ffmpeg's concat demuxer and copying streams unless a format is set. The files must share codecs, dimensions and audio layout. Otherwise it throws `InvalidMediaException` and you should use `clips()`.
- **`clip($from, $to)` on a single file** with `Format::copy()` starts at the keyframe before `$from`. Use a re-encoding format for exact cuts.

## Progress

`onProgress()` receives a `Foxws\Media\Process\Progress` about twice a second while ffmpeg runs. It's available on the ffmpeg builder and on `thumbnails()`.

@boostsnippet("Progress for a queued job", "php")
use Foxws\Media\Process\Progress;

$media->ffmpeg()
    ->inFormat(Format::h264())
    ->onProgress(function (Progress $progress) use ($video) {
        $progress->percentage();   // 0-100 across all passes, null when the duration is unknown
        $progress->remaining();    // estimated seconds left, from ffmpeg's speed
        $progress->speed;          // e.g. 2.5 (times real time)

        $video->update(['progress' => $progress->percentage()]);   // or broadcast an event
    })
    ->save('encoded.mp4');
@endboostsnippet

- The duration comes from the probe, a clip's length, the sum of the clips for `clips()`, or all files for `concat()`. A single `frame()` reports no percentage.
- Two-pass encodes report one percentage: the first pass is 0-50% and the second 50-100%.
- Callbacks run inside the job, so keep them cheap (throttle database writes or broadcasts yourself if needed).

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

### HDR to SDR

HDR video (PQ/HDR10 or HLG) looks washed out when encoded as regular SDR H.264 or turned into images. `$probe->videoStream()->isHdr()` detects it, and `colorTransfer`, `colorPrimaries` and `colorSpace` are available too.

@boostsnippet("Tone mapping", "php")
use Foxws\Media\Filters\{Tonemap, ToneMapAlgorithm};

$media->ffmpeg()
    ->toneMap()                                               // only applied when the source is HDR
    ->addFilter(Scale::to(1280))
    ->inFormat(Format::h264())
    ->save('sdr.mp4');

$media->ffmpeg()->toneMap(new Tonemap(ToneMapAlgorithm::Mobius, desaturation: 0.5));
@endboostsnippet

- `toneMap()` is safe to always call for files of unknown origin. It goes first in the video chain, works inside watermark graphs, and in `clips()` only maps the clips whose file is HDR.
- `thumbnails()` tone maps HDR by default, and only the sampled frames, so it stays cheap. Call `->toneMap(null)` to keep the source colours.
- It uses zscale, so ffmpeg needs libzimg. Most static and distribution builds have it, and `ffmpeg -filters | grep zscale` checks.
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

Publish with `{{ $assist->artisanCommand('vendor:publish --tag=media-config') }}`. The config is read once per application into `Foxws\\Media\\MediaConfig`, which the package's services receive through the container. Set config values in tests before using `Media` or calling `Media::fake()`.

| Key | Purpose |
| --- | --- |
| `disk` | Default disk for `Media::open()` (`MEDIA_DISK`) |
| `executables.ffmpeg`, `.ffprobe`, `.packager`, `.ab-av1` | Path or command name (`MEDIA_FFMPEG_PATH`, …) |
| `timeout` | Process timeout in seconds; keep it at or below the queue job's `$timeout` |
| `log_channel` | Log channel, `false` to disable |
| `ffmpeg_log_level` | ffmpeg's `-loglevel` (`error`); `warning` logs warnings of successful runs (`MEDIA_FFMPEG_LOG_LEVEL`) |
| `remote_inputs.enabled`, `.url_lifetime` | Read remote disks through signed URLs |
| `temporary_files.root`, `.cache_root` | Where downloads and outputs are written; the cache root is for small files (e.g. `/dev/shm`) |
| `temporary_files.min_free`, `.size_multiplier`, `.cache_min_free` | Fail fast with `InsufficientStorageException` when a root is too full |
| `uploads.concurrency`, `.multipart_threshold`, `.multipart_part_size`, `.multipart_concurrency` | S3 upload tuning |

## Errors, retries and logging

Failed runs throw `Foxws\Media\Exceptions\ProcessFailedException`:
- `$exception->reason` is a `FailureReason` recognised from ffmpeg's error output: `InvalidInput`, `MissingInput`, `UnsupportedCodec`, `InvalidOptions`, `PermissionDenied`, `NoSpace`, `Network`, `Timeout` or `Unknown`.
- `$exception->isRetryable()` is true for `Network`, `Timeout`, `NoSpace` and `Unknown`. Broken uploads, missing codecs or wrong options fail the same way every time.
- When the exception is reported, its `context()` (executable, exit code, reason, redacted command, last 20 lines of error output) appears in the logs and in error trackers such as Sentry, Flare or Nightwatch.

@boostsnippet("Retrying only what can recover", "php")
use Foxws\Media\Exceptions\ProcessFailedException;

public function handle(): void
{
    try {
        Media::fromDisk('s3')->open($this->path)->ffmpeg()->timeout(1800)->inFormat(Format::h264())->save($this->output);
    } catch (ProcessFailedException $exception) {
        $exception->isRetryable() ? $this->release(60) : $this->fail($exception);
    }
}
@endboostsnippet

- `->timeout($seconds)` on the builder or `thumbnails()` overrides `media.timeout`. Keep it below the job's `$timeout`, so the package stops ffmpeg and throws a `Timeout` failure instead of the worker being killed.
- Failures are logged as errors with the same context. Successful runs that still wrote to the error output are logged as warnings. Set `MEDIA_FFMPEG_LOG_LEVEL=warning` to see why output from damaged files looks wrong.
- **Events:** `Process\Events\ProcessStarted`, `ProcessCompleted` and `ProcessFailed` (with `$result` and `$reason`) are dispatched for every ffmpeg/ffprobe run, with the command redacted.
- **Other exceptions** live in `Foxws\Media\Exceptions` too: `ExecutableNotFoundException`, `InvalidMediaException`, `InvalidFormatException`, `InvalidFilterException`, `ExportFailedException`, `InsufficientStorageException` and `TemporaryFileException`.

## Testing

Use `Media::fake()`. Nothing is executed and no ffmpeg is needed: probes return fake data, ffmpeg writes placeholder files to the (faked) target disk, and every command is recorded. Events, failures and progress behave as in production, and Laravel's `Process` facade is left alone.

@boostsnippet("Testing with Media::fake()", "php")
use Foxws\Media\Executables\Executable;
use Foxws\Media\Facades\Media;
use Foxws\Media\Testing\FakeProbe;

Storage::fake('videos');
Storage::fake('clips');

Media::fake([
    'uploads/movie.mkv' => FakeProbe::video(duration: 120, subtitles: ['eng', 'nld']),   // keyed by the end of the path
    'uploads/song.mp3' => FakeProbe::audio(duration: 200),
])->scenes('uploads/movie.mkv', [12.5, 40.0]);                                         // fake scene changes

CreateClip::run($video);   // the code under test

Media::assertProbed('uploads/movie.mkv');
Media::assertSaved('clips/1.mp4', 'clips');
Media::assertRan(Executable::FFMpeg, fn (array $arguments) => in_array('libx264', $arguments, true));
Media::assertNotRan(Executable::Packager);
@endboostsnippet

- Unknown paths probe as a one-minute 1080p H.264 video with AAC audio. `FakeProbe::video()` also takes `width`, `height`, `codec`, `audio: false`, `transfer: 'smpte2084'` (HDR) and `frameRate`.
- `Media::fake()->failNext(Executable::FFMpeg, 'Invalid data found')` makes the next run throw `ProcessFailedException`. Use it to test failure handling and retries.
- `onProgress()` callbacks receive 50% and 100%. Probes and scenes are matched against the end of the opened path.
- Other assertions: `assertRanTimes()`, `assertNothingRan()`, `assertNotSaved()`. `Media::fake()` returns the fake, whose `commands(Executable::FFMpeg)` lists the recorded arguments.
