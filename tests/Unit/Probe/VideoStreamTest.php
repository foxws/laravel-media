<?php

declare(strict_types=1);

use Foxws\Media\Probe\VideoStream;

it('detects hdr from the pq and hlg transfer functions', function (?string $transfer, bool $hdr) {
    $stream = VideoStream::fromArray(['index' => 0, 'codec_type' => 'video', 'color_transfer' => $transfer]);

    expect($stream->isHdr())->toBe($hdr);
})->with([
    'hdr10 (pq)' => ['smpte2084', true],
    'hlg' => ['arib-std-b67', true],
    'sdr' => ['bt709', false],
    'unknown' => [null, false],
]);

it('reads the colour properties', function () {
    $stream = VideoStream::fromArray([
        'index' => 0,
        'codec_type' => 'video',
        'color_transfer' => 'smpte2084',
        'color_primaries' => 'bt2020',
        'color_space' => 'bt2020nc',
    ]);

    expect($stream)
        ->colorTransfer->toBe('smpte2084')
        ->colorPrimaries->toBe('bt2020')
        ->colorSpace->toBe('bt2020nc');
});
