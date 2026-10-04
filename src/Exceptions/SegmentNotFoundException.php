<?php

declare(strict_types=1);

namespace Foxws\Media\Exceptions;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A requested segment or variant doesn't exist. Laravel renders it as a 404.
 */
class SegmentNotFoundException extends NotFoundHttpException
{
    public static function for(int $variant, int $segment): self
    {
        return new self("Segment {$segment} of variant {$variant} doesn't exist.");
    }
}
