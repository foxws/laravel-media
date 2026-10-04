<?php

declare(strict_types=1);

use Foxws\Media\Executables\Executable;
use Foxws\Media\Facades\Media;
use Illuminate\Cache\ArrayStore;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

function fakeKeyframes(string $packets, array $probe): void
{
    fakeExecutable(Executable::FFProbe);

    Process::fake(['*' => fn ($process) => in_array('packet=pts_time,flags', $process->command, true)
        ? Process::result(output: $packets)
        : Process::result(output: (string) json_encode($probe))]);
}

it('lists the video packets without decoding and caches the index per file version', function () {
    fakeKeyframes("0.000000,K__\n6.000000,K__\n", videoProbe(duration: 12));
    Storage::fake('videos');
    Storage::disk('videos')->put('video.mp4', 'v1');

    $first = Media::fromDisk('videos')->open('video.mp4')->keyframes();
    $second = Media::fromDisk('videos')->open('video.mp4')->keyframes();

    expect($first->keyframes)->toBe([0.0, 6.0])
        ->and($second->keyframes)->toBe([0.0, 6.0])
        ->and($first->segments(6))->toHaveCount(2);
    Process::assertRanTimes(fn ($process) => in_array('packet=pts_time,flags', $process->command, true), 1);
    Process::assertRan(fn ($process) => array_slice($process->command, 1, -1) === [
        '-v', 'error', '-select_streams', 'v:0', '-show_entries', 'packet=pts_time,flags', '-of', 'csv=print_section=0',
    ]);
});

it('indexes a changed file again', function () {
    fakeKeyframes("0.000000,K__\n", videoProbe(duration: 12));
    Storage::fake('videos');
    Storage::disk('videos')->put('video.mp4', 'v1');

    Media::fromDisk('videos')->open('video.mp4')->keyframes();
    Storage::disk('videos')->put('video.mp4', 'version two');
    Media::fromDisk('videos')->open('video.mp4')->keyframes();

    Process::assertRanTimes(fn ($process) => in_array('packet=pts_time,flags', $process->command, true), 2);
});

it('does not list packets of audio files', function () {
    fakeKeyframes('', ['streams' => [['index' => 0, 'codec_type' => 'audio', 'codec_name' => 'aac']], 'format' => ['duration' => '13']]);
    Storage::fake('videos');

    $index = Media::fromDisk('videos')->open('song.m4a')->keyframes();

    expect($index->keyframes)->toBe([])
        ->and($index->segments(6))->toHaveCount(3);
    Process::assertDidntRun(fn ($process) => in_array('packet=pts_time,flags', $process->command, true));
});

it('uses the configured cache store', function () {
    fakeKeyframes("0.000000,K__\n", videoProbe(duration: 12));
    config(['media.delivery.cache_store' => 'array']);
    Storage::fake('videos');
    Storage::disk('videos')->put('video.mp4', 'v1');

    Media::fromDisk('videos')->open('video.mp4')->keyframes();

    expect(Cache::store('array')->getStore())->toBeInstanceOf(ArrayStore::class);
    Process::assertRanTimes(fn ($process) => in_array('packet=pts_time,flags', $process->command, true), 1);
});
