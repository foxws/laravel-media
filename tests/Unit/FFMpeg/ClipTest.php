<?php

declare(strict_types=1);

use Foxws\Media\FFMpeg\Clip;

it('knows its duration and source', function () {
    $clip = Clip::make(12.5, 20, 'b.mp4');

    expect($clip->duration())->toBe(7.5)
        ->and($clip->path)->toBe('b.mp4');
});

it('rejects a clip that does not end after it starts', function (float $from, float $to) {
    Clip::make($from, $to);
})->throws(InvalidArgumentException::class, 'A clip must end after it starts')->with([
    'equal' => [5, 5],
    'reversed' => [10, 5],
    'negative start' => [-1, 5],
]);
