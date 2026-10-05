<?php

declare(strict_types=1);

use Foxws\Media\Delivery\CommonEncryption;
use Foxws\Media\Delivery\DirectStream;
use Foxws\Media\Delivery\LookAheadStrategy;
use Foxws\Media\Delivery\Marker;
use Foxws\Media\Delivery\PackageSegments;
use Foxws\Media\Delivery\Segment;
use Foxws\Media\Delivery\Subtitle;
use Foxws\Media\Delivery\Track;
use Foxws\Media\Encoding\Ladder;
use Foxws\Media\Encoding\Rendition;
use Foxws\Media\Encoding\VideoCodec;
use Foxws\Media\Encryption\EncryptionKey;
use Foxws\Media\Exceptions\InvalidMediaException;
use Foxws\Media\Exceptions\SegmentNotFoundException;
use Foxws\Media\Executables\Executable;
use Foxws\Media\Facades\Media;
use Foxws\Media\FFMpeg\Scene;
use Foxws\Media\FFMpeg\ThumbnailsResult;
use Foxws\Media\Filesystem\Disk;
use Foxws\Media\Testing\FakeProbe;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Illuminate\Support\Facades\Bus;
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

it('lists video tracks with a shared audio rendition when fragmented', function () {
    Media::fake([
        '1080.mp4' => FakeProbe::video(width: 1920, height: 1080),
        '720.mp4' => FakeProbe::video(width: 1280, height: 720),
    ]);

    $playlist = Media::fromDisk('videos')->open(['1080.mp4', '720.mp4'])->stream()->fragmented()
        ->masterPlaylist(fn (int $variant, ?Track $track) => "{$variant}/{$track?->value}.m3u8");

    expect($playlist)->toBe(implode("\n", [
        '#EXTM3U',
        '#EXT-X-VERSION:7',
        '#EXT-X-INDEPENDENT-SEGMENTS',
        '#EXT-X-MEDIA:TYPE=AUDIO,GROUP-ID="audio",NAME="Audio",DEFAULT=YES,AUTOSELECT=YES,URI="0/audio.m3u8"',
        '#EXT-X-STREAM-INF:BANDWIDTH=4950000,RESOLUTION=1920x1080,FRAME-RATE=30.000,CODECS="avc1.640028,mp4a.40.2",AUDIO="audio"',
        '0/video.m3u8',
        '#EXT-X-STREAM-INF:BANDWIDTH=4950000,RESOLUTION=1280x720,FRAME-RATE=30.000,CODECS="avc1.640028,mp4a.40.2",AUDIO="audio"',
        '1/video.m3u8',
        '',
    ]));
});

it('lists an i-frame playlist per video variant with trick play', function () {
    Media::fake([
        '1080.mp4' => FakeProbe::video(width: 1920, height: 1080),
        '720.mp4' => FakeProbe::video(width: 1280, height: 720),
    ]);
    $stream = Media::fromDisk('videos')->open(['1080.mp4', '720.mp4'])->stream()->withTrickPlay();

    expect($stream->fragmented()->masterPlaylist(fn (int $variant, ?Track $track) => "{$variant}/{$track?->value}.m3u8"))->toEndWith(implode("\n", [
        '1/video.m3u8',
        '#EXT-X-I-FRAME-STREAM-INF:BANDWIDTH=495000,RESOLUTION=1920x1080,CODECS="avc1.640028",URI="0/iframes.m3u8"',
        '#EXT-X-I-FRAME-STREAM-INF:BANDWIDTH=495000,RESOLUTION=1280x720,CODECS="avc1.640028",URI="1/iframes.m3u8"',
        '',
    ]))
        ->and($stream->fragmented(false)->masterPlaylist(fn (int $variant) => "{$variant}.m3u8"))->not->toContain('I-FRAME');
});

it('marks the media playlist of the i-frames track as i-frames only', function () {
    Media::fake(['video.mp4' => FakeProbe::video(duration: 13)]);

    $playlist = Media::fromDisk('videos')->open('video.mp4')->stream()->fragmented()->mediaPlaylist(
        0,
        fn (Segment $segment, int $variant, ?Track $track) => "{$variant}/{$track?->value}/{$segment->index}.m4s",
        Track::IFrames,
        fn (int $variant, Track $track) => "{$variant}/{$track->value}/init.mp4",
    );

    expect($playlist)->toContain("#EXT-X-PLAYLIST-TYPE:VOD\n#EXT-X-I-FRAMES-ONLY\n#EXT-X-MAP:URI=\"0/iframes/init.mp4\"\n#EXTINF:6.000000,\n0/iframes/0.m4s")
        ->not->toContain('#EXT-X-INDEPENDENT-SEGMENTS');
});

it('lists an audio rendition per audio stream with its language', function () {
    Media::fake(['movie.mkv' => FakeProbe::video(audioLanguages: ['eng', 'jpn', 'jpn'])]);

    $playlist = Media::fromDisk('videos')->open('movie.mkv')->stream()->fragmented()
        ->masterPlaylist(fn (int $variant, ?Track $track, int $stream) => "{$variant}/{$track?->name($stream)}.m3u8");

    expect($playlist)->toContain(implode("\n", [
        '#EXT-X-MEDIA:TYPE=AUDIO,GROUP-ID="audio",NAME="eng",LANGUAGE="eng",DEFAULT=YES,AUTOSELECT=YES,URI="0/audio.m3u8"',
        '#EXT-X-MEDIA:TYPE=AUDIO,GROUP-ID="audio",NAME="jpn",LANGUAGE="jpn",DEFAULT=NO,AUTOSELECT=YES,URI="0/audio-1.m3u8"',
        '#EXT-X-MEDIA:TYPE=AUDIO,GROUP-ID="audio",NAME="jpn 3",LANGUAGE="jpn",DEFAULT=NO,AUTOSELECT=YES,URI="0/audio-2.m3u8"',
        '#EXT-X-STREAM-INF:BANDWIDTH=4950000,RESOLUTION=1920x1080,FRAME-RATE=30.000,CODECS="avc1.640028,mp4a.40.2",AUDIO="audio"',
    ]));
});

it('offers the audio streams picked by position or language, leaving out codecs fragmented mp4 cannot carry', function () {
    $probe = FakeProbe::video(audioLanguages: ['eng', 'jpn', 'nld']);
    $probe['streams'][3]['codec_name'] = 'truehd';
    Media::fake(['movie.mkv' => $probe]);
    $stream = Media::fromDisk('videos')->open('movie.mkv')->stream()->fragmented();
    $playlistUrl = fn (int $variant, ?Track $track, int $stream) => "{$variant}/{$track?->name($stream)}.m3u8";

    expect($stream->masterPlaylist($playlistUrl))->toContain('0/audio.m3u8')->toContain('0/audio-1.m3u8')->not->toContain('0/audio-2.m3u8')
        ->and($stream->withAudioStreams(['jpn'])->masterPlaylist($playlistUrl))->toContain('NAME="jpn",LANGUAGE="jpn",DEFAULT=YES')->not->toContain('0/audio.m3u8')
        ->and($stream->withAudioStreams([0])->masterPlaylist($playlistUrl))->toContain('NAME="eng"')->not->toContain('audio-1');
});

