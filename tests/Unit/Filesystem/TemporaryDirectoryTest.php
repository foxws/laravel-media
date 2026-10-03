<?php

declare(strict_types=1);

use Foxws\Media\Filesystem\TemporaryDirectories;
use Foxws\Media\Filesystem\TemporaryDirectory;

it('joins paths inside the directory', function (string $path, string $expected) {
    $directory = new TemporaryDirectory('/tmp/media/abc', new TemporaryDirectories('/tmp/media'));

    expect($directory->path($path))->toBe($expected);
})->with([
    'the directory itself' => ['', '/tmp/media/abc'],
    'a file' => ['ffmpeg2pass', '/tmp/media/abc/ffmpeg2pass'],
    'a nested file' => ['/sprites/sheet_001.jpg', '/tmp/media/abc/sprites/sheet_001.jpg'],
    'windows separators' => ['captions\\nld.vtt', '/tmp/media/abc/captions/nld.vtt'],
]);

it('casts to its path', function () {
    $directory = new TemporaryDirectory('/tmp/media/abc', new TemporaryDirectories('/tmp/media'));

    expect((string) $directory)->toBe('/tmp/media/abc');
});
