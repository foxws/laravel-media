<?php

declare(strict_types=1);

use Foxws\Media\Executables\Executable;
use Foxws\Media\Filesystem\Disk;
use Foxws\Media\Filesystem\Media;
use Foxws\Media\Filesystem\TemporaryDirectories;
use Foxws\Media\Packaging\Drivers\Shaka\ShakaPackager;
use Foxws\Media\Packaging\HlsPlaylistType;
use Foxws\Media\Packaging\PackagerManager;
use Foxws\Media\Packaging\PackagingSpec;
use Foxws\Media\Packaging\PackagingStream;
use Foxws\Media\Packaging\StreamType;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

function shakaMedia(string $path): Media
{
    Storage::disk('videos')->put($path, 'video');

    return new Media(Disk::make('videos'), $path, app(TemporaryDirectories::class));
}

function shaka(): ShakaPackager
{
    return app(PackagerManager::class)->driver('shaka');
}

it('describes each stream and the manifests as shaka packager arguments', function () {
    Storage::fake('videos');
    $media = shakaMedia('video.mp4');

    $spec = new PackagingSpec(
        streams: [
            new PackagingStream(StreamType::Video, $media, '0_video.mp4'),
            new PackagingStream(StreamType::Audio, $media, '0_audio.mp4', language: 'eng'),
            new PackagingStream(StreamType::Text, $media, 'captions/nld.mp4', language: 'nld', options: ['dash_roles' => 'subtitle']),
        ],
        dashManifest: 'manifest.mpd',
        hlsPlaylist: 'master.m3u8',
        hlsPlaylistType: HlsPlaylistType::Vod,
        segmentDuration: 6,
        fragmentDuration: 2,
        defaultLanguage: 'eng',
        defaultTextLanguage: 'nld',
        allowCodecSwitching: true,
        approximateSegmentTimeline: true,
        options: ['hls_base_url' => 'https://cdn.test/', 'generate_static_live_mpd' => false],
    );

    expect(shaka()->arguments($spec, '/tmp/out'))->toBe([
        'in=video.mp4,stream=video,output=/tmp/out/0_video.mp4',
        'in=video.mp4,stream=audio,output=/tmp/out/0_audio.mp4,language=eng',
        'in=video.mp4,stream=text,output=/tmp/out/captions/nld.mp4,language=nld,dash_roles=subtitle',
        '--mpd_output=/tmp/out/manifest.mpd',
        '--hls_master_playlist_output=/tmp/out/master.m3u8',
        '--hls_playlist_type=VOD',
        '--segment_duration=6',
        '--fragment_duration=2',
        '--default_language=eng',
        '--default_text_language=nld',
        '--allow_codec_switching',
        '--allow_approximate_segment_timeline',
        '--hls_base_url=https://cdn.test/',
    ]);
});

it('reads local copies of the inputs when packaging, linking unsafe names under a plain one', function () {
    Storage::fake('videos');
    $safe = shakaMedia('safe.mp4');
    $unsafe = shakaMedia('my video, part 1.mp4');
    $inputs = app(TemporaryDirectories::class)->createCache();

    $arguments = shaka()->arguments(new PackagingSpec([
        new PackagingStream(StreamType::Video, $safe, 'a.mp4'),
        new PackagingStream(StreamType::Video, $unsafe, 'b.mp4'),
    ]), '/tmp/out', $inputs);

    expect($arguments[0])->toStartWith('in='.diskPath('videos', 'safe.mp4').',')
        ->and($arguments[1])->toStartWith('in='.$inputs->path('input-1.mp4').',')
        ->and(file_get_contents($inputs->path('input-1.mp4')))->toBe('video');
});

it('keeps descriptor fields from being split or read as options', function () {
    Storage::fake('videos');

    $arguments = shaka()->arguments(new PackagingSpec([
        new PackagingStream(StreamType::Audio, shakaMedia('video.mp4'), 'audio.mp4', options: ['hls_name' => 'English, “Original”', 'dash_label' => '-label']),
    ]), '/tmp/out');

    expect($arguments[0])->toEndWith(',hls_name=English- "Original,dash_label=./-label');
});

it('rejects option names that could inject arguments', function () {
    Storage::fake('videos');

    shaka()->arguments(new PackagingSpec([new PackagingStream(StreamType::Video, shakaMedia('video.mp4'), 'video.mp4')], options: ['mpd_output=/etc/passwd --x' => true]), '/tmp/out');
})->throws(InvalidArgumentException::class, 'Invalid Shaka Packager option or field name');

it('rejects fragments longer than segments', function () {
    Storage::fake('videos');

    shaka()->arguments(new PackagingSpec([new PackagingStream(StreamType::Video, shakaMedia('video.mp4'), 'video.mp4')], segmentDuration: 2, fragmentDuration: 4), '/tmp/out');
})->throws(InvalidArgumentException::class, "The fragment duration (4s) can't be longer than the segment duration (2s).");

it('runs shaka packager and removes the linked inputs afterwards', function () {
    $packager = fakeExecutable(Executable::Packager);
    Storage::fake('videos');
    fakePackaging();
    $output = app(TemporaryDirectories::class)->create();

    shaka()->package(new PackagingSpec([new PackagingStream(StreamType::Video, shakaMedia('a b.mp4'), 'video.mp4')], hlsPlaylist: 'master.m3u8'), $output, timeout: 300);

    expect($output->path('video.mp4'))->toBeFile()
        ->and($output->path('master.m3u8'))->toBeFile();
    Process::assertRan(fn ($process) => $process->command[0] === $packager
        && $process->timeout === 300
        && ! file_exists(substr(explode(',', $process->command[1])[0], 3)));
});
