<?php

declare(strict_types=1);

use Foxws\Media\Filters\Custom;
use Foxws\Media\Filters\FilterType;

it('passes any filter through for video or audio', function () {
    expect((string) Custom::video('hqdn3d'))->toBe('hqdn3d')
        ->and(Custom::video('hqdn3d')->type())->toBe(FilterType::Video)
        ->and(Custom::audio('atempo=1.25')->type())->toBe(FilterType::Audio);
});
