<?php

declare(strict_types=1);

namespace Foxws\Media\Exceptions;

use Foxws\Media\Filesystem\CopyFailure;
use RuntimeException;

class ExportFailedException extends RuntimeException
{
    /**
     * @param  list<CopyFailure>  $failures
     */
    public function __construct(string $message, public readonly array $failures)
    {
        parent::__construct($message);
    }

    /**
     * @param  list<CopyFailure>  $failures
     */
    public static function copyFailed(string $disk, array $failures): self
    {
        return new self(sprintf(
            '%d file(s) failed to copy to disk [%s]: %s',
            count($failures),
            $disk,
            implode('; ', array_map(strval(...), $failures)),
        ), $failures);
    }
}
