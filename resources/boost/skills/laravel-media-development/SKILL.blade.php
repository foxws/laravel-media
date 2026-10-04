---
name: laravel-media-development
description: Probe, process and package audio and video with foxws/laravel-media (ffprobe, ffmpeg and Shaka Packager), including typed stream and chapter info, upload validation, progress reporting and cancelling, export events, clips, frames, subtitle extraction, scene detection, clip reels and concatenation, several outputs in one run, seek-preview thumbnail sprites with WebVTT, filters (scale, crop, fade, loudnorm, watermark, HDR to SDR tone mapping), encoding presets with bitrate and two-pass control, audio-only output, packaging into HLS and DASH with AES encryption, signed manifests through DynamicHLSPlaylist and DynamicDASHManifest, and exporting to local or S3 disks. Use when working with the Media facade, Foxws\Media classes, config/media.php, or replacing pbmedia/laravel-ffmpeg and php-ffmpeg.
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
- Temporary files, including downloaded remote inputs, are deleted after every queue job and at the end of each request. Call `$media->cleanupTemporaryFiles()` to free them earlier, for example between steps of a long job.

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
- **Cancelling:** return `false` from a progress callback, e.g. when the user cancelled. ffmpeg is stopped, nothing is saved, and a `ProcessFailedException` with reason `Cancelled` (not retryable) is thrown.

## Events

Exports dispatch events. Add `->withContext([...])` on the builder or `thumbnails()` so listeners know what an export is about.

| Event | When | Properties |
| --- | --- | --- |
| `Foxws\Media\Events\ProgressReported` | every progress update (about twice a second) | `progress`, `context` |
| `Foxws\Media\Events\ExportCompleted` | after the outputs are on the target disk and `afterSaving` ran | `result` (`ExportResult`), `context`, `duration` |
| `Foxws\Media\Events\ExportFailed` | when an export throws | `exception`, `context` |
| `Foxws\Media\Process\Events\ProcessStarted`/`ProcessCompleted`/`ProcessFailed` | every ffmpeg/ffprobe run | redacted command, `result`, `reason` |

@boostsnippet("Broadcasting progress", "php")
$media->ffmpeg()->withContext(['video_id' => $video->id])->inFormat(Format::h264())->save('encoded.mp4');

// A listener, e.g. in AppServiceProvider::boot()
Event::listen(function (ProgressReported $event) {
    if (isset($event->context['video_id'])) {
        broadcast(new VideoEncoding($event->context['video_id'], $event->progress->percentage()));
    }
});
@endboostsnippet

- Progress is requested from ffmpeg as soon as there's a progress callback or a `ProgressReported` listener, so listening alone is enough.
- `thumbnails()` dispatches `ExportCompleted` for its sprite sheets, then writes the VTT file.

## Validating uploads

`Foxws\Media\Rules\MediaFile` probes the uploaded file with ffprobe, so validation relies on what the file really contains rather than its extension or MIME type.

@boostsnippet("Upload validation", "php")
use Foxws\Media\Rules\MediaFile;

$request->validate([
    'video' => ['required', 'file', 'max:2097152', MediaFile::video()
        ->withAudio()
        ->minDuration(1)
        ->maxDuration(3600)
        ->minDimensions(640, 360)
        ->maxDimensions(3840, 2160)
        ->videoCodecs(['h264', 'hevc', 'av1', 'vp9'])
        ->audioCodecs(['aac', 'opus', 'mp3'])],
    'podcast' => ['required', 'file', MediaFile::audio()->maxDuration(7200)],
    'anything' => ['required', 'file', MediaFile::any()],
]);
@endboostsnippet

- Files ffprobe can't read, and values that aren't files, fail with "The :attribute must be a readable media file."
- Codec names are ffprobe's (`h264`, `hevc`, `av1`, `vp9`, `aac`, `opus`, ...). Keep the `file`/`max` rules too, so oversized uploads are rejected before probing.

## Packaging into HLS and DASH

Packaging splits already-encoded files into streaming segments with HLS and DASH manifests. It doesn't transcode, so encode renditions first (for example with the ffmpeg builder), then package them. Shaka Packager (`packager`) is the default driver.

@boostsnippet("Packaging renditions", "php")
$result = Media::fromDisk('renditions')
    ->open(['1080.mp4', '720.mp4', '480.mp4'])
    ->exportAsStreams()                      // HLS (master.m3u8) and DASH (manifest.mpd) from the same segments
    ->toDisk('streams')
    ->withContext(['video_id' => $video->id])
    ->save("videos/{$video->id}");

