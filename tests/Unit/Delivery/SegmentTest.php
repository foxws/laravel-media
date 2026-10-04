<?php

declare(strict_types=1);

use Foxws\Media\Delivery\Segment;

it('knows where it ends', function () {
    expect(new Segment(3, 18.0, 6.5)->end())->toBe(24.5);
});
