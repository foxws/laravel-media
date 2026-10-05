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
     * The ffmpeg stream specifier of one of the track's streams, the first by default.
     */
    public function map(int $stream = 0): string
    {
        return ($this->isVideo() ? '0:v:' : '0:a:').$stream;
    }

    /**
     * The name of the track for one of its streams, in URLs and cache paths: the track itself for
     * the first stream, e.g. "audio", and with the stream's position for the others, e.g. "audio-1".
     */
    public function name(int $stream = 0): string
    {
        return $stream > 0 ? "{$this->value}-{$stream}" : $this->value;
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
