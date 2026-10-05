<?php

declare(strict_types=1);

use Foxws\Media\Encoding\PixelFormat;

it('knows the bit depth of each pixel format', function () {
    expect(array_map(fn (PixelFormat $format): int => $format->bitDepth(), PixelFormat::cases()))->toBe([8, 8, 10, 10]);
});