it('copies a later audio stream into its own track', function () {
    Media::fake(['movie.mkv' => FakeProbe::video(duration: 13, audioLanguages: ['eng', 'jpn'])]);
    $stream = Media::fromDisk('videos')->open('movie.mkv')->stream();

    expect($stream->segment(0, 1, Track::Audio, 1))->toMatch('#^media-segments/[0-9a-f]{32}/6/audio-1/1\.m4s$#')
        ->and($stream->initSegment(0, Track::Audio, 1))->toEndWith('/audio-1/init.mp4')
        ->and(fn () => $stream->segment(0, 1, Track::Audio, 2))->toThrow(SegmentNotFoundException::class, 'Variant 0 has no audio-2 track.');
    Media::assertRan(Executable::FFMpeg, fn (array $arguments) => array_slice($arguments, 12, 2) === ['-map', '0:a:1']);
});

it('lists the audio track as the variant of fragmented audio', function () {
    Media::fake(['song.m4a' => FakeProbe::audio()]);

    $playlist = Media::fromDisk('videos')->open('song.m4a')->stream()->fragmented()
        ->masterPlaylist(fn (int $variant, ?Track $track) => "{$variant}/{$track?->value}.m3u8");

    expect($playlist)->toContain("CODECS=\"mp4a.40.2\"\n0/audio.m3u8")
        ->not->toContain('#EXT-X-MEDIA');
});

it('maps the initialization segment in the media playlist of a track', function () {
    Media::fake(['video.mp4' => FakeProbe::video(duration: 13)]);

    $playlist = Media::fromDisk('videos')->open('video.mp4')->stream()->fragmented()->mediaPlaylist(
        0,
        fn (Segment $segment, int $variant, ?Track $track) => "{$variant}/{$track?->value}/{$segment->index}.m4s",
        Track::Audio,
        fn (int $variant, Track $track) => "{$variant}/{$track->value}/init.mp4",
    );

    expect($playlist)->toStartWith("#EXTM3U\n#EXT-X-VERSION:7\n")
        ->toContain("#EXT-X-MAP:URI=\"0/audio/init.mp4\"\n#EXTINF:6.000000,\n0/audio/0.m4s")
        ->toContain('0/audio/2.m4s');
});

it('needs the url of the initialization segment and an existing track', function () {
    Media::fake(['song.m4a' => FakeProbe::audio()]);
    $stream = Media::fromDisk('videos')->open('song.m4a')->stream()->fragmented();

    expect(fn () => $stream->mediaPlaylist(0, fn () => 'segment'))->toThrow(InvalidArgumentException::class, 'initialization segment')
        ->and(fn () => $stream->mediaPlaylist(0, fn () => 'segment', Track::Video, fn () => 'init'))->toThrow(SegmentNotFoundException::class, 'Variant 0 has no video track.');
});

it('copies one track of a segment into fragmented mp4 and caches its initialization segment', function () {
    Media::fake(['video.mp4' => FakeProbe::video(duration: 13)]);
    $stream = Media::fromDisk('videos')->open('video.mp4')->stream();

    $path = $stream->segment(0, 1, Track::Video);

    expect($path)->toMatch('#^media-segments/[0-9a-f]{32}/6/video/1\.m4s$#')
        ->and($stream->initSegment(0, Track::Video))->toBe(dirname($path).'/init.mp4');
    expect(Storage::disk('segments')->get($path))->toStartWith(pack('N', 8).'moof')
        ->and(Storage::disk('segments')->get(dirname($path).'/init.mp4'))->toStartWith(pack('N', 12).'ftyp');
    Media::assertRanTimes(Executable::FFMpeg, 1);
    Media::assertRan(Executable::FFMpeg, fn (array $arguments) => array_slice($arguments, 12, -1) === [
        '-map', '0:v:0', '-c', 'copy',
        '-output_ts_offset', '10', '-avoid_negative_ts', 'disabled', '-use_editlist', '0',
        '-movflags', '+frag_keyframe+empty_moov+default_base_moof+frag_discont', '-fflags', '+bitexact', '-f', 'mp4',
    ]);
});

it('copies only the first keyframe of a segment into the i-frames track', function () {
    Media::fake(['video.mp4' => FakeProbe::video(duration: 13)]);
    $stream = Media::fromDisk('videos')->open('video.mp4')->stream();

    expect($stream->segment(0, 1, Track::IFrames))->toMatch('#^media-segments/[0-9a-f]{32}/6/iframes/1\.m4s$#');
    Media::assertRan(Executable::FFMpeg, fn (array $arguments) => array_slice($arguments, 12, 6) === ['-map', '0:v:0', '-frames:v', '1', '-c', 'copy']);
});

it('keeps the cached initialization segment when packaging more fragments', function () {
    Media::fake(['video.mp4' => FakeProbe::video(duration: 13)]);
    $stream = Media::fromDisk('videos')->open('video.mp4')->stream()->lookAhead(0);
    $init = dirname($stream->segment(0, 0, Track::Video)).'/init.mp4';
    Storage::disk('segments')->put($init, 'cached');

    $stream->segment(0, 1, Track::Video);

    expect(Storage::disk('segments')->get($init))->toBe('cached');
});

it('packages the first segment for an initialization segment that is not cached yet', function () {
    Media::fake(['video.mp4' => FakeProbe::video(codec: 'hevc', duration: 13)]);

    $response = Media::fromDisk('videos')->open('video.mp4')->stream()->initSegmentResponse(0, Track::Video);

    expect($response->headers->get('Content-Type'))->toBe('video/mp4');
    Media::assertRan(Executable::FFMpeg, fn (array $arguments) => in_array('hvc1', $arguments, true) && $arguments[array_search('-ss', $arguments, true) + 1] === '0');
});

it('serves fragments with the content type of their track', function () {
    Media::fake(['video.mp4' => FakeProbe::video(duration: 13)]);

    $response = Media::fromDisk('videos')->open('video.mp4')->stream()->segmentResponse(0, 2, Track::Audio);

    expect($response->headers->get('Content-Type'))->toBe('audio/mp4');
});

it('refuses codecs fragmented mp4 cannot carry', function () {
    Media::fake(['video.ogv' => FakeProbe::video(codec: 'theora')]);

    Media::fromDisk('videos')->open('video.ogv')->stream()->segment(0, 0, Track::Video);
})->throws(InvalidMediaException::class, "video.ogv can't be streamed with fragmented MP4 segments without re-encoding: [theora] isn't supported.");

