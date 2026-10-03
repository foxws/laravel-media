<?php

declare(strict_types=1);

use Foxws\Media\Filters\Fps;

it('sets a constant frame rate without trailing zeros', function () {
    expect((string) new Fps(30))->toBe('fps=30')
        ->and((string) new Fps(29.97))->toBe('fps=29.97');
});
