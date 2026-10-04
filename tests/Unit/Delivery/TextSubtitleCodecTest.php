<?php

declare(strict_types=1);

use Foxws\Media\Delivery\TextSubtitleCodec;

it('supports the text subtitle codecs ffmpeg converts to webvtt', function (?string $codec, bool $supported) {
    expect(TextSubtitleCodec::supports($codec))->toBe($supported);
})->with([
    'subrip' => ['subrip', true],
    'mov_text' => ['mov_text', true],
    'ass' => ['ass', true],
    'webvtt' => ['webvtt', true],
    'pgs' => ['hdmv_pgs_subtitle', false],
    'dvd' => ['dvd_subtitle', false],
    'unknown' => [null, false],
]);