it('describes every track and segment in a dash manifest', function () {
    Media::fake([
        '1080.mp4' => FakeProbe::video(duration: 13, width: 1920, height: 1080),
        '720.mp4' => FakeProbe::video(duration: 13, width: 1280, height: 720, audio: false),
    ]);

    $manifest = Media::fromDisk('videos')->open(['1080.mp4', '720.mp4'])->stream()->dashManifest(
        fn (int $variant, Track $track) => "{$variant}/{$track->value}/init.mp4",
        fn (Segment $segment, int $variant, Track $track) => "{$variant}/{$track->value}/{$segment->index}.m4s?a=1&b=2",
    );

    expect($manifest)->toBe(implode("\n", [
        '<?xml version="1.0" encoding="UTF-8"?>',
        '<MPD xmlns="urn:mpeg:dash:schema:mpd:2011" profiles="urn:mpeg:dash:profile:isoff-main:2011" type="static" mediaPresentationDuration="PT13.000S" minBufferTime="PT6.000S">',
        '  <Period id="0" start="PT0S">',
        '    <AdaptationSet id="0" contentType="video" mimeType="video/mp4" startWithSAP="1">',
        '      <Representation id="video-0" codecs="avc1.640028" bandwidth="4950000" width="1920" height="1080" frameRate="30/1">',
        '        <SegmentList timescale="1000" presentationTimeOffset="10000">',
        '          <Initialization sourceURL="0/video/init.mp4"/>',
        '          <SegmentTimeline>',
        '            <S t="10000" d="6000" r="1"/>',
        '            <S t="22000" d="1000"/>',
        '          </SegmentTimeline>',
        '          <SegmentURL media="0/video/0.m4s?a=1&amp;b=2"/>',
        '          <SegmentURL media="0/video/1.m4s?a=1&amp;b=2"/>',
        '          <SegmentURL media="0/video/2.m4s?a=1&amp;b=2"/>',
        '        </SegmentList>',
        '      </Representation>',
        '      <Representation id="video-1" codecs="avc1.640028" bandwidth="4950000" width="1280" height="720" frameRate="30/1">',
        '        <SegmentList timescale="1000" presentationTimeOffset="10000">',
        '          <Initialization sourceURL="1/video/init.mp4"/>',
        '          <SegmentTimeline>',
        '            <S t="10000" d="6000" r="1"/>',
        '            <S t="22000" d="1000"/>',
        '          </SegmentTimeline>',
        '          <SegmentURL media="1/video/0.m4s?a=1&amp;b=2"/>',
        '          <SegmentURL media="1/video/1.m4s?a=1&amp;b=2"/>',
        '          <SegmentURL media="1/video/2.m4s?a=1&amp;b=2"/>',
        '        </SegmentList>',
        '      </Representation>',
        '    </AdaptationSet>',
        '    <AdaptationSet id="1" contentType="audio" mimeType="audio/mp4" startWithSAP="1">',
        '      <Representation id="audio-0" codecs="mp4a.40.2" bandwidth="128000" audioSamplingRate="48000">',
        '        <SegmentList timescale="1000" presentationTimeOffset="10000">',
        '          <Initialization sourceURL="0/audio/init.mp4"/>',
        '          <SegmentTimeline>',
        '            <S t="10000" d="6000" r="1"/>',
        '            <S t="22000" d="1000"/>',
        '          </SegmentTimeline>',
        '          <SegmentURL media="0/audio/0.m4s?a=1&amp;b=2"/>',
        '          <SegmentURL media="0/audio/1.m4s?a=1&amp;b=2"/>',
        '          <SegmentURL media="0/audio/2.m4s?a=1&amp;b=2"/>',
        '        </SegmentList>',
        '      </Representation>',
        '    </AdaptationSet>',
        '  </Period>',
        '</MPD>',
        '',
    ]));
});

/**
 * Replace the placeholders the fake ffmpeg cached for a track with a real fragmented MP4 track.
 *
 * @param  list<string>  $samples
 * @return array{init: string, media: string}
 */
function cacheFragmentedTrack(DirectStream $stream, int $segment, Track $track, string $sampleEntry, array $samples): array
{
    $path = $stream->segment(0, $segment, $track);
    $fragments = fragmentedTrack($sampleEntry, $samples);

    Storage::disk('segments')->put(dirname($path).'/init.mp4', $fragments['init']);
    Storage::disk('segments')->put($path, $fragments['media']);

    return $fragments;
}

it('adds an audio adaptation set per audio stream to the dash manifest', function () {
    Media::fake(['movie.mkv' => FakeProbe::video(duration: 13, audioLanguages: ['eng', 'jpn'], subtitles: ['eng'])]);

    $manifest = Media::fromDisk('videos')->open('movie.mkv')->stream()->withEmbeddedSubtitles()->dashManifest(
        fn (int $variant, Track $track, int $stream) => "{$variant}/{$track->name($stream)}/init.mp4",
        fn (Segment $segment, int $variant, Track $track, int $stream) => "{$variant}/{$track->name($stream)}/{$segment->index}.m4s",
        fn (int $subtitle) => "{$subtitle}.vtt",
    );

    expect($manifest)->toContain(implode("\n", [
        '    <AdaptationSet id="1" contentType="audio" mimeType="audio/mp4" lang="eng" startWithSAP="1">',
        '      <Label>eng</Label>',
        '      <Role schemeIdUri="urn:mpeg:dash:role:2011" value="main"/>',
        '      <Representation id="audio-0" codecs="mp4a.40.2" bandwidth="128000" audioSamplingRate="48000">',
    ]))
        ->toContain(implode("\n", [
            '    <AdaptationSet id="2" contentType="audio" mimeType="audio/mp4" lang="jpn" startWithSAP="1">',
            '      <Label>jpn</Label>',
            '      <Role schemeIdUri="urn:mpeg:dash:role:2011" value="alternate"/>',
            '      <Representation id="audio-1-0" codecs="mp4a.40.2" bandwidth="128000" audioSamplingRate="48000">',
        ]))
        ->toContain('<Initialization sourceURL="0/audio-1/init.mp4"/>')
        ->toContain('<AdaptationSet id="3" contentType="text"');
});

it('adds a trick mode adaptation set of the i-frames to the dash manifest', function () {
    Media::fake(['video.mp4' => FakeProbe::video(duration: 13)]);

    $manifest = Media::fromDisk('videos')->open('video.mp4')->stream()->withTrickPlay()->dashManifest(
        fn (int $variant, Track $track) => "{$variant}/{$track->value}/init.mp4",
        fn (Segment $segment, int $variant, Track $track) => "{$variant}/{$track->value}/{$segment->index}.m4s",
    );

    expect($manifest)->toContain(implode("\n", [
        '    <AdaptationSet id="2" contentType="video" mimeType="video/mp4" startWithSAP="1">',
        '      <EssentialProperty schemeIdUri="http://dashif.org/guidelines/trickmode" value="0"/>',
        '      <Representation id="iframes-0" codecs="avc1.640028" bandwidth="495000" width="1920" height="1080" maxPlayoutRate="6" codingDependency="false">',
    ]))
        ->toContain('<Initialization sourceURL="0/iframes/init.mp4"/>')
        ->toContain('<SegmentURL media="0/iframes/2.m4s"/>');
});

it('encrypts fragmented streams with one key only', function () {
    Media::fake(['video.mp4' => FakeProbe::video(duration: 13)]);
    $stream = Media::fromDisk('videos')->open('video.mp4')->stream()->withEncryption(EncryptionKey::generate(), fn () => 'key', rotateEvery: 2);

    expect(fn () => $stream->dashManifest(fn () => 'init', fn () => 'segment'))->toThrow(InvalidArgumentException::class, 'encrypted with one key')
        ->and(fn () => $stream->fragmented()->masterPlaylist(fn () => 'playlist'))->toThrow(InvalidArgumentException::class, 'encrypted with one key')
        ->and(fn () => $stream->segmentResponse(0, 0, Track::Video))->toThrow(InvalidArgumentException::class, 'encrypted with one key');
});

