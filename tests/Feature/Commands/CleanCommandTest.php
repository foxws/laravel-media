<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;

beforeEach(function () {
    $this->root = sys_get_temp_dir().'/laravel-media-clean-'.bin2hex(random_bytes(4));
    $this->cache = $this->root.'-cache';

    config(['media.temporary_files.root' => $this->root, 'media.temporary_files.cache_root' => $this->cache, 'media.timeout' => 3600]);
});

afterEach(function () {
    new Filesystem()->deleteDirectory($this->root);
    new Filesystem()->deleteDirectory($this->cache);
});

/**
 * A directory with a file, both last modified the given number of minutes ago.
 */
function temporaryDirectoryAged(string $path, int $minutes): string
{
    mkdir($path, 0777, true);
    file_put_contents("{$path}/output.mp4", 'output');
    touch("{$path}/output.mp4", time() - $minutes * 60);
    touch($path, time() - $minutes * 60);

    return $path;
}

it('deletes temporary directories older than the media timeout plus an hour', function () {
    $stale = temporaryDirectoryAged("{$this->root}/0123456789abcdef", minutes: 121);
    $recent = temporaryDirectoryAged("{$this->root}/fedcba9876543210", minutes: 30);
    $staleCache = temporaryDirectoryAged("{$this->cache}/aaaaaaaaaaaaaaaa", minutes: 500);

    $this->artisan('media:clean')
        ->expectsOutputToContain('Deleted 2 temporary directories older than 120 minutes.')
        ->assertSuccessful();

    expect($stale)->not->toBeDirectory()
        ->and($staleCache)->not->toBeDirectory()
        ->and($recent)->toBeDirectory();
});

it('keeps directories with recently written files', function () {
    $active = temporaryDirectoryAged("{$this->root}/0123456789abcdef", minutes: 300);
    touch("{$active}/output.mp4");

    $this->artisan('media:clean', ['--older-than' => 60])->assertSuccessful();

    expect($active)->toBeDirectory();
});

it('leaves directories it did not create alone', function () {
    $other = temporaryDirectoryAged("{$this->cache}/some-other-app", minutes: 9000);

    $this->artisan('media:clean', ['--older-than' => 0])->assertSuccessful();

    expect($other)->toBeDirectory();
});

it('only lists what it would delete in a dry run', function () {
    $stale = temporaryDirectoryAged("{$this->root}/0123456789abcdef", minutes: 300);

    $this->artisan('media:clean', ['--dry-run' => true])
        ->expectsOutputToContain("Would delete {$stale}")
        ->expectsOutputToContain('Found 1 temporary directory older than 120 minutes.')
        ->assertSuccessful();

    expect($stale)->toBeDirectory();
});
