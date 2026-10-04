<?php

declare(strict_types=1);

namespace Foxws\Media\Delivery;

/**
 * A part of a video to stream as one segment, starting on a keyframe.
 */
final readonly class Segment
{
    public function __construct(
        public int $index,
        public float $start,
        public float $duration,
    ) {}

    public function end(): float
    {
        return $this->start + $this->duration;
    }
}
