<?php

declare(strict_types=1);

use Foxws\Media\Encoding\HardwareAcceleration;
use Foxws\Media\Encoding\Ladder;
use Foxws\Media\Encoding\Rendition;
use Foxws\Media\Encoding\VideoCodec;
use Foxws\Media\Probe\Probe;
use Foxws\Media\Probe\VideoStream;
use Foxws\Media\Testing\FakeProbe;

function sourceVideo(int $width, int $height): VideoStream
{
    return Probe::fromArray(FakeProbe::video(width: $width, height: $height))->videoStream();
}

it('skips renditions larger than the source', function () {
    $heights = fn (int $width, int $height) => array_map(fn (Rendition $rendition) => $rendition->height, Ladder::standard()->for(sourceVideo($width, $height)));

    expect($heights(1920, 1080))->toBe([1080, 720, 480, 360])
        ->and($heights(1280, 720))->toBe([720, 480, 360])
        ->and($heights(1080, 1920))->toBe([1080, 720, 480, 360])
        ->and($heights(854, 480))->toBe([480, 360]);
});

it('encodes a source smaller than every rendition at its own size', function () {
    expect(Ladder::standard()->for(sourceVideo(426, 241)))->toEqual([new Rendition(240, 800)]);
});

it('scales the short side of landscape and portrait video', function () {
    $rendition = new Rendition(720, 2800);

    expect((string) Ladder::standard()->scale($rendition, sourceVideo(1920, 1080)))->toBe('scale=-2:720')
        ->and((string) Ladder::standard()->scale($rendition, sourceVideo(1080, 1920)))->toBe('scale=720:-2');
});

it('encodes h264 at the rendition bitrates with keyframes at fixed times', function () {
    expect(Ladder::standard()->format(new Rendition(720, 2800))->toArguments())->toBe([
        '-c:v', 'libx264', '-preset', 'medium', '-b:v', '2800k', '-maxrate', '2996k', '-bufsize', '5600k',
        '-c:a', 'aac', '-b:a', '128k',
        '-pix_fmt', 'yuv420p', '-sc_threshold', '0',
        '-force_key_frames', 'expr:gte(t,n_forced*6)', '-movflags', '+faststart',
        '-f', 'mp4',
    ]);
});

it('places keyframes only at the given seconds, a millisecond early', function () {
    $ladder = Ladder::standard()->keyframesAt([12.0, 0.0, 6.006, 12.0]);

    expect($ladder->keyframes)->toBe([0.0, 6.006, 12.0])
        ->and($ladder->format(new Rendition(720, 2800))->toArguments())
        ->toContain('-force_key_frames', '0,6.005,11.999', '-g', '65535')
        ->not->toContain('expr:gte(t,n_forced*6)');
});

it('aligns to the source with the keyframe interval as the shortest segment', function () {
    config(['media.delivery.segment_duration' => 4.0]);

    expect(Ladder::standard()->alignToSource)->toBeFalse()
        ->and(Ladder::standard()->alignToSource()->alignToSource)->toBeTrue()
        ->and(Ladder::standard()->interval())->toBe(4.0)
        ->and(Ladder::standard()->keyframeInterval(10)->interval())->toBe(10.0);
});

it('turns scene-cut keyframes off for hevc and av1', function () {
    $hevc = Ladder::standard()->codec(VideoCodec::Hevc, 'slow')->keyframeInterval(4)->format(new Rendition(720, 2800))->toArguments();
    $av1 = Ladder::standard()->codec(VideoCodec::Av1)->audioBitrate(96)->format(new Rendition(720, 2800))->toArguments();

    expect($hevc)->toContain('libx265', 'slow', 'hvc1', 'scenecut=0', 'expr:gte(t,n_forced*4)')
        ->and($av1)->toContain('libsvtav1', 'scd=0', '96k')
        ->and($av1)->not->toContain('-maxrate')
        ->and(array_slice($av1, 0, 6))->toBe(['-c:v', 'libsvtav1', '-preset', '8', '-b:v', '2800k']);
});

it('encodes on the gpu with the hardware encoder', function () {
    $arguments = Ladder::standard()->codec(VideoCodec::Hevc)->hardware(HardwareAcceleration::Vaapi)->format(new Rendition(720, 2800))->toArguments();

    expect($arguments)->toBe([
        '-b:v', '2800k', '-maxrate', '2996k', '-bufsize', '5600k',
        '-c:a', 'aac', '-b:a', '128k',
        '-c:v', 'hevc_vaapi', '-tag:v', 'hvc1',
        '-force_key_frames', 'expr:gte(t,n_forced*6)', '-movflags', '+faststart',
        '-f', 'mp4',
    ]);
});

it('decodes on the cpu and uploads the frames when the gpu cannot decode the source', function () {
    $source = Probe::fromArray(FakeProbe::video())->videoStream();
    $ladder = Ladder::standard()->hardware(HardwareAcceleration::Vaapi);

    expect($ladder->hardwareDecoding)->toBeTrue()
        ->and($ladder->inputArguments())->toContain('-hwaccel')
        ->and((string) $ladder->scale(new Rendition(720, 2800), $source))->toBe('scale_vaapi=w=-2:h=720:format=nv12')
        ->and($ladder->hardwareDecoding(false)->inputArguments())->toBe(['-vaapi_device', '/dev/dri/renderD128'])
        ->and((string) $ladder->hardwareDecoding(false)->scale(new Rendition(720, 2800), $source))->toBe('format=nv12,hwupload,scale_vaapi=w=-2:h=720:format=nv12');
});

it('uses the configured acceleration unless the ladder sets one', function () {
    config(['media.ladder.hardware' => 'nvenc']);

    expect(Ladder::standard()->acceleration())->toBe(HardwareAcceleration::Nvenc)
        ->and(Ladder::standard()->hardware(HardwareAcceleration::None)->acceleration())->toBe(HardwareAcceleration::None);
});

it('refuses ladders it cannot encode', function (Closure $make, string $message) {
    expect($make)->toThrow(InvalidArgumentException::class, $message);
})->with([
    'no renditions' => [fn () => new Ladder([]), 'at least one rendition'],
    'vp9' => [fn () => Ladder::standard()->codec(VideoCodec::Vp9), 'not [libvpx-vp9]'],
    'keyframe interval' => [fn () => Ladder::standard()->keyframeInterval(0), 'positive number of seconds'],
]);
