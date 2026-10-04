# Laravel Media

This application uses `foxws/laravel-media` to probe, process and package audio and video with ffprobe and ffmpeg on any Laravel disk.

- Use the `Foxws\Media\Facades\Media` facade (`Media::fromDisk($disk)->open($path)`). Don't add pbmedia/laravel-ffmpeg or php-ffmpeg, and don't shell out to ffmpeg or ffprobe directly.
- Read stream, format and chapter details from `->probe()` instead of parsing ffprobe output yourself, and validate uploads with the `Foxws\Media\Rules\MediaFile` rule rather than trusting extensions or MIME types.
- Use the filter classes in `Foxws\Media\Filters` with `addFilter()` (or `Custom::video()`/`Custom::audio()`) instead of raw `-vf`/`-af` arguments, and pass other ffmpeg options the package has no method for through `addArgs()`/`addInputArgs()` rather than building a separate command.
- Temporary files are cleaned up after every queue job automatically; schedule `media:clean` hourly for leftovers of crashed workers, and use Laravel's `WithoutOverlapping` middleware for jobs that must not process the same media at once.
- Serve direct HLS and DASH (with WebVTT subtitles through `withSubtitles()` and seek thumbnails through `withThumbnails()`, chapters, scenes and other markers through `withChapters()`, `withScenes()` and `withMarkers()`) with `MediaStream::define()` and `Route::mediaStream()` instead of hand-written playlist and segment controllers (`withEncryption()` encrypts them per request: AES-128 for MPEG-TS, ClearKey CENC for CMAF and DASH), and schedule `media:prune` daily to trim the segment cache. Direct streams cache probes and keyframe indexes per file version, so only the first request for a file runs ffprobe. They also package the next segments ahead of their requests (`media.delivery.look_ahead`, on a queue or after the response), so run a queue worker for the look-ahead queue.
- Catch `ProcessFailedException` in jobs and use `isRetryable()` to decide between `release()` and `fail()`; give long encodes a `->timeout()` below the job's `$timeout`.
- In tests, call `Media::fake()` (with `FakeProbe` data when needed) and `Storage::fake()` for the disks, then use `Media::assertSaved()`/`assertRan()`; never run the real executables.

When streaming HLS or DASH straight from stored files, packaging into HLS or DASH, probing media, extracting frames, subtitles or thumbnail sprites, detecting scenes, building reels, encoding, clipping or exporting results to disks, invoke `laravel-media-development` for detailed rules.
