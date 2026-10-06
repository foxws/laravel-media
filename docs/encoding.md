---
section: Usage
order: 2
---

# Encoding

`ffmpeg()` returns a builder for one ffmpeg run. `save()` writes the output to a temporary directory, then copies it to the target disk.

```php
use Foxws\Media\Encoding\Format;
use Foxws\Media\Facades\Media;

$result = Media::fromDisk('s3')
    ->open('videos/clip.mp4')
    ->ffmpeg()
    ->clip(from: 12.5, to: 40)
    ->inFormat(Format::h264(crf: 22))
    ->toDisk('clips')                 // the source disk by default
    ->withVisibility('private')
    ->afterSaving(fn ($builder, $result) => $clip->markAsReady($result->path()))
    ->save('intro/clip.mp4');

$result->paths();   // every written path
```

- `frame(at: 5.0)->save('thumb.jpg')` grabs a single frame.
- `map('0:2')->inFormat(Format::webVtt())->save('captions/nld.vtt')` extracts a subtitle stream.
- `addArgs([...])` adds output options, and `addInputArgs([...])` adds options before every input, for anything the builder has no method for.
- `clip()` seeks on the input. With `Format::copy()` the clip starts at the keyframe before `from`; use a re-encoding format for exact cuts.
- `beforeSaving()` can still change the command, and `afterSaving()` runs once the files are on the target disk.
- `command('out.mp4')` returns the command line, with keys redacted, without running it.

## Formats

Presets: `Format::copy()`, `h264()`, `hevc()`, `av1()`, `vp9()`, the audio-only `aac()`, `mp3()`, `opus()` and `flac()`, plus `webVtt()` and `jpeg()`. Formats are immutable, so every method returns a changed copy:

```php
Format::h264()->crf(20)->preset('slow');                 // constant quality
Format::h264()->bitrate(2500, max: 3000, buffer: 6000);    // kbit/s
Format::h264()->bitrate(2500)->twoPass();                  // libx264 or libvpx-vp9
Format::vp9(crf: 31)->bitrate(1800);                        // constrained quality
Format::h264()->audioBitrate(128)->audioChannels(2)->sampleRate(48000);
Format::h264()->withoutAudio();                             // also withoutVideo(), withoutSubtitles()
```

Invalid combinations, like two passes without a bitrate, throw `InvalidFormatException` before ffmpeg runs. `withArguments([...])` appends raw output options.

## Filters

Filters are value objects in `Foxws\Media\Filters`. Video and audio filters go into their own chain, in the order they're added:

```php
use Foxws\Media\Filters\Fade;
use Foxws\Media\Filters\Loudnorm;
use Foxws\Media\Filters\Position;
use Foxws\Media\Filters\Scale;

$media->ffmpeg()
    ->addFilter(Scale::fit(1080, 1920), Fade::in(1), Fade::out(1, start: 29))
    ->addFilter(new Loudnorm, Fade::audioIn(0.5))
    ->watermark('logo.png', disk: 'branding', position: Position::BottomRight, margin: 24, width: 160)
    ->inFormat(Format::h264())
    ->save('reels/1.mp4');
```

- **Video:** `Scale::to()`, `Scale::fit()` (letterbox), `Scale::fill()` (crop), `Crop`, `Pad`, `Rotate`, `Fps` and `Fade`.
- **Audio:** `Fade::audioIn()`/`audioOut()`, `Volume::times()`/`decibels()` and `Loudnorm`.
- **Anything else:** `Custom::video('hqdn3d')` or `Custom::audio('atempo=1.25')`.

### HDR to SDR

HDR video looks washed out when it's encoded as regular SDR or turned into images. `toneMap()` converts it, and does nothing for SDR sources, so it's safe to always call:

```php
$media->ffmpeg()->toneMap()->addFilter(Scale::to(1280))->inFormat(Format::h264())->save('sdr.mp4');
```

It uses zscale, so ffmpeg needs libzimg. Thumbnails are tone mapped by default.

## Several outputs in one run

`addOutput()` writes another file from the same run, so the input is read and decoded once:

```php
use Foxws\Media\FFMpeg\Output;

$builder = $media->ffmpeg()->toDisk('captions');

foreach ($media->probe()->subtitleStreams() as $stream) {
    $builder->addOutput("{$movie->id}/{$stream->language}.vtt", fn (Output $output) => $output
        ->map("0:{$stream->index}")
        ->inFormat(Format::webVtt()));
}

$builder->save();
```

Each output has its own `map()`, `inFormat()`, `addFilter()` and `addArgs()`. Two-pass encoding and watermarks need a single output.

## Rendition ladders

`ladder()` encodes a video into several sizes for adaptive streaming, in one ffmpeg run:

