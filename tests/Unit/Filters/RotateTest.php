<?php

declare(strict_types=1);

use Foxws\Media\Filters\Rotate;

it('rotates and flips', function (Rotate $rotate, string $filter) {
    expect((string) $rotate)->toBe($filter);
})->with([
    'clockwise' => [Rotate::clockwise(), 'transpose=clock'],
    'counter clockwise' => [Rotate::counterClockwise(), 'transpose=cclock'],
    'upside down' => [Rotate::upsideDown(), 'hflip,vflip'],
    'horizontally' => [Rotate::flipHorizontally(), 'hflip'],
    'vertically' => [Rotate::flipVertically(), 'vflip'],
]);
