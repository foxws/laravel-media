<?php

declare(strict_types=1);

use Foxws\Media\Facades\Media;
use Foxws\Media\Filesystem\TemporaryDirectory;
use Foxws\Media\Packaging\Drivers\Shaka\ShakaPackager;
use Foxws\Media\Packaging\Packager;
use Foxws\Media\Packaging\PackagerManager;
use Foxws\Media\Packaging\PackagingSpec;
use Illuminate\Support\Facades\Storage;

it('uses shaka packager by default', function () {
    expect(app(PackagerManager::class)->driver())->toBeInstanceOf(ShakaPackager::class);
});

it('packages with a registered custom driver', function () {
    Storage::fake('videos');
    $packager = new class implements Packager
    {
        public ?PackagingSpec $packaged = null;

        public function package(PackagingSpec $spec, TemporaryDirectory $directory, ?int $timeout = null): void
        {
            $this->packaged = $spec;
            file_put_contents($directory->path('manifest.mpd'), 'custom');
        }

        public function command(PackagingSpec $spec, string $directory): string
        {
            return 'custom';
        }
    };
    app(PackagerManager::class)->extend('custom', fn () => $packager);

    $result = Media::fromDisk('videos')->open('video.mp4')->package()->addVideoStream()->withDashManifest()->using('custom')->save('streams');

    expect($result->paths())->toBe(['streams/manifest.mpd'])
        ->and($packager->packaged?->dashManifest)->toBe('manifest.mpd');
});

it('reads the default driver from the config', function () {
    config(['media.packager.default' => 'custom']);
    app(PackagerManager::class)->extend('custom', fn () => Mockery::mock(Packager::class));

    expect(app(PackagerManager::class)->getDefaultDriver())->toBe('custom');
});
