<?php

declare(strict_types=1);

namespace Foxws\Media\Events;

use Foxws\Media\Filesystem\ExportResult;

final readonly class ExportCompleted
{
    /**
     * @param  array<string, mixed>  $context
     * @param  float  $duration  Seconds the export took, including the upload.
     */
    public function __construct(
        public ExportResult $result,
        public array $context = [],
        public float $duration = 0.0,
    ) {}
}
