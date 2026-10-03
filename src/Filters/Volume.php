<?php

declare(strict_types=1);

namespace Foxws\Media\Filters;

final readonly class Volume implements Filter
{
    protected function __construct(protected string $volume) {}

    /**
     * Multiply the volume, e.g. 0.5 for half or 2 for double.
     */
    public static function times(float $factor): self
    {
        return new self(Number::format($factor));
    }

    /**
     * Change the volume by the given decibels, e.g. -6 or 3.
     */
    public static function decibels(float $decibels): self
    {
        return new self(Number::format($decibels).'dB');
    }

    public function type(): FilterType
    {
        return FilterType::Audio;
    }

    public function __toString(): string
    {
        return "volume={$this->volume}";
    }
}
