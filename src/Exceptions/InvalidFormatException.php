<?php

declare(strict_types=1);

namespace Foxws\Media\Exceptions;

use Foxws\Media\Encoding\VideoCodec;
use InvalidArgumentException;

class InvalidFormatException extends InvalidArgumentException
{
    public static function twoPassUnsupported(?VideoCodec $codec): self
    {
        return new self(sprintf(
            'Two-pass encoding is supported for libx264 and libvpx-vp9, not [%s].',
            $codec->value ?? 'no video',
        ));
    }

    public static function twoPassWithOutputs(): self
    {
        return new self('Two-pass encoding writes a single output, so it can\'t be combined with addOutput().');
    }

    public static function twoPassWithoutBitrate(): self
    {
        return new self('Two-pass encoding needs a target bitrate. Call bitrate() on the format.');
    }
}
