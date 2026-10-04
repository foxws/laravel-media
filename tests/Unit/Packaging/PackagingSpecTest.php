<?php

declare(strict_types=1);

use Foxws\Media\Packaging\PackagingSpec;

it('lists its manifests with the hls playlist first', function () {
    expect(new PackagingSpec([], dashManifest: 'manifest.mpd', hlsPlaylist: 'master.m3u8')->manifests())->toBe(['master.m3u8', 'manifest.mpd'])
        ->and(new PackagingSpec([], dashManifest: 'manifest.mpd')->manifests())->toBe(['manifest.mpd'])
        ->and(new PackagingSpec([])->manifests())->toBe([]);
});
