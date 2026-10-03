<?php

declare(strict_types=1);

namespace Foxws\Media\Filesystem;

use RuntimeException;

class MediaNotFoundException extends RuntimeException
{
    public static function unreadable(string $path): self
    {
        return new self("Can't read {$path}: it doesn't exist on its disk.");
    }

    public static function noPaths(): self
    {
        return new self('No media has been opened. Call open() with one or more paths first.');
    }
}
