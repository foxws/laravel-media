# Laravel Media

This application uses `foxws/laravel-media` to probe and process audio and video with ffprobe and ffmpeg on any Laravel disk.

- Use the `Foxws\Media\Facades\Media` facade (`Media::fromDisk($disk)->open($path)`). Don't add pbmedia/laravel-ffmpeg or php-ffmpeg, and don't shell out to ffmpeg or ffprobe directly.
- Read stream, format and chapter details from `->probe()` instead of parsing ffprobe output yourself.
- Use the filter classes in `Foxws\Media\Filters` with `addFilter()` (or `Custom::video()`/`Custom::audio()`) instead of raw `-vf`/`-af` arguments, and pass other ffmpeg options the package has no method for through `addArgs()`/`addInputArgs()` rather than building a separate command.
- Call `cleanupTemporaryFiles()` on the opener in `finally` in queued jobs, because workers are long-lived.
- In tests, fake processes with `Process::fake()` and disks with `Storage::fake()`; never run the real executables.

When probing media, extracting frames or subtitles, encoding, clipping or exporting results to disks, invoke `laravel-media-development` for detailed rules.
