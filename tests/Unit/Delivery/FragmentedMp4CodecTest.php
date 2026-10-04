<?php

declare(strict_types=1);

use Foxws\Media\Delivery\FragmentedMp4Codec;

it('supports the codecs fragmented mp4 can carry', function (?string $codec, bool $supported) {
    expect(FragmentedMp4Codec::supports($codec))->toBe($supported);
})->with([
    'h264' => ['h264', true],
    'hevc' => ['hevc', true],
    'av1' => ['av1', true],
    'vp9' => ['vp9', true],
    'aac' => ['aac', true],
    'opus' => ['opus', true],
    'flac' => ['flac', true],
    'theora' => ['theora', false],
    'vorbis' => ['vorbis', false],
    'unknown' => [null, false],
]);
