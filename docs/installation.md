---
section: Getting Started
order: 1
---

# Installation

## Requirements

- PHP 8.4 or higher, with the OpenSSL extension
- Laravel 13
- ffmpeg and ffprobe. Static builds work; tone mapping needs ffmpeg built with libzimg (`ffmpeg -filters | grep zscale` checks).
- `league/flysystem-aws-s3-v3` when you read from or write to S3 disks

## Install the package

```bash
composer require foxws/laravel-media
```

Publish the config file to `config/media.php`:

```bash
php artisan vendor:publish --tag="media-config"
```

## Point it at ffmpeg

Executables are resolved when they're first used, so only the tools you call need to be installed. A command name is looked up in the `PATH` and in the project root, so a static binary placed next to `artisan` is found too. Set an absolute path when they live elsewhere:

```dotenv
MEDIA_FFMPEG_PATH=/usr/local/bin/ffmpeg
MEDIA_FFPROBE_PATH=/usr/local/bin/ffprobe
```

Check what's found, with paths and versions:

```bash
php artisan media:info
```

`php artisan about` has a Media section too. A missing executable throws `ExecutableNotFoundException`, which names the variable to set.

## Schedule the cleanup commands

```php
// routes/console.php
use Illuminate\Support\Facades\Schedule;

Schedule::command('media:clean')->hourly();   // temporary files left behind by crashed workers
Schedule::command('media:prune')->daily();    // segments cached for direct streaming
```

- `media:clean` deletes the package's own temporary directories that haven't been written to for longer than `--older-than` minutes (by default the process timeout plus an hour). Temporary files are already deleted after every queue job and request, so this only catches what crashes, OOM kills or power loss leave behind.
- `media:prune` deletes segments and converted subtitles that were packaged more than `--older-than` minutes ago (a week by default) from the segment cache. They're packaged again when requested, so pruning is always safe.

Both take `--dry-run`.

## Run a queue worker for streaming

[Direct streams](streaming.md) package the next segments ahead of the player's requests in a queued job. Run a worker for that queue, ideally a dedicated one:

```dotenv
MEDIA_DELIVERY_LOOK_AHEAD_QUEUE=media
```

```bash
php artisan queue:work --queue=media
```

Without a worker, set `MEDIA_DELIVERY_LOOK_AHEAD_VIA=defer` to package them in the same process after the response instead.
