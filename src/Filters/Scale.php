<?php

declare(strict_types=1);

namespace Foxws\Media\Filters;

/**
 * Resize the video, keeping the aspect ratio unless both sides are given.
 */
final readonly class Scale implements Filter
{
    protected function __construct(protected string $filter) {}

    /**
     * Scale to the given width and/or height. A missing side follows the
     * aspect ratio, rounded to an even number as most encoders require.
     */
    public static function to(?int $width = null, ?int $height = null): self
    {
        return new self(sprintf('scale=%d:%d', $width ?? -2, $height ?? -2));
    }

    /**
     * Fit inside the box and pad the rest (letterboxing), so the output is exactly width × height.
     */
    public static function fit(int $width, int $height, string $color = 'black'): self
    {
        return new self(sprintf(
            'scale=%1$d:%2$d:force_original_aspect_ratio=decrease,pad=%1$d:%2$d:(ow-iw)/2:(oh-ih)/2:color=%3$s,setsar=1',
            $width,
            $height,
            $color,
        ));
    }

    /**
     * Fill the box and crop the overflow, so the output is exactly width × height.
     */
    public static function fill(int $width, int $height): self
    {
        return new self(sprintf(
            'scale=%1$d:%2$d:force_original_aspect_ratio=increase,crop=%1$d:%2$d,setsar=1',
            $width,
            $height,
        ));
    }

    public function type(): FilterType
    {
        return FilterType::Video;
    }

    public function __toString(): string
    {
        return $this->filter;
    }
}
