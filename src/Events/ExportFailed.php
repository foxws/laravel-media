<?php

declare(strict_types=1);

namespace Foxws\Media\Events;

use Throwable;

final readonly class ExportFailed
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public Throwable $exception,
        public array $context = [],
    ) {}
}
