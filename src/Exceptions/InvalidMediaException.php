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

    /**
     * @param  list<string>  $paths
     */
    public static function notConcatenable(array $paths): self
    {
        return new self(sprintf(
            'The files [%s] differ in codecs or dimensions, so they can\'t be joined without re-encoding. Use clips() to join them.',
            implode(', ', $paths),
        ));
    }

    public static function noClips(): self
    {
        return new self('Pass at least one clip to join.');
    }

    public static function noVideo(string $path): self
    {
        return new self("{$path} has no video stream.");
    }
}
