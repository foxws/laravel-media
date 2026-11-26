<?php

declare(strict_types=1);

use Foxws\Media\Filters\FilterType;
use Foxws\Media\Filters\LoudnessMeasurement;
use Foxws\Media\Filters\Loudnorm;

it('normalises loudness to the given targets', function () {
    expect((string) new Loudnorm)->toBe('loudnorm=I=-16:TP=-1.5:LRA=11,aresample=48000')
        ->and((string) new Loudnorm(integrated: -14, truePeak: -1, range: 7))->toBe('loudnorm=I=-14:TP=-1:LRA=7,aresample=48000')
        ->and((new Loudnorm)->type())->toBe(FilterType::Audio);
});

it('resamples to the given rate, or keeps the rate of loudnorm', function () {
    expect((string) new Loudnorm(sampleRate: 44100))->toBe('loudnorm=I=-16:TP=-1.5:LRA=11,aresample=44100')
        ->and((string) new Loudnorm(sampleRate: null))->toBe('loudnorm=I=-16:TP=-1.5:LRA=11');
});

it('normalises with the measurement of a first pass', function () {
    $loudnorm = new Loudnorm(integrated: -16, truePeak: -1.5, range: 11)
        ->withMeasurement(new LoudnessMeasurement(integrated: -27.2, truePeak: -14.3, range: 6.1, threshold: -37.6, targetOffset: 0.3));

    expect((string) $loudnorm)->toBe('loudnorm=I=-16:TP=-1.5:LRA=11:measured_I=-27.2:measured_TP=-14.3:measured_LRA=6.1:measured_thresh=-37.6:offset=0.3:linear=true,aresample=48000')
        ->and($loudnorm->isLinear())->toBeTrue()
        ->and(new Loudnorm()->isLinear())->toBeFalse();
});

it('normalises silence in a single pass, since nothing was measured', function () {
    $loudnorm = new Loudnorm()->withMeasurement(new LoudnessMeasurement(integrated: null, truePeak: null, range: 0.0, threshold: -70.0));

    expect((string) $loudnorm)->toBe('loudnorm=I=-16:TP=-1.5:LRA=11,aresample=48000')
        ->and($loudnorm->isLinear())->toBeFalse();
});
