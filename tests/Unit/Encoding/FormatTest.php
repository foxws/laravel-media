<?php

declare(strict_types=1);

use Foxws\Media\Encoding\Format;
use Foxws\Media\Encoding\HardwareAcceleration;

it('builds the ffmpeg output arguments for h264', function () {
    expect(Format::h264(crf: 20, preset: 'slow')->toArguments())->toBe([
        '-c:v', 'libx264', '-crf', '20', '-preset', 'slow',
        '-c:a', 'aac',
        '-pix_fmt', 'yuv420p', '-movflags', '+faststart',
        '-f', 'mp4',
    ]);
});

it('uses constant quality for vp9 by setting the bitrate to zero', function () {
    expect(Format::vp9(crf: 31)->toArguments())->toBe(['-c:v', 'libvpx-vp9', '-crf', '31', '-b:v', '0', '-c:a', 'libopus', '-f', 'webm']);
});

it('targets a video bitrate with a maximum rate and buffer size', function () {
    $format = Format::h264()->bitrate(2500, max: 3000, buffer: 6000);

    expect($format->toArguments())->toContain('-b:v', '2500k', '-maxrate', '3000k', '-bufsize', '6000k')
        ->and(array_slice($format->toArguments(), 6, 6))->toBe(['-b:v', '2500k', '-maxrate', '3000k', '-bufsize', '6000k']);
});

it('sets the audio bitrate, channels and sample rate', function () {
    $format = Format::h264()->audioBitrate(128)->audioChannels(2)->sampleRate(48000);

    expect(array_slice($format->toArguments(), 6, 8))->toBe(['-c:a', 'aac', '-b:a', '128k', '-ac', '2', '-ar', '48000']);
});

it('changes the quality and preset', function () {
    expect(Format::av1()->crf(35)->preset(6)->toArguments())->toBe([
        '-c:v', 'libsvtav1', '-crf', '35', '-preset', '6', '-c:a', 'libopus', '-movflags', '+faststart', '-f', 'mp4',
    ]);
});

it('writes audio only files', function (Format $format, array $arguments) {
    expect($format->toArguments())->toBe($arguments);
})->with([
    'aac' => [Format::aac(), ['-vn', '-c:a', 'aac', '-b:a', '160k', '-sn', '-f', 'ipod']],
    'mp3' => [Format::mp3(256), ['-vn', '-c:a', 'libmp3lame', '-b:a', '256k', '-sn', '-f', 'mp3']],
    'opus' => [Format::opus(), ['-vn', '-c:a', 'libopus', '-b:a', '128k', '-sn', '-f', 'opus']],
    'flac' => [Format::flac(), ['-vn', '-c:a', 'flac', '-sn', '-f', 'flac']],
]);

it('drops video, audio or subtitle streams', function () {
    expect(Format::h264()->withoutVideo()->toArguments())->toBe(['-vn', '-c:a', 'aac', '-pix_fmt', 'yuv420p', '-movflags', '+faststart', '-f', 'mp4'])
        ->and(Format::h264()->withoutAudio()->toArguments())->toBe(['-c:v', 'libx264', '-crf', '23', '-preset', 'medium', '-an', '-pix_fmt', 'yuv420p', '-movflags', '+faststart', '-f', 'mp4'])
        ->and(Format::copy('mp4')->withoutSubtitles()->toArguments())->toBe(['-c:v', 'copy', '-c:a', 'copy', '-sn', '-f', 'mp4']);
});

it('drops video and audio when converting subtitles to webvtt', function () {
    expect(Format::webVtt()->toArguments())->toBe(['-vn', '-an', '-f', 'webvtt']);
});

it('copies streams without re-encoding', function () {
    expect(Format::copy('matroska')->toArguments())->toBe(['-c:v', 'copy', '-c:a', 'copy', '-f', 'matroska']);
});

it('returns changed copies and leaves the original format alone', function () {
    $format = Format::h264();

    $changed = $format->bitrate(1000)->withArguments(['-g', '48'])->twoPass();

    expect($changed->passes)->toBe(2)
        ->and($changed->toArguments())->toContain('-g', '-b:v')
        ->and($format->passes)->toBe(1)
        ->and($format->toArguments())->not->toContain('-g')
        ->not->toContain('-b:v');
});

it('encodes on a gpu with the codec\'s hardware encoder and the crf as its quality', function () {
    expect(Format::h264(crf: 20, preset: 'slow')->forHardware(HardwareAcceleration::Vaapi)->toArguments())->toBe([
        '-c:a', 'aac',
        '-c:v', 'h264_vaapi', '-rc_mode', 'CQP', '-qp', '20',
        '-movflags', '+faststart',
        '-f', 'mp4',
    ])
        ->and(Format::hevc()->forHardware(HardwareAcceleration::Nvenc)->toArguments())
        ->toContain('hevc_nvenc', '-cq', '28', '-pix_fmt', 'yuv420p', '-tag:v', 'hvc1');
});

it('keeps a bitrate on a gpu instead of constant quality', function () {
    $arguments = Format::av1()->bitrate(3000)->forHardware(HardwareAcceleration::Qsv)->toArguments();

    expect($arguments)->toContain('av1_qsv', '-b:v', '3000k')
        ->not->toContain('-global_quality', '-crf', '-preset');
});

it('leaves formats alone that do not encode video', function (Format $format) {
    expect($format->encodesVideo())->toBeFalse()
        ->and($format->forHardware(HardwareAcceleration::Vaapi))->toBe($format);
})->with([
    'copy' => [Format::copy('mp4')],
    'audio' => [Format::aac()],
    'image' => [Format::jpeg()],
    'without video' => [Format::h264()->withoutVideo()],
]);

it('keeps the cpu encoder without hardware', function () {
    $format = Format::h264();

    expect($format->encodesVideo())->toBeTrue()
        ->and($format->forHardware(HardwareAcceleration::None))->toBe($format);
});