it('adds the key to the playlists of fragmented tracks for players to use as a clear key', function () {
    Media::fake(['video.mp4' => FakeProbe::video(duration: 13)]);

    $playlist = Media::fromDisk('videos')->open('video.mp4')->stream()->fragmented()
        ->withEncryption(EncryptionKey::generate(), fn (int $period, int $variant) => "keys/{$variant}/{$period}.key")
        ->mediaPlaylist(0, fn (Segment $segment) => "{$segment->index}.m4s", initUrl: fn () => 'init.mp4');

    expect($playlist)->toContain("#EXT-X-KEY:METHOD=SAMPLE-AES-CTR,URI=\"keys/0/0.key\",KEYFORMAT=\"identity\",KEYFORMATVERSIONS=\"1\"\n#EXT-X-MAP:URI=\"init.mp4\"")
        ->and(substr_count($playlist, '#EXT-X-KEY'))->toBe(1);
});

it('names the key and the clearkey license url in the dash manifest', function () {
    Media::fake(['video.mp4' => FakeProbe::video(duration: 13)]);
    $key = EncryptionKey::generate();
    $stream = Media::fromDisk('videos')->open('video.mp4')->stream()->withEncryption($key, fn () => 'key');

    $withoutLicense = simplexml_load_string($stream->dashManifest(fn () => 'init', fn () => 'segment'));

    expect((string) $withoutLicense->Period->AdaptationSet[0]->ContentProtection[1]['value'])->toBe('ClearKey1.0')
        ->and($withoutLicense->Period->AdaptationSet[0]->ContentProtection[1]->children('https://dashif.org/CPS')->Laurl)->toHaveCount(0);

    $manifest = simplexml_load_string($stream->licenseUrlUsing(fn () => 'license.json?a=1&b=2')->dashManifest(fn () => 'init', fn () => 'segment'));

    foreach ($manifest->Period->AdaptationSet as $set) {
        $protection = $set->ContentProtection;

        expect((string) $protection[0]['value'])->toBe('cenc')
            ->and((string) $protection[0]->attributes('urn:mpeg:cenc:2013')['default_KID'])->toBe($key->keyIdUuid())
            ->and((string) $protection[1]['schemeIdUri'])->toBe('urn:uuid:e2719d58-a985-b3c9-781a-b030af78d30e')
            ->and((string) $protection[1]->children('https://dashif.org/CPS')->Laurl)->toBe('license.json?a=1&b=2');
    }

    expect($manifest->Period->AdaptationSet)->toHaveCount(2);
});

it('encrypts the initialization segment and fragments of a track as they are served', function () {
    Media::fake(['video.mp4' => FakeProbe::video(duration: 13)]);
    $key = EncryptionKey::generate();
    $stream = Media::fromDisk('videos')->open('video.mp4')->stream()->lookAhead(0)->withEncryption($key, fn () => 'key');
    $samples = [pack('N', 33).chr(0x65).str_repeat('x', 32)];
    $fragments = cacheFragmentedTrack($stream, 1, Track::Video, 'avc1', $samples);

    $init = $stream->initSegmentResponse(0, Track::Video);
    $fragment = $stream->segmentResponse(0, 1, Track::Video);

    expect($init->getContent())->toBe(CommonEncryption::init($fragments['init'], $key))
        ->and($init->headers->get('Cache-Control'))->toContain('private')
        ->and($fragment->headers->get('Content-Type'))->toBe('video/mp4')
        ->and($fragment->getContent())->not->toContain(str_repeat('x', 32))
        ->and(decryptCenc((string) $fragment->getContent(), $key)['samples'])->toBe($samples);
});

it('serves encrypted fragments itself instead of redirecting to the cache disk', function () {
    Media::fake(['video.mp4' => FakeProbe::video(duration: 13)]);
    $remote = remoteDisk(Storage::fake('remote-segments')->path(''));
    $stream = Media::fromDisk('videos')->open('video.mp4')->stream()->toCache($remote)->lookAhead(0)->withEncryption(EncryptionKey::generate(), fn () => 'key');
    $path = $stream->segment(0, 0, Track::Audio);
    $fragments = fragmentedTrack('mp4a', ['sample']);
    $remote->put(dirname($path).'/init.mp4', $fragments['init']);
    $remote->put($path, $fragments['media']);

    expect($stream->segmentResponse(0, 0, Track::Audio))->not->toBeInstanceOf(RedirectResponse::class)
        ->and($stream->initSegmentResponse(0, Track::Audio))->not->toBeInstanceOf(RedirectResponse::class);
});

it('refuses to encrypt codecs whose frame headers have to stay readable', function () {
    Media::fake(['video.mp4' => FakeProbe::video(codec: 'av1', duration: 13)]);
    $stream = Media::fromDisk('videos')->open('video.mp4')->stream()->withEncryption(EncryptionKey::generate(), fn () => 'key');

    expect(fn () => $stream->fragmented()->mediaPlaylist(0, fn () => 'segment', initUrl: fn () => 'init'))->toThrow(InvalidMediaException::class, '[av1] isn\'t supported')
        ->and(fn () => $stream->segmentResponse(0, 0, Track::Video))->toThrow(InvalidMediaException::class, '[av1] isn\'t supported');
});

it('serves the key as a clearkey license', function () {
    Media::fake();
    $key = EncryptionKey::generate();

    $response = Media::fromDisk('videos')->open('video.mp4')->stream()->withEncryption($key)->licenseResponse();

    expect(json_decode((string) $response->getContent(), true))->toBe(['keys' => [$key->toJsonWebKey()], 'type' => 'temporary'])
        ->and($response->headers->get('Content-Type'))->toBe('application/json')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store');
});

it('offers added files and embedded text streams as subtitle renditions', function () {
    Media::fake(['video.mp4' => FakeProbe::video(duration: 13, subtitles: ['eng', 'nld'])]);
    Storage::disk('videos')->put('captions/de.vtt', "WEBVTT\n\n00:00.000 --> 00:01.000\nHallo");

    $stream = Media::fromDisk('videos')->open('video.mp4')->stream()
        ->withSubtitles('captions/de.vtt', 'deu', 'Deutsch')
        ->withEmbeddedSubtitles();

    expect(array_map(fn (Subtitle $subtitle) => [$subtitle->label, $subtitle->language, $subtitle->stream], $stream->subtitles()))->toBe([
        ['Deutsch', 'deu', null],
        ['eng', 'eng', 2],
        ['nld', 'nld', 3],
    ]);

    $playlist = $stream->masterPlaylist(fn (int $variant) => "{$variant}.m3u8", fn (int $subtitle) => "subtitles/{$subtitle}.m3u8");

    expect($playlist)->toContain(
        '#EXT-X-MEDIA:TYPE=SUBTITLES,GROUP-ID="subtitles",NAME="Deutsch",LANGUAGE="deu",DEFAULT=NO,AUTOSELECT=YES,URI="subtitles/0.m3u8"',
        'URI="subtitles/2.m3u8"',
        'CODECS="avc1.640028,mp4a.40.2",SUBTITLES="subtitles"',
    )->and($stream->fragmented()->masterPlaylist(fn (int $variant) => "{$variant}.m3u8", fn (int $subtitle) => "subtitles/{$subtitle}.m3u8"))
        ->toContain('AUDIO="audio",SUBTITLES="subtitles"');
});

it('needs subtitle urls when the stream has subtitles', function () {
    Media::fake(['video.mp4' => FakeProbe::video(duration: 13)]);
    $stream = Media::fromDisk('videos')->open('video.mp4')->stream()->withSubtitles('en.vtt', 'en');

    expect(fn () => $stream->masterPlaylist(fn () => 'playlist'))->toThrow(InvalidArgumentException::class, 'URLs of their playlists')
        ->and(fn () => $stream->dashManifest(fn () => 'init', fn () => 'segment'))->toThrow(InvalidArgumentException::class, 'URLs of their WebVTT files');
});

