<?php

declare(strict_types=1);

namespace Foxws\Media\Exceptions;

use InvalidArgumentException;

class InvalidFilterException extends InvalidArgumentException
{
    public static function watermarkWithOutputs(): self
    {
        return new self('A watermark is only applied to the main output, so it can\'t be combined with addOutput().');
    }

    public static function watermarkWithMaps(): self
    {
        return new self('A watermark maps its own output streams, so it can\'t be combined with map().');
    }
}
