---
section: Usage
order: 5
---

# Queues, progress and errors

Encoding takes long, so run it in queued jobs. This page covers what those jobs need.

## Progress

`onProgress()` receives a `Progress` about twice a second while ffmpeg runs:

```php
use Foxws\Media\Process\Progress;

$media->ffmpeg()
    ->inFormat(Format::h264())
    ->onProgress(function (Progress $progress) use ($video) {
        $video->update(['progress' => $progress->percentage()]);   // 0-100, null when the duration is unknown

        $progress->remaining();   // estimated seconds left
        $progress->speed;         // e.g. 2.5 times real time
    })
    ->save('encoded.mp4');
```

- Two-pass encodes report one percentage over both passes.
- Return `false` from the callback to cancel. ffmpeg is stopped, nothing is saved, and a `ProcessFailedException` with the reason `Cancelled` is thrown.

## Events

| Event | When |
| --- | --- |
| `Foxws\Media\Events\ProgressReported` | every progress update |
| `Foxws\Media\Events\ExportCompleted` | after the files are on the target disk |
| `Foxws\Media\Events\ExportFailed` | when an export throws |
| `Foxws\Media\Process\Events\ProcessStarted`, `ProcessCompleted`, `ProcessFailed` | every ffmpeg and ffprobe run, with the command redacted |

`withContext()` tells listeners what an export is about:

```php
$media->ffmpeg()->withContext(['video_id' => $video->id])->inFormat(Format::h264())->save('encoded.mp4');

Event::listen(function (ProgressReported $event) {
    broadcast(new VideoEncoding($event->context['video_id'], $event->progress->percentage()));
});
```

## Failures and retries

Failed runs throw `ProcessFailedException`. Its `reason` is recognised from ffmpeg's error output, and `isRetryable()` tells whether trying again can help:

```php
use Foxws\Media\Exceptions\ProcessFailedException;

public function handle(): void
{
    try {
        Media::fromDisk('s3')->open($this->path)->ffmpeg()
            ->timeout(1800)
            ->inFormat(Format::h264())
            ->save($this->output);
    } catch (ProcessFailedException $exception) {
        $exception->isRetryable() ? $this->release(60) : $this->fail($exception);
    }
}
```

- Network errors, timeouts and full disks are retryable. Broken files, missing codecs and invalid options fail the same way every time.
- Reported exceptions include the exit code, the redacted command and the last lines of ffmpeg's output, so they're readable in Sentry, Flare or Nightwatch.
- Give long encodes a `timeout()` below the job's `$timeout`, so ffmpeg is stopped and the job fails with a retryable timeout instead of the worker being killed.
- Set `MEDIA_FFMPEG_LOG_LEVEL=warning` to log the warnings of runs that succeeded, for example to see why output from a damaged file looks wrong.

## Temporary files and crashes

- Temporary files are deleted after every queue job and request. Call `$media->cleanupTemporaryFiles()` to free them earlier.
- When a job times out, the running ffmpeg is stopped before the worker exits, so it doesn't keep running on its own.
- Schedule `media:clean` for what crashes and OOM kills leave behind, see [Installation](installation.md#schedule-the-cleanup-commands).
- Set `MEDIA_TEMPORARY_FILES_MIN_FREE` to make jobs fail early with `InsufficientStorageException` when the temporary disk is nearly full.
- To keep two jobs from processing the same video at once, use Laravel's `WithoutOverlapping` job middleware.
