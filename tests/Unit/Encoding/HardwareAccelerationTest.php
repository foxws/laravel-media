<?php

declare(strict_types=1);

use Foxws\Media\Encoding\HardwareAcceleration;
use Foxws\Media\Encoding\VideoCodec;
use Foxws\Media\Executables\Executable;
use Foxws\Media\Facades\Media;
use Foxws\Media\Testing\FakeProbe;

it('decodes on the gpu and keeps the frames there', function (HardwareAcceleration $hardware, array $arguments) {
    expect($hardware->inputArguments())->toBe($arguments);
})->with([
    'none' => [HardwareAcceleration::None, []],
    'vaapi' => [HardwareAcceleration::Vaapi, ['-hwaccel', 'vaapi', '-hwaccel_output_format', 'vaapi', '-vaapi_device', '/dev/dri/renderD128']],
    'nvenc' => [HardwareAcceleration::Nvenc, ['-hwaccel', 'cuda', '-hwaccel_output_format', 'cuda']],
    'qsv' => [HardwareAcceleration::Qsv, ['-init_hw_device', 'qsv=hw,child_device=/dev/dri/renderD128', '-hwaccel', 'qsv', '-hwaccel_device', 'hw', '-hwaccel_output_format', 'qsv']],
]);

it('reads the vaapi device and the configured acceleration from the config', function () {
    config(['media.ladder.vaapi_device' => '/dev/dri/renderD129', 'media.ladder.hardware' => 'vaapi']);

    expect(HardwareAcceleration::configured())->toBe(HardwareAcceleration::Vaapi)
        ->and(HardwareAcceleration::Vaapi->inputArguments())->toContain('/dev/dri/renderD129');

    config(['media.ladder.hardware' => 'unknown']);

    expect(HardwareAcceleration::configured())->toBe(HardwareAcceleration::None);
});

it('scales with the filter of each acceleration', function (HardwareAcceleration $hardware, string $filter) {
    expect((string) $hardware->scale(-2, 720))->toBe($filter);
})->with([
    'none' => [HardwareAcceleration::None, 'scale=-2:720'],
    'vaapi' => [HardwareAcceleration::Vaapi, 'scale_vaapi=w=-2:h=720:format=nv12'],
    'nvenc' => [HardwareAcceleration::Nvenc, 'scale_cuda=-2:720:format=yuv420p'],
    'qsv' => [HardwareAcceleration::Qsv, 'scale_qsv=w=-2:h=720:format=nv12'],
]);

it('uploads frames decoded on the cpu before scaling them on the gpu', function (HardwareAcceleration $hardware, string $filter) {
    expect((string) $hardware->scale(-2, 720, uploaded: true))->toBe($filter);
})->with([
    'none' => [HardwareAcceleration::None, 'scale=-2:720'],
    'vaapi' => [HardwareAcceleration::Vaapi, 'format=nv12,hwupload,scale_vaapi=w=-2:h=720:format=nv12'],
    'nvenc' => [HardwareAcceleration::Nvenc, 'format=nv12,hwupload_cuda,scale_cuda=-2:720:format=yuv420p'],
    'qsv' => [HardwareAcceleration::Qsv, 'format=nv12,hwupload=extra_hw_frames=64,scale_qsv=w=-2:h=720:format=nv12'],
]);

it('names the encoder of each codec', function () {
    expect(HardwareAcceleration::None->encoder(VideoCodec::H264))->toBe('libx264')
        ->and(HardwareAcceleration::Vaapi->encoder(VideoCodec::Hevc))->toBe('hevc_vaapi')
        ->and(HardwareAcceleration::Nvenc->encoder(VideoCodec::Av1))->toBe('av1_nvenc')
        ->and(HardwareAcceleration::Qsv->encoder(VideoCodec::H264))->toBe('h264_qsv')
        ->and(fn () => HardwareAcceleration::Vaapi->encoder(VideoCodec::Vp9))->toThrow(InvalidArgumentException::class, '[libvpx-vp9] has no vaapi encoder');
});

it('uploads frames decoded on the cpu to the gpu', function (HardwareAcceleration $hardware, array $arguments, ?string $filter) {
    expect($hardware->uploadArguments())->toBe($arguments)
        ->and($hardware->upload() !== null ? (string) $hardware->upload() : null)->toBe($filter);
})->with([
    'none' => [HardwareAcceleration::None, [], null],
    'vaapi' => [HardwareAcceleration::Vaapi, ['-vaapi_device', '/dev/dri/renderD128'], 'format=nv12,hwupload'],
    'nvenc' => [HardwareAcceleration::Nvenc, [], null],
    'qsv' => [HardwareAcceleration::Qsv, ['-init_hw_device', 'qsv=hw,child_device=/dev/dri/renderD128', '-filter_hw_device', 'hw'], 'format=nv12,hwupload=extra_hw_frames=64'],
]);

