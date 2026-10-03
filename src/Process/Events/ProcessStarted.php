<?php

declare(strict_types=1);

namespace Foxws\Media\Process\Events;

use Foxws\Media\Executables\Executable;

final readonly class ProcessStarted
{
    public function __construct(
        public Executable $executable,
        public string $command,
    ) {}
}
