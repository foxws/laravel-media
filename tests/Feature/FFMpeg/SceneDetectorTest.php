<?php

declare(strict_types=1);

use Foxws\Media\Executables\Executable;
use Foxws\Media\Facades\Media;
use Foxws\Media\FFMpeg\Scene;
use Foxws\Media\FFMpeg\SceneDetector;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

$output = <<<'TXT'
    frame:0    pts:153153  pts_time:12.762750
    lavfi.scene_score=0.612000
    frame:1    pts:408408  pts_time:34.034000
    lavfi.scene_score=0.441000
    TXT;

it('splits the video into scenes at each change', function () use ($output) {
    fakeProbes(['video.mp4' => videoProbe(duration: 60)], $output);
    Storage::fake('videos');

    $scenes = Media::fromDisk('videos')->open('video.mp4')->scenes(threshold: 0.4);

    expect($scenes)->toEqual([
        new Scene(0.0, 12.76275, null),
        new Scene(12.76275, 34.034, 0.612),
        new Scene(34.034, 60.0, 0.441),
    ]);
});

it('scores downscaled frames and prints changes to stdout', function () use ($output) {
    fakeProbes(['video.mp4' => videoProbe()], $output);
    Storage::fake('videos');

    Media::fromDisk('videos')->open('video.mp4')->scenes(threshold: 0.4);

    Process::assertRan(fn ($process) => runs($process, Executable::FFMpeg)
        && array_slice($process->command, 7) === [
            '-map', '0:v:0',
            '-vf', "scale=320:-2,select='gt(scene,0.4)',metadata=print:file=-",
            '-an', '-f', 'null', '-',
        ]);
});

it('detects scenes once per threshold', function () use ($output) {
    fakeProbes(['video.mp4' => videoProbe()], $output);
    Storage::fake('videos');
    $opener = Media::fromDisk('videos')->open('video.mp4');

    $opener->scenes();
    $opener->scenes();
    $opener->scenes(0.5);

    Process::assertRanTimes(fn ($process) => runs($process, Executable::FFMpeg), 2);
});

it('returns a single scene when nothing changes', function () {
    fakeProbes(['video.mp4' => videoProbe(duration: 30)]);
    Storage::fake('videos');

    expect(Media::fromDisk('videos')->open('video.mp4')->scenes())->toEqual([new Scene(0.0, 30.0, null)]);
});

it('rejects a threshold outside 0 and 1', function () {
    Storage::fake('videos');
    $media = Media::fromDisk('videos')->open('video.mp4')->mediaFor();

    SceneDetector::make()->detect($media, 60, threshold: 1.5);
})->throws(InvalidArgumentException::class, 'between 0 and 1');
