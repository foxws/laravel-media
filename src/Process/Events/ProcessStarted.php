<?php

declare(strict_types=1);

namespace Foxws\Media\Process\Events;

use Foxws\Media\Executables\Binary;

final readonly class ProcessStarted
{
    public function __construct(
        public Binary $executable,
        public string $command,
    ) {}
}
