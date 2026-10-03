<?php

declare(strict_types=1);

use Foxws\Media\Filters\Pad;

it('pads to a size with the video centred', function () {
    expect((string) new Pad(1920, 1080))->toBe('pad=1920:1080:(ow-iw)/2:(oh-ih)/2:color=black')
        ->and((string) new Pad(1920, 1080, 'white'))->toEndWith(':color=white');
});