it('lists a subtitle track as one segment in its media playlist', function () {
    Media::fake(['video.mp4' => FakeProbe::video(duration: 13.5)]);

    $playlist = Media::fromDisk('videos')->open('video.mp4')->stream()->withSubtitles('en.vtt')->subtitlePlaylist(0, 'en.vtt');

    expect($playlist)->toBe(implode("\n", [
        '#EXTM3U',
        '#EXT-X-VERSION:3',
        '#EXT-X-TARGETDURATION:14',
        '#EXT-X-MEDIA-SEQUENCE:0',
        '#EXT-X-PLAYLIST-TYPE:VOD',
        '#EXTINF:13.500000,',
        'en.vtt',
        '#EXT-X-ENDLIST',
        '',
    ]));
});

it('maps subtitle cues onto the segment timestamps for hls', function () {
    Media::fake(['video.mp4' => FakeProbe::video()]);
    Storage::disk('videos')->put('en.vtt', "\u{FEFF}WEBVTT - English\nX-TIMESTAMP-MAP=MPEGTS:0,LOCAL:00:00:00.000\n\n00:00.000 --> 00:01.000\nHello");
    $stream = Media::fromDisk('videos')->open('video.mp4')->stream()->withSubtitles('en.vtt');

    $cmaf = $stream->subtitleResponse(0, timestampOffset: 10);
    $dash = $stream->subtitleResponse(0);

    expect($cmaf->getContent())->toBe("WEBVTT - English\nX-TIMESTAMP-MAP=MPEGTS:900000,LOCAL:00:00:00.000\n\n00:00.000 --> 00:01.000\nHello")
        ->and($cmaf->headers->get('Content-Type'))->toBe('text/vtt; charset=utf-8')
        ->and($dash->getContent())->toBe(Storage::disk('videos')->get('en.vtt'));
});

it('converts an embedded subtitle stream to webvtt once', function () {
    Media::fake(['video.mp4' => FakeProbe::video(subtitles: ['eng'])]);
    $stream = Media::fromDisk('videos')->open('video.mp4')->stream()->withEmbeddedSubtitles();

    $stream->subtitle(0);
    $stream->subtitle(0);

    Media::assertRanTimes(Executable::FFMpeg, 1);
    Media::assertRan(Executable::FFMpeg, fn (array $arguments) => array_slice($arguments, 7, -1) === ['-map', '0:2', '-c:s', 'webvtt', '-f', 'webvtt']);
    expect(Storage::disk('segments')->allFiles())->toHaveCount(1)
        ->and(Storage::disk('segments')->allFiles()[0])->toMatch('#^media-segments/[0-9a-f]{32}/subtitles/2\.vtt$#');
});

it('adds subtitles to the dash manifest as webvtt files', function () {
    Media::fake(['video.mp4' => FakeProbe::video(duration: 13)]);

    $manifest = Media::fromDisk('videos')->open('video.mp4')->stream()
        ->withSubtitles('en.vtt', 'en', 'English & more')
        ->dashManifest(fn () => 'init', fn () => 'segment', fn (int $subtitle) => "subtitles/{$subtitle}.vtt?a=1&b=2");

    expect($manifest)->toContain(implode("\n", [
        '    <AdaptationSet id="2" contentType="text" mimeType="text/vtt" lang="en">',
        '      <Label>English &amp; more</Label>',
        '      <Role schemeIdUri="urn:mpeg:dash:role:2011" value="subtitle"/>',
        '      <Representation id="text-0" bandwidth="256">',
        '        <BaseURL>subtitles/0.vtt?a=1&amp;b=2</BaseURL>',
        '      </Representation>',
        '    </AdaptationSet>',
    ]));
});

it('throws a 404 for subtitles that do not exist', function () {
    Media::fake(['video.mp4' => FakeProbe::video()]);

    Media::fromDisk('videos')->open('video.mp4')->stream()->withSubtitles('missing.vtt')->subtitle(1);
})->throws(SegmentNotFoundException::class, "Subtitle 1 doesn't exist.");

/**
 * Two sprite sheets of a 2x2 grid with a thumbnail every 2 seconds, the second sheet half full.
 */
function thumbnailSheets(string $disk = 'videos'): ThumbnailsResult
{
    return new ThumbnailsResult(Disk::make($disk), ['sb_001.jpg', 'sb_002.jpg'], 'sb.vtt', 2.0, 6, columns: 2, rows: 2, width: 160, height: 90);
}

it('lists the sprite sheets with their tiles in an image playlist', function () {
    Media::fake(['video.mp4' => FakeProbe::video(duration: 12)]);

    $playlist = Media::fromDisk('videos')->open('video.mp4')->stream()->withThumbnails(thumbnailSheets())
        ->thumbnailPlaylist(fn (int $sheet) => "thumbnails/{$sheet}.jpg");

    expect($playlist)->toBe(implode("\n", [
        '#EXTM3U',
        '#EXT-X-VERSION:7',
        '#EXT-X-TARGETDURATION:8',
        '#EXT-X-MEDIA-SEQUENCE:0',
        '#EXT-X-PLAYLIST-TYPE:VOD',
        '#EXT-X-IMAGES-ONLY',
        '#EXTINF:8.000000,',
        '#EXT-X-TILES:RESOLUTION=160x90,LAYOUT=2x2,DURATION=2.000',
        'thumbnails/0.jpg',
        '#EXTINF:4.000000,',
        '#EXT-X-TILES:RESOLUTION=160x90,LAYOUT=2x2,DURATION=2.000',
        'thumbnails/1.jpg',
        '#EXT-X-ENDLIST',
        '',
    ]));
});

it('adds the image stream to the master playlists', function () {
    Media::fake(['video.mp4' => FakeProbe::video(duration: 12)]);
    $stream = Media::fromDisk('videos')->open('video.mp4')->stream()->withThumbnails(thumbnailSheets());

    $line = '#EXT-X-IMAGE-STREAM-INF:BANDWIDTH=3600,RESOLUTION=320x180,CODECS="jpeg",URI="thumbnails.m3u8"';

    expect($stream->masterPlaylist(fn () => 'playlist', thumbnailUrl: fn () => 'thumbnails.m3u8'))->toEndWith("{$line}\n")
        ->and($stream->fragmented()->masterPlaylist(fn () => 'playlist', thumbnailUrl: fn () => 'thumbnails.m3u8'))->toEndWith("{$line}\n")
        ->and(fn () => $stream->masterPlaylist(fn () => 'playlist'))->toThrow(InvalidArgumentException::class, 'URL of their image playlist')
        ->and(fn () => $stream->dashManifest(fn () => 'init', fn () => 'segment'))->toThrow(InvalidArgumentException::class, 'URLs of their sprite sheets');
});

