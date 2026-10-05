# Laravel Media

[![Latest Version on Packagist](https://img.shields.io/packagist/v/foxws/laravel-media.svg?style=flat-square)](https://packagist.org/packages/foxws/laravel-media)
[![Tests](https://github.com/foxws/laravel-media/actions/workflows/tests.yml/badge.svg)](https://github.com/foxws/laravel-media/actions/workflows/tests.yml)
[![Total Downloads](https://img.shields.io/packagist/dt/foxws/laravel-media.svg?style=flat-square)](https://packagist.org/packages/foxws/laravel-media)

Probe, encode, package and stream audio and video in Laravel with ffprobe and ffmpeg, on any Laravel disk.

- **Probe** files into typed streams, chapters and formats, and validate uploads by what they really contain.
- **Encode** with presets, filters, watermarks, HDR to SDR tone mapping, several outputs in one run, and rendition ladders with optional GPU encoding.
- **Process** clips, frames, subtitles, scene detection, reels and seek-preview thumbnail sprites.
- **Stream** HLS (CMAF or MPEG-TS) and DASH straight from the stored files, cutting segments on request like nginx-vod-module, with per-request encryption, subtitles, thumbnails, chapters, trick play and multiple audio languages.
- **Package** renditions into static HLS and DASH with ClearKey encryption, and serve private manifests with signed URLs.
- **Export** to local or S3 disks with concurrent multipart uploads, and follow progress, events and retryable failures from queued jobs.
- **Test** without ffmpeg using `Media::fake()`.

It builds ffmpeg commands directly, without php-ffmpeg underneath, so any ffmpeg option is available without waiting on a package release.

See the [full documentation](docs): [Installation](docs/installation.md), [Opening and probing](docs/probing.md), [Encoding](docs/encoding.md), [Streaming](docs/streaming.md), [Packaging](docs/packaging.md), [Queues, progress and errors](docs/queues.md), [Testing](docs/testing.md), [Configuration](docs/configuration.md), [Extending](docs/extending.md).

## Requirements

- PHP 8.4 or higher, with the OpenSSL extension
- Laravel 13
- ffmpeg and ffprobe
- `league/flysystem-aws-s3-v3` for S3 disks

## Installation

```bash
composer require foxws/laravel-media
```

```bash
php artisan vendor:publish --tag="media-config"
```

Check that ffmpeg and ffprobe are found:

```bash
php artisan media:info
```

See [Installation](docs/installation.md) for the scheduled commands and queue setup.

## Quick start

Probe a file and encode a clip of it to another disk:

```php
use Foxws\Media\Encoding\Format;
use Foxws\Media\Facades\Media;

$media = Media::fromDisk('s3')->open('videos/clip.mp4');

$media->probe()->duration();              // 93.4
$media->probe()->videoStream()?->height;  // 1080

$media->ffmpeg()
    ->clip(from: 12.5, to: 40)
    ->inFormat(Format::h264(crf: 22))
    ->toDisk('clips')
    ->save('intro.mp4');
```

Encode renditions once, then stream them as HLS and DASH without packaging them first:

```php
use Foxws\Media\Encoding\Ladder;
use Foxws\Media\Facades\MediaStream;

// in a queued job
$result = Media::fromDisk('uploads')->open($upload)
    ->ladder(Ladder::standard(), "videos/{$video->id}/{height}p.mp4")
    ->toDisk('videos')
    ->save();

// AppServiceProvider::boot()
MediaStream::define('videos', fn (Video $video) => Media::fromDisk('videos')->open($video->renditions)
    ->stream()
    ->withChapters()
    ->withTrickPlay());

// routes/web.php
Route::middleware('auth')->group(fn () => Route::mediaStream('videos/{video}', 'videos'));

// the URL to give the player
MediaStream::url('videos', ['video' => $video]);
```

See [Streaming](docs/streaming.md) for subtitles, thumbnails, encryption and the segment cache.

## Testing

```bash
composer test
```

## Links

- [CHANGELOG](CHANGELOG.md)
- [Security policy](../../security/policy)
- [ffmpeg documentation](https://ffmpeg.org/documentation.html)

## Credits

- [francoism90](https://github.com/francoism90)
- [All Contributors](../../contributors)

This package started from ideas in [Laravel FFMpeg](https://github.com/protonemedia/laravel-ffmpeg) and [nginx-vod-module](https://github.com/kaltura/nginx-vod-module).

Used by [Stry](https://github.com/francoism90/stry), a self-hosted video streaming app.

AI, specifically [Claude](https://claude.com/product/claude-code), was used to help build this package. All AI-assisted output is reviewed by me, and I retain final say over everything that is implemented and released.

## License

MIT. See [License File](LICENSE.md).
