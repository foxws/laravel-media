<?php

declare(strict_types=1);

use Foxws\Media\Encoding\Format;
use Foxws\Media\Exceptions\InvalidFilterException;
use Foxws\Media\Exceptions\InvalidMediaException;
use Foxws\Media\Facades\Media;
use Foxws\Media\FFMpeg\Clip;
use Foxws\Media\Filters\Fade;
use Foxws\Media\Filters\Loudnorm;
use Illuminate\Support\Facades\Storage;

it('joins clips of one file with accurate seeks on separate inputs', function () {
    fakeProbes(['video.mp4' => videoProbe()]);
    Storage::fake('videos');
    $path = Storage::disk('videos')->path('video.mp4');

    $arguments = Media::fromDisk('videos')->open('video.mp4')->ffmpeg()
        ->clips([Clip::make(5, 8), Clip::make(20, 24.5)])
        ->inFormat(Format::h264())
        ->arguments('reel.mp4');

    expect(array_slice($arguments, 5, 19))->toBe([
        '-ss', '5', '-t', '3', '-i', $path,
        '-ss', '20', '-t', '4.5', '-i', $path,
        '-filter_complex', '[0:v:0]setpts=PTS-STARTPTS[v0];[0:a:0]asetpts=PTS-STARTPTS[a0];[1:v:0]setpts=PTS-STARTPTS[v1];[1:a:0]asetpts=PTS-STARTPTS[a1];[v0][a0][v1][a1]concat=n=2:v=1:a=1[v][a]',
        '-map', '[v]', '-map', '[a]',
        '-c:v',
    ]);
});

it('fits clips from different files to the first file\'s size', function () {
    fakeProbes(['a.mp4' => videoProbe(1920, 1080), 'b.mp4' => videoProbe(1280, 720)]);
    Storage::fake('videos');

    $arguments = Media::fromDisk('videos')->open(['a.mp4', 'b.mp4'])->ffmpeg()
        ->clips([Clip::make(0, 2), Clip::make(0, 2, 'b.mp4')])
        ->arguments('reel.mp4');

    expect($arguments[array_search('-filter_complex', $arguments, true) + 1])
        ->toContain('[1:v:0]setpts=PTS-STARTPTS,scale=1920:1080:force_original_aspect_ratio=decrease,pad=1920:1080:(ow-iw)/2:(oh-ih)/2:color=black,setsar=1[v1]');
});

it('fits clips to a given size and frame rate, and filters the joined result', function () {
    fakeProbes(['video.mp4' => videoProbe()]);
    Storage::fake('videos');

    $arguments = Media::fromDisk('videos')->open('video.mp4')->ffmpeg()
        ->clips([Clip::make(0, 2)], width: 1080, height: 1920, fps: 30)
        ->addFilter(Fade::out(0.5, start: 1.5), new Loudnorm)
        ->arguments('reel.mp4');

    expect($arguments[array_search('-filter_complex', $arguments, true) + 1])->toBe(
        '[0:v:0]setpts=PTS-STARTPTS,scale=1080:1920:force_original_aspect_ratio=decrease,pad=1080:1920:(ow-iw)/2:(oh-ih)/2:color=black,setsar=1,fps=30[v0];'
        .'[0:a:0]asetpts=PTS-STARTPTS[a0];'
        .'[v0][a0]concat=n=1:v=1:a=1[joined][joineda];'
        .'[joined]fade=t=out:st=1.5:d=0.5[v];'
        .'[joineda]loudnorm=I=-16:TP=-1.5:LRA=11[a]',
    );
});

it('makes a silent reel when a file has no audio', function () {
    fakeProbes(['a.mp4' => videoProbe(), 'b.mp4' => videoProbe(audio: false)]);
    Storage::fake('videos');

    $arguments = Media::fromDisk('videos')->open(['a.mp4', 'b.mp4'])->ffmpeg()
        ->clips([Clip::make(0, 2), Clip::make(0, 2, 'b.mp4')])
        ->arguments('reel.mp4');

    $graph = $arguments[array_search('-filter_complex', $arguments, true) + 1];

    expect($graph)->not->toContain('[a0]')->toEndWith('[v0][v1]concat=n=2:v=1:a=0[v]')
        ->and(array_slice($arguments, array_search('-filter_complex', $arguments, true) + 2, 3))->toBe(['-map', '[v]', '-an']);
});

it('does not combine clips with options that need the original inputs', function (Closure $configure, string $conflict) {
    fakeProbes(['video.mp4' => videoProbe()]);
    Storage::fake('videos');

    $builder = $configure(Media::fromDisk('videos')->open('video.mp4')->ffmpeg()->clips([Clip::make(0, 2)]));

    expect(fn () => $builder->arguments('reel.mp4'))->toThrow(InvalidFilterException::class, "can't be combined with {$conflict}");
})->with([
    'maps' => [fn ($builder) => $builder->map('0:v'), 'map()'],
    'watermark' => [fn ($builder) => $builder->watermark('logo.png'), 'watermark()'],
    'outputs' => [fn ($builder) => $builder->addOutput('x.mp4'), 'addOutput()'],
    'clip' => [fn ($builder) => $builder->clip(1, 2), 'clip(), frame() or addInputArgs()'],
]);

it('needs at least one clip', function () {
    Storage::fake('videos');

    Media::fromDisk('videos')->open('video.mp4')->ffmpeg()->clips([]);
})->throws(InvalidMediaException::class, 'at least one clip');