it('adds the sprite sheets to the dash manifest as thumbnail tiles', function () {
    Media::fake(['video.mp4' => FakeProbe::video(duration: 12)]);

    $manifest = Media::fromDisk('videos')->open('video.mp4')->stream()->withThumbnails(thumbnailSheets())
        ->dashManifest(fn () => 'init', fn () => 'segment', thumbnailUrl: fn (int $sheet) => "thumbnails/{$sheet}.jpg?a=1&b=2");

    expect($manifest)->toContain(implode("\n", [
        '    <AdaptationSet id="2" contentType="image" mimeType="image/jpeg">',
        '      <Representation id="thumbnails" bandwidth="3600" width="320" height="180">',
        '        <EssentialProperty schemeIdUri="http://dashif.org/thumbnail_tile" value="2x2"/>',
        '        <SegmentList timescale="1000">',
        '          <SegmentTimeline>',
        '            <S t="0" d="8000"/>',
        '            <S t="8000" d="4000"/>',
        '          </SegmentTimeline>',
        '          <SegmentURL media="thumbnails/0.jpg?a=1&amp;b=2"/>',
        '          <SegmentURL media="thumbnails/1.jpg?a=1&amp;b=2"/>',
        '        </SegmentList>',
        '      </Representation>',
        '    </AdaptationSet>',
    ]));
});

it('serves sprite sheets from their disk', function () {
    Media::fake(['video.mp4' => FakeProbe::video()]);
    Storage::disk('videos')->put('sb_002.jpg', 'sheet');
    $remote = remoteDisk(Storage::fake('remote-storyboards')->path(''));
    $stream = Media::fromDisk('videos')->open('video.mp4')->stream();

    $local = $stream->withThumbnails(thumbnailSheets())->thumbnailResponse(1);
    $redirect = $stream->withThumbnails(new ThumbnailsResult(Disk::make($remote), ['sb_001.jpg'], 'sb.vtt', 2.0, 4, 2, 2))->thumbnailResponse(0);

    expect($local->headers->get('Content-Type'))->toBe('image/jpeg')
        ->and($redirect)->toBeInstanceOf(RedirectResponse::class)
        ->and(fn () => $stream->thumbnailResponse(5))->toThrow(SegmentNotFoundException::class, "Thumbnail sheet 5 doesn't exist.")
        ->and(fn () => $stream->withThumbnails(null)->thumbnailPlaylist(fn () => 'sheet'))->toThrow(SegmentNotFoundException::class, 'This stream has no thumbnails.');
});

/**
 * A video of 13 seconds with two chapters.
 *
 * @return array<string, mixed>
 */
function videoWithChapters(): array
{
    return [...FakeProbe::video(duration: 13), 'chapters' => [
        ['start_time' => '0.000000', 'end_time' => '6.000000', 'tags' => ['title' => 'Opening']],
        ['start_time' => '6.000000', 'end_time' => '13.000000', 'tags' => ['title' => 'The "end"']],
    ]];
}

it('lists added markers, scenes and the chapters of the first file in order of their start', function () {
    Media::fake(['video.mp4' => videoWithChapters()]);

    $markers = Media::fromDisk('videos')->open('video.mp4')->stream()
        ->withMarkers([new Marker(8.5, title: 'Twist', class: 'highlight')])
        ->withScenes([new Scene(2, 7, 0.4)])
        ->withChapters()
        ->markers();

    expect(array_map(fn (Marker $marker) => [$marker->class, $marker->start], $markers))->toBe([
        ['chapter', 0.0],
        ['scene', 2.0],
        ['chapter', 6.0],
        ['highlight', 8.5],
    ]);
});

it('adds the markers as date ranges to the media playlists, anchored at the epoch', function () {
    Media::fake(['video.mp4' => videoWithChapters()]);
    $stream = Media::fromDisk('videos')->open('video.mp4')->stream()->withChapters()
        ->withMarkers([new Marker(8.5, title: "Line\nbreak", class: 'highlight')]);

    $tags = [
        '#EXT-X-PROGRAM-DATE-TIME:1970-01-01T00:00:00.000Z',
        '#EXT-X-DATERANGE:ID="chapter-0",CLASS="chapter",START-DATE="1970-01-01T00:00:00.000Z",DURATION=6.000,X-TITLE="Opening"',
        '#EXT-X-DATERANGE:ID="chapter-1",CLASS="chapter",START-DATE="1970-01-01T00:00:06.000Z",DURATION=7.000,X-TITLE="The \'end\'"',
        '#EXT-X-DATERANGE:ID="highlight-2",CLASS="highlight",START-DATE="1970-01-01T00:00:08.500Z",X-TITLE="Line break"',
        '#EXTINF:6.000000,',
    ];

    expect($stream->mediaPlaylist(0, fn (Segment $segment) => "{$segment->index}.ts"))->toContain(implode("\n", $tags))
        ->and($stream->fragmented()->mediaPlaylist(0, fn (Segment $segment) => "{$segment->index}.m4s", initUrl: fn () => 'init.mp4'))
        ->toContain('#EXT-X-MAP:URI="init.mp4"'."\n".implode("\n", $tags));
});

it('anchors the subtitle and image playlists at the epoch too when the stream has markers', function () {
    Media::fake(['video.mp4' => FakeProbe::video(duration: 13)]);
    $stream = Media::fromDisk('videos')->open('video.mp4')->stream()->withSubtitles('en.vtt')->withThumbnails(thumbnailSheets());

    expect($stream->subtitlePlaylist(0, 'en.vtt'))->not->toContain('#EXT-X-PROGRAM-DATE-TIME')
        ->and($stream->mediaPlaylist(0, fn (Segment $segment) => "{$segment->index}.ts"))->not->toContain('#EXT-X-PROGRAM-DATE-TIME');

    $stream->withMarkers([new Marker(1)]);

    expect($stream->subtitlePlaylist(0, 'en.vtt'))->toContain("#EXT-X-PLAYLIST-TYPE:VOD\n#EXT-X-PROGRAM-DATE-TIME:1970-01-01T00:00:00.000Z\n#EXTINF")
        ->and($stream->thumbnailPlaylist(fn (int $sheet) => "{$sheet}.jpg"))->toContain("#EXT-X-IMAGES-ONLY\n#EXT-X-PROGRAM-DATE-TIME:1970-01-01T00:00:00.000Z\n#EXTINF");
});

it('adds the markers to the dash manifest as an event stream per class', function () {
    Media::fake(['video.mp4' => videoWithChapters()]);

    $manifest = Media::fromDisk('videos')->open('video.mp4')->stream()->withChapters()
        ->withMarkers([new Marker(8.5, title: 'A & B', class: 'highlight')])
        ->dashManifest(fn () => 'init', fn () => 'segment');

    expect($manifest)->toContain(implode("\n", [
        '  <Period id="0" start="PT0S">',
        '    <EventStream schemeIdUri="urn:foxws:media:marker" value="chapter" timescale="1000">',
        '      <Event id="0" presentationTime="0" duration="6000">Opening</Event>',
        '      <Event id="1" presentationTime="6000" duration="7000">The &quot;end&quot;</Event>',
        '    </EventStream>',
        '    <EventStream schemeIdUri="urn:foxws:media:marker" value="highlight" timescale="1000">',
        '      <Event id="2" presentationTime="8500">A &amp; B</Event>',
        '    </EventStream>',
        '    <AdaptationSet id="0"',
    ]));
});

it('serves the chapters as a webvtt track', function () {
    Media::fake(['video.mp4' => videoWithChapters()]);
    $stream = Media::fromDisk('videos')->open('video.mp4')->stream()->withChapters()
        ->withMarkers([new Marker(8.5, 10, 'Twist', 'highlight')]);

    $response = $stream->chapterTrackResponse();

    expect($response->headers->get('Content-Type'))->toBe('text/vtt; charset=utf-8')
        ->and($response->getContent())->toContain("chapter-0\n00:00:00.000 --> 00:00:06.000\nOpening")
        ->toContain("chapter-1\n00:00:06.000 --> 00:00:13.000\nThe \"end\"")
        ->not->toContain('Twist');
});

