<?php

declare(strict_types=1);

use Foxws\Media\Filesystem\Disk;
use Illuminate\Support\Facades\Storage;

it('reads the bucket and key prefix of an s3 disk', function () {
    recordingS3Disk();

    $disk = Disk::make('recording-s3');

    expect($disk->isS3())->toBeTrue()
        ->and($disk->isLocal())->toBeFalse()
        ->and($disk->s3Bucket())->toBe('test-bucket')
        ->and($disk->prefixS3Path('videos/1/master.m3u8'))->toBe('segments/videos/1/master.m3u8');
});

it('reads the default object options of an s3 disk', function () {
    recordingS3Disk();
    config(['filesystems.disks.recording-s3.options' => ['CacheControl' => 'max-age=3600']]);
    Storage::forgetDisk('recording-s3');

    expect(Disk::make('recording-s3')->s3UploadOptions())->toBe(['CacheControl' => 'max-age=3600']);
});

it('does not treat a faked disk as s3', function () {
    $disk = Disk::make(Storage::fake('s3'));

    expect($disk->isS3())->toBeFalse()
        ->and($disk->isLocal())->toBeTrue();
});

it('names a configured disk after its name and a runtime disk after its adapter', function () {
    Storage::fake('videos');

    expect(Disk::make('videos')->name())->toBe('videos')
        ->and(Disk::local(sys_get_temp_dir())->name())->toStartWith('League\Flysystem\Local\LocalFilesystemAdapter_');
});

it('returns full local paths with forward slashes', function () {
    $root = Storage::fake('videos')->path('');

    expect(Disk::make('videos')->path('movies/video.mp4'))->toBe(rtrim(str_replace('\\', '/', $root), '/').'/movies/video.mp4');
});

it('signs temporary urls when the disk supports them', function () {
    $disk = Disk::make(remoteDisk(Storage::fake('remote-root')->path('')));

    expect($disk->providesTemporaryUrls())->toBeTrue()
        ->and($disk->temporaryUrl('video.mp4', now()->addHour()))->toBe('https://remote.test/video.mp4?signature=abc');
});
