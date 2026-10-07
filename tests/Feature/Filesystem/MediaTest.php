<?php

declare(strict_types=1);

use Foxws\Media\Exceptions\MediaNotFoundException;
use Foxws\Media\Filesystem\Disk;
use Foxws\Media\Filesystem\Media;
use Foxws\Media\Filesystem\TemporaryDirectories;
use Illuminate\Support\Facades\Storage;

it('reads media on a local disk from its own path', function () {
    Storage::fake('videos')->put('movies/video.mp4', 'video');

    $media = new Media(Disk::make('videos'), 'movies/video.mp4', app(TemporaryDirectories::class));

    expect($media->inputPath())->toBe(diskPath('videos', 'movies/video.mp4'))
        ->and($media->localPath())->toBe(diskPath('videos', 'movies/video.mp4'));
});

it('reads remote media through a temporary url instead of downloading it', function () {
    $root = Storage::fake('remote-root')->path('');
    file_put_contents("{$root}/video.mp4", 'video');

    $media = new Media(Disk::make(remoteDisk($root)), 'video.mp4', app(TemporaryDirectories::class));

    expect($media->inputPath())->toBe('https://remote.test/video.mp4?signature=abc');
});

it('passes no tls options for remote media by default', function () {
    $media = new Media(Disk::make(remoteDisk(Storage::fake('remote-root')->path(''))), 'video.mp4', app(TemporaryDirectories::class));

    expect($media->inputOptions())->toBe([])
        ->and($media->inputArguments())->toBe([]);
});

it('skips tls verification of remote media when disabled', function () {
    config(['media.remote_inputs.verify_tls' => false, 'media.remote_inputs.ca_file' => '/certs/ca.pem']);
    $media = new Media(Disk::make(remoteDisk(Storage::fake('remote-root')->path(''))), 'video.mp4', app(TemporaryDirectories::class));

    expect($media->inputOptions())->toBe(['tls_verify' => '0'])
        ->and($media->inputArguments())->toBe(['-tls_verify', '0']);
});

it('trusts a ca file for remote media', function () {
    config(['media.remote_inputs.ca_file' => '/certs/ca.pem']);
    $media = new Media(Disk::make(remoteDisk(Storage::fake('remote-root')->path(''))), 'video.mp4', app(TemporaryDirectories::class));

    expect($media->inputArguments())->toBe(['-ca_file', '/certs/ca.pem']);
});

it('passes no tls options for media read from a local path', function (bool $remote) {
    config(['media.remote_inputs.enabled' => false, 'media.remote_inputs.verify_tls' => false]);
    $root = Storage::fake('remote-root')->path('');
    file_put_contents("{$root}/video.mp4", 'video');
    $disk = $remote ? remoteDisk($root) : Storage::disk('remote-root');

    expect((new Media(Disk::make($disk), 'video.mp4', app(TemporaryDirectories::class)))->inputArguments())->toBe([]);
})->with(['local disk' => false, 'downloaded remote disk' => true]);

it('downloads remote media to a temporary directory when remote inputs are disabled', function () {
    config(['media.remote_inputs.enabled' => false]);
    $root = Storage::fake('remote-root')->path('');
    file_put_contents("{$root}/video.mp4", 'video');
    $media = new Media(Disk::make(remoteDisk($root)), 'video.mp4', app(TemporaryDirectories::class));

    $input = $media->inputPath();

    expect($input)->toStartWith(str_replace('\\', '/', config('media.temporary_files.root')))
        ->and(file_get_contents($input))->toBe('video');
});

it('downloads remote media only once', function () {
    $root = Storage::fake('remote-root')->path('');
    file_put_contents("{$root}/video.mp4", 'video');
    $media = new Media(Disk::make(remoteDisk($root)), 'video.mp4', app(TemporaryDirectories::class));
    $first = $media->localPath();
    file_put_contents("{$root}/video.mp4", 'changed');

    $second = $media->localPath();

    expect($second)->toBe($first)
        ->and(file_get_contents($second))->toBe('video');
});

it('deletes the downloaded copy on cleanup', function () {
    $root = Storage::fake('remote-root')->path('');
    file_put_contents("{$root}/video.mp4", 'video');
    $media = new Media(Disk::make(remoteDisk($root)), 'video.mp4', app(TemporaryDirectories::class));
    $path = $media->localPath();

    $media->cleanup();

    expect(file_exists($path))->toBeFalse();
});

it('fails when remote media does not exist', function () {
    $media = new Media(Disk::make(remoteDisk(Storage::fake('remote-root')->path(''))), 'missing.mp4', app(TemporaryDirectories::class));

    $media->localPath();
})->throws(MediaNotFoundException::class);

it('has a version key that changes with the file', function () {
    Storage::fake('videos')->put('video.mp4', 'one');
    $media = new Media(Disk::make('videos'), 'video.mp4', app(TemporaryDirectories::class));
    $first = $media->versionKey();

    Storage::disk('videos')->put('video.mp4', 'version two');

    expect($first)->toMatch('/^[0-9a-f]{32}$/')
        ->and($media->versionKey())->not->toBe($first)
        ->and(new Media(Disk::make('videos'), 'other.mp4', app(TemporaryDirectories::class))->versionKey())->not->toBe($first);
});