it('sets constant quality in the terms of each encoder', function (HardwareAcceleration $hardware, array $arguments) {
    expect($hardware->quality(22))->toBe($arguments);
})->with([
    'none' => [HardwareAcceleration::None, ['-crf', '22']],
    'vaapi' => [HardwareAcceleration::Vaapi, ['-rc_mode', 'CQP', '-qp', '22']],
    'nvenc' => [HardwareAcceleration::Nvenc, ['-rc', 'vbr', '-cq', '22', '-b:v', '0']],
    'qsv' => [HardwareAcceleration::Qsv, ['-global_quality', '22']],
]);

it('opens the configured render device, for a second gpu too', function () {
    config(['media.ladder.vaapi_device' => '/dev/dri/renderD129']);

    expect(HardwareAcceleration::device())->toBe('/dev/dri/renderD129')
        ->and(HardwareAcceleration::Vaapi->uploadArguments())->toBe(['-vaapi_device', '/dev/dri/renderD129'])
        ->and(HardwareAcceleration::Qsv->uploadArguments())->toContain('qsv=hw,child_device=/dev/dri/renderD129');
});

it('encodes renditions on request with the delivery hardware, or the ladder hardware', function () {
    config(['media.ladder.hardware' => 'vaapi', 'media.delivery.hardware' => null]);

    expect(HardwareAcceleration::forDelivery())->toBe(HardwareAcceleration::Vaapi);

    config(['media.delivery.hardware' => 'nvenc']);

    expect(HardwareAcceleration::forDelivery())->toBe(HardwareAcceleration::Nvenc);

    config(['media.delivery.hardware' => 'unknown']);

    expect(HardwareAcceleration::forDelivery())->toBe(HardwareAcceleration::None);
});

it('uses a gpu whose device opens, and remembers the check', function () {
    Media::fake();

    expect(HardwareAcceleration::Vaapi->orCpu())->toBe(HardwareAcceleration::Vaapi)
        ->and(HardwareAcceleration::Vaapi->isAvailable())->toBeTrue();

    Media::assertRanTimes(Executable::FFMpeg, 1);
    Media::assertRan(Executable::FFMpeg, fn (array $arguments) => in_array('vaapi=hw:/dev/dri/renderD128', $arguments, true));
});

it('falls back to the cpu when the gpu cannot be opened', function (HardwareAcceleration $hardware, string $device) {
    Media::fake()->failNext(Executable::FFMpeg, 'No VA display found for device /dev/dri/renderD128.');

    expect($hardware->orCpu())->toBe(HardwareAcceleration::None);
    Media::assertRan(Executable::FFMpeg, fn (array $arguments) => in_array($device, $arguments, true));
})->with([
    'vaapi' => [HardwareAcceleration::Vaapi, 'vaapi=hw:/dev/dri/renderD128'],
    'nvenc' => [HardwareAcceleration::Nvenc, 'cuda=hw'],
    'qsv' => [HardwareAcceleration::Qsv, 'qsv=hw,child_device=/dev/dri/renderD128'],
]);

it('needs no check for the cpu', function () {
    Media::fake();

    expect(HardwareAcceleration::None->orCpu())->toBe(HardwareAcceleration::None);
    Media::assertNothingRan();
});

it('checks once per kind of source whether the gpu decodes and scales it', function () {
    Media::fake(['h264.mp4' => FakeProbe::video(), 'other.mp4' => FakeProbe::video(), 'av1.mp4' => FakeProbe::video(codec: 'av1')]);

    $decodes = fn (string $path): bool => HardwareAcceleration::Vaapi->canDecode(
        ($opener = Media::fromDisk('local')->open($path))->mediaFor(),
        $opener->probe()->videoStream(),
    );

    expect($decodes('h264.mp4'))->toBeTrue()
        ->and($decodes('other.mp4'))->toBeTrue()
        ->and($decodes('av1.mp4'))->toBeTrue();

    Media::assertRanTimes(Executable::FFMpeg, 2);
    Media::assertRan(Executable::FFMpeg, fn (array $arguments) => in_array('-xerror', $arguments, true)
        && in_array('-hwaccel', $arguments, true)
        && in_array('scale_vaapi=w=-2:h=64:format=nv12', $arguments, true));
});

it('knows the gpu cannot decode a source when its first frame fails', function () {
    Media::fake(['av1.mp4' => FakeProbe::video(codec: 'av1')])->failNext(Executable::FFMpeg, 'Impossible to convert between the formats supported by the filter');

    $opener = Media::fromDisk('local')->open('av1.mp4');

    expect(HardwareAcceleration::Vaapi->canDecode($opener->mediaFor(), $opener->probe()->videoStream()))->toBeFalse()
        ->and(HardwareAcceleration::None->canDecode($opener->mediaFor(), $opener->probe()->videoStream()))->toBeTrue();

    Media::assertRanTimes(Executable::FFMpeg, 1);
});
