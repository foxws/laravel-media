<?php

declare(strict_types=1);

namespace Foxws\Media\Filters;

/**
 * Where an overlay, such as a watermark, is placed on the video.
 */
enum Position
{
    case TopLeft;
    case TopRight;
    case BottomLeft;
    case BottomRight;
    case Center;

    /**
     * The overlay filter's x and y expressions, with the margin in pixels from the edges.
     */
    public function overlay(int $margin = 0): string
    {
        return match ($this) {
            self::TopLeft => "x={$margin}:y={$margin}",
            self::TopRight => "x=W-w-{$margin}:y={$margin}",
            self::BottomLeft => "x={$margin}:y=H-h-{$margin}",
            self::BottomRight => "x=W-w-{$margin}:y=H-h-{$margin}",
            self::Center => 'x=(W-w)/2:y=(H-h)/2',
        };
    }
}
