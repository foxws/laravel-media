<?php

declare(strict_types=1);

namespace Foxws\Media\Delivery;

/**
 * A track of a variant, packaged into its own fragmented MP4 segments, as CMAF and DASH expect.
 * The I-frames track holds only the keyframe each video segment starts with, for trick play.
 */
enum Track: string
{
    case Video = 'video';
    case Audio = 'audio';
    case IFrames = 'iframes';

    /**
     * The ffmpeg stream specifier of the track's first stream.
     */
    public function map(): string
    {
        return match ($this) {
            self::Video, self::IFrames => '0:v:0',
            self::Audio => '0:a:0',
        };
    }

    public function isVideo(): bool
    {
        return $this !== self::Audio;
    }

    public function contentType(): string
    {
        return $this->isVideo() ? 'video/mp4' : 'audio/mp4';
    }
}
