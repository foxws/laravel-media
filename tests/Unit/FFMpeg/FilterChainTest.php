<?php

declare(strict_types=1);

use Foxws\Media\FFMpeg\FilterChain;
use Foxws\Media\Filters\Fade;
use Foxws\Media\Filters\FilterType;
use Foxws\Media\Filters\Scale;
use Foxws\Media\Filters\Volume;

it('joins the filters of one type in order', function () {
    $filters = [Scale::to(1280), Volume::times(2), Fade::in(1), Fade::audioIn(1)];

    expect(FilterChain::of($filters, FilterType::Video))->toBe('scale=1280:-2,fade=t=in:st=0:d=1')
        ->and(FilterChain::of($filters, FilterType::Audio))->toBe('volume=2,afade=t=in:st=0:d=1');
});

it('only emits the chains that have filters', function () {
    expect(FilterChain::arguments([Volume::times(2)]))->toBe(['-af', 'volume=2'])
        ->and(FilterChain::arguments([]))->toBe([]);
});
