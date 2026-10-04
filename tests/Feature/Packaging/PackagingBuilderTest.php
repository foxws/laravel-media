<?php

declare(strict_types=1);

use Foxws\Media\Encryption\EncryptionKey;
use Foxws\Media\Encryption\ProtectionScheme;
use Foxws\Media\Events\ExportCompleted;
use Foxws\Media\Events\ExportFailed;
use Foxws\Media\Exceptions\InvalidMediaException;
use Foxws\Media\Executables\Executable;
use Foxws\Media\Facades\Media;
use Foxws\Media\Packaging\Drivers\Shaka\ShakaOptions;
use Foxws\Media\Packaging\HlsPlaylistType;
use Foxws\Media\Packaging\StreamType;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

it('packages the probed streams of every file into hls and dash', function () {
    fakePackaging([
        '1080.mp4' => videoProbe(1920, 1080),
        '720.mp4' => videoProbe(1280, 720, audio: false),
    ]);
    Storage::fake('renditions');
    Storage::fake('streams');

    $result = Media::fromDisk('renditions')->open(['1080.mp4', '720.mp4'])
        ->exportAsStreams()
        ->toDisk('streams')
        ->save('videos/1');

    expect($result->path())->toBe('videos/1/master.m3u8')
        ->and($result->paths())->toBe(['videos/1/master.m3u8', 'videos/1/manifest.mpd', 'videos/1/0_audio.mp4', 'videos/1/0_video.mp4', 'videos/1/1_video.mp4']);
    Storage::disk('streams')->assertExists(['videos/1/master.m3u8', 'videos/1/0_video.mp4', 'videos/1/1_video.mp4']);
    Process::assertRan(fn ($process) => runs($process, Executable::Packager)
        && count(array_filter($process->command, fn (string $argument) => str_starts_with($argument, 'in='))) === 3
        && in_array('--allow_codec_switching', $process->command, true)
        && in_array('--hls_playlist_type=VOD', $process->command, true));
});

it('adds streams by hand, including subtitles from the same disk', function () {
    fakePackaging();
    Storage::fake('videos');
    Storage::disk('videos')->put('captions/nld.vtt', 'WEBVTT');

    $spec = Media::fromDisk('videos')->open('video.mp4')->package()
        ->addVideoStream()
        ->addAudioStream(language: 'eng')
        ->addTextStream('captions/nld.vtt', 'nld.mp4', 'nld', ['dash_roles' => 'subtitle'])
        ->withHlsPlaylist('index.m3u8', HlsPlaylistType::Event)
        ->segmentDuration(4)
        ->defaultLanguage('eng')
        ->spec();

    expect(array_map(fn ($stream) => [$stream->type, $stream->media->path(), $stream->output, $stream->language], $spec->streams))->toBe([
        [StreamType::Video, 'video.mp4', 'video_0.mp4', null],
        [StreamType::Audio, 'video.mp4', 'audio_0.mp4', 'eng'],
        [StreamType::Text, 'captions/nld.vtt', 'nld.mp4', 'nld'],
    ])
        ->and($spec)->hlsPlaylist->toBe('index.m3u8')->hlsPlaylistType->toBe(HlsPlaylistType::Event)->segmentDuration->toBe(4.0)->defaultLanguage->toBe('eng');
});

it('dispatches export events with the context and runs save callbacks', function () {
    fakePackaging();
    Storage::fake('videos');
    Event::fake([ExportCompleted::class]);
    $saved = null;

    Media::fromDisk('videos')->open('video.mp4')->exportAsHLS()
        ->withContext(['video_id' => 1])
        ->afterSaving(function ($builder, $result) use (&$saved) {
            $saved = $result->path();
        })
        ->save('streams');

    expect($saved)->toBe('streams/master.m3u8');
    Event::assertDispatched(ExportCompleted::class, fn (ExportCompleted $event) => $event->context === ['video_id' => 1]);
});

