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

it('encrypts every codec but those whose frame headers have to stay readable', function (FragmentedMp4Codec $codec, bool $encryptable) {
    expect($codec->isEncryptable())->toBe($encryptable);
})->with([
    'h264' => [FragmentedMp4Codec::H264, true],
    'hevc' => [FragmentedMp4Codec::Hevc, true],
    'aac' => [FragmentedMp4Codec::Aac, true],
    'opus' => [FragmentedMp4Codec::Opus, true],
    'av1' => [FragmentedMp4Codec::Av1, true],
    'vp9' => [FragmentedMp4Codec::Vp9, false],
]);
