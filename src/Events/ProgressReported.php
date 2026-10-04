<?php

declare(strict_types=1);

namespace Foxws\Media\Events;

use Foxws\Media\Process\Progress;

/**
 * Dispatched for every progress update of an export, about twice a second.
 * Listen to it to broadcast progress, e.g. with Reverb, using the context to know what it's about.
 */
final readonly class ProgressReported
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public Progress $progress,
        public array $context = [],
    ) {}
}
