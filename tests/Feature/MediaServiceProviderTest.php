<?php

declare(strict_types=1);

use Foxws\Media\Executables\Executable;
use Foxws\Media\Executables\Executables;
use Foxws\Media\Filesystem\TemporaryDirectories;
use Foxws\Media\MediaConfig;

it('reads the media config once per application', function () {
    expect(app(MediaConfig::class))->toBe(app(MediaConfig::class))
        ->and(app(MediaConfig::class)->temporaryRoot)->toBe(config('media.temporary_files.root'));
});

it('builds its services from the media config', function () {
    mediaConfig([
        'media.executables.ffmpeg' => '/opt/ffmpeg',
        'media.temporary_files.root' => sys_get_temp_dir().'/laravel-media-provider',
    ]);

    expect(app(Executables::class)->available(Executable::FFMpeg))->toBeFalse()
        ->and(app(TemporaryDirectories::class)->create()->path())->toStartWith(sys_get_temp_dir().'/laravel-media-provider/');
});
