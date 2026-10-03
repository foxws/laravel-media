<?php

declare(strict_types=1);

use Foxws\Media\Filters\Crop;

it('crops the centre by default', function () {
    expect((string) new Crop(640, 360))->toBe('crop=640:360');
});

it('crops at a position', function () {
    expect((string) new Crop(640, 360, x: 10))->toBe('crop=640:360:10:0')
        ->and((string) new Crop(640, 360, 10, 20))->toBe('crop=640:360:10:20');
});
