<?php

declare(strict_types=1);

namespace Foxws\Media\FFMpeg;

/**
 * A stretch of video between two scene changes.
 */
final readonly class Scene
{
    /**
     * @param  float|null  $score  How strongly the scene differs from the previous one (0-1), null for the first scene.
     */
    public function __construct(
        public float $start,
        public float $end,
        public ?float $score,
    ) {}

    public function duration(): float
    {
        return max(0.0, $this->end - $this->start);
    }

    /**
     * A clip of this scene, optionally limited to a maximum length from its start.
     */
    public function toClip(?float $maximumDuration = null, ?string $path = null): Clip
    {
        $end = $maximumDuration !== null ? min($this->end, $this->start + $maximumDuration) : $this->end;

        return Clip::make($this->start, $end, $path);
    }
}
