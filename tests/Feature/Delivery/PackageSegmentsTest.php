<?php

declare(strict_types=1);

use Foxws\Media\Delivery\PackageSegments;
use Foxws\Media\Delivery\Track;
use Foxws\Media\Executables\Executable;
use Foxws\Media\Facades\Media;
use Foxws\Media\MediaFactory;
use Foxws\Media\Testing\FakeProbe;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('videos');
    Storage::fake('segments');
    Storage::disk('videos')->put('video.mp4', 'video');
});

it('packages the listed segments onto the cache disk', function () {
    Media::fake(['video.mp4' => FakeProbe::video(duration: 13)]);

    new PackageSegments('videos', 'video.mp4', [1, 2], Track::Video, 6.0, 'segments')->handle(app(MediaFactory::class));

    $stream = Media::fromDisk('videos')->open('video.mp4')->stream()->toCache('segments');

    expect(Storage::disk('segments')->exists($stream->segment(0, 1, Track::Video)))->toBeTrue()
        ->and(Storage::disk('segments')->exists($stream->segment(0, 2, Track::Video)))->toBeTrue();
    Media::assertRanTimes(Executable::FFMpeg, 2);
});

it('skips segments that were cached in the meantime', function () {
    Media::fake(['video.mp4' => FakeProbe::video(duration: 13)]);
    Media::fromDisk('videos')->open('video.mp4')->stream()->toCache('segments')->lookAhead(0)->segment(0, 1);

    new PackageSegments('videos', 'video.mp4', [1], null, 6.0, 'segments')->handle(app(MediaFactory::class));

    Media::assertRanTimes(Executable::FFMpeg, 1);
});

it('is unique per file, segments, track, duration and cache disk', function () {
    $job = new PackageSegments('videos', 'video.mp4', [1, 2], Track::Video, 6.0, 'segments');

    expect($job->uniqueId())->toBe(new PackageSegments('videos', 'video.mp4', [1, 2], Track::Video, 6.0, 'segments')->uniqueId())
        ->not->toBe(new PackageSegments('videos', 'video.mp4', [2, 3], Track::Video, 6.0, 'segments')->uniqueId())
        ->not->toBe(new PackageSegments('videos', 'video.mp4', [1, 2], Track::Audio, 6.0, 'segments')->uniqueId())
        ->not->toBe(new PackageSegments('videos', 'video.mp4', [1, 2], null, 6.0, 'segments')->uniqueId())
        ->not->toBe(new PackageSegments('videos', 'video.mp4', [1, 2], Track::Audio, 6.0, 'segments', 1)->uniqueId());
});

it('packages the segments of a later audio stream', function () {
    Media::fake(['video.mp4' => FakeProbe::video(duration: 13, audioLanguages: ['eng', 'jpn'])]);

    new PackageSegments('videos', 'video.mp4', [1], Track::Audio, 6.0, 'segments', 1)->handle(app(MediaFactory::class));

    expect(Storage::disk('segments')->allFiles())->toContain(Media::fromDisk('videos')->open('video.mp4')->stream()->toCache('segments')->segment(0, 1, Track::Audio, 1));
    Media::assertRanTimes(Executable::FFMpeg, 1);
});
