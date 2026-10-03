<?php

declare(strict_types=1);

namespace Foxws\Media\Process\Events;

use Foxws\Media\Process\Result;

final readonly class ProcessCompleted
{
    public function __construct(
        public Result $result,
    ) {}
}
