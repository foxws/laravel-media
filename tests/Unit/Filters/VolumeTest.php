<?php

declare(strict_types=1);

use Foxws\Media\Filters\FilterType;
use Foxws\Media\Filters\Volume;

it('changes the volume by a factor or in decibels', function () {
    expect((string) Volume::times(0.5))->toBe('volume=0.5')
        ->and((string) Volume::decibels(-6))->toBe('volume=-6dB')
        ->and(Volume::times(2)->type())->toBe(FilterType::Audio);
});
