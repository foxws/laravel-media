<?php

declare(strict_types=1);

use Foxws\Media\Encoding\Rendition;

it('peaks 7% above the target bitrate with a buffer of twice the target by default', function () {
    expect(new Rendition(720, 2800))
        ->peakBitrate()->toBe(2996)
        ->buffer()->toBe(5600)
        ->and(new Rendition(720, 2800, maxBitrate: 3500, bufferSize: 7000))
        ->peakBitrate()->toBe(3500)
        ->buffer()->toBe(7000);
});

it('refuses odd heights and bitrates that do not add up', function (Closure $make, string $message) {
    expect($make)->toThrow(InvalidArgumentException::class, $message);
})->with([
    'odd height' => [fn () => new Rendition(721, 2800), 'positive even number, not [721]'],
    'no bitrate' => [fn () => new Rendition(720, 0), 'positive bitrate'],
    'peak below target' => [fn () => new Rendition(720, 2800, maxBitrate: 2000), 'at least as large'],
]);
