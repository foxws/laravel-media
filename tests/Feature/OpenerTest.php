<?php

declare(strict_types=1);

use Foxws\Media\Exceptions\MediaNotFoundException;
use Foxws\Media\Executables\Executable;
use Foxws\Media\Facades\Media;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

it('probes the opened file with ffprobe', function () {
    fakeExecutable(Executable::FFProbe);
    Storage::fake('videos');
    Process::fake(['*' => Process::result(output: file_get_contents(fixture('ffprobe.json')))]);

    $probe = Media::fromDisk('videos')->open('movies/video.mp4')->probe();

    expect($probe->hasVideo())->toBeTrue()
        ->and($probe->duration())->toBe(120.12);
    Process::assertRan(fn ($process) => array_slice($process->command, 1) === [
        '-v', 'error', '-print_format', 'json', '-show_format', '-show_streams', '-show_chapters',
        diskPath('videos', 'movies/video.mp4'),
    ]);
});

it('probes each file once per opener', function () {
    fakeExecutable(Executable::FFProbe);
    Storage::fake('videos');
    Process::fake(['*' => Process::result(output: file_get_contents(fixture('ffprobe.json')))]);

    $opener = Media::fromDisk('videos')->open(['a.mp4', 'b.mp4']);
    $opener->probe('a.mp4');
    $opener->probe('a.mp4');
    $probes = $opener->probeAll();

    expect($probes)->toHaveKeys(['a.mp4', 'b.mp4']);
    Process::assertRanTimes(fn ($process) => true, 2);
});

it('exposes the source disk and opened paths', function () {
    Storage::fake('videos');

    $opener = Media::fromDisk('videos')->open('a.mp4', ['b.mp4', 'c.mp4']);

    expect($opener->disk()->name())->toBe('videos')
        ->and($opener->paths())->toBe(['a.mp4', 'b.mp4', 'c.mp4']);
});

it('opens media from the configured default disk', function () {
    Storage::fake('archive');
    mediaConfig(['media.disk' => 'archive']);

    expect(Media::open('a.mp4')->disk()->name())->toBe('archive');
});

it('fails when probing before any media is opened', function () {
    Storage::fake('videos');

    Media::fromDisk('videos')->probe();
})->throws(MediaNotFoundException::class, 'No media has been opened.');
