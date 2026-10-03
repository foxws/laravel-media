<?php

declare(strict_types=1);

namespace Foxws\Media\Exceptions;

use Foxws\Media\Process\Result;
use RuntimeException;

class ProcessFailedException extends RuntimeException
{
    public function __construct(string $message, public readonly Result $result)
    {
        parent::__construct($message, $result->exitCode);
    }

    public static function for(Result $result): self
    {
        $error = trim($result->errorOutput) !== '' ? trim($result->errorOutput) : trim($result->output);

        return new self(
            sprintf('%s exited with code %d: %s', $result->executable->value, $result->exitCode, $error),
            $result,
        );
    }
}
