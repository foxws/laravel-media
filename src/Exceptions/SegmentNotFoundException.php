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

    public static function forSubtitle(int $subtitle): self
    {
        return new self("Subtitle {$subtitle} doesn't exist.");
    }

    public static function forSheet(int $sheet): self
    {
        return new self("Thumbnail sheet {$sheet} doesn't exist.");
    }

    public static function noThumbnails(): self
    {
        return new self('This stream has no thumbnails.');
    }

    public static function noChapters(): self
    {
        return new self('This stream has no chapters.');
    }

    public static function forTrack(int $variant, string $track): self
    {
        return new self("Variant {$variant} has no {$track} track.");
    }
}
