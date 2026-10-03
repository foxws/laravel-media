<?php

declare(strict_types=1);

namespace Foxws\Media\Filters;

/**
 * Fade the video from or to black, or the audio from or to silence.
 */
final readonly class Fade implements Filter
{
    protected function __construct(
        public string $direction,
        public float $duration,
        public float $start,
        public FilterType $filterType,
    ) {}

    public static function in(float $duration, float $start = 0.0): self
    {
        return new self('in', $duration, $start, FilterType::Video);
    }

    /**
     * Fade out over the given duration, starting at the given second (e.g. the duration minus the fade).
     */
    public static function out(float $duration, float $start): self
    {
        return new self('out', $duration, $start, FilterType::Video);
    }

    public static function audioIn(float $duration, float $start = 0.0): self
    {
        return new self('in', $duration, $start, FilterType::Audio);
    }

    public static function audioOut(float $duration, float $start): self
    {
        return new self('out', $duration, $start, FilterType::Audio);
    }

    public function type(): FilterType
    {
        return $this->filterType;
    }

    public function __toString(): string
    {
        return sprintf(
            '%s=t=%s:st=%s:d=%s',
            $this->filterType === FilterType::Audio ? 'afade' : 'fade',
            $this->direction,
            Number::format($this->start),
            Number::format($this->duration),
        );
    }
}
