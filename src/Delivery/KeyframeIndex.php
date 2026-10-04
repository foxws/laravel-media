<?php

declare(strict_types=1);

namespace Foxws\Media\Delivery;

use InvalidArgumentException;

/**
 * Where a video's keyframes are, so it can be split into segments that each start on one
 * and can be copied without re-encoding.
 */
final readonly class KeyframeIndex
{
    /**
     * @param  list<float>  $keyframes  Keyframe timestamps in seconds, ascending. Empty for audio.
     */
    public function __construct(
        public array $keyframes,
        public float $duration,
    ) {}

    /**
     * Read ffprobe's packet list ("pts_time,flags" per line), keeping the keyframes ("K" flag).
     */
    public static function fromPackets(string $packets, float $duration): self
    {
        $keyframes = [];

        foreach (preg_split('/\R/', trim($packets)) ?: [] as $line) {
            [$time, $flags] = array_pad(explode(',', trim($line), 2), 2, '');

            if (is_numeric($time) && str_contains($flags, 'K')) {
                $keyframes[] = (float) $time;
            }
        }

        sort($keyframes);

        return new self(array_values(array_unique($keyframes, SORT_REGULAR)), $duration);
    }

    /**
     * Split into segments of at least the target length, each starting on a keyframe. Videos with
     * long keyframe intervals get longer segments. Without keyframes (audio), segments are even.
     *
     * @return list<Segment>
     */
    public function segments(float $target = 6.0): array
    {
        if ($target <= 0) {
            throw new InvalidArgumentException('The segment duration must be greater than zero.');
        }

        if ($this->duration <= 0) {
            return [];
        }

        $boundaries = $this->keyframes === [] ? $this->evenBoundaries($target) : $this->keyframeBoundaries($target);

        $segments = [];

        foreach ($boundaries as $index => $start) {
            $end = $boundaries[$index + 1] ?? $this->duration;

            if ($end > $start) {
                $segments[] = new Segment(count($segments), round($start, 6), round($end - $start, 6));
            }
        }

        return $segments;
    }

    /**
     * The longest segment, for the HLS target duration.
     */
    public function longestSegment(float $target = 6.0): float
    {
        return max([0.0, ...array_map(fn (Segment $segment): float => $segment->duration, $this->segments($target))]);
    }

    /**
     * @return list<float>
     */
    protected function keyframeBoundaries(float $target): array
    {
        $boundaries = [0.0];
        $last = 0.0;

        foreach ($this->keyframes as $keyframe) {
            if ($keyframe >= $last + $target && $keyframe < $this->duration) {
                $boundaries[] = $last = $keyframe;
            }
        }

        return $boundaries;
    }

    /**
     * @return list<float>
     */
    protected function evenBoundaries(float $target): array
    {
        $boundaries = [];

        for ($start = 0.0; $start < $this->duration; $start += $target) {
            $boundaries[] = $start;
        }

        return $boundaries;
    }
}
