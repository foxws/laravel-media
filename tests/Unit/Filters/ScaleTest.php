<?php

declare(strict_types=1);

use Foxws\Media\Filters\FilterType;
use Foxws\Media\Filters\Scale;

it('scales to a size and keeps the aspect ratio for a missing side', function () {
    expect((string) Scale::to(width: 1280))->toBe('scale=1280:-2')
        ->and((string) Scale::to(height: 720))->toBe('scale=-2:720')
        ->and((string) Scale::to(640, 360))->toBe('scale=640:360');
});

it('fits inside a box with letterboxing', function () {
    expect((string) Scale::fit(1080, 1920))
        ->toBe('scale=1080:1920:force_original_aspect_ratio=decrease,pad=1080:1920:(ow-iw)/2:(oh-ih)/2:color=black,setsar=1');
});

it('fills a box and crops the overflow', function () {
    expect((string) Scale::fill(1080, 1920))
        ->toBe('scale=1080:1920:force_original_aspect_ratio=increase,crop=1080:1920,setsar=1');
});

it('is a video filter', function () {
    expect(Scale::to(1280)->type())->toBe(FilterType::Video);
});
