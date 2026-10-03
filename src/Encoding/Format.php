<?php

declare(strict_types=1);

namespace Foxws\Media\Encoding;

/**
 * The output settings for an encode: container, codecs, rate control and stream selection.
 */
final readonly class Format
{
    /**
     * @param  int|null  $videoBitrate  Target video bitrate in kbit/s.
     * @param  int|null  $maxVideoBitrate  Maximum video bitrate in kbit/s.
     * @param  int|null  $bufferSize  Rate control buffer size in kbit.
     * @param  int|null  $audioBitrate  Audio bitrate in kbit/s.
     * @param  list<string>  $arguments  Extra output arguments, placed after the generated ones.
     */
    public function __construct(
        public ?string $container = null,
        public ?VideoCodec $videoCodec = null,
        public ?AudioCodec $audioCodec = null,
        public ?int $crf = null,
        public string|int|null $preset = null,
        public ?int $videoBitrate = null,
        public ?int $maxVideoBitrate = null,
        public ?int $bufferSize = null,
        public ?int $audioBitrate = null,
        public ?int $audioChannels = null,
        public ?int $sampleRate = null,
        public int $passes = 1,
        public bool $withoutVideo = false,
        public bool $withoutAudio = false,
        public bool $withoutSubtitles = false,
        public array $arguments = [],
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
        return new self('mp4', VideoCodec::H264, AudioCodec::Aac, crf: $crf, preset: $preset, arguments: ['-pix_fmt', 'yuv420p', '-movflags', '+faststart']);
    }

    public static function hevc(int $crf = 28, string $preset = 'medium'): self
    {
        return new self('mp4', VideoCodec::Hevc, AudioCodec::Aac, crf: $crf, preset: $preset, arguments: ['-tag:v', 'hvc1', '-movflags', '+faststart']);
    }

    public static function av1(int $crf = 30, int $preset = 8): self
    {
        return new self('mp4', VideoCodec::Av1, AudioCodec::Opus, crf: $crf, preset: $preset, arguments: ['-movflags', '+faststart']);
    }

    /**
     * VP9 in constant quality mode. Add bitrate() for constrained quality.
     */
    public static function vp9(int $crf = 32): self
    {
        return new self('webm', VideoCodec::Vp9, AudioCodec::Opus, crf: $crf, videoBitrate: 0);
    }

    /**
     * AAC audio in an M4A container.
     */
    public static function aac(int $bitrate = 160): self
    {
        return new self('ipod', audioCodec: AudioCodec::Aac, audioBitrate: $bitrate, withoutVideo: true, withoutSubtitles: true);
    }

    public static function mp3(int $bitrate = 192): self
    {
        return new self('mp3', audioCodec: AudioCodec::Mp3, audioBitrate: $bitrate, withoutVideo: true, withoutSubtitles: true);
    }

    public static function opus(int $bitrate = 128): self
    {
        return new self('opus', audioCodec: AudioCodec::Opus, audioBitrate: $bitrate, withoutVideo: true, withoutSubtitles: true);
    }

    public static function flac(): self
    {
        return new self('flac', audioCodec: AudioCodec::Flac, withoutVideo: true, withoutSubtitles: true);
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
     * Constant quality: lower is better quality and larger files.
     */
    public function crf(int $crf): self
    {
        return $this->with(['crf' => $crf]);
    }

    /**
     * The encoder speed preset, e.g. "slow" for libx264 or 6 for libsvtav1.
     */
    public function preset(string|int $preset): self
    {
        return $this->with(['preset' => $preset]);
    }

    /**
     * Target a video bitrate in kbit/s, optionally capped by a maximum rate and buffer size.
     * Combined with crf(), encoders that support it (such as libvpx-vp9) use constrained quality.
     */
    public function bitrate(int $kbps, ?int $max = null, ?int $buffer = null): self
    {
        return $this->with(['videoBitrate' => $kbps, 'maxVideoBitrate' => $max, 'bufferSize' => $buffer]);
    }

    /**
     * The audio bitrate in kbit/s.
     */
    public function audioBitrate(int $kbps): self
    {
        return $this->with(['audioBitrate' => $kbps]);
    }

    public function audioChannels(int $channels): self
    {
        return $this->with(['audioChannels' => $channels]);
    }

    /**
     * The audio sample rate in Hz.
     */
    public function sampleRate(int $hertz): self
    {
        return $this->with(['sampleRate' => $hertz]);
    }

    /**
     * Encode in two passes, to hit the target bitrate more closely. Needs bitrate()
     * and a codec that supports it (libx264 or libvpx-vp9).
     */
    public function twoPass(): self
    {
        return $this->with(['passes' => 2]);
    }

    /**
     * @param  list<string>  $arguments
     */
    public function withArguments(array $arguments): self
    {
        return $this->with(['arguments' => [...$this->arguments, ...$arguments]]);
    }

    public function withoutVideo(): self
    {
        return $this->with(['withoutVideo' => true, 'videoCodec' => null]);
    }

    public function withoutAudio(): self
    {
        return $this->with(['withoutAudio' => true, 'audioCodec' => null]);
    }

    public function withoutSubtitles(): self
    {
        return $this->with(['withoutSubtitles' => true]);
    }

    /**
     * The ffmpeg output arguments for this format.
     *
     * @return list<string>
     */
    public function toArguments(): array
    {
        return [
            ...$this->videoArguments(),
            ...$this->audioArguments(),
            ...($this->withoutSubtitles ? ['-sn'] : []),
            ...$this->arguments,
            ...($this->container !== null ? ['-f', $this->container] : []),
        ];
    }

    /**
     * @return list<string>
     */
    protected function videoArguments(): array
    {
        if ($this->withoutVideo) {
            return ['-vn'];
        }

        return [
            ...($this->videoCodec !== null ? ['-c:v', $this->videoCodec->value] : []),
            ...($this->crf !== null ? ['-crf', (string) $this->crf] : []),
            ...($this->preset !== null ? ['-preset', (string) $this->preset] : []),
            ...($this->videoBitrate !== null ? ['-b:v', $this->kilobits($this->videoBitrate)] : []),
            ...($this->maxVideoBitrate !== null ? ['-maxrate', $this->kilobits($this->maxVideoBitrate)] : []),
            ...($this->bufferSize !== null ? ['-bufsize', $this->kilobits($this->bufferSize)] : []),
        ];
    }

    /**
     * @return list<string>
     */
    protected function audioArguments(): array
    {
        if ($this->withoutAudio) {
            return ['-an'];
        }

        return [
            ...($this->audioCodec !== null ? ['-c:a', $this->audioCodec->value] : []),
            ...($this->audioBitrate !== null ? ['-b:a', $this->kilobits($this->audioBitrate)] : []),
            ...($this->audioChannels !== null ? ['-ac', (string) $this->audioChannels] : []),
            ...($this->sampleRate !== null ? ['-ar', (string) $this->sampleRate] : []),
        ];
    }

    protected function kilobits(int $kbps): string
    {
        return $kbps === 0 ? '0' : "{$kbps}k";
    }

    /**
     * A copy with the given properties changed.
     *
     * @param  array<string, mixed>  $changes
     */
    protected function with(array $changes): self
    {
        return new self(...[...get_object_vars($this), ...$changes]);
    }
}
