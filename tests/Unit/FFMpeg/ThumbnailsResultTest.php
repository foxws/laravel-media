<?php

declare(strict_types=1);

use Foxws\Media\FFMpeg\ThumbnailsResult;
use Foxws\Media\Filesystem\Disk;

it('lists the sprite sheets before the webvtt file', function () {
    $result = new ThumbnailsResult(Disk::local(sys_get_temp_dir()), ['a_001.jpg', 'a_002.jpg'], 'a.vtt', 10.0, 150);

    expect($result->paths())->toBe(['a_001.jpg', 'a_002.jpg', 'a.vtt']);
});