it('fails and dispatches an export failed event when the packager fails', function () {
    fakeExecutable(Executable::FFProbe);
    fakeExecutable(Executable::Packager);
    Storage::fake('videos');
    Process::fake(['*' => fn ($process) => runs($process, Executable::FFProbe)
        ? Process::result(output: (string) json_encode(videoProbe()))
        : Process::result(errorOutput: 'Unknown encoder', exitCode: 1)]);
    Event::fake([ExportFailed::class]);

    rescue(fn () => Media::fromDisk('videos')->open('video.mp4')->exportAsDASH()->save('streams'), report: false);

    Event::assertDispatched(ExportFailed::class);
    Storage::disk('videos')->assertMissing('streams/manifest.mpd');
});

it('needs at least one stream', function () {
    Storage::fake('videos');

    Media::fromDisk('videos')->open('video.mp4')->package()->withHlsPlaylist()->save();
})->throws(InvalidMediaException::class, 'Add at least one stream to package');

it('shows the packager command without running it', function () {
    $packager = fakeExecutable(Executable::Packager);
    Storage::fake('videos');

    $command = Media::fromDisk('videos')->open('video.mp4')->package()->addVideoStream()->withDashManifest()->command('out');

    expect($command)->toBe("{$packager} in=video.mp4,stream=video,output=out/video_0.mp4 --mpd_output=out/manifest.mpd");
});

it('encrypts the segments and saves the raw key next to them', function () {
    fakePackaging();
    Storage::fake('streams');
    $key = new EncryptionKey('0123456789abcdef0123456789abcdef', 'fedcba9876543210fedcba9876543210');

    $result = Media::fromDisk('streams')->open('video.mp4')->exportAsHLS()
        ->withEncryption($key, ProtectionScheme::Cbcs)
        ->save('videos/1');

    expect($result->encryptionKey())->toBe($key)
        ->and($result->paths())->toContain('videos/1/key')
        ->and(Storage::disk('streams')->get('videos/1/key'))->toBe(hex2bin('0123456789abcdef0123456789abcdef'));
    Process::assertRan(fn ($process) => runs($process, Executable::Packager)
        && in_array('--hls_key_uri=key', $process->command, true)
        && in_array('--protection_scheme=cbcs', $process->command, true));
});

it('leaves the key file out when the app serves the key itself', function () {
    fakePackaging();
    Storage::fake('streams');

    $builder = Media::fromDisk('streams')->open('video.mp4')->exportAsHLS()
        ->withEncryption(keyFile: null, keyUri: 'https://app.test/videos/1/key');

    $result = $builder->save('videos/1');

    expect($result->paths())->not->toContain('videos/1/key')
        ->and($result->encryptionKey())->toBe($builder->encryptionKey());
    Process::assertRan(fn ($process) => in_array('--hls_key_uri=https://app.test/videos/1/key', $process->command, true));
});

it('keeps key rotation and clear lead in any order and generates a key for them', function () {
    fakePackaging();
    Storage::fake('streams');
    $key = EncryptionKey::generate();

    $spec = Media::fromDisk('streams')->open('video.mp4')->package()->addVideoStream()
        ->withKeyRotation(300)
        ->withClearLead(3)
        ->withEncryption($key)
        ->spec();

    expect($spec->encryption)
        ->key->toBe($key)
        ->rotation->toBe(300)
        ->clearLead->toBe(3.0);

    expect(Media::fromDisk('streams')->open('video.mp4')->package()->withKeyRotation(60)->encryptionKey())->toBeInstanceOf(EncryptionKey::class)
        ->and(Media::fromDisk('streams')->open('video.mp4')->package()->encryptionKey())->toBeNull();
});

it('passes typed shaka options to the packager and redacts signing keys', function () {
    $packager = fakeExecutable(Executable::Packager);
    Storage::fake('videos');

    $command = Media::fromDisk('videos')->open('video.mp4')->package()->addVideoStream()->withDashManifest()
        ->withOptions(ShakaOptions::make()->lowLatencyDashMode()->timeShiftBufferDepth(60)->aesSigning('signer', 'aabbccdd', '00112233'))
        ->withOptions(['hls_base_url' => 'https://cdn.test/'])
        ->command('out');

    expect($command)->toBe("{$packager} in=video.mp4,stream=video,output=out/video_0.mp4 --mpd_output=out/manifest.mpd"
        .' --low_latency_dash_mode --time_shift_buffer_depth=60 --signer=signer --aes_signing_key=[REDACTED] --aes_signing_iv=[REDACTED] --hls_base_url=https://cdn.test/');
});
