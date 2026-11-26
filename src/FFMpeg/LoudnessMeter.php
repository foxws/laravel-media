<?php

declare(strict_types=1);

namespace Foxws\Media\FFMpeg;

use Foxws\Media\Concerns\ResolvesFromContainer;
use Foxws\Media\Executables\Executable;
use Foxws\Media\Filesystem\Media;
use Foxws\Media\Filters\LoudnessMeasurement;
use Foxws\Media\Process\Runner;

/**
 * Measures the loudness of an audio stream with loudnorm's first pass.
 */
class LoudnessMeter
{
    use ResolvesFromContainer;

    public function __construct(protected Runner $runner) {}

    /**
     * @param  int  $audioStream  The position of the audio stream among the audio streams, from 0.
     */
    public function measure(Media $media, int $audioStream = 0): LoudnessMeasurement
    {
        // loudnorm prints its measurement at the info level, below the configured log level.
        $result = $this->runner->run(Executable::FFMpeg, [
            '-hide_banner',
            '-nostdin',
            '-nostats',
            '-loglevel', 'info',
            ...$media->inputArguments(),
            '-i', $media->inputPath(),
            '-map', "0:a:{$audioStream}",
            '-vn', '-sn', '-dn',
            '-af', 'loudnorm=print_format=json',
            '-f', 'null',
            '-',
        ], logWarnings: false);

        return LoudnessMeasurement::fromOutput($result->errorOutput);
    }
}
