<?php

declare(strict_types=1);

namespace Foxws\Media\Exceptions;

use Foxws\Media\Executables\Binary;
use RuntimeException;

class ExecutableNotFoundException extends RuntimeException
{
    public static function for(Binary $executable, string $configured): self
    {
        return new self(sprintf(
            'The %s executable [%s] could not be found. Install it, or set %s to its path.',
            $executable->identifier(),
            $configured,
            $executable->environmentKey(),
        ));
    }
}
