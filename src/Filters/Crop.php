<?php

declare(strict_types=1);

namespace Foxws\Media\Filters;

/**
 * Cut out a width × height area, centred unless x and y are given.
 */
final readonly class Crop implements Filter
{
    public function __construct(
        public int $width,
        public int $height,
        public ?int $x = null,
        public ?int $y = null,
    ) {}

    public function type(): FilterType
    {
        return FilterType::Video;
    }

    public function __toString(): string
    {
        return $this->x === null && $this->y === null
            ? "crop={$this->width}:{$this->height}"
            : sprintf('crop=%d:%d:%d:%d', $this->width, $this->height, $this->x ?? 0, $this->y ?? 0);
    }
}
