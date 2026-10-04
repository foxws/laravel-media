<?php

declare(strict_types=1);

use Foxws\Media\Delivery\TransportStreamCodec;

it('supports the codecs mpeg-ts can carry', function (?string $codec, bool $supported) {
    expect(TransportStreamCodec::supports($codec))->toBe($supported);
})->with([
    'h264' => ['h264', true],
    'hevc' => ['hevc', true],
    'aac' => ['aac', true],
    'e-ac-3' => ['eac3', true],
    'vp9' => ['vp9', false],
    'av1' => ['av1', false],
    'opus' => ['opus', false],
    'unknown' => [null, false],
]);
