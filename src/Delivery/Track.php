<?php

declare(strict_types=1);

namespace Foxws\Media\Delivery;

/**
 * A track of a variant, packaged into its own fragmented MP4 segments, as CMAF and DASH expect.
 */
enum Track: string
{
    case Video = 'video';
    case Audio = 'audio';

    /**
     * The ffmpeg stream specifier of the track's first stream.
     */
    public function map(): string
    {
        return match ($this) {
            self::Video => '0:v:0',
            self::Audio => '0:a:0',
        };
    }

    public function contentType(): string
    {
        return "{$this->value}/mp4";
    }
}
