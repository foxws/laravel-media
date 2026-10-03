<?php

declare(strict_types=1);

use Foxws\Media\Encoding\Format;

it('builds the ffmpeg output arguments for h264', function () {
    expect(Format::h264(crf: 20, preset: 'slow')->toArguments())->toBe([
        '-c:v', 'libx264',
        '-c:a', 'aac',
        '-crf', '20', '-preset', 'slow', '-pix_fmt', 'yuv420p', '-movflags', '+faststart',
        '-f', 'mp4',
    ]);
});

it('drops video and audio when converting subtitles to webvtt', function () {
    expect(Format::webVtt()->toArguments())->toBe(['-vn', '-an', '-f', 'webvtt']);
});

it('copies streams without re-encoding', function () {
    expect(Format::copy('matroska')->toArguments())->toBe(['-c:v', 'copy', '-c:a', 'copy', '-f', 'matroska']);
});

it('appends arguments and removes audio without changing the original format', function () {
    $format = Format::av1();

    $changed = $format->withArguments(['-g', '48'])->withoutAudio();

    expect($changed->toArguments())->toBe(['-c:v', 'libsvtav1', '-an', '-crf', '30', '-preset', '8', '-movflags', '+faststart', '-g', '48', '-f', 'mp4'])
        ->and($format->toArguments())->toContain('-c:a');
});
