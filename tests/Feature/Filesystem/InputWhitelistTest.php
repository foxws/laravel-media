<?php

declare(strict_types=1);

use Foxws\Media\Delivery\Track;
use Foxws\Media\Encoding\Format;
use Foxws\Media\Executables\Executable;
use Foxws\Media\Facades\Media;
use Foxws\Media\FFMpeg\Clip;
use Foxws\Media\Filesystem\Disk;
use Foxws\Media\Opener;
use Foxws\Media\Testing\FakeProbe;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('videos');
    Storage::fake('segments');

    config([
        'media.inputs' => (require __DIR__.'/../../../config/media.php')['inputs'],
        'media.delivery.cache_disk' => 'segments',
        'media.delivery.look_ahead' => 0,
    ]);
});

it('limits the formats and protocols of every input', function (Closure $run) {
    $fake = Media::fake(['*' => FakeProbe::video(subtitles: ['eng'])]);

    $run(Media::fromDisk('videos')->open('video.mkv'));

    expect($fake->commands())->not->toBeEmpty();

    foreach ($fake->commands(Executable::FFMpeg) as $arguments) {
        $inputs = array_count_values($arguments);

        expect($inputs['-format_whitelist'] ?? 0)->toBe($inputs['-i'])
            ->and($inputs['-protocol_whitelist'] ?? 0)->toBe($inputs['-i']);
    }

    foreach ($fake->commands(Executable::FFProbe) as $arguments) {
        expect($arguments)->toContain('-format_whitelist', '-protocol_whitelist', 'file');
    }
})->with([
    'probe' => [fn (Opener $media) => $media->probe()],
    'keyframes' => [fn (Opener $media) => $media->keyframes()],
    'scenes' => [fn (Opener $media) => $media->scenes()],
    'encode' => [fn (Opener $media) => $media->ffmpeg()->inFormat(Format::h264())->save('out.mp4')],
    'watermark' => [fn (Opener $media) => $media->ffmpeg()->watermark('logo.png')->save('out.mp4')],
    'reel' => [fn (Opener $media) => $media->ffmpeg()->clips([Clip::make(0, 2), Clip::make(4, 6)])->save('out.mp4')],
    'thumbnails' => [fn (Opener $media) => $media->thumbnails()->save('storyboard')],
    'mpeg-ts segment' => [fn (Opener $media) => $media->stream()->segment(0, 0)],
    'fragmented segment' => [fn (Opener $media) => $media->stream()->segment(0, 0, Track::Video)],
    'embedded subtitle' => [fn (Opener $media) => $media->stream()->withEmbeddedSubtitles()->subtitle(0)],
]);

it('allows no format that opens other files or urls', function (string $format) {
    $whitelist = app(Opener::class)->makeMedia(Disk::make('videos'), 'video.mp4')->formatWhitelist();

    expect(explode(',', (string) $whitelist))
        ->toContain('mov', 'matroska', 'webm', 'mpegts')
        ->not->toContain($format);
})->with(['hls', 'dash', 'concat', 'imf', 'sdp', 'rtsp', 'image2pipe', 'svg_pipe', 'lavfi', 'tty', 'vobsub', 'webm_dash_manifest']);

it('reads remote inputs over http only', function () {
    $media = app(Opener::class)->makeMedia(Disk::make(remoteDisk(Storage::disk('videos')->path(''))), 'video.mp4');

    expect($media->inputArguments())->toContain('http,https,httpproxy,tls,tcp')->not->toContain('file');
});

it('does not read a file name with a percent sign as an image pattern', function () {
    $media = app(Opener::class)->makeMedia(Disk::make('videos'), 'image%03d.png');

    expect(explode(',', (string) $media->formatWhitelist()))->not->toContain('image2')->toContain('png_pipe');
});

it('allows concat for its own list only, and limits each file in it', function () {
    Media::fake();

    $builder = Media::fromDisk('videos')->open(['a.mp4', 'b.mp4'])->ffmpeg()->concat();
    $arguments = $builder->arguments('out.mp4');

    expect($arguments[array_search('-format_whitelist', $arguments, true) + 1])->toStartWith('concat,mov,');
});
