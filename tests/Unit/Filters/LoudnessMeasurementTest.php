<?php

declare(strict_types=1);

use Foxws\Media\Exceptions\InvalidMediaException;
use Foxws\Media\Filters\LoudnessMeasurement;
use Foxws\Media\Filters\Loudnorm;

it('reads the measurement loudnorm prints', function () {
    $output = <<<'TXT'
        Input #0, wav, from 'talk.wav':
          Duration: 00:00:06.00, bitrate: 768 kb/s
        [Parsed_loudnorm_0 @ 0x55d5] 
        {
        	"input_i" : "-40.85",
        	"input_tp" : "-32.38",
        	"input_lra" : "1.20",
        	"input_thresh" : "-50.85",
        	"output_i" : "-16.01",
        	"output_tp" : "-7.53",
        	"output_lra" : "0.10",
        	"output_thresh" : "-26.01",
        	"normalization_type" : "dynamic",
        	"target_offset" : "0.01"
        }
        [out#0/null @ 0x55d6] video:0KiB audio:1125KiB
        TXT;

    expect(LoudnessMeasurement::fromOutput($output))->toEqual(new LoudnessMeasurement(-40.85, -32.38, 1.2, -50.85, 0.01));
});

it('reads silence as nothing measured', function () {
    $measurement = LoudnessMeasurement::fromOutput('{"input_i" : "-inf", "input_tp" : "-inf", "input_lra" : "0.00", "input_thresh" : "-70.00", "target_offset" : "inf"}');

    expect($measurement)->toEqual(new LoudnessMeasurement(null, null, 0.0, -70.0, 0.0))
        ->and($measurement->isSilent())->toBeTrue();
});

it('throws when ffmpeg printed no measurement', function () {
    LoudnessMeasurement::fromOutput('Stream map matches no streams.');
})->throws(InvalidMediaException::class, 'ffmpeg reported no loudness measurement for the audio stream.');

it('allows linear normalisation only when the gain keeps the true peak and range within the target', function (LoudnessMeasurement $measurement, bool $linear) {
    expect($measurement->allowsLinear(new Loudnorm(integrated: -16, truePeak: -1.5, range: 11)))->toBe($linear);
})->with([
    'quiet with headroom' => [new LoudnessMeasurement(-27.0, -14.0, 6.0, -37.0), true],
    'peak would clip' => [new LoudnessMeasurement(-27.0, -10.0, 6.0, -37.0), false],
    'range too wide' => [new LoudnessMeasurement(-27.0, -14.0, 15.0, -37.0), false],
    'no measured range' => [new LoudnessMeasurement(-27.0, -14.0, 0.0, -37.0), false],
    'silence' => [new LoudnessMeasurement(null, null, 0.0, -70.0), false],
]);
