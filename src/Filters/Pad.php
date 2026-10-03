<?php

declare(strict_types=1);

namespace Foxws\Media\Filters;

/**
 * Add borders up to width × height, with the video centred.
 */
final readonly class Pad implements Filter
{
    public function __construct(
        public int $width,
        public int $height,
        public string $color = 'black',
    ) {}

    public function type(): FilterType
    {
        return FilterType::Video;
    }

    public function __toString(): string
    {
        return "pad={$this->width}:{$this->height}:(ow-iw)/2:(oh-ih)/2:color={$this->color}";
    }
}
