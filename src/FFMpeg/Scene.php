<?php

declare(strict_types=1);

namespace Foxws\Media\FFMpeg;

use Illuminate\Contracts\Support\Arrayable;

/**
 * A stretch of video between two scene changes.
 *
 * @implements Arrayable<string, float|null>
 */
final readonly class Scene implements Arrayable
{
    /**
     * @param  float|null  $score  How strongly the scene differs from the previous one (0-1), null for the first scene.
     */
    public function __construct(
        public float $start,
        public float $end,
        public ?float $score,
    ) {}

    /**
     * Restore a scene stored with toArray(), e.g. from a JSON column.
     *
     * @param  array<string, mixed>  $scene
     */
    public static function fromArray(array $scene): self
    {
        $score = $scene['score'] ?? null;

        return new self(
            start: (float) ($scene['start'] ?? 0),
            end: (float) ($scene['end'] ?? 0),
            score: is_numeric($score) ? (float) $score : null,
        );
    }

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

    /**
     * @return array{start: float, end: float, score: float|null}
     */
    public function toArray(): array
    {
        return [
            'start' => $this->start,
            'end' => $this->end,
            'score' => $this->score,
        ];
    }
}
