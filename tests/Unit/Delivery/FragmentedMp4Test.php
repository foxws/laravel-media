<?php

declare(strict_types=1);

use Foxws\Media\Delivery\FragmentedMp4;
use Foxws\Media\Exceptions\InvalidMediaException;

it('splits the initialization segment from the fragments', function () {
    $init = mp4Box('ftyp', 'iso5').mp4Box('moov', mp4Box('mvhd', 'header'));
    $media = mp4Box('moof', 'fragment header').mp4Box('mdat', 'samples').mp4Box('moof').mp4Box('mdat', 'more');

    expect(FragmentedMp4::split($init.$media))->toBe(['init' => $init, 'media' => $media]);
});

it('reads boxes with a 64-bit size', function () {
    $init = mp4Box('ftyp', 'iso5').pack('N', 1).'moov'.pack('J', 16 + 6).'header';
    $media = mp4Box('moof').mp4Box('mdat', 'samples');

    expect(FragmentedMp4::split($init.$media))->toBe(['init' => $init, 'media' => $media]);
});

it('refuses files without fragments or an initialization segment', function (string $file) {
    FragmentedMp4::split($file);
})->throws(InvalidMediaException::class, 'did not write a fragmented MP4 segment')->with([
    'progressive mp4' => [mp4Box('ftyp').mp4Box('moov').mp4Box('mdat', 'samples')],
    'no moov' => [mp4Box('ftyp').mp4Box('moof').mp4Box('mdat')],
    'not mp4' => ['fake media'],
]);
