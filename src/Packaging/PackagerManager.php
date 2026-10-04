<?php

declare(strict_types=1);

namespace Foxws\Media\Packaging;

use Foxws\Media\Packaging\Drivers\Native\NativePackager;
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
        return Config::string('media.packager.default', 'native');
    }

    /**
     * Packages with ffmpeg and the direct stream playlists, without Shaka Packager.
     */
    public function createNativeDriver(): NativePackager
    {
        return new NativePackager;
    }
}
