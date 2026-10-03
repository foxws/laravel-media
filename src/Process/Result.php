<?php

declare(strict_types=1);

namespace Foxws\Media\Process;

use Foxws\Media\Executables\Executable;

final readonly class Result
{
    public function __construct(
        public Executable $executable,
        public string $command,
        public int $exitCode,
        public string $output,
        public string $errorOutput,
        public float $duration,
    ) {}

    public function successful(): bool
    {
        return $this->exitCode === 0;
    }

    public function failed(): bool
    {
        return ! $this->successful();
    }
}
