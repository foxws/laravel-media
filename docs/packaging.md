---
section: Usage
order: 4
---

# Packaging

Packaging writes HLS and DASH to a disk ahead of time: segments, playlists and manifests that any web server or CDN can serve. It doesn't re-encode, so [encode](encoding.md#rendition-ladders) the renditions first.

[Streaming](streaming.md) needs no packaging step and is usually simpler. Package when the files have to be served without the app, for example from a CDN.

```php
$result = Media::fromDisk('renditions')
    ->open(['1080p.mp4', '720p.mp4', '480p.mp4'])
    ->exportAsStreams()           // or exportAsHLS(), exportAsDASH()
    ->toDisk('streams')
    ->save("videos/{$video->id}");

$result->path();    // "videos/1/master.m3u8"
$result->paths();   // every written file, manifests first
```

- `exportAsHLS()`, `exportAsDASH()` and `exportAsStreams()` add the video and audio of every opened file. Add streams by hand with `package()->addVideoStream()`, `addAudioStream()` and `addTextStream()`.
- `segmentDuration(6)`, `withHlsPlaylist('master.m3u8')` and `withDashManifest('manifest.mpd')` change the defaults.
- `toDisk()`, `withVisibility()`, `timeout()`, `withContext()`, `beforeSaving()`/`afterSaving()`, events and S3 uploads work like the [ffmpeg builder](encoding.md).

## The native driver

The default `native` driver needs only ffmpeg. It cuts the same keyframe-aligned fragmented MP4 segments as streaming, shared by HLS and DASH:

- `0_video.mp4` becomes `0_video/init.mp4`, `0_video/{n}.m4s` and the `0_video.m3u8` media playlist. Everything is linked with relative URLs.
- Segments already cut for streaming are reused from the segment cache.
- Live playlists, schemes other than `cenc`, key rotation, a clear lead and more than one audio stream aren't supported, and throw `InvalidArgumentException`.

Other drivers can be registered, see [Extending](extending.md#packager-drivers), and picked per export with `->using('name')` or by default with `MEDIA_PACKAGER`.

## Encryption

```php
$result = Media::fromDisk('renditions')->open($renditions)
    ->exportAsStreams()
    ->withEncryption()            // a new random key, or pass an EncryptionKey
    ->toDisk('streams')
    ->withVisibility('private')
    ->save("videos/{$video->id}");

$key = $result->encryptionKey();  // store $key->keyId and encrypt($key->key)
```

- Segments are encrypted with Common Encryption (`cenc`). HLS playlists point at the raw key, saved next to them as `key`. Keep it private and serve it through an authorized route; `withEncryption(keyFile: null, keyUri: route('videos.key', $video))` points the playlists at your route instead.
- DASH manifests name the key ID but have no license URL, so DASH players need the key in their ClearKey configuration.
- Keys are redacted from commands, logs and events.

## Serving private files with signed URLs

Keep the packaged files on a private disk and rewrite the manifests per request, so every URL in them is short-lived:

```php
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
```

- Resolvers receive each file's path on the disk, resolved relative to the manifest that links it.
- HLS rewrites media playlists, segments, init segments and keys. DASH rewrites base URLs, segments and init segments, and expands `$Number$` templates into a list, so every segment gets its own signed URL.
- Without a resolver a URL stays as it is, and absolute URLs are never changed.
