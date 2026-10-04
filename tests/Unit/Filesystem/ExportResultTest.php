<?php

declare(strict_types=1);

use Foxws\Media\Encryption\EncryptionKey;
use Foxws\Media\Filesystem\Disk;
use Foxws\Media\Filesystem\ExportResult;

it('exposes the written paths, the main path and the encryption key', function () {
    $key = EncryptionKey::generate();

    $result = new ExportResult(Disk::local(sys_get_temp_dir()), ['master.m3u8', 'video.mp4'], $key);

    expect($result->path())->toBe('master.m3u8')
        ->and($result->paths())->toBe(['master.m3u8', 'video.mp4'])
        ->and($result->encryptionKey())->toBe($key)
        ->and(new ExportResult(Disk::local(sys_get_temp_dir()), [])->path())->toBeNull();
});
