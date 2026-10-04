<?php

declare(strict_types=1);

use Foxws\Media\Delivery\Track;
use Foxws\Media\Encryption\EncryptionKey;
use Foxws\Media\Encryption\ProtectionScheme;
use Foxws\Media\Executables\Executable;
use Foxws\Media\Facades\Media;
use Foxws\Media\Packaging\HlsPlaylistType;
use Foxws\Media\Testing\FakeProbe;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('videos');
    Storage::fake('segments');
    Storage::fake('streams');
    config(['media.delivery.cache_disk' => 'segments']);
});

it('writes the segments of every stream with hls playlists and a dash manifest that link them relatively', function () {
    Media::fake([
        '1080.mp4' => FakeProbe::video(duration: 13),
        '720.mp4' => FakeProbe::video(duration: 13, width: 1280, height: 720, audio: false),
    ]);

    $result = Media::fromDisk('videos')->open(['1080.mp4', '720.mp4'])
        ->exportAsStreams()
        ->using('native')
        ->toDisk('streams')
        ->save('videos/1');

    expect($result->paths())->toContain('videos/1/master.m3u8', 'videos/1/manifest.mpd', 'videos/1/0_video/init.mp4', 'videos/1/0_video/2.m4s', 'videos/1/1_video/0.m4s', 'videos/1/0_audio/1.m4s', 'videos/1/0_audio.m3u8')
        ->and($result->path())->toBe('videos/1/master.m3u8');

    $streams = Storage::disk('streams');
    expect($streams->get('videos/1/master.m3u8'))->toContain('URI="0_audio.m3u8"', "\n0_video.m3u8\n", "\n1_video.m3u8\n")
        ->and($streams->get('videos/1/0_video.m3u8'))->toContain('#EXT-X-MAP:URI="0_video/init.mp4"', "\n0_video/0.m4s\n", '#EXT-X-ENDLIST')
        ->and($streams->get('videos/1/manifest.mpd'))->toContain('sourceURL="1_video/init.mp4"', 'media="0_audio/2.m4s"')
        ->and($streams->get('videos/1/0_video/init.mp4'))->toStartWith(pack('N', 12).'ftyp');
});

it('reuses segments already cut for direct play', function () {
    $fake = Media::fake(['video.mp4' => FakeProbe::video(duration: 13)]);
    $export = fn () => Media::fromDisk('videos')->open('video.mp4')->exportAsDASH()->using('native')->toDisk('streams')->save('a');

    $export();
    $runs = count($fake->commands(Executable::FFMpeg));
    $export();

    expect($runs)->toBe(6)
        ->and($fake->commands(Executable::FFMpeg))->toHaveCount(6);
});

it('links outputs in other directories relative to each playlist', function () {
    Media::fake(['video.mp4' => FakeProbe::video(duration: 13)]);

    Media::fromDisk('videos')->open('video.mp4')->package()
        ->addVideoStream(output: 'video/main.mp4')
        ->withHlsPlaylist('hls/master.m3u8')
        ->using('native')
        ->toDisk('streams')
        ->save();

    expect(Storage::disk('streams')->get('hls/master.m3u8'))->toContain("\n../video/main.m3u8\n")
        ->and(Storage::disk('streams')->get('video/main.m3u8'))->toContain('#EXT-X-MAP:URI="main/init.mp4"', "\nmain/0.m4s\n");
    Storage::disk('streams')->assertExists(['video/main/init.mp4', 'video/main/1.m4s']);
});

it('encrypts the segments with the key and points hls playlists at the key file', function () {
    Media::fake(['video.mp4' => FakeProbe::video(duration: 13)]);
    $key = EncryptionKey::generate();
    $direct = Media::fromDisk('videos')->open('video.mp4')->stream()->lookAhead(0);
    $samples = ['audio sample'];
    $fragments = fragmentedTrack('mp4a', $samples);

    foreach ([0, 1, 2] as $index) {
        $path = $direct->segment(0, $index, Track::Audio);
        Storage::disk('segments')->put($path, $fragments['media']);
        Storage::disk('segments')->put(dirname($path).'/init.mp4', $fragments['init']);
    }

    Media::fromDisk('videos')->open('video.mp4')->package()
        ->addAudioStream(output: 'audio.mp4')
        ->withHlsPlaylist()
        ->withDashManifest()
        ->withEncryption($key)
        ->using('native')
        ->toDisk('streams')
        ->save();

    $streams = Storage::disk('streams');
    expect($streams->get('key'))->toBe($key->binary())
        ->and($streams->get('audio.m3u8'))->toContain('#EXT-X-KEY:METHOD=SAMPLE-AES-CTR,URI="key"')
        ->and($streams->get('audio/init.mp4'))->toContain('enca')
        ->and(decryptCenc((string) $streams->get('audio/1.m4s'), $key)['samples'])->toBe($samples)
        ->and($streams->get('manifest.mpd'))->toContain('cenc:default_KID="'.$key->keyIdUuid().'"')->not->toContain('Laurl');
});

it('writes subtitles for hls with the segment timestamps and for dash as they are', function () {
    Media::fake(['video.mp4' => FakeProbe::video(duration: 13)]);
    Storage::disk('videos')->put('captions/nld.vtt', "WEBVTT\n\n00:00:01.000 --> 00:00:02.000\nHallo\n");

    Media::fromDisk('videos')->open('video.mp4')->exportAsStreams()
        ->addTextStream('captions/nld.vtt', 'nld.mp4', 'nld')
        ->using('native')
        ->toDisk('streams')
        ->save();

    $streams = Storage::disk('streams');
    expect($streams->get('master.m3u8'))->toContain('TYPE=SUBTITLES', 'LANGUAGE="nld"', 'URI="nld.m3u8"')
        ->and($streams->get('nld.m3u8'))->toContain("\nnld-hls.vtt\n")
        ->and($streams->get('nld-hls.vtt'))->toContain('X-TIMESTAMP-MAP=MPEGTS:900000,LOCAL:00:00:00.000')
        ->and($streams->get('nld.vtt'))->not->toContain('X-TIMESTAMP-MAP')
        ->and($streams->get('manifest.mpd'))->toContain('<BaseURL>nld.vtt</BaseURL>');
});

it('describes the segmenting instead of one command line', function () {
    Media::fake();

    expect(Media::fromDisk('videos')->open('video.mp4')->exportAsHLS()->using('native')->command())
        ->toBe('native: ffmpeg -c copy per segment of video video.mp4, audio video.mp4 into output');
});

it('refuses what only shaka packager supports', function (Closure $configure, string $unsupported) {
    Media::fake(['video.mp4' => FakeProbe::video(duration: 13)]);
    Storage::disk('videos')->put('other.mp4', 'video');

    $builder = $configure(Media::fromDisk('videos')->open('video.mp4')->exportAsHLS()->using('native'))->toDisk('streams');

    expect(fn () => $builder->save())->toThrow(InvalidArgumentException::class, "The native packager does not support {$unsupported}");
})->with([
    'driver options' => [fn ($builder) => $builder->withOption('low_latency_dash_mode'), 'driver options'],
    'live playlists' => [fn ($builder) => $builder->withHlsPlaylist('live.m3u8', HlsPlaylistType::Live), 'live playlists'],
    'cbcs' => [fn ($builder) => $builder->withEncryption(scheme: ProtectionScheme::Cbcs), 'protection schemes'],
    'key rotation' => [fn ($builder) => $builder->withEncryption()->withKeyRotation(60), 'key rotation'],
    'two audio streams' => [fn ($builder) => $builder->addAudioStream('other.mp4'), 'more than one audio stream'],
]);
