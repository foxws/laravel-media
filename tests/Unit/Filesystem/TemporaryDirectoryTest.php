<?php

declare(strict_types=1);

use Foxws\Media\Exceptions\TemporaryFileException;
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

it('writes files and creates their folders', function () {
    $root = sys_get_temp_dir().'/laravel-media-directory-'.bin2hex(random_bytes(4));
    $directory = new TemporaryDirectory($root, new TemporaryDirectories(sys_get_temp_dir()));

    $file = $directory->put('lists/concat.txt', "file 'a.mp4'\n");

    expect($file)->toBe("{$root}/lists/concat.txt")
        ->and(file_get_contents($file))->toBe("file 'a.mp4'\n")
        ->and($directory->makeDirectory('sprites'))->toBe("{$root}/sprites")->toBeDirectory();
});

it('fails clearly when a file cannot be written', function () {
    $directory = new TemporaryDirectory('/proc/laravel-media', new TemporaryDirectories(sys_get_temp_dir()));

    $directory->put('concat.txt', 'list');
})->throws(TemporaryFileException::class, "Can't write the temporary file [/proc/laravel-media/concat.txt]");
