<?php

declare(strict_types=1);

namespace Foxws\Media\Exceptions;

use RuntimeException;

class InvalidMediaException extends RuntimeException
{
    public static function unknownDuration(string $path): self
    {
        return new self("The duration of {$path} is unknown, so it can't be sampled over time.");
    }

    /**
     * @param  list<string>  $paths
     */
    public static function notConcatenable(array $paths): self
    {
        return new self(sprintf(
            'The files [%s] differ in codecs or dimensions, so they can\'t be joined without re-encoding. Use clips() to join them.',
            implode(', ', $paths),
        ));
    }

    public static function notStreamable(string $path, string $codec): self
    {
        return new self("{$path} can't be streamed as HLS with MPEG-TS segments without re-encoding: [{$codec}] isn't supported. Use H.264 or HEVC video with AAC, MP3 or AC-3 audio.");
    }

    public static function notFragmentable(string $path, string $codec): self
    {
        return new self("{$path} can't be streamed with fragmented MP4 segments without re-encoding: [{$codec}] isn't supported. Use H.264, HEVC, AV1 or VP9 video with AAC, MP3, AC-3, Opus or FLAC audio.");
    }

    public static function notFragmented(): self
    {
        return new self('ffmpeg did not write a fragmented MP4 segment with an initialization segment.');
    }

    public static function noStreams(): self
    {
        return new self('Add at least one stream to package, e.g. with addStreamsFrom().');
    }

    public static function noClips(): self
    {
        return new self('Pass at least one clip to join.');
    }

    public static function noVideo(string $path): self
    {
        return new self("{$path} has no video stream.");
    }
}