it('lists the markers of other classes in the chapter track, with a title for the gaps', function () {
    Media::fake(['video.mp4' => FakeProbe::video(duration: 13)]);

    $track = Media::fromDisk('videos')->open('video.mp4')->stream()
        ->withMarkers([new Marker(2, 4, 'Intro', 'intro'), new Marker(8, 9, 'Twist', 'highlight')])
        ->chapterTrackFrom(['intro'], 'Main')
        ->chapterTrack();

    expect($track->markers)->toHaveCount(1)->and($track->duration)->toBe(13.0)->and($track->gapTitle)->toBe('Main')
        ->and(Media::fromDisk('videos')->open('video.mp4')->stream()->withMarkers([new Marker(8, 9)])->chapterTrackFrom(null)->chapterTrack()->markers)->toHaveCount(1);
});

it('has no chapter track without chapters', function () {
    Media::fake(['video.mp4' => FakeProbe::video(duration: 13)]);

    Media::fromDisk('videos')->open('video.mp4')->stream()->withChapters()->chapterTrackResponse();
})->throws(SegmentNotFoundException::class, 'This stream has no chapters.');

it('probes each file once across requests', function () {
    Media::fake(['video.mp4' => FakeProbe::video(duration: 13)]);
    Storage::disk('videos')->put('video.mp4', 'video');

    Media::fromDisk('videos')->open('video.mp4')->stream()->masterPlaylist(fn () => 'playlist');
    Media::fromDisk('videos')->open('video.mp4')->stream()->mediaPlaylist(0, fn (Segment $segment) => "{$segment->index}.ts");

    Media::assertRanTimes(Executable::FFProbe, 2);
});

/**
 * Name the faked disks in the config and use a queue that doesn't run jobs straight away.
 */
function lookAheadOnQueue(): void
{
    config([
        'filesystems.disks.videos' => ['driver' => 'local', 'root' => sys_get_temp_dir()],
        'filesystems.disks.segments' => ['driver' => 'local', 'root' => sys_get_temp_dir()],
        'queue.default' => 'database',
        'media.delivery.look_ahead' => 2,
        'media.delivery.look_ahead_via' => 'queue',
    ]);
}

it('queues the next segments that are not cached yet after a segment request', function () {
    Bus::fake();
    lookAheadOnQueue();
    config(['media.delivery.look_ahead_connection' => 'redis', 'media.delivery.look_ahead_queue' => 'media']);
    Media::fake(['video.mp4' => FakeProbe::video(duration: 19)]);
    $stream = Media::fromDisk('videos')->open('video.mp4')->stream();
    $stream->lookAhead(0)->segment(0, 2, Track::Video);

    $stream->lookAhead(2)->segmentResponse(0, 0, Track::Video);

    Bus::assertDispatched(PackageSegments::class, fn (PackageSegments $job) => $job->segments === [1]
        && $job->track === Track::Video
        && $job->disk === 'videos'
        && $job->path === 'video.mp4'
        && $job->cacheDisk === 'segments'
        && $job->segmentDuration === 6.0
        && $job->connection === 'redis'
        && $job->queue === 'media');
});

it('looks no further than the last segment and dispatches nothing when all are cached', function () {
    Bus::fake();
    lookAheadOnQueue();
    Media::fake(['video.mp4' => FakeProbe::video(duration: 13)]);
    $stream = Media::fromDisk('videos')->open('video.mp4')->stream();

    $stream->segmentResponse(0, 1);
    $stream->lookAhead(0)->segment(0, 2);
    $stream->lookAhead(2)->segmentResponse(0, 1);

    Bus::assertDispatchedTimes(PackageSegments::class, 1);
    Bus::assertDispatched(PackageSegments::class, fn (PackageSegments $job) => $job->segments === [2] && $job->track === null);
});

it('packages ahead after the response on a sync queue', function () {
    Bus::fake();
    lookAheadOnQueue();
    config(['queue.default' => 'sync']);
    Media::fake(['video.mp4' => FakeProbe::video(duration: 13)]);
    $stream = Media::fromDisk('videos')->open('video.mp4')->stream();

    $stream->segmentResponse(0, 0);

    Bus::assertNotDispatched(PackageSegments::class);
    Media::assertRanTimes(Executable::FFMpeg, 1);

    app(DeferredCallbackCollection::class)->invoke();

    Media::assertRanTimes(Executable::FFMpeg, 3);
});

it('packages ahead after the response with the defer strategy', function () {
    Bus::fake();
    lookAheadOnQueue();
    Media::fake(['video.mp4' => FakeProbe::video(duration: 13)]);

    Media::fromDisk('videos')->open('video.mp4')->stream()->lookAhead(1, LookAheadStrategy::Defer)->segmentResponse(0, 0);
    app(DeferredCallbackCollection::class)->invoke();

    Bus::assertNotDispatched(PackageSegments::class);
    Media::assertRanTimes(Executable::FFMpeg, 2);
});

it('does not look ahead when it is turned off', function (array $config) {
    Bus::fake();
    lookAheadOnQueue();
    config($config);
    Media::fake(['video.mp4' => FakeProbe::video(duration: 13)]);

    Media::fromDisk('videos')->open('video.mp4')->stream()->segmentResponse(0, 0);
    app(DeferredCallbackCollection::class)->invoke();

    Bus::assertNotDispatched(PackageSegments::class);
    Media::assertRanTimes(Executable::FFMpeg, 1);
})->with([
    'no segments' => [['media.delivery.look_ahead' => 0]],
    'no strategy' => [['media.delivery.look_ahead_via' => null]],
]);

it('queues the first segments of every dash track', function () {
    Bus::fake();
    lookAheadOnQueue();
    Media::fake([
        '1080.mp4' => FakeProbe::video(duration: 13, width: 1920, height: 1080),
        '720.mp4' => FakeProbe::video(duration: 13, width: 1280, height: 720, audio: false),
    ]);

    Media::fromDisk('videos')->open(['1080.mp4', '720.mp4'])->stream()->packageStart();

    Bus::assertDispatchedTimes(PackageSegments::class, 3);
    Bus::assertDispatched(PackageSegments::class, fn (PackageSegments $job) => $job->path === '1080.mp4' && $job->track === Track::Video && $job->segments === [0, 1]);
    Bus::assertDispatched(PackageSegments::class, fn (PackageSegments $job) => $job->path === '720.mp4' && $job->track === Track::Video);
    Bus::assertDispatched(PackageSegments::class, fn (PackageSegments $job) => $job->path === '1080.mp4' && $job->track === Track::Audio);
});

it('picks the variants that give the video tracks and the shared audio track', function () {
    Media::fake([
        '1080.mp4' => FakeProbe::video(duration: 13),
        'dub.mp4' => FakeProbe::video(duration: 13, width: 640, height: 360),
    ]);
    $stream = Media::fromDisk('videos')->open(['1080.mp4', 'dub.mp4'])->stream()->fragmented()->tracksFrom([0], 1);

    $playlist = $stream->masterPlaylist(fn (int $variant, ?Track $track) => "{$variant}/{$track?->value}.m3u8");

    expect($playlist)->toContain('URI="1/audio.m3u8"', "\n0/video.m3u8")
        ->not->toContain('1/video.m3u8');
});

