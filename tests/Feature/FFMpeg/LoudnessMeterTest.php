<?php

declare(strict_types=1);

use Foxws\Media\Executables\Executable;
use Foxws\Media\Facades\Media;
use Foxws\Media\Filters\LoudnessMeasurement;
use Foxws\Media\Filters\Loudnorm;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

it('measures an audio stream with the first pass of loudnorm', function () {
    fakeExecutable(Executable::FFMpeg);
    Process::fake(['*' => Process::result(errorOutput: '{"input_i" : "-27.20", "input_tp" : "-14.30", "input_lra" : "6.10", "input_thresh" : "-37.60", "target_offset" : "0.30"}')]);
    Storage::fake('videos');

    $measurement = Media::fromDisk('videos')->open('talk.mp4')->loudness(audioStream: 1);

    expect($measurement)->toEqual(new LoudnessMeasurement(-27.2, -14.3, 6.1, -37.6, 0.3));
    Process::assertRan(fn ($process) => runs($process, Executable::FFMpeg)
        && array_slice($process->command, 1, 5) === ['-hide_banner', '-nostdin', '-nostats', '-loglevel', 'info']
        && array_slice($process->command, -10) === ['-map', '0:a:1', '-vn', '-sn', '-dn', '-af', 'loudnorm=print_format=json', '-f', 'null', '-']);
});

it('measures once per audio stream', function () {
    Media::fake();
    Storage::fake('videos');
    $opener = Media::fromDisk('videos')->open('talk.mp4');

    $opener->loudness();
    $opener->loudness();
    $opener->loudness(1);

    Media::assertRanTimes(Executable::FFMpeg, 2);
});

it('fakes the measurement, and normalises with it in a second pass', function () {
    Media::fake()->loudness('talk.mp4', integrated: -30.0, truePeak: -12.0, range: 5.0, threshold: -40.0);
    Storage::fake('videos');
    $opener = Media::fromDisk('videos')->open('talk.mp4');

    $opener->ffmpeg()->addFilter(new Loudnorm()->withMeasurement($opener->loudness()))->save('normalised.mp4');

    Media::assertRan(Executable::FFMpeg, fn (array $arguments) => in_array(
        'loudnorm=I=-16:TP=-1.5:LRA=11:measured_I=-30:measured_TP=-12:measured_LRA=5:measured_thresh=-40:offset=0:linear=true,aresample=48000',
        $arguments,
        true,
    ));
    Media::assertSaved('normalised.mp4', 'videos');
});

it('fakes the same measurement for any media by default', function () {
    Media::fake();
    Storage::fake('videos');

    expect(Media::fromDisk('videos')->open('talk.mp4')->loudness())->toEqual(new LoudnessMeasurement(-23.0, -4.0, 7.0, -33.5, 0.0));
});
