<?php

declare(strict_types=1);

use Foxws\Media\Exceptions\MediaNotFoundException;
use Foxws\Media\Filesystem\Disk;
use Foxws\Media\Filesystem\Media;
use Foxws\Media\Filesystem\TemporaryDirectories;
use Foxws\Media\MediaConfig;
use Illuminate\Support\Facades\Storage;

it('reads media on a local disk from its own path', function () {
    Storage::fake('videos')->put('movies/video.mp4', 'video');

    $media = new Media(Disk::make('videos'), 'movies/video.mp4', app(TemporaryDirectories::class), app(MediaConfig::class));

    expect($media->inputPath())->toBe(diskPath('videos', 'movies/video.mp4'))
        ->and($media->localPath())->toBe(diskPath('videos', 'movies/video.mp4'));
});

it('reads remote media through a temporary url instead of downloading it', function () {
    $root = Storage::fake('remote-root')->path('');
    file_put_contents("{$root}/video.mp4", 'video');

    $media = new Media(Disk::make(remoteDisk($root)), 'video.mp4', app(TemporaryDirectories::class), app(MediaConfig::class));

    expect($media->inputPath())->toBe('https://remote.test/video.mp4?signature=abc');
});

it('downloads remote media to a temporary directory when remote inputs are disabled', function () {
    mediaConfig(['media.remote_inputs.enabled' => false]);
    $root = Storage::fake('remote-root')->path('');
    file_put_contents("{$root}/video.mp4", 'video');
    $media = new Media(Disk::make(remoteDisk($root)), 'video.mp4', app(TemporaryDirectories::class), app(MediaConfig::class));

    $input = $media->inputPath();

    expect($input)->toStartWith(str_replace('\\', '/', config('media.temporary_files.root')))
        ->and(file_get_contents($input))->toBe('video');
});

it('downloads remote media only once', function () {
    $root = Storage::fake('remote-root')->path('');
    file_put_contents("{$root}/video.mp4", 'video');
    $media = new Media(Disk::make(remoteDisk($root)), 'video.mp4', app(TemporaryDirectories::class), app(MediaConfig::class));
    $first = $media->localPath();
    file_put_contents("{$root}/video.mp4", 'changed');

    $second = $media->localPath();

    expect($second)->toBe($first)
        ->and(file_get_contents($second))->toBe('video');
});

it('deletes the downloaded copy on cleanup', function () {
    $root = Storage::fake('remote-root')->path('');
    file_put_contents("{$root}/video.mp4", 'video');
    $media = new Media(Disk::make(remoteDisk($root)), 'video.mp4', app(TemporaryDirectories::class), app(MediaConfig::class));
    $path = $media->localPath();

    $media->cleanup();

    expect(file_exists($path))->toBeFalse();
});

it('fails when remote media does not exist', function () {
    $media = new Media(Disk::make(remoteDisk(Storage::fake('remote-root')->path(''))), 'missing.mp4', app(TemporaryDirectories::class), app(MediaConfig::class));

    $media->localPath();
})->throws(MediaNotFoundException::class);
