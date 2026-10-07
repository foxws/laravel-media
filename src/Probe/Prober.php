<?php

declare(strict_types=1);

namespace Foxws\Media\Probe;

use Foxws\Media\Concerns\ResolvesFromContainer;
use Foxws\Media\Executables\Executable;
use Foxws\Media\Filesystem\Media;
use Foxws\Media\Process\Runner;

class Prober
{
    use ResolvesFromContainer;

    public function __construct(protected Runner $runner) {}

    public function probe(Media $media): Probe
    {
        $result = $this->runner->run(Executable::FFProbe, [
            '-v', 'error',
            '-print_format', 'json',
            '-show_format',
            '-show_streams',
            '-show_chapters',
            ...$media->inputArguments(),
            $media->inputPath(),
        ]);

        return Probe::fromJson($result->output);
    }
}
