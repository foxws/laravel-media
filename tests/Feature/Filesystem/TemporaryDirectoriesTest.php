<?php

declare(strict_types=1);

use Foxws\Media\Exceptions\InsufficientStorageException;
use Foxws\Media\Filesystem\TemporaryDirectories;

beforeEach(function () {
    $this->root = sys_get_temp_dir().'/laravel-media-directories-'.bin2hex(random_bytes(4));
});

it('creates directories under the root', function () {
    $directories = new TemporaryDirectories($this->root);

    $directory = $directories->create();

    expect($directory->path())->toStartWith($this->root.'/')->toBeDirectory();
});

it('creates cache directories under the cache root, or the root when none is set', function () {
    $withCache = new TemporaryDirectories($this->root, cacheRoot: $this->root.'/cache');
    $withoutCache = new TemporaryDirectories($this->root);

    expect($withCache->createCache()->path())->toStartWith($this->root.'/cache/')
        ->and(dirname($withoutCache->createCache()->path()))->toBe($this->root);
});

it('fails before creating a directory when there is not enough free space', function () {
    $directories = new TemporaryDirectories($this->root, minFreeBytes: PHP_INT_MAX);

    $directories->create();
})->throws(InsufficientStorageException::class, 'Insufficient storage space');

it('requires room for the expected size times the multiplier', function () {
    $free = (int) disk_free_space(sys_get_temp_dir());
    $directories = new TemporaryDirectories($this->root, sizeMultiplier: 2.0);

    $directories->create(expectedBytes: intdiv($free, 2) + 1024 * 1024 * 1024);
})->throws(InsufficientStorageException::class);

it('deletes a single directory or all of them', function () {
    $directories = new TemporaryDirectories($this->root);
    $first = $directories->create();
    $second = $directories->create();
    $third = $directories->create();

    $first->delete();

    expect($first->path())->not->toBeDirectory()
        ->and($second->path())->toBeDirectory();

    $directories->deleteAll();

    expect($second->path())->not->toBeDirectory()
        ->and($third->path())->not->toBeDirectory();
});
