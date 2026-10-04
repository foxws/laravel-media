<?php

declare(strict_types=1);

use Foxws\Media\Delivery\Segment;
use Foxws\Media\Encryption\EncryptionKey;
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

it('adds the key to the media playlist, changing it every rotation period', function () {
    Media::fake(['video.mp4' => FakeProbe::video(duration: 13)]);

    $playlist = Media::fromDisk('videos')->open('video.mp4')->stream()
        ->segmentDuration(4)
        ->withEncryption(fn (int $period) => EncryptionKey::derive('secret', "video:1:{$period}"), fn (int $period, int $variant) => "https://app.test/keys/{$variant}/{$period}", rotateEvery: 2)
        ->mediaPlaylist(0, fn (Segment $segment) => "{$segment->index}.ts");

    expect(array_values(array_filter(explode("\n", $playlist), fn (string $line) => str_starts_with($line, '#EXT-X-KEY') || str_ends_with($line, '.ts'))))->toBe([
        '#EXT-X-KEY:METHOD=AES-128,URI="https://app.test/keys/0/0"',
        '0.ts',
        '1.ts',
        '#EXT-X-KEY:METHOD=AES-128,URI="https://app.test/keys/0/1"',
        '2.ts',
        '3.ts',
    ]);
});

it('encrypts each segment response with its period key and the sequence number as iv', function () {
    Media::fake(['video.mp4' => FakeProbe::video(duration: 13)]);
    $stream = Media::fromDisk('videos')->open('video.mp4')->stream()
        ->withEncryption(fn (int $period) => EncryptionKey::derive('secret', "video:{$period}"), fn (int $period) => "key/{$period}", rotateEvery: 2);
    $plain = Storage::disk('segments')->get($stream->segment(0, 2));

    $response = $stream->segmentResponse(0, 2);

    $iv = str_pad(pack('J', 2), 16, "\0", STR_PAD_LEFT);
    expect(openssl_decrypt((string) $response->getContent(), 'aes-128-cbc', EncryptionKey::derive('secret', 'video:1')->binary(), OPENSSL_RAW_DATA, $iv))->toBe($plain)
        ->and($response->headers->get('Cache-Control'))->toContain('private');
});

it('serves encrypted segments itself instead of redirecting to the cache disk', function () {
    Media::fake(['video.mp4' => FakeProbe::video(duration: 13)]);
    $remote = remoteDisk(Storage::fake('remote-segments')->path(''));

    $response = Media::fromDisk('videos')->open('video.mp4')->stream()->toCache($remote)
        ->withEncryption(EncryptionKey::generate(), fn () => 'key')
        ->segmentResponse(0, 0);

    expect($response)->not->toBeInstanceOf(RedirectResponse::class)
        ->and($response->headers->get('Content-Type'))->toBe('video/mp2t');
});

it('serves the raw key of a period', function () {
    Media::fake();
    $stream = Media::fromDisk('videos')->open('video.mp4')->stream()
        ->withEncryption(fn (int $period) => EncryptionKey::derive('secret', "video:{$period}"), fn () => 'key');

    $response = $stream->keyResponse(3);

    expect($response->getContent())->toBe(EncryptionKey::derive('secret', 'video:3')->binary())
        ->and($response->headers->get('Content-Type'))->toBe('application/octet-stream')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store');
});

it('needs encryption to serve keys and a positive rotation', function () {
    Media::fake();
    $stream = Media::fromDisk('videos')->open('video.mp4')->stream();

    expect(fn () => $stream->keyResponse())->toThrow(InvalidArgumentException::class, 'not encrypted')
        ->and(fn () => $stream->withEncryption(EncryptionKey::generate(), fn () => 'key', rotateEvery: 0))->toThrow(InvalidArgumentException::class, 'at least one segment');
});

it('takes the key urls separately and needs them for the media playlist', function () {
    Media::fake(['video.mp4' => FakeProbe::video(duration: 13)]);
    $stream = Media::fromDisk('videos')->open('video.mp4')->stream()->withEncryption(EncryptionKey::generate());

    expect($stream->isEncrypted())->toBeTrue()
        ->and(fn () => $stream->mediaPlaylist(0, fn (Segment $segment) => "{$segment->index}.ts"))->toThrow(InvalidArgumentException::class, 'need a key URL')
        ->and($stream->keyUrlsUsing(fn (int $period) => "key/{$period}")->mediaPlaylist(0, fn (Segment $segment) => "{$segment->index}.ts"))->toContain('URI="key/0"');
});

it('adds no keys to unencrypted streams', function () {
    Media::fake(['video.mp4' => FakeProbe::video(duration: 13)]);
    $stream = Media::fromDisk('videos')->open('video.mp4')->stream()->keyUrlsUsing(fn () => 'key');

    expect($stream->isEncrypted())->toBeFalse()
        ->and($stream->mediaPlaylist(0, fn (Segment $segment) => "{$segment->index}.ts"))->not->toContain('#EXT-X-KEY');
});
