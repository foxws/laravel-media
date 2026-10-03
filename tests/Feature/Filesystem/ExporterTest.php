<?php

declare(strict_types=1);

use Foxws\Media\Exceptions\ExportFailedException;
use Foxws\Media\Filesystem\Disk;
use Foxws\Media\Filesystem\Exporter;
use Illuminate\Support\Facades\Storage;

it('uploads files to s3 under the disk root with their content type and acl', function () {
    $commands = [];
    $disk = Disk::make(recordingS3Disk($commands));
    $directory = directoryWith(['master.m3u8' => '#EXTM3U', 'video/init.mp4' => 'init']);

    $paths = Exporter::make()->export($directory, $disk, 'videos/1', 'public');

    expect($paths)->toBe(['videos/1/master.m3u8', 'videos/1/video/init.mp4'])
        ->and(collect($commands)->pluck('args')->map(fn (array $args) => [$args['Key'], $args['ContentType'], $args['ACL']])->sort()->values()->all())
        ->toBe([
            ['segments/videos/1/master.m3u8', 'application/vnd.apple.mpegurl', 'public-read'],
            ['segments/videos/1/video/init.mp4', 'video/mp4', 'public-read'],
        ]);
});

it('uses a multipart upload for files at or above the threshold', function () {
    config(['media.uploads.multipart_threshold' => 1024 * 1024, 'media.uploads.multipart_part_size' => 5 * 1024 * 1024]);
    $commands = [];
    $disk = Disk::make(recordingS3Disk($commands));
    $directory = directoryWith(['video.mp4' => str_repeat('v', 6 * 1024 * 1024), 'index.mpd' => '<MPD/>']);

    Exporter::make()->export($directory, $disk);

    expect(array_column($commands, 'name'))->toEqualCanonicalizing([
        'PutObject', 'CreateMultipartUpload', 'UploadPart', 'UploadPart', 'CompleteMultipartUpload',
    ])
        ->and(collect($commands)->firstWhere('name', 'CreateMultipartUpload')['args'])->toMatchArray([
            'Key' => 'segments/video.mp4',
            'ContentType' => 'video/mp4',
        ]);
});

it('aborts a failed multipart upload and reports the failure', function () {
    config(['media.uploads.multipart_threshold' => 1024 * 1024]);
    $commands = [];
    recordingS3Disk($commands, failOn: 'UploadPart');
    $disk = Disk::make('recording-s3');
    $directory = directoryWith(['video.mp4' => str_repeat('v', 2 * 1024 * 1024)]);

    expect(fn () => Exporter::make()->export($directory, $disk))
        ->toThrow(ExportFailedException::class, '1 file(s) failed to copy to disk [recording-s3]: video.mp4:');

    expect(collect($commands)->firstWhere('name', 'AbortMultipartUpload')['args']['UploadId'])->toBe('upload-1');
});

it('reports every file that failed to upload', function () {
    $disk = Disk::make(recordingS3Disk(failOn: 'PutObject'));
    $directory = directoryWith(['a.m4s' => 'a', 'b.m4s' => 'b']);

    try {
        Exporter::make()->export($directory, $disk);
    } catch (ExportFailedException $exception) {
        expect(collect($exception->failures)->pluck('target')->sort()->values()->all())->toBe(['a.m4s', 'b.m4s']);

        return;
    }

    $this->fail('No ExportFailedException was thrown.');
});

it('uploads encryption keys as octet-stream instead of a keynote document', function () {
    $commands = [];
    $disk = Disk::make(recordingS3Disk($commands));
    $directory = directoryWith(['key.key' => random_bytes(16)]);

    Exporter::make()->export($directory, $disk);

    expect($commands[0]['args']['ContentType'])->toBe('application/octet-stream');
});

it('moves files onto a local disk instead of copying them', function () {
    Storage::fake('videos');
    $directory = directoryWith(['clip.mp4' => 'clip']);

    $paths = Exporter::make()->export($directory, Disk::make('videos'), 'clips', move: true);

    expect($paths)->toBe(['clips/clip.mp4'])
        ->and(file_exists("{$directory}/clip.mp4"))->toBeFalse();
    expect(Storage::disk('videos')->get('clips/clip.mp4'))->toBe('clip');
});

it('copies files and leaves the source in place without move', function () {
    Storage::fake('videos');
    $directory = directoryWith(['clip.mp4' => 'clip']);

    Exporter::make()->export($directory, Disk::make('videos'));

    expect(file_exists("{$directory}/clip.mp4"))->toBeTrue();
    Storage::disk('videos')->assertExists('clip.mp4');
});

it('reads the bucket, key prefix and object options of an s3 disk', function () {
    config(['filesystems.disks.recording-s3.options' => ['CacheControl' => 'max-age=3600']]);
    $disk = Disk::make(recordingS3Disk());

    expect($disk->isS3())->toBeTrue()
        ->and(Disk::make(Storage::fake('fake-s3'))->isS3())->toBeFalse()
        ->and($disk->s3Bucket())->toBe('test-bucket')
        ->and($disk->prefixS3Path('video.mp4'))->toBe('segments/video.mp4');
});
