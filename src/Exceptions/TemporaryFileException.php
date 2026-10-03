<?php

declare(strict_types=1);

namespace Foxws\Media\Exceptions;

use RuntimeException;
use Throwable;

class TemporaryFileException extends RuntimeException
{
    public static function unwritable(string $path, ?Throwable $previous = null): self
    {
        return new self("Can't write the temporary file [{$path}]. Check the permissions and free space of the temporary root.", previous: $previous);
    }
}
