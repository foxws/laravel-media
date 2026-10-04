<?php

declare(strict_types=1);

use Foxws\Media\Filters\FilterType;
use Foxws\Media\Filters\Tonemap;
use Foxws\Media\Filters\ToneMapAlgorithm;

it('converts hdr to sdr bt709 through linear light', function () {
    expect((string) new Tonemap)->toBe(
        'zscale=t=linear:npl=100,format=gbrpf32le,zscale=p=bt709,tonemap=tonemap=hable:desat=0,zscale=t=bt709:m=bt709:r=tv,format=yuv420p',
    )->and((new Tonemap)->type())->toBe(FilterType::Video);
});

it('uses another algorithm, desaturation and peak', function () {
    expect((string) new Tonemap(ToneMapAlgorithm::Mobius, desaturation: 0.5, peak: 203))
        ->toStartWith('zscale=t=linear:npl=203,')
        ->toContain('tonemap=tonemap=mobius:desat=0.5');
});
