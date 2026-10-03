<?php

declare(strict_types=1);

use Foxws\Media\Encoding\Format;

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
