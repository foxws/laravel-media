<?php

declare(strict_types=1);

use Foxws\Media\FFMpeg\ThumbnailsResult;
use Foxws\Media\Filesystem\Disk;

it('lists the sprite sheets before the webvtt file', function () {
    $result = new ThumbnailsResult(Disk::local(sys_get_temp_dir()), ['a_001.jpg', 'a_002.jpg'], 'a.vtt', 10.0, 150);

    expect($result->paths())->toBe(['a_001.jpg', 'a_002.jpg', 'a.vtt']);
});

it('knows how long each sheet lasts', function () {
    $result = new ThumbnailsResult(Disk::make('local'), ['a_001.jpg', 'a_002.jpg'], 'a.vtt', 5.0, 130);

    expect($result->perSheet())->toBe(100)
        ->and($result->sheetDuration(0))->toBe(500.0)
        ->and($result->sheetDuration(1))->toBe(150.0)
        ->and($result->sheetDuration(2))->toBe(0.0)
        ->and($result->extension())->toBe('jpg');
});

it('can be stored and restored', function () {
    $result = new ThumbnailsResult(Disk::make('storyboards'), ['1/a_001.webp'], '1/a.vtt', 2.5, 40, 8, 5, 192, 108);

    $restored = ThumbnailsResult::fromArray(json_decode(json_encode($result->toArray()), true));

    expect($restored->toArray())->toBe($result->toArray())
        ->and($restored->extension())->toBe('webp')
        ->and(ThumbnailsResult::fromArray($result->toArray(), 's3')->disk->name())->toBe('s3');
});