$result->path();    // "videos/1/master.m3u8" (manifests come first in paths())
@endboostsnippet

- **Shortcuts:** `exportAsHLS()`, `exportAsDASH()` and `exportAsStreams()` probe every opened file, add its video and audio (named `{index}_video.mp4`/`{index}_audio.mp4`), and apply `forVod()` (VOD playlist, codec switching, approximate segment timeline).
- **By hand:** `->package()->addVideoStream($path)`, `->addAudioStream($path, language: 'eng')`, `->addTextStream('captions/nld.vtt', 'nld.mp4', 'nld', ['dash_roles' => 'subtitle'])`. Text files can come from anywhere on the source disk. Package subtitles as `.mp4` for DASH, because a plain `.vtt` output gets no segment index and players drop it.
- **Settings:**
  - `withHlsPlaylist('master.m3u8', HlsPlaylistType::Vod)`, `withDashManifest('manifest.mpd')`
  - `segmentDuration(6)`, `fragmentDuration(2)` (a fragment can't be longer than a segment)
  - `defaultLanguage()`, `defaultTextLanguage()`, `allowCodecSwitching()`, `approximateSegmentTimeline()`
  - `withOption('hls_base_url', 'https://cdn.test/')` passes any other Shaka option as is
- **Shared with the ffmpeg builder:** `toDisk()`, `withVisibility()`, `timeout()`, `withContext()`, save callbacks, `ExportCompleted`/`ExportFailed` events, failure reasons, S3 uploads, rollback and `command()`.
- **Inputs:** they're read from local copies, downloaded from remote disks when needed. Names with commas or special characters are linked under a plain name, because Shaka's stream descriptors use commas.
- **Drivers:** `media.packager.default` (`MEDIA_PACKAGER`) picks the default. Register another with `app(PackagerManager::class)->extend('name', fn () => new MyPackager)`, implementing `Foxws\Media\Packaging\Packager`, and choose it per export with `->using('name')`.
- **Under `Media::fake()`**, packaging writes placeholder segments and manifests.

### Encryption

@boostsnippet("AES encryption", "php")
use Foxws\Media\Encryption\ProtectionScheme;

$result = Media::fromDisk('renditions')->open($renditions)
    ->exportAsStreams()
    ->withEncryption(scheme: ProtectionScheme::Cbcs)   // a new random key unless you pass one
    ->toDisk('streams')
    ->withVisibility('private')
    ->save("videos/{$video->id}");

$video->update([
    'key_id' => $result->encryptionKey()->keyId,       // hex
    'key' => encrypt($result->encryptionKey()->key),   // hex; store it encrypted
]);
@endboostsnippet

- **The key file:** the raw 16-byte key is saved next to the segments as `key`, and HLS playlists reference it by that name. Keep the disk private and serve the key only through an authorized route or a short-lived signed URL. `$key->binary()` returns the bytes to respond with.
- **Serving the key yourself:** pass `withEncryption(keyFile: null, keyUri: route('videos.key', $video))` to skip the key file and point playlists at your route.
- **Schemes:** null uses Shaka's default, `cenc`. Use `cbcs` when one set of segments serves both HLS and DASH, including Safari. Avoid `cbc1` and `cens`, which few players support.
- **DASH** has no key URI, so DASH players need the key themselves, for example Shaka Player's `drm.clearKeys` with the key ID and key.
- `withClearLead($seconds)` leaves the start unencrypted, so playback can begin before the key is fetched.
- `withKeyRotation($seconds)` uses a new key per period. Shaka derives the later keys from the first one, and only the first key is returned, so test full playback before relying on it.
- Keys are redacted from commands, logs and events.

### Shaka Packager options

Options only Shaka Packager has (DRM key servers, live and low-latency DASH, base URLs, segment numbering) are typed and validated in `Foxws\Media\Packaging\Drivers\Shaka\ShakaOptions`, so the generic builder stays driver-neutral:

@boostsnippet("Shaka options", "php")
use Foxws\Media\Packaging\Drivers\Shaka\{ProtectionSystem, ShakaOptions};

$media->package()->addStreamsFrom()->withDashManifest()
    ->withOptions(ShakaOptions::make()
        ->baseUrls('https://cdn.test/videos/1/')
        ->lowLatencyDashMode()
        ->timeShiftBufferDepth(60)
        ->widevine('https://license.test/cenc/getcontentkey', contentId: 'abcd1234')
        ->aesSigning('widevine_test', $signingKey, $signingIv)
        ->protectionSystems(ProtectionSystem::Widevine, ProtectionSystem::PlayReady))
    ->save('videos/1');
@endboostsnippet

- **HLS:** `hlsBaseUrl()`, `hlsMediaSequenceNumber()`, `hlsStartTimeOffset()`, `createSessionKeys()`.
- **Live DASH:** `minBufferTime()`, `minimumUpdatePeriod()`, `suggestedPresentationDelay()`, `timeShiftBufferDepth()`, `preservedSegmentsOutsideLiveWindow()`, `utcTimings([...])`, `lowLatencyDashMode()`, `generateStaticLiveMpd()`.
- **Segments:** `startSegmentNumber()`, `transportStreamTimestampOffset()`, `forceClIndex()`.
- **Encryption:**
  - `cryptByteBlock()`, `skipByteBlock()`, `vp9SubsampleEncryption()`, `protectionSystems(ProtectionSystem::...)`, `pssh()`, `iv()`, `playreadyExtraHeaderData()`
  - `widevine()`, `playready()`, `maxPixels()`, `groupId()`, `enableEntitlementLicense()`
  - `aesSigning()` or `rsaSigning()` (one at a time), `keyServerTls()`, `decrypt()`
- Signing keys, IVs, PSSH and certificate passwords are redacted from commands and logs. `withOptions([...])` also takes a plain array, and `withOption()` takes a single raw option.

## Serving manifests with signed URLs

Keep packaged segments on a private disk and rewrite the manifests per request, so every URI is a short-lived signed URL. Resolvers receive each file's path on the disk (for example `videos/1/0_video.mp4`), resolved relative to the manifest that references it.

@boostsnippet("A manifest controller", "php")
public function __invoke(Request $request, Video $video, string $path): Response
{
    Gate::authorize('view', $video);

    $media = Media::fromDisk('streams')->open("videos/{$video->id}/{$path}");

    $manifest = str_ends_with($path, '.m3u8')
        ? $media->hlsPlaylist()
            ->resolveKeyUrlsUsing(fn (string $key) => URL::temporarySignedRoute('videos.key', now()->addMinutes(10), [$video]))
            ->resolvePlaylistUrlsUsing(fn (string $playlist) => URL::temporarySignedRoute('videos.manifest', now()->addHours(4), [$video, Str::after($playlist, "videos/{$video->id}/")]))
            ->resolveMediaUrlsUsing(fn (string $file) => Storage::disk('streams')->temporaryUrl($file, now()->addHours(4)))
        : $media->dashManifest()
            ->resolveMediaUrlsUsing(fn (string $file) => Storage::disk('streams')->temporaryUrl($file, now()->addHours(4)));

    return $manifest->toResponse($request);
}
@endboostsnippet

- **`hlsPlaylist()`** rewrites:
  - media playlists, including `#EXT-X-MEDIA` and I-frame playlists (`resolvePlaylistUrlsUsing`)
  - segments and `#EXT-X-MAP` init segments (`resolveMediaUrlsUsing`)
  - `#EXT-X-KEY`/`#EXT-X-SESSION-KEY` keys (`resolveKeyUrlsUsing`)

  `all()` returns the master and every media playlist it references, keyed by disk path. `process($path)` rewrites one playlist.
- **`dashManifest()`** rewrites `BaseURL`, `media` and `initialization`/`sourceURL` (`resolveInitUrlsUsing` falls back to the media resolver). Segment templates with `$Number$` are expanded into a segment list, because a template can't produce a different signed URL per segment. Query strings are escaped for XML.
- Without a resolver, URIs stay as they are, and absolute URLs are never changed. Each path is resolved once per request.
- Both are `Responsable` with the right content type, and also usable standalone: `new DynamicHLSPlaylist('streams')->open($path)`. Add cache headers yourself, and keep them shorter than the signed URLs' lifetime.

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

Each executable resolves lazily: an absolute path from config, or a command name found in the `PATH` or the project root. Only the tools you call need to be installed. Run `{{ $assist->artisanCommand('media:info') }}` to see which are found, with their paths and versions. `{{ $assist->artisanCommand('about') }}` also has a Media section with the disk, temporary root, timeout and executables. A missing one throws `ExecutableNotFoundException`, which names the env key to set.

## Configuration

Publish with `{{ $assist->artisanCommand('vendor:publish --tag=media-config') }}`.

| Key | Purpose |
| --- | --- |
| `disk` | Default disk for `Media::open()` (`MEDIA_DISK`) |
| `packager.default` | Packager driver (`MEDIA_PACKAGER`, `shaka`) |
| `executables.ffmpeg`, `.ffprobe`, `.packager`, `.ab-av1` | Path or command name (`MEDIA_FFMPEG_PATH`, …) |
| `timeout` | Process timeout in seconds; keep it at or below the queue job's `$timeout` |
| `log_channel` | Log channel, `false` to disable |
| `ffmpeg_log_level` | ffmpeg's `-loglevel` (`error`); `warning` logs warnings of successful runs (`MEDIA_FFMPEG_LOG_LEVEL`) |
| `remote_inputs.enabled`, `.url_lifetime` | Read remote disks through signed URLs |
| `temporary_files.root`, `.cache_root` | Where downloads and outputs are written; the cache root is for small files (e.g. `/dev/shm`) |
| `temporary_files.min_free`, `.size_multiplier`, `.cache_min_free` | Fail fast with `InsufficientStorageException` when a root is too full |
| `temporary_files.cleanup_after_jobs` | Delete temporary directories after every queue job (`MEDIA_CLEANUP_AFTER_JOBS`, on) |
| `uploads.concurrency`, `.multipart_threshold`, `.multipart_part_size`, `.multipart_concurrency` | S3 upload tuning |
| `uploads.rollback_on_failure` | Delete what an export already uploaded when another file fails (`MEDIA_UPLOADS_ROLLBACK_ON_FAILURE`, on) |

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

## Queue jobs, crashes and cleanup

- **After every job:** temporary directories are deleted when a queue job finishes or throws (`media.temporary_files.cleanup_after_jobs`, on by default), and at the end of each request.
- **Timeouts:** when a job times out, Laravel's worker kills itself with SIGKILL. The package listens for `JobTimedOut` and `WorkerStopping` first, stops the running ffmpeg (SIGTERM, then SIGKILL) so it doesn't keep running as an orphan, and deletes the temporary directories. Still give long encodes a `->timeout()` below the job's `$timeout`, so they fail cleanly with a retryable `Timeout` instead.
- **Crashes:** schedule `media:clean` to remove directories left behind by OOM kills, crashes or power loss. It only deletes the package's own directories (16 hex characters) that haven't been written to for longer than `--older-than` minutes (default: `media.timeout` plus an hour), so it's safe on shared mounts such as `/dev/shm`. `--dry-run` lists them.
- **Partial exports:** when one file of an export fails to upload, the files of that export that did reach the disk are deleted (`media.uploads.rollback_on_failure`), so retries start clean.
- **Overlapping jobs:** to stop two jobs from processing the same media at once, use Laravel's `WithoutOverlapping` job middleware, keyed by the model.

@boostsnippet("Scheduling media:clean", "php")
// routes/console.php
Schedule::command('media:clean')->hourly();

// a job that shouldn't overlap for the same video
public function middleware(): array
{
    return [(new WithoutOverlapping($this->video->id))->expireAfter(7200)];
}
@endboostsnippet

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

- Unknown paths probe as a one-minute 1080p H.264 video with AAC audio. Use `'*'` as the key to fake every probe, e.g. for uploads, which have random temporary names: `Media::fake(['*' => FakeProbe::video(duration: 5)])` makes `MediaFile::video()->minDuration(10)` fail. `FakeProbe::video()` also takes `width`, `height`, `codec`, `audio: false`, `transfer: 'smpte2084'` (HDR) and `frameRate`.
- `Media::fake()->failNext(Executable::FFMpeg, 'Invalid data found')` makes the next run throw `ProcessFailedException`. Use it to test failure handling and retries.
- `onProgress()` callbacks receive 50% and 100%. Probes and scenes are matched against the end of the opened path.
- Other assertions: `assertRanTimes()`, `assertNothingRan()`, `assertNotSaved()`. `Media::fake()` returns the fake, whose `commands(Executable::FFMpeg)` lists the recorded arguments.
