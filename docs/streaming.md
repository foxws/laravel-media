# Streaming

`stream()` serves stored files as HLS and DASH without packaging them first, like nginx-vod-module. Playlists come from the [keyframe index](probing.md#keyframes), and each segment is copied out of the file with `ffmpeg -c copy` the first time a player requests it, then kept on a cache disk. Each video is stored once, as an ordinary MP4 or MKV.

Every opened file is one variant, for example the renditions of a [ladder](encoding.md#rendition-ladders).

## Defining a stream

Define how a stream is resolved from its route parameters, for example in `AppServiceProvider::boot()`:

```php
use Foxws\Media\Facades\Media;
use Foxws\Media\Facades\MediaStream;

MediaStream::define('videos', function (Video $video) {
    Gate::authorize('view', $video);

    return Media::fromDisk('videos')->open($video->renditions)->stream();
})->signed();
```

- Parameters typed as a model are bound like route model binding, and other parameters are injected by the container.
- Return an `Opener`, or a `DirectStream` from `stream()` to configure it.
- The resolver runs for every playlist, segment and key request, so authorize in it or with route middleware.
- `signed()` rejects requests without a valid signature and signs every URL in the playlists. They're valid for `media.delivery.url_lifetime` seconds, or pass a lifetime: `signed(14400)`.

Register the routes:

```php
// routes/web.php
Route::middleware('auth')->group(fn () => Route::mediaStream('videos/{video}', 'videos'));
```

Then give the player one of these URLs:

```php
MediaStream::url('videos', ['video' => $video]);          // HLS with fragmented MP4 (CMAF)
MediaStream::dashUrl('videos', ['video' => $video]);      // DASH, sharing the CMAF segments
MediaStream::hlsUrl('videos', ['video' => $video]);       // HLS with MPEG-TS
MediaStream::chaptersUrl('videos', ['video' => $video]);  // chapters as WebVTT
```

Prefer `url()` and `dashUrl()`: one set of cached segments serves both, and every modern player plays fragmented MP4 natively. MPEG-TS is for rotating encryption keys and old devices. Shaka Player needs mux.js for it.

| Codecs | CMAF and DASH | MPEG-TS |
| --- | --- | --- |
| Video | H.264, HEVC, AV1, VP9 | H.264, HEVC |
| Audio | AAC, MP3, AC-3, E-AC-3, Opus, FLAC | AAC, MP3, AC-3, E-AC-3 |

## Subtitles

```php
->stream()
    ->withSubtitles('captions/1_en.vtt', language: 'en', label: 'English')   // a WebVTT file
    ->withEmbeddedSubtitles();                                               // and the text subtitles inside the first file
```

Embedded SubRip, MP4 text, ASS and WebVTT streams are converted to WebVTT once and cached. Bitmap subtitles (PGS, DVD) are skipped.

## Audio languages

Every audio stream of the first file with audio becomes an audio track, so players pick one by language, like Shaka Player's `preferredAudioLanguage`. The stream marked as default starts selected. Limit them by language or position:

```php
->stream()->withAudioStreams(['eng', 'jpn']);
```

Streams that fragmented MP4 can't carry, like TrueHD or DTS, are left out. MPEG-TS playlists carry the first audio stream only.

## Thumbnails

Give the stream the [thumbnail sprites](encoding.md#thumbnail-sprites) made when the video was stored, and players get seek previews from the playlist and manifest themselves:

```php
use Foxws\Media\FFMpeg\ThumbnailsResult;

->stream()->withThumbnails(ThumbnailsResult::fromArray($video->thumbnails));
```

HLS gets an `#EXT-X-IMAGE-STREAM-INF` image playlist and DASH a thumbnail tile adaptation set. Shaka Player reads both with `getThumbnails()`.

## Chapters and markers

```php
use Foxws\Media\Delivery\Marker;
use Foxws\Media\FFMpeg\Scene;

->stream()
    ->withChapters()                                                  // the chapters of the first file
    ->withScenes(array_map(Scene::fromArray(...), $video->scenes))
    ->withMarkers([new Marker(12.4, 20.6, 'Intro', class: 'intro')]);
```

- **In the playlists:** markers become `#EXT-X-DATERANGE` tags in HLS and an `EventStream` per class in DASH. Shaka Player reports both as `timelineregionadded` events.
- **On the seek bar:** `chaptersUrl()` serves the chapters as WebVTT, for Shaka Player's `addChaptersTrack()`. Each chapter ends where the next one starts.
- **Other classes and gaps:** `chapterTrackFrom(['chapter', 'intro'], 'Main')` lists other marker classes as chapters, and fills the gaps between them with a cue titled `Main`, so the last chapter's title doesn't stay on screen until the end.

## Trick play

```php
->stream()->withTrickPlay();
```

Adds an I-frame playlist per video variant to CMAF HLS, and a trick mode adaptation set to DASH, so players show frames while fast-forwarding. Each segment of the trick track is the one keyframe a video segment starts with. With one per segment, shorter segments give smoother trick play.

## Encryption

Segments can be encrypted for each request, while the cache keeps them unencrypted and shared:

```php
use Foxws\Media\Encryption\EncryptionKey;

->stream()->withEncryption(EncryptionKey::derive(config('app.key'), "video:{$video->id}"));
```

- **MPEG-TS** segments are encrypted with AES-128, and the playlist links the key.
- **CMAF and DASH** use Common Encryption (`cenc`), played through ClearKey in browsers that support it (Chrome, Edge and Firefox). DASH players fetch the key as a license from `license.json`. Players POST to it, so register the routes where CSRF protection doesn't apply, such as `routes/api.php`, or exclude `*/license.json`.
- **Keys:** `EncryptionKey::derive()` makes a key from a secret and a context, so it doesn't need storing.
- **Rotating keys:** pass a callback and `rotateEvery`, e.g. `withEncryption(fn (int $period) => EncryptionKey::derive($secret, "video:{$id}:{$period}"), rotateEvery: 100)`. This is for MPEG-TS only, since fragmented MP4 takes one key.
- **AV1 and VP9** can't be encrypted in fragmented MP4 yet.

ClearKey hands the key to the browser. It protects segments in transit and at rest, but not from the viewer.

## The segment cache

- **Where:** segments are kept on `media.delivery.cache_disk` under `media.delivery.cache_path`. That can be local storage, a RAM disk or S3. On disks with temporary URLs, segment requests redirect to one. Keep the disk private when streams are encrypted.
- **Per file version:** segments are keyed by the disk, path, size and modification time of the source, so every viewer shares them and a replaced file gets new ones.
- **One package per segment:** concurrent requests for the same segment package it once.
- **Look-ahead:** after a segment is requested, the next `media.delivery.look_ahead` segments are packaged ahead in a `PackageSegments` job, so they're cached before the player asks. Set `look_ahead_via` to `defer` to package them after the response instead, or `null` to turn it off. Per stream: `lookAhead(4)`.
- **Probes:** each file is probed once per version, then read from the cache store, so playlist and segment requests don't run ffprobe.
- **Pruning:** schedule `media:prune` to delete old segments. `media:prune --older-than=0` empties the cache.

## Without the routes

For full control, call the stream from your own controllers:

```php
use Foxws\Media\Delivery\Segment;

public function playlist(Video $video, int $variant): Response
{
    $playlist = Media::fromDisk('videos')->open($video->renditions)->stream()
        ->mediaPlaylist($variant, fn (Segment $segment, int $variant) => route('videos.segment', [$video, $variant, $segment->index]));

    return response($playlist, 200, ['Content-Type' => 'application/vnd.apple.mpegurl']);
}

public function segment(Video $video, int $variant, int $segment): Response
{
    return Media::fromDisk('videos')->open($video->renditions)->stream()->segmentResponse($variant, $segment);
}
```

`masterPlaylist()`, `mediaPlaylist()`, `dashManifest()`, `segmentResponse()`, `initSegmentResponse()`, `subtitleResponse()`, `keyResponse()` and `licenseResponse()` are the building blocks the routes use.
