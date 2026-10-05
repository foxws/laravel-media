<?php

declare(strict_types=1);

use Foxws\Media\Delivery\KeyframeIndex;
use Foxws\Media\Encoding\HardwareAcceleration;
use Foxws\Media\Encoding\Ladder;
use Foxws\Media\Exceptions\InvalidMediaException;
use Foxws\Media\Exceptions\MediaNotFoundException;
use Foxws\Media\Executables\Executable;
use Foxws\Media\Facades\Media;
use Foxws\Media\MediaFactory;
use Foxws\Media\Opener;
use Foxws\Media\Testing\FakeProbe;
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

it('remembers probes across openers per file version after rememberProbes', function () {
    fakeExecutable(Executable::FFProbe);
    Storage::fake('videos');
    Storage::disk('videos')->put('a.mp4', 'video');
    Process::fake(['*' => Process::result(output: file_get_contents(fixture('ffprobe.json')))]);

    Media::fromDisk('videos')->open('a.mp4')->rememberProbes()->probe();
    $probe = Media::fromDisk('videos')->open('a.mp4')->rememberProbes()->probe();

    expect($probe->duration())->toBe(120.12);
    Process::assertRanTimes(fn ($process) => true, 1);

    Storage::disk('videos')->put('a.mp4', 'a new version');
    Media::fromDisk('videos')->open('a.mp4')->rememberProbes()->probe();

    Process::assertRanTimes(fn ($process) => true, 2);
});

it('probes again for every opener without rememberProbes', function () {
    fakeExecutable(Executable::FFProbe);
    Storage::fake('videos');
    Process::fake(['*' => Process::result(output: file_get_contents(fixture('ffprobe.json')))]);

    Media::fromDisk('videos')->open('a.mp4')->probe();
    Media::fromDisk('videos')->open('a.mp4')->probe();

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
    config(['media.disk' => 'archive']);

    expect(Media::open('a.mp4')->disk()->name())->toBe('archive');
});

it('fails when probing before any media is opened', function () {
    Storage::fake('videos');

    Media::fromDisk('videos')->probe();
})->throws(MediaNotFoundException::class, 'No media has been opened.');

it('takes macros, so other packages can add their own tools', function () {
    Media::fake();
    Opener::macro('pathCount', fn () => count($this->paths()));
    MediaFactory::macro('openTwo', fn (string $disk) => $this->fromDisk($disk)->open(['a.mp4', 'b.mp4']));

    expect(Media::openTwo('local')->pathCount())->toBe(2);
});

it('encodes a ladder in one ffmpeg run with an output per rendition that fits', function () {
    Media::fake(['video.mp4' => FakeProbe::video(width: 1280, height: 720)]);
    Storage::fake('renditions');

    $result = Media::fromDisk('renditions')->open('video.mp4')
        ->ladder(Ladder::standard()->hardware(HardwareAcceleration::Vaapi), 'videos/1/{height}p-{bitrate}.mp4')
        ->toDisk('renditions')
        ->save();

    expect($result->paths())->toBe(['videos/1/720p-2800.mp4', 'videos/1/480p-1400.mp4', 'videos/1/360p-800.mp4']);
    Media::assertRanTimes(Executable::FFMpeg, 1);
    Media::assertRan(Executable::FFMpeg, fn (array $arguments) => array_slice($arguments, 5, 7) === ['-hwaccel', 'vaapi', '-hwaccel_output_format', 'vaapi', '-vaapi_device', '/dev/dri/renderD128', '-i']
        && count(array_keys($arguments, '-map', true)) === 6
        && in_array('scale_vaapi=w=-2:h=480', $arguments, true)
        && in_array('h264_vaapi', $arguments, true));
});

it('aligns a ladder to the segments of the source, so every variant is split the same way', function () {
    Media::fake(['video.mp4' => FakeProbe::video(duration: 20)]);
    Storage::fake('renditions');

    $media = Media::fromDisk('renditions')->open('video.mp4');

    $media->ladder(Ladder::standard()->alignToSource(), 'videos/1/{height}p.mp4')->toDisk('renditions')->save();

    $forced = collect(Media::commands(Executable::FFMpeg)[0])->after(fn (string $argument) => $argument === '-force_key_frames');
    $rendition = new KeyframeIndex(array_map(fn (string $second): float => (float) $second + 0.001, explode(',', (string) $forced)), 20.0);

    expect($forced)->toBe('0,5.999,11.999,17.999')
        ->and($rendition->segments())->toEqual($media->keyframes()->segments());
});

it('checks whether browsers can play an opened file', function () {
    Media::fake(['old.avi' => FakeProbe::video(codec: 'mpeg4'), 'new.mp4' => FakeProbe::video()]);

    expect(Media::fromDisk('local')->open('old.avi')->playability()->isPlayable())->toBeFalse()
        ->and(Media::fromDisk('local')->open(['old.avi', 'new.mp4'])->playability('new.mp4')->isPlayable())->toBeTrue();
});

it('makes a file playable, keeping subtitles in containers that carry them', function () {
    Media::fake(['old.avi' => FakeProbe::video(codec: 'mpeg4', subtitles: ['eng'])]);
    Storage::fake('videos');

    Media::fromDisk('videos')->open('old.avi')->makePlayable('playable.mkv')->save();
    Media::fromDisk('videos')->open('old.avi')->makePlayable('playable.mp4')->save();

    Media::assertSaved('playable.mkv', 'videos');
    Media::assertSaved('playable.mp4', 'videos');

    [$mkv, $mp4] = Media::commands(Executable::FFMpeg);

    expect($mkv)->toContain('0:V:0?', '0:a?', '0:s?', 'libx264')
        ->and($mp4)->toContain('0:V:0?', '0:a?')
        ->and($mp4)->not->toContain('0:s?');
});

it('makes only the audio playable, to stream next to the untouched video', function () {
    Media::fake(['movie.mkv' => FakeProbe::video(audioLanguages: ['eng', 'jpn'])]);
    Storage::fake('videos');

    Media::fromDisk('videos')->open('movie.mkv')->makePlayable('movie-audio.m4a', audioOnly: true)->save();

    Media::assertSaved('movie-audio.m4a', 'videos');
    Media::assertRan(Executable::FFMpeg, fn (array $arguments) => in_array('0:a', $arguments, true)
        && in_array('-vn', $arguments, true)
        && ! in_array('0:V:0?', $arguments, true));
});

it('needs a full copy when the video does not play', function () {
    Media::fake(['old.avi' => FakeProbe::video(codec: 'mpeg4')]);

    Media::fromDisk('local')->open('old.avi')->makePlayable('old-audio.m4a', audioOnly: true);
})->throws(InvalidMediaException::class, "The video of old.avi doesn't play in browsers, so it needs a full playable copy.");

it('needs a video stream for a ladder', function () {
    Media::fake(['song.m4a' => FakeProbe::audio()]);

    Media::fromDisk('local')->open('song.m4a')->ladder(Ladder::standard());
})->throws(InvalidMediaException::class, 'song.m4a has no video stream.');
