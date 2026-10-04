<?php

declare(strict_types=1);

use Foxws\Media\Delivery\Segment;
use Foxws\Media\Exceptions\InvalidMediaException;
use Foxws\Media\Exceptions\SegmentNotFoundException;
use Foxws\Media\Executables\Executable;
use Foxws\Media\Facades\Media;
use Foxws\Media\Testing\FakeProbe;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\RedirectResponse;

beforeEach(function () {
    Storage::fake('videos');
    Storage::fake('segments');
    config(['media.delivery.cache_disk' => 'segments']);
});

it('lists every opened file as a variant of the master playlist', function () {
    Media::fake([
        '1080.mp4' => FakeProbe::video(width: 1920, height: 1080),
        '720.mp4' => FakeProbe::video(width: 1280, height: 720, frameRate: 25),
    ]);

    $playlist = Media::fromDisk('videos')->open(['1080.mp4', '720.mp4'])->stream()
        ->masterPlaylist(fn (int $variant) => "https://app.test/variants/{$variant}.m3u8");

    expect($playlist)->toBe(implode("\n", [
        '#EXTM3U',
        '#EXT-X-VERSION:3',
        '#EXT-X-INDEPENDENT-SEGMENTS',
        '#EXT-X-STREAM-INF:BANDWIDTH=4950000,RESOLUTION=1920x1080,FRAME-RATE=30.000,CODECS="avc1.640028,mp4a.40.2"',
        'https://app.test/variants/0.m3u8',
        '#EXT-X-STREAM-INF:BANDWIDTH=4950000,RESOLUTION=1280x720,FRAME-RATE=25.000,CODECS="avc1.640028,mp4a.40.2"',
        'https://app.test/variants/1.m3u8',
        '',
    ]));
});

it('lists the keyframe segments of a variant in its media playlist', function () {
    Media::fake(['video.mp4' => FakeProbe::video(duration: 13)]);

    $playlist = Media::fromDisk('videos')->open('video.mp4')->stream()
        ->mediaPlaylist(0, fn (Segment $segment, int $variant) => "https://app.test/segments/{$variant}/{$segment->index}.ts");

    expect($playlist)->toBe(implode("\n", [
        '#EXTM3U',
        '#EXT-X-VERSION:3',
        '#EXT-X-TARGETDURATION:6',
        '#EXT-X-MEDIA-SEQUENCE:0',
        '#EXT-X-PLAYLIST-TYPE:VOD',
        '#EXTINF:6.000000,',
        'https://app.test/segments/0/0.ts',
        '#EXTINF:6.000000,',
        'https://app.test/segments/0/1.ts',
        '#EXTINF:1.000000,',
        'https://app.test/segments/0/2.ts',
        '#EXT-X-ENDLIST',
        '',
    ]));
});

it('copies a segment into mpeg-ts on its first request and caches it per file version', function () {
    Media::fake(['video.mp4' => FakeProbe::video(duration: 13)]);
    Storage::disk('videos')->put('video.mp4', 'video');
    $stream = Media::fromDisk('videos')->open('video.mp4')->stream();

    $path = $stream->segment(0, 1);
    $again = $stream->segment(0, 1);

    expect($again)->toBe($path)
        ->and($path)->toMatch('#^media-segments/[0-9a-f]{32}/6/1\.ts$#');
    Storage::disk('segments')->assertExists($path);
    Media::assertRanTimes(Executable::FFMpeg, 1);
    Media::assertRan(Executable::FFMpeg, fn (array $arguments) => array_slice($arguments, 5, 6) === ['-ss', '6', '-t', '6', '-copyts', '-i']
        && array_slice($arguments, 12, -1) === ['-map', '0:v:0?', '-map', '0:a:0?', '-c', 'copy', '-muxdelay', '0', '-muxpreload', '0', '-f', 'mpegts']);
});

it('caches segments of a changed file separately', function () {
    Media::fake(['video.mp4' => FakeProbe::video(duration: 13)]);
    Storage::disk('videos')->put('video.mp4', 'video');
    $first = Media::fromDisk('videos')->open('video.mp4')->stream()->segment(0, 0);

    Storage::disk('videos')->put('video.mp4', 'a new version');

    expect(Media::fromDisk('videos')->open('video.mp4')->stream()->segment(0, 0))->not->toBe($first);
});

it('serves a segment from a local cache disk', function () {
    Media::fake(['video.mp4' => FakeProbe::video(duration: 13)]);

    $response = Media::fromDisk('videos')->open('video.mp4')->stream()->segmentResponse(0, 0);

    expect($response->headers->get('Content-Type'))->toBe('video/mp2t')
        ->and($response->headers->get('Cache-Control'))->toContain('max-age=3600');
});

it('redirects to a temporary url of a remote cache disk', function () {
    Media::fake(['video.mp4' => FakeProbe::video(duration: 13)]);
    $remote = remoteDisk(Storage::fake('remote-segments')->path(''));

    $response = Media::fromDisk('videos')->open('video.mp4')->stream()->toCache($remote)->segmentResponse(0, 2);

    expect($response)->toBeInstanceOf(RedirectResponse::class)
        ->and($response->headers->get('Location'))->toStartWith('https://remote.test/media-segments/')->toContain('/2.ts');
});

it('uses its own segment duration', function () {
    Media::fake(['video.mp4' => FakeProbe::video(duration: 13)]);

    $segments = Media::fromDisk('videos')->open('video.mp4')->stream()->segmentDuration(4)->segments(0);

    expect(array_map(fn (Segment $segment) => $segment->start, $segments))->toBe([0.0, 4.0, 8.0, 12.0]);
});

it('throws a 404 for segments and variants that do not exist', function (int $variant, int $segment) {
    Media::fake(['video.mp4' => FakeProbe::video(duration: 13)]);

    Media::fromDisk('videos')->open('video.mp4')->stream()->segment($variant, $segment);
})->throws(SegmentNotFoundException::class)->with([
    'segment' => [0, 9],
    'variant' => [3, 0],
]);

it('refuses codecs mpeg-ts segments cannot carry', function () {
    Media::fake(['video.webm' => FakeProbe::video(codec: 'vp9')]);

    Media::fromDisk('videos')->open('video.webm')->stream()->segment(0, 0);
})->throws(InvalidMediaException::class, "video.webm can't be streamed as HLS with MPEG-TS segments without re-encoding: [vp9] isn't supported.");