```php
use Foxws\Media\Encoding\HardwareAcceleration;
use Foxws\Media\Encoding\Ladder;
use Foxws\Media\Encoding\Rendition;
use Foxws\Media\Encoding\VideoCodec;

$result = Media::fromDisk('uploads')->open($upload)
    ->ladder(Ladder::standard(), "videos/{$video->id}/{height}p.mp4")   // 1080p, 720p, 480p and 360p
    ->toDisk('videos')
    ->save();

new Ladder([new Rendition(1440, 9000), new Rendition(720, 3000)], VideoCodec::Hevc, preset: 'slow');
Ladder::standard()->codec(VideoCodec::Av1)->hardware(HardwareAcceleration::Vaapi)->keyframeInterval(4);
```

- **Sizes:** a rendition is a short side and a bitrate in kbit/s. Portrait video is scaled on its width. Renditions larger than the source are skipped, so nothing is upscaled.
- **Keyframes:** every rendition gets keyframes at the same times (every `media.delivery.segment_duration` seconds by default, with scene-cut keyframes off), so players can switch between them at every segment.
- **Keeping the source:** `->alignToSource()` places the keyframes where the source's [direct stream](streaming.md) segments start instead, so the source can be streamed unchanged as the top variant, with only smaller sizes encoded:

```php
$media = Media::fromDisk('videos')->open('movie.mp4');

$media->ladder(new Ladder([new Rendition(720, 2800), new Rendition(480, 1400)])->alignToSource(), 'renditions/{height}p.mp4')->save();

Media::fromDisk('videos')->open(['movie.mp4', 'renditions/720p.mp4', 'renditions/480p.mp4'])->stream();
```

  Stream them with the same segment duration the ladder used. `->keyframesAt([0, 6.2, 12.4])` places keyframes at times of your own.
- **Codecs:** H.264 (the default), HEVC or AV1, with AAC audio in MP4.
- **GPU encoding:** set `MEDIA_LADDER_HARDWARE` to `vaapi`, `nvenc` or `qsv`, or call `hardware()`, to decode, scale and encode on the GPU. VAAPI and Quick Sync open `MEDIA_LADDER_VAAPI_DEVICE`: `/dev/dri/renderD128` for the first GPU, `renderD129` for a second one. When the GPU can't be opened (no device in the container, or no access to it), the ladder is encoded on the CPU instead; the failed check is logged and remembered for five minutes. Sources the GPU can't decode, such as AV1 or 10-bit video on many GPUs, are decoded on the CPU and uploaded to the GPU to be scaled and encoded; that's checked by decoding and scaling the first frame once per codec, profile and pixel format, and remembered for an hour. `hardwareDecoding(false)` always decodes on the CPU. GPU frames are scaled to 8-bit 4:2:0, so 10-bit sources work with every hardware encoder.

The result can be [streamed](streaming.md) straight away or [packaged](packaging.md).

## Scenes, clips and reels

```php
use Foxws\Media\FFMpeg\Clip;
use Foxws\Media\FFMpeg\Scene;

$media = Media::fromDisk('s3')->open(['videos/a.mp4', 'videos/b.mp4']);

$scenes = $media->scenes(threshold: 0.3);   // list<Scene>: start, end, score

$clips = collect($scenes)
    ->sortByDesc('score')->take(5)->sortBy('start')
    ->map(fn (Scene $scene) => $scene->toClip(maximumDuration: 4))
    ->push(Clip::make(10, 14, 'videos/b.mp4'))
    ->values()->all();

$media->ffmpeg()
    ->clips($clips, width: 1080, height: 1920, fps: 30)   // a vertical reel
    ->inFormat(Format::h264())
    ->save('reels/1.mp4');
```

- `clips()` re-encodes, with frame-accurate cuts, and can take clips from any opened file.
- `concat()` joins whole files without re-encoding when they share codecs and dimensions.
- `Scene::toArray()` and `Scene::fromArray()` store scenes in a JSON column, since detection decodes the whole video.

## Thumbnail sprites

`thumbnails()` samples a video into sprite sheets and a WebVTT file whose cues point at each tile, for seek previews:

```php
$result = Media::fromDisk('s3')->open('videos/movie.mp4')->thumbnails()
    ->every(10)                    // or ->count(100)
    ->size(160, 90)
    ->grid(10, 10)
    ->format('webp', quality: 75)
    ->keyframesOnly()              // decode keyframes only: much faster on long videos
    ->toDisk('storyboards')
    ->save("{$movie->id}/storyboard");

$result->sprites;     // ["1/storyboard_001.webp", ...]
$result->vtt;         // "1/storyboard.vtt"
$result->toArray();   // store it, and give it to a stream with withThumbnails()
```

When every thumbnail fits on one sheet, the grid shrinks to just the thumbnails it holds, so a short video gets a small sheet instead of a mostly empty one.

## Exporting

- **S3 disks:** files upload concurrently, and large ones as multipart uploads that are aborted when they fail.
- **Local disks:** files are moved.
- When one file of an export fails, the files that did reach the disk are deleted, so a retry starts clean. A failed copy throws `ExportFailedException`, which lists every failure.
