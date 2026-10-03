<?php

declare(strict_types=1);

use Foxws\Media\Filters\FilterType;
use Foxws\Media\Filters\Loudnorm;

it('normalises loudness to the given targets', function () {
    expect((string) new Loudnorm)->toBe('loudnorm=I=-16:TP=-1.5:LRA=11')
        ->and((string) new Loudnorm(integrated: -14, truePeak: -1, range: 7))->toBe('loudnorm=I=-14:TP=-1:LRA=7')
        ->and((new Loudnorm)->type())->toBe(FilterType::Audio);
});
