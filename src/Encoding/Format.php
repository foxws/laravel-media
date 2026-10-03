<?php

declare(strict_types=1);

namespace Foxws\Media\Encoding;

/**
 * The output settings for an encode: container, codecs and any extra arguments.
 */
final readonly class Format
{
    /**
     * @param  list<string>  $arguments
     */
    public function __construct(
        public ?string $container = null,
        public ?VideoCodec $videoCodec = null,
        public ?AudioCodec $audioCodec = null,
        public array $arguments = [],
        public bool $withoutVideo = false,
        public bool $withoutAudio = false,
    ) {}

    /**
     * Copy the streams without re-encoding.
     */
    public static function copy(?string $container = null): self
    {
        return new self($container, VideoCodec::Copy, AudioCodec::Copy);
    }

    public static function h264(int $crf = 23, string $preset = 'medium'): self
    {
        return new self('mp4', VideoCodec::H264, AudioCodec::Aac, ['-crf', (string) $crf, '-preset', $preset, '-pix_fmt', 'yuv420p', '-movflags', '+faststart']);
    }

    public static function hevc(int $crf = 28, string $preset = 'medium'): self
    {
        return new self('mp4', VideoCodec::Hevc, AudioCodec::Aac, ['-crf', (string) $crf, '-preset', $preset, '-tag:v', 'hvc1', '-movflags', '+faststart']);
    }

    public static function av1(int $crf = 30, int $preset = 8): self
    {
        return new self('mp4', VideoCodec::Av1, AudioCodec::Opus, ['-crf', (string) $crf, '-preset', (string) $preset, '-movflags', '+faststart']);
    }

    public static function vp9(int $crf = 32): self
    {
        return new self('webm', VideoCodec::Vp9, AudioCodec::Opus, ['-crf', (string) $crf, '-b:v', '0']);
    }

    /**
     * Convert subtitles to WebVTT.
     */
    public static function webVtt(): self
    {
        return new self('webvtt', withoutVideo: true, withoutAudio: true);
    }

    /**
     * A single JPEG image, such as a thumbnail or storyboard.
     */
    public static function jpeg(int $quality = 2): self
    {
        return new self('image2', arguments: ['-q:v', (string) $quality], withoutAudio: true);
    }

    /**
     * @param  list<string>  $arguments
     */
    public function withArguments(array $arguments): self
    {
        return new self($this->container, $this->videoCodec, $this->audioCodec, [...$this->arguments, ...$arguments], $this->withoutVideo, $this->withoutAudio);
    }

    public function withoutAudio(): self
    {
        return new self($this->container, $this->videoCodec, null, $this->arguments, $this->withoutVideo, true);
    }

    /**
     * The ffmpeg output arguments for this format.
     *
     * @return list<string>
     */
    public function toArguments(): array
    {
        return [
            ...($this->withoutVideo ? ['-vn'] : ($this->videoCodec ? ['-c:v', $this->videoCodec->value] : [])),
            ...($this->withoutAudio ? ['-an'] : ($this->audioCodec ? ['-c:a', $this->audioCodec->value] : [])),
            ...$this->arguments,
            ...($this->container ? ['-f', $this->container] : []),
        ];
    }
}
