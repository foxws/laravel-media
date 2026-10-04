<?php

declare(strict_types=1);

namespace Foxws\Media\Exceptions;

use RuntimeException;

/**
 * Thrown while a process runs to stop it, e.g. when a progress callback returns false.
 * The runner stops the process and turns this into a ProcessFailedException with reason Cancelled.
 */
class ProcessCancelledException extends RuntimeException
{
    public static function make(): self
    {
        return new self('The process was cancelled.');
    }
}
