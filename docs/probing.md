---
section: Usage
order: 1
---

# Opening and probing

## Opening files

`Media::fromDisk()` picks a Laravel disk and `open()` takes one path or several:

```php
use Foxws\Media\Facades\Media;

$media = Media::fromDisk('s3')->open('videos/clip.mp4');
$renditions = Media::fromDisk('videos')->open(['1080p.mp4', '720p.mp4']);

Media::open('videos/clip.mp4');   // the disk in media.disk, or the app's default disk
```

On disks that provide temporary URLs, such as S3, ffprobe and ffmpeg read the file through a short-lived signed URL instead of downloading it first. ffprobe then only fetches the parts it needs. Set `MEDIA_REMOTE_INPUTS=false` to download to the temporary directory instead.

## Probing

`probe()` runs ffprobe once and returns typed results:

```php
$probe = $media->probe();

$probe->duration();                     // float, seconds
$probe->format()->bitRate;              // bits per second
$probe->hasVideo();                     // ignores cover art
$probe->videoStream()?->width;
$probe->videoStream()?->frameRate;
$probe->videoStream()?->isHdr();        // PQ/HDR10 or HLG
$probe->audioStreams();                 // list<AudioStream>: channels, sampleRate, language
$probe->subtitleStreams();              // list<SubtitleStream>: language, forced()
$probe->chapters();                     // list<Chapter>: title, start, end
$probe->stream(2)?->get('tags.title');  // any raw ffprobe field, with dot notation
```

- With several files, `probe($path)` probes one (the first by default) and `probeAll()` returns them keyed by path.
- Results are cached on the opener. `rememberProbes()` also keeps them in the cache store per file version, so later requests skip ffprobe; streams do this by themselves.

## Keyframes

`keyframes()` lists where the keyframes of a video are. It reads packets without decoding, so it's fast even for long files:

```php
$index = $media->keyframes();

$index->keyframes;          // [0.0, 2.002, 4.004, ...]
$index->segments(6);        // list<Segment>, each at least 6 seconds and starting on a keyframe
$index->longestSegment(6);
```

Indexes are cached per file version for `media.delivery.index_lifetime` seconds. Streaming relies on them: segments that start on a keyframe can be copied without re-encoding.

## Validating uploads

The `MediaFile` rule probes the upload with ffprobe, so validation depends on what the file contains instead of its extension or MIME type:

```php
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
```

Files ffprobe can't read fail with "The :attribute must be a readable media file." Codec names are ffprobe's. Keep the `file` and `max` rules, so oversized uploads are rejected before they're probed.