it('returns the bytes of segments and initialization segments, encrypted when the stream is', function () {
    Media::fake(['video.mp4' => FakeProbe::video(duration: 13)]);
    $key = EncryptionKey::generate();
    $stream = Media::fromDisk('videos')->open('video.mp4')->stream()->lookAhead(0);
    $samples = ['sample'];
    $fragments = cacheFragmentedTrack($stream, 1, Track::Audio, 'mp4a', $samples);
    $plain = Storage::disk('segments')->get($stream->segment(0, 0));

    expect($stream->segmentContents(0, 1, Track::Audio))->toBe($fragments['media'])
        ->and($stream->initSegmentContents(0, Track::Audio))->toBe($fragments['init'])
        ->and($stream->segmentContents(0, 0))->toBe($plain);

    $stream->withEncryption($key, fn () => 'key');

    expect(decryptCenc($stream->segmentContents(0, 1, Track::Audio), $key)['samples'])->toBe($samples)
        ->and($stream->initSegmentContents(0, Track::Audio))->toBe(CommonEncryption::init($fragments['init'], $key))
        ->and(openssl_decrypt($stream->segmentContents(0, 0), 'aes-128-cbc', $key->binary(), OPENSSL_RAW_DATA, str_repeat("\0", 16)))->toBe($plain);
});

it('returns subtitle contents with or without a timestamp map', function () {
    Media::fake(['video.mp4' => FakeProbe::video(duration: 13)]);
    Storage::disk('videos')->put('nld.vtt', "WEBVTT\n\n00:00:01.000 --> 00:00:02.000\nHallo\n");
    $stream = Media::fromDisk('videos')->open('video.mp4')->stream()->withSubtitles('nld.vtt', 'nld');

    expect($stream->subtitleContents(0))->toBe("WEBVTT\n\n00:00:01.000 --> 00:00:02.000\nHallo\n")
        ->and($stream->subtitleContents(0, 10))->toStartWith("WEBVTT\nX-TIMESTAMP-MAP=MPEGTS:900000,LOCAL:00:00:00.000\n");
});

it('lists the renditions encoded on request below the source', function () {
    Media::fake(['1080.mp4' => FakeProbe::video(width: 1920, height: 1080)]);

    $stream = Media::fromDisk('videos')->open('1080.mp4')->stream()->fragmented()->withTrickPlay()
        ->withRenditions(new Ladder([new Rendition(1080, 5000), new Rendition(720, 2800), new Rendition(480, 1400)]));

    $playlist = $stream->masterPlaylist(fn (int $variant, ?Track $track) => "{$variant}/{$track?->value}.m3u8");

    expect(array_map(fn ($rendition) => $rendition->rendition->height, $stream->renditions()))->toBe([720, 480])
        ->and($playlist)->toContain(
            "#EXT-X-STREAM-INF:BANDWIDTH=2996000,RESOLUTION=1280x720,FRAME-RATE=30.000,CODECS=\"avc1.64002a,mp4a.40.2\",AUDIO=\"audio\"\n1/video.m3u8",
            "RESOLUTION=854x480,FRAME-RATE=30.000,CODECS=\"avc1.64002a,mp4a.40.2\",AUDIO=\"audio\"\n2/video.m3u8",
        )
        ->and(substr_count($playlist, '#EXT-X-I-FRAME-STREAM-INF'))->toBe(1)
        ->and($stream->mediaPlaylist(1, fn (Segment $segment, int $variant) => "{$variant}/{$segment->index}.m4s", Track::Video, fn () => 'init.mp4'))->toContain('1/0.m4s');
});

it('adds the renditions encoded on request to the dash manifest', function () {
    Media::fake(['1080.mp4' => FakeProbe::video(width: 1920, height: 1080, duration: 12)]);

    $manifest = Media::fromDisk('videos')->open('1080.mp4')->stream()
        ->withRenditions(new Ladder([new Rendition(720, 2800)]))
        ->dashManifest(fn (int $variant, Track $track) => "{$variant}/init.mp4", fn (Segment $segment, int $variant) => "{$variant}/{$segment->index}.m4s");

    expect($manifest)->toContain('<Representation id="video-1" codecs="avc1.64002a" bandwidth="2996000" width="1280" height="720"', '1/init.mp4', '1/1.m4s');
});

it('encodes a segment of a rendition on its first request and caches it apart from the source', function () {
    Media::fake(['video.mp4' => FakeProbe::video(duration: 13)]);
    $stream = Media::fromDisk('videos')->open('video.mp4')->stream()->withRenditions(new Ladder([new Rendition(720, 2800)]));

    $path = $stream->segment(1, 1, Track::Video);

    expect($path)->toMatch('#^media-segments/[0-9a-f]{32}/6/renditions/720p-2800-[0-9a-f]{8}/video/1\.m4s$#')
        ->and($stream->initSegment(1, Track::Video))->toBe(dirname($path).'/init.mp4')
        ->and($stream->segment(0, 1, Track::Video))->not->toBe($path);
    Media::assertRan(Executable::FFMpeg, fn (array $arguments) => in_array('scale=-2:720', $arguments, true)
        && in_array('libx264', $arguments, true)
        && in_array('5.999', $arguments, true)
        && in_array('-copyts', $arguments, true)
        && ! in_array('copy', $arguments, true));
});

it('offers only the video track of a rendition', function (?Track $track) {
    Media::fake(['video.mp4' => FakeProbe::video(duration: 13)]);

    Media::fromDisk('videos')->open('video.mp4')->stream()->withRenditions(new Ladder([new Rendition(720, 2800)]))->segment(1, 0, $track);
})->with([
    'mpeg-ts' => [null],
    'audio' => [Track::Audio],
    'i-frames' => [Track::IFrames],
])->throws(SegmentNotFoundException::class);

it('encodes renditions on request as h264 only', function () {
    Media::fromDisk('videos')->open('video.mp4')->stream()->withRenditions(Ladder::standard()->codec(VideoCodec::Hevc));
})->throws(InvalidArgumentException::class, 'Renditions encoded on request are H.264.');

it('has no renditions without a ladder or a video', function () {
    Media::fake(['song.m4a' => FakeProbe::audio(), 'video.mp4' => FakeProbe::video()]);

    expect(Media::fromDisk('videos')->open('video.mp4')->stream()->renditions())->toBe([])
        ->and(Media::fromDisk('videos')->open('song.m4a')->stream()->withRenditions(Ladder::standard())->renditions())->toBe([])
        ->and(Media::fromDisk('videos')->open('video.mp4')->stream()->withRenditions(Ladder::standard())->withRenditions(null)->renditions())->toBe([]);
});

it('queues the next segments of a rendition with its ladder', function () {
    Bus::fake();
    lookAheadOnQueue();
    Media::fake(['video.mp4' => FakeProbe::video(duration: 19)]);
    $ladder = new Ladder([new Rendition(720, 2800), new Rendition(480, 1400)]);
    $stream = Media::fromDisk('videos')->open('video.mp4')->stream()->withRenditions($ladder);

    $stream->lookAhead(1)->segmentResponse(2, 0, Track::Video);

    Bus::assertDispatched(PackageSegments::class, fn (PackageSegments $job) => $job->segments === [1]
        && $job->renditions === $ladder
        && $job->variant === 2);
});
