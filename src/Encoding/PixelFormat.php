<?php

declare(strict_types=1);

namespace Foxws\Media\Encoding;

/**
 * The 4:2:0 pixel formats browsers decode, by their ffmpeg name.
 */
enum PixelFormat: string
{
    case Yuv420p = 'yuv420p';
    case Yuvj420p = 'yuvj420p';
    case Yuv420p10le = 'yuv420p10le';
    case Yuv420p10be = 'yuv420p10be';

    public function bitDepth(): int
    {
        return match ($this) {
            self::Yuv420p, self::Yuvj420p => 8,
            self::Yuv420p10le, self::Yuv420p10be => 10,
        };
    }
}
