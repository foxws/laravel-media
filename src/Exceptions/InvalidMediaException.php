<?php

declare(strict_types=1);

namespace Foxws\Media\Exceptions;

use RuntimeException;

class InvalidMediaException extends RuntimeException
{
    public static function unknownDuration(string $path): self
    {
        return new self("The duration of {$path} is unknown, so it can't be sampled over time.");
    }

    public static function noVideo(string $path): self
    {
        return new self("{$path} has no video stream.");
    }
}
