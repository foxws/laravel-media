<?php

declare(strict_types=1);

namespace Foxws\Media\Exceptions;

use RuntimeException;

class InsufficientStorageException extends RuntimeException
{
    public static function in(string $path, int $free, int $required): self
    {
        return new self(sprintf(
            'Insufficient storage space in [%s]: %d bytes free, %d bytes required.',
            $path,
            $free,
            $required,
        ));
    }
}
