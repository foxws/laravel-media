<?php

declare(strict_types=1);

use Foxws\Media\Delivery\CommonEncryption;
use Foxws\Media\Delivery\FragmentedMp4;
use Foxws\Media\Encryption\EncryptionKey;
use Foxws\Media\Exceptions\InvalidMediaException;

function nalUnit(int $header, int $size): string
{
    return pack('N', $size).chr($header).str_repeat('x', $size - 1);
}

it('marks the track as encrypted with the cenc scheme and lists the key id', function (string $sampleEntry, string $protected) {
    $key = EncryptionKey::generate();
    $track = fragmentedTrack($sampleEntry, ['sample']);

    $init = CommonEncryption::init($track['init'], $key);

    $moov = mp4Boxes($init)['moov'];
    expect(unpack('N', $moov)[1])->toBe(strlen($moov))
        ->and(strlen($init))->toBe(strlen(mp4Boxes($init)['ftyp']) + strlen($moov))
        ->and($init)->toContain($protected)
        ->and($init)->not->toContain($sampleEntry === 'avc1' ? 'avc1'.str_repeat("\0", 4) : 'mp4a'.str_repeat("\0", 4))
        ->and($init)->toContain(mp4Box('frma', $sampleEntry))
        ->and($init)->toContain(mp4Box('schm', pack('N', 0).'cenc'.pack('N', 0x00010000)))
        ->and($init)->toContain(mp4Box('tenc', pack('N', 0)."\0\0\1\x08".hex2bin($key->keyId)))
        ->and($init)->toEndWith(mp4Box('pssh', pack('N', 0x01000000).hex2bin('1077efecc0b24d02ace33c1e52e2fb4b').pack('N', 1).hex2bin($key->keyId).pack('N', 0)))
        ->and(FragmentedMp4::split($init.$track['media'])['init'])->toBe($init);
})->with([
    'video' => ['avc1', 'encv'],
    'audio' => ['mp4a', 'enca'],
]);

it('encrypts audio samples whole with an iv per sample', function () {
    $key = EncryptionKey::generate();
    $samples = [str_repeat('a', 300), str_repeat('b', 17), 'c'];
    $track = fragmentedTrack('mp4a', $samples);

    $segment = CommonEncryption::segment($track['init'], $track['media'], $key, '0|audio|3');
    $decrypted = decryptCenc($segment, $key);

    expect($decrypted['samples'])->toBe($samples)
        ->and($decrypted['subsamples'])->toBe([[], [], []])
        ->and(array_unique($decrypted['ivs']))->toHaveCount(3)
        ->and($segment)->not->toContain(str_repeat('a', 300))
        ->and(strlen(mp4Boxes($segment)['mdat']))->toBe(strlen(mp4Boxes($track['media'])['mdat']));
});

it('encrypts a sample whose ciphertext is the string zero', function () {
    $key = new EncryptionKey(str_repeat('0', 32), str_repeat('0', 32));
    $track = fragmentedTrack('mp4a', ['c']);

    $segment = CommonEncryption::segment($track['init'], $track['media'], $key, '0|audio|564');

    expect(decryptCenc($segment, $key)['samples'])->toBe(['c'])
        ->and(mp4Boxes($segment)['mdat'])->toEndWith('0');
});

it('leaves the nal headers and non-slice nal units of h264 readable', function () {
    $key = EncryptionKey::generate();
    $sample = nalUnit(0x67, 12).nalUnit(0x65, 40).nalUnit(0x41, 8);
    $track = fragmentedTrack('avc1', [$sample]);

    $decrypted = decryptCenc(CommonEncryption::segment($track['init'], $track['media'], $key, '0|video|0'), $key);

    // The parameter set and the slice too short for a block stay clear; the IDR slice keeps 7 bytes of its data clear for whole blocks.
    expect($decrypted['samples'])->toBe([$sample])
        ->and($decrypted['subsamples'])->toBe([[[4 + 12 + 4 + 1 + 7, 32], [4 + 8, 0]]]);
});

it('reads the two-byte nal headers of hevc', function () {
    $key = EncryptionKey::generate();
    $sample = nalUnit(0x40, 20).nalUnit(0x26, 34);
    $track = fragmentedTrack('hvc1', [$sample]);

    $segment = CommonEncryption::segment($track['init'], $track['media'], $key, '0|video|0');
    $decrypted = decryptCenc($segment, $key);

    expect($decrypted['samples'])->toBe([$sample])
        ->and($decrypted['subsamples'])->toBe([[[4 + 20 + 4 + 2, 32]]])
        ->and($segment)->toContain(nalUnit(0x40, 20).pack('N', 34)."\x26x");
});

it('splits clear runs a subsample cannot hold', function () {
    $key = EncryptionKey::generate();
    $sample = nalUnit(0x06, 70000).nalUnit(0x65, 17);
    $track = fragmentedTrack('avc1', [$sample]);

    $decrypted = decryptCenc(CommonEncryption::segment($track['init'], $track['media'], $key, '0|video|0'), $key);

    expect($decrypted['samples'])->toBe([$sample])
        ->and($decrypted['subsamples'])->toBe([[[0xFFFF, 0], [4 + 70000 + 4 + 1 - 0xFFFF, 16]]]);
});

it('encrypts every fragment of a segment with its own ivs', function () {
    $key = EncryptionKey::generate();
    $track = fragmentedTrack('mp4a', ['first', 'second']);

    $decrypted = decryptCenc(CommonEncryption::segment($track['init'], $track['media'].$track['media'], $key, '0|audio|0'), $key);

    expect($decrypted['samples'])->toBe(['first', 'second', 'first', 'second'])
        ->and(array_unique($decrypted['ivs']))->toHaveCount(4);
});

it('derives the same ivs for the same context only', function () {
    $key = EncryptionKey::generate();
    $track = fragmentedTrack('mp4a', ['sample']);

    $encrypt = fn (string $context) => CommonEncryption::segment($track['init'], $track['media'], $key, $context);

    expect($encrypt('0|audio|1'))->toBe($encrypt('0|audio|1'))
        ->and($encrypt('0|audio|2'))->not->toBe($encrypt('0|audio|1'));
});

it('refuses codecs whose frame headers have to stay readable', function () {
    $track = fragmentedTrack('av01', ['sample']);

    CommonEncryption::init($track['init'], EncryptionKey::generate());
})->throws(InvalidMediaException::class, "Fragmented MP4 segments can only be encrypted with H.264 or HEVC video and AAC, MP3, AC-3, Opus or FLAC audio: [av01] isn't supported.");

it('refuses segments that are not fragmented mp4', function (string $init, string $media) {
    CommonEncryption::segment($init, $media, EncryptionKey::generate(), '0|audio|0');
})->throws(InvalidMediaException::class, 'did not write a fragmented MP4 segment')->with([
    'no sample entry' => [mp4Box('ftyp').mp4Box('moov'), fragmentedTrack('mp4a', ['sample'])['media']],
    'truncated box' => [fragmentedTrack('mp4a', ['sample'])['init'], substr(fragmentedTrack('mp4a', ['sample'])['media'], 0, -3)],
    'samples beyond the segment' => [fragmentedTrack('mp4a', ['sample'])['init'], fragmentedTrack('mp4a', ['sample'])['media'].mp4Box('moof', mp4Box('traf', mp4Box('tfhd', pack('NN', 0x020000, 1)).mp4Box('trun', pack('NNNN', 0x201, 1, 100, 50)))).mp4Box('mdat')],
]);
