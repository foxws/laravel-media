<?php

declare(strict_types=1);

namespace Foxws\Media\Filesystem;

use Stringable;

/**
 * A file that failed to copy to its target disk.
 */
final readonly class CopyFailure implements Stringable
{
    public function __construct(
        public string $source,
        public string $target,
        public string $error,
    ) {}

    public function __toString(): string
    {
        return "{$this->target}: {$this->error}";
    }
}
