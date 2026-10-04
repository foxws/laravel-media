<?php

declare(strict_types=1);

use Foxws\Media\Delivery\LookAheadStrategy;
use Foxws\Media\Delivery\Marker;
use Foxws\Media\Delivery\PackageSegments;
use Foxws\Media\Delivery\Segment;
use Foxws\Media\Delivery\Subtitle;
use Foxws\Media\Delivery\Track;
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

it('serves encrypted streams with mpeg-ts segments only', function () {
    Media::fake(['video.mp4' => FakeProbe::video(duration: 13)]);
    $stream = Media::fromDisk('videos')->open('video.mp4')->stream()->withEncryption(EncryptionKey::generate(), fn () => 'key');

    expect(fn () => $stream->dashManifest(fn () => 'init', fn () => 'segment'))->toThrow(InvalidArgumentException::class, 'Encrypted direct streams use MPEG-TS segments.')
        ->and(fn () => $stream->fragmented()->masterPlaylist(fn () => 'playlist'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $stream->segmentResponse(0, 0, Track::Video))->toThrow(InvalidArgumentException::class);
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
