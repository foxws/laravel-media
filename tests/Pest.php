<?php

declare(strict_types=1);

use Aws\CommandInterface;
use Aws\Result;
use Aws\S3\Exception\S3Exception;
use Foxws\Media\Executables\Executable;
use Foxws\Media\Executables\Executables;
use Foxws\Media\Tests\Fixtures\RemoteAdapter;
use Foxws\Media\Tests\TestCase;
use GuzzleHttp\Promise\Create;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Filesystem as Flysystem;
use League\Flysystem\Local\LocalFilesystemAdapter;

uses(TestCase::class)->in(__DIR__);

/**
 * Point the executable's config at an executable file, so it resolves without being installed.
 */
function fakeExecutable(Executable $executable): string
{
    $directory = sys_get_temp_dir().'/laravel-media-executables-'.getmypid();

    if (! is_dir($directory)) {
        mkdir($directory, 0777, true);
    }

    $path = "{$directory}/{$executable->value}";

    file_put_contents($path, "#!/bin/sh\nexit 0\n");
    chmod($path, 0755);

    config(["media.executables.{$executable->value}" => $path]);

    app(Executables::class)->flush();

    return $path;
}

/**
 * A disk that isn't local (like S3), backed by a local directory, that signs temporary URLs.
 */
function remoteDisk(string $root): FilesystemAdapter
{
    $adapter = new RemoteAdapter(new LocalFilesystemAdapter($root));

    $disk = new FilesystemAdapter(new Flysystem($adapter), $adapter, ['root' => $root]);

    $disk->buildTemporaryUrlsUsing(fn (string $path): string => "https://remote.test/{$path}?signature=abc");

    return $disk;
}

/**
 * A real S3-driver disk whose requests are answered in-process and recorded,
 * because Storage::fake() always swaps in a local disk.
 *
 * @param  array<int, array{name: string, args: array<string, mixed>}>  $commands  Filled with each issued command.
 * @param  string|null  $failOn  Command name to reject, e.g. 'UploadPart'.
 */
function recordingS3Disk(array &$commands = [], ?string $failOn = null): Filesystem
{
    config(['filesystems.disks.recording-s3' => [
        'driver' => 's3',
        'key' => 'test-key',
        'secret' => 'test-secret',
        'region' => 'us-east-1',
        'bucket' => 'test-bucket',
        'root' => 'segments',
        'handler' => function (CommandInterface $command) use (&$commands, $failOn) {
            $commands[] = ['name' => $command->getName(), 'args' => $command->toArray()];

            if ($command->getName() === $failOn) {
                return Create::rejectionFor(new S3Exception("{$failOn} failed", $command));
            }

            return Create::promiseFor(new Result(match ($command->getName()) {
                'CreateMultipartUpload' => ['UploadId' => 'upload-1'],
                'UploadPart' => ['ETag' => '"etag"'],
                default => [],
            }));
        },
    ]]);

    Storage::forgetDisk('recording-s3');

    return Storage::disk('recording-s3');
}

/**
 * A temporary directory with the given files.
 *
 * @param  array<string, string>  $files
 */
function directoryWith(array $files): string
{
    $directory = sys_get_temp_dir().'/laravel-media-export-'.bin2hex(random_bytes(4));

    foreach ($files as $path => $contents) {
        @mkdir(dirname("{$directory}/{$path}"), 0777, true);
        file_put_contents("{$directory}/{$path}", $contents);
    }

    return $directory;
}
