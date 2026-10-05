<?php

declare(strict_types=1);

use Foxws\Media\Encoding\HardwareAcceleration;
use Foxws\Media\Encoding\VideoCodec;

it('decodes on the gpu and keeps the frames there', function (HardwareAcceleration $hardware, array $arguments) {
    expect($hardware->inputArguments())->toBe($arguments);
})->with([
    'none' => [HardwareAcceleration::None, []],
    'vaapi' => [HardwareAcceleration::Vaapi, ['-hwaccel', 'vaapi', '-hwaccel_output_format', 'vaapi', '-vaapi_device', '/dev/dri/renderD128']],
    'nvenc' => [HardwareAcceleration::Nvenc, ['-hwaccel', 'cuda', '-hwaccel_output_format', 'cuda']],
    'qsv' => [HardwareAcceleration::Qsv, ['-hwaccel', 'qsv', '-hwaccel_output_format', 'qsv']],
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
    'vaapi' => [HardwareAcceleration::Vaapi, 'scale_vaapi=w=-2:h=720'],
    'nvenc' => [HardwareAcceleration::Nvenc, 'scale_cuda=-2:720'],
    'qsv' => [HardwareAcceleration::Qsv, 'scale_qsv=w=-2:h=720'],
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
    'qsv' => [HardwareAcceleration::Qsv, ['-init_hw_device', 'qsv=hw', '-filter_hw_device', 'hw'], 'format=nv12,hwupload=extra_hw_frames=64'],
]);

it('sets constant quality in the terms of each encoder', function (HardwareAcceleration $hardware, array $arguments) {
    expect($hardware->quality(22))->toBe($arguments);
})->with([
    'none' => [HardwareAcceleration::None, ['-crf', '22']],
    'vaapi' => [HardwareAcceleration::Vaapi, ['-rc_mode', 'CQP', '-qp', '22']],
    'nvenc' => [HardwareAcceleration::Nvenc, ['-rc', 'vbr', '-cq', '22', '-b:v', '0']],
    'qsv' => [HardwareAcceleration::Qsv, ['-global_quality', '22']],
]);
