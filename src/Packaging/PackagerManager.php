<?php

declare(strict_types=1);

namespace Foxws\Media\Packaging;

use Foxws\Media\Filesystem\TemporaryDirectories;
use Foxws\Media\Packaging\Drivers\Native\NativePackager;
use Foxws\Media\Packaging\Drivers\Shaka\ShakaPackager;
use Foxws\Media\Process\Runner;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Manager;

/**
 * Resolves packager drivers. Register your own with extend('name', fn ($app) => new MyPackager).
 *
 * @method Packager driver(?string $driver = null)
 */
class PackagerManager extends Manager
{
    public function getDefaultDriver(): string
    {
        return Config::string('media.packager.default', 'shaka');
    }

    /**
     * Packages with ffmpeg and the direct stream playlists, without Shaka Packager.
     */
    public function createNativeDriver(): NativePackager
    {
        return new NativePackager;
    }

    public function createShakaDriver(): ShakaPackager
    {
        return new ShakaPackager(
            $this->container->make(Runner::class),
            $this->container->make(TemporaryDirectories::class),
        );
    }
}
