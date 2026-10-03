<?php

declare(strict_types=1);

namespace Foxws\Media\Filesystem;

/**
 * A local file to copy to a path on the target disk.
 */
final readonly class FileOperation
{
    public function __construct(
        public string $absolutePath,
        public string $targetPath,
    ) {}
}
