<?php

declare(strict_types=1);

namespace Foxws\Media\Exceptions;

use RuntimeException;

class MissingCapabilitiesException extends RuntimeException
{
    /**
     * @param  array<string, list<string>>  $missing  Names keyed by Capability value.
     */
    public function __construct(string $message, public readonly array $missing)
    {
        parent::__construct($message);
    }

    /**
     * @param  array<string, list<string>>  $missing
     */
    public static function for(array $missing): self
    {
        $parts = array_map(
            fn (string $kind, array $names): string => sprintf('%s [%s]', $kind, implode(', ', $names)),
            array_keys($missing),
            $missing,
        );

        return new self('The ffmpeg build is missing '.implode(', ', $parts).'.', $missing);
    }
}
