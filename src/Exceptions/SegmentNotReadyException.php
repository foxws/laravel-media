<?php

declare(strict_types=1);

namespace Foxws\Media\Exceptions;

use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

/**
 * Another request or job is still packaging a segment, and this one stopped waiting for it. Laravel
 * renders it as a 503 with a Retry-After header, so players request the segment again.
 */
class SegmentNotReadyException extends ServiceUnavailableHttpException
{
    public static function for(int $variant, int $segment, int $retryAfter = 1): self
    {
        return new self($retryAfter, "Segment {$segment} of variant {$variant} is still being packaged.");
    }
}
