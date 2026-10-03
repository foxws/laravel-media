<?php

declare(strict_types=1);

use Foxws\Media\Executables\Executable;
use Foxws\Media\Executables\Executables;
use Foxws\Media\Tests\Fixtures\RemoteAdapter;
use Foxws\Media\Tests\TestCase;
use Illuminate\Filesystem\FilesystemAdapter;
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
