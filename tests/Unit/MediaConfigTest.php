<?php

declare(strict_types=1);

use Foxws\Media\Executables\Executable;
use Foxws\Media\MediaConfig;

it('reads the media config array', function () {
    $config = MediaConfig::fromArray([
        'disk' => 's3',
        'executables' => ['ffmpeg' => '/opt/ffmpeg/bin/ffmpeg', 'packager' => ''],
        'timeout' => 600,
        'ffmpeg_log_level' => 'warning',
        'log_channel' => 'media',
        'remote_inputs' => ['enabled' => false, 'url_lifetime' => 60],
        'temporary_files' => ['root' => '/tmp/media', 'cache_root' => '/dev/shm/media', 'min_free' => 1024, 'size_multiplier' => 2, 'cache_min_free' => 512],
        'uploads' => ['concurrency' => 4, 'multipart_threshold' => 1000, 'multipart_part_size' => 500, 'multipart_concurrency' => 2],
    ], defaultDisk: 'local');

    expect($config)
        ->disk->toBe('s3')
        ->timeout->toBe(600)
        ->ffmpegLogLevel->toBe('warning')
        ->logChannel->toBe('media')
        ->remoteInputs->toBeFalse()
        ->remoteInputUrlLifetime->toBe(60)
        ->temporaryMinFree->toBe(1024)
        ->temporarySizeMultiplier->toBe(2.0)
        ->cacheMinFree->toBe(512)
        ->uploadConcurrency->toBe(4)
        ->multipartThreshold->toBe(1000)
        ->multipartPartSize->toBe(500)
        ->multipartConcurrency->toBe(2)
        ->and($config->executable(Executable::FFMpeg))->toBe('/opt/ffmpeg/bin/ffmpeg')
        ->and($config->executable(Executable::Packager))->toBe('packager')
        ->and($config->temporaryRoots())->toBe(['/tmp/media', '/dev/shm/media']);
});

it('falls back to the default disk and package defaults', function () {
    $config = MediaConfig::fromArray(['disk' => null, 'log_channel' => 'false'], defaultDisk: 'public');

    expect($config)
        ->disk->toBe('public')
        ->timeout->toBe(14400)
        ->ffmpegLogLevel->toBe('error')
        ->logChannel->toBeFalse()
        ->remoteInputs->toBeTrue()
        ->cacheRoot->toBeNull()
        ->and($config->executable(Executable::AbAv1))->toBe('ab-av1');
});
