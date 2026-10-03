<?php

declare(strict_types=1);

namespace Foxws\Media\Exceptions;

use RuntimeException;

class MediaNotFoundException extends RuntimeException
{
    public static function unreadable(string $path): self
    {
        return new self("Can't read {$path}: it doesn't exist on its disk.");
    }

    public static function noOutputs(): self
    {
        return new self('Nothing to save. Pass a path to save(), or add outputs with addOutput().');
    }

    public static function noPaths(): self
    {
        return new self('No media has been opened. Call open() with one or more paths first.');
    }
}
