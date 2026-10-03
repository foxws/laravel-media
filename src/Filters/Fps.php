<?php

declare(strict_types=1);

namespace Foxws\Media\Filters;

/**
 * Convert to a constant frame rate, duplicating or dropping frames.
 */
final readonly class Fps implements Filter
{
    public function __construct(public float $fps) {}

    public function type(): FilterType
    {
        return FilterType::Video;
    }

    public function __toString(): string
    {
        return 'fps='.Number::format($this->fps);
    }
}
