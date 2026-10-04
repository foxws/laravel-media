<?php

declare(strict_types=1);

use Foxws\Media\Encoding\Format;
use Foxws\Media\Exceptions\ProcessFailedException;
use Foxws\Media\Executables\Executable;
use Foxws\Media\Facades\Media;
use Foxws\Media\FFMpeg\Output;
use Foxws\Media\FFMpeg\Scene;
use Foxws\Media\Process\Events\ProcessFailed;
use Foxws\Media\Process\Progress;
use Foxws\Media\Testing\FakeProbe;
use Foxws\Media\Testing\MediaFake;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\ExpectationFailedException;

it('probes with fake data keyed by the end of the path, or a default video', function () {
    Media::fake(['movies/song.mp3' => FakeProbe::audio(duration: 200)]);
    Storage::fake('media');

    $song = Media::fromDisk('media')->open('movies/song.mp3')->probe();
    $video = Media::fromDisk('media')->open('other.mp4')->probe();

    expect($song->hasVideo())->toBeFalse()
        ->and($song->duration())->toBe(200.0)
        ->and($video->videoStream()?->height)->toBe(1080)
        ->and($video->duration())->toBe(60.0);
    Media::assertProbed('movies/song.mp3');
    Media::assertProbed('other.mp4');
});

it('saves placeholder files to the target disk without running anything', function () {
    Media::fake();
    Storage::fake('media');
    Storage::fake('clips');
    Process::fake();

    $result = Media::fromDisk('media')->open('video.mp4')->ffmpeg()
        ->clip(from: 2, to: 6)
        ->inFormat(Format::h264())
        ->toDisk('clips')
        ->save('intro/clip.mp4');

    expect($result->paths())->toBe(['intro/clip.mp4']);
    Media::assertSaved('intro/clip.mp4', 'clips');
    Media::assertRan(Executable::FFMpeg, fn (array $arguments) => in_array('libx264', $arguments, true));
    Media::assertRanTimes(Executable::FFMpeg, 1);
    Process::assertNothingRan();
});

it('writes every output of one run', function () {
    Media::fake(['movie.mkv' => FakeProbe::video(subtitles: ['eng', 'nld'])]);
    Storage::fake('media');
    $media = Media::fromDisk('media')->open('movie.mkv');
    $builder = $media->ffmpeg();

    foreach ($media->probe()->subtitleStreams() as $stream) {
        $builder->addOutput("captions/{$stream->language}.vtt", fn (Output $output) => $output->map("0:{$stream->index}")->inFormat(Format::webVtt()));
    }

    $builder->save();

    Media::assertSaved('captions/eng.vtt', 'media');
    Media::assertSaved('captions/nld.vtt', 'media');
});

it('creates thumbnail sprites and their webvtt file', function () {
    Media::fake();
    Storage::fake('media');

    $result = Media::fromDisk('media')->open('video.mp4')->thumbnails()->every(10)->save('storyboard');

    expect($result->sprites)->toBe(['storyboard_001.jpg']);
    Media::assertSaved('storyboard.vtt', 'media');
});

it('fakes scene changes', function () {
    Media::fake(['video.mp4' => FakeProbe::video(duration: 30)])->scenes('video.mp4', [10.0, 20.0]);
    Storage::fake('media');

    expect(Media::fromDisk('media')->open('video.mp4')->scenes())->toEqual([
        new Scene(0.0, 10.0, null),
        new Scene(10.0, 20.0, 0.5),
        new Scene(20.0, 30.0, 0.5),
    ]);
});

it('reports progress halfway and at the end', function () {
    Media::fake(['video.mp4' => FakeProbe::video(duration: 40)]);
    Storage::fake('media');
    $percentages = [];

    Media::fromDisk('media')->open('video.mp4')->ffmpeg()
        ->onProgress(function (Progress $progress) use (&$percentages) {
            $percentages[] = $progress->percentage();
        })
        ->save('out.mp4');

    expect($percentages)->toBe([50.0, 100.0]);
});

it('fails the next run when asked, with the real failure handling', function () {
    Media::fake()->failNext(Executable::FFMpeg, 'Invalid data found when processing input');
    Storage::fake('media');
    Event::fake([ProcessFailed::class]);
    $builder = fn () => Media::fromDisk('media')->open('broken.mp4')->ffmpeg();

    expect(fn () => $builder()->save('out.mp4'))->toThrow(ProcessFailedException::class, 'Invalid data found when processing input');

    $builder()->save('out.mp4');

    Event::assertDispatched(ProcessFailed::class);
    Media::assertSaved('out.mp4', 'media');
});

it('asserts on the default media disk and on runs that did not happen', function () {
    Media::fake();
    Storage::fake('local');
    config(['media.disk' => 'local']);

    Media::open('video.mp4')->ffmpeg()->frame(at: 1)->save('thumb.jpg');

    Media::assertSaved('thumb.jpg');
    Media::assertNotSaved('other.jpg');
    Media::assertNotRan(Executable::Packager);
    Media::assertNotRan(Executable::FFMpeg, fn (array $arguments) => in_array('-pass', $arguments, true));
});

it('fails assertions with a clear message', function () {
    Media::fake();

    expect(fn () => Media::assertRan(Executable::FFMpeg))->toThrow(ExpectationFailedException::class, 'The expected [ffmpeg] command was not run.')
        ->and(fn () => Media::assertProbed('video.mp4'))->toThrow(ExpectationFailedException::class, 'The media [video.mp4] was not probed.');

    Media::assertNothingRan();
});

it('returns the fake, which records the commands', function () {
    $fake = Media::fake();
    Storage::fake('media');

    Media::fromDisk('media')->open('video.mp4')->probe();

    expect($fake)->toBeInstanceOf(MediaFake::class)
        ->and($fake->commands(Executable::FFProbe))->toHaveCount(1)
        ->and($fake->commands(Executable::FFMpeg))->toBe([]);
});
