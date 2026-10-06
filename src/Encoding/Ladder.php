<?php

declare(strict_types=1);

namespace Foxws\Media\Encoding;

use Foxws\Media\Filters\Filter;
use Foxws\Media\Filters\Number;
use Foxws\Media\Probe\VideoStream;
use Illuminate\Support\Facades\Config;
use InvalidArgumentException;

/**
 * The renditions to encode a video into for adaptive streaming, each at its own size and bitrate,
 * with keyframes at the same times in every rendition so players can switch between them. Aligned
 * to the source, the renditions get keyframes where the source's direct stream segments start, so
 * the untouched source can be streamed as the top variant.
 */
final readonly class Ladder
{
    /**
     * @param  list<Rendition>  $renditions
     * @param  string|int|null  $preset  The encoder preset; the codec's default when null.
     * @param  float|null  $keyframeInterval  Seconds between keyframes; media.delivery.segment_duration when null.
     * @param  HardwareAcceleration|null  $hardware  media.ladder.hardware when null.
     * @param  bool  $alignToSource  Whether ladder() places the keyframes where the source's segments start.
     * @param  list<float>  $keyframes  Seconds to place the keyframes at, instead of every keyframe interval.
     * @param  bool  $hardwareDecoding  Whether the GPU decodes the source, or the CPU decodes it and uploads the frames.
     */
    public function __construct(
        public array $renditions,
        public VideoCodec $codec = VideoCodec::H264,
        public string|int|null $preset = null,
        public int $audioBitrate = 128,
        public ?float $keyframeInterval = null,
        public ?HardwareAcceleration $hardware = null,
        public bool $alignToSource = false,
        public array $keyframes = [],
        public bool $hardwareDecoding = true,
    ) {
        if ($renditions === []) {
            throw new InvalidArgumentException('A ladder needs at least one rendition.');
        }

        if (! in_array($codec, [VideoCodec::H264, VideoCodec::Hevc, VideoCodec::Av1], true)) {
            throw new InvalidArgumentException("Ladders encode H.264, HEVC or AV1, not [{$codec->value}].");
        }

        if ($keyframeInterval !== null && $keyframeInterval <= 0) {
            throw new InvalidArgumentException('The keyframe interval must be a positive number of seconds.');
        }
    }

    /**
     * 1080p, 720p, 480p and 360p at common H.264 bitrates.
     */
    public static function standard(): self
    {
        return new self([
            new Rendition(1080, 5000),
            new Rendition(720, 2800),
            new Rendition(480, 1400),
            new Rendition(360, 800),
        ]);
    }

    public function codec(VideoCodec $codec, string|int|null $preset = null): self
    {
        return $this->with(['codec' => $codec, 'preset' => $preset]);
    }

    public function audioBitrate(int $kbps): self
    {
        return $this->with(['audioBitrate' => $kbps]);
    }

    public function keyframeInterval(float $seconds): self
    {
        return $this->with(['keyframeInterval' => $seconds]);
    }

    public function hardware(HardwareAcceleration $hardware): self
    {
        return $this->with(['hardware' => $hardware]);
    }

    /**
     * Decode the source on the GPU, or on the CPU and upload the frames to the GPU to scale and
     * encode them, for sources the GPU can't decode.
     */
    public function hardwareDecoding(bool $decode = true): self
    {
        return $this->with(['hardwareDecoding' => $decode]);
    }

    /**
     * Place the keyframes where the source's direct stream segments start, each the keyframe
     * interval or longer, so the source and its renditions are split into the same segments and
     * players can switch between them. Streams of the source should use the same segment duration.
     */
    public function alignToSource(bool $align = true): self
    {
        return $this->with(['alignToSource' => $align]);
    }

    /**
     * Place the keyframes at the given seconds, and nowhere else.
     *
     * @param  list<float>  $seconds
     */
    public function keyframesAt(array $seconds): self
    {
        $seconds = array_values(array_unique(array_map(fn (float $second): float => max(0.0, $second), $seconds), SORT_REGULAR));
        sort($seconds);

        return $this->with(['keyframes' => $seconds]);
    }

    /**
     * The seconds between keyframes, which is also the shortest segment when aligned to the source.
     */
    public function interval(): float
    {
        return $this->keyframeInterval ?? Config::float('media.delivery.segment_duration', 6.0);
    }

    /**
     * The renditions no larger than the source, which is never upscaled. A source smaller than every
     * rendition gets the smallest one at its own size.
     *
     * @return list<Rendition>
     */
    public function for(VideoStream $source): array
    {
        $sides = array_filter([$source->width, $source->height], fn (?int $side): bool => $side !== null && $side > 0);
        $sourceHeight = $sides !== [] ? min($sides) : PHP_INT_MAX;
        $renditions = array_values(array_filter($this->renditions, fn (Rendition $rendition): bool => $rendition->height <= $sourceHeight));

        if ($renditions !== []) {
            return $renditions;
        }

        $smallest = array_reduce($this->renditions, fn (?Rendition $carry, Rendition $rendition): Rendition => $carry === null || $rendition->height < $carry->height ? $rendition : $carry)
            ?? throw new InvalidArgumentException('A ladder needs at least one rendition.');

        return [new Rendition(intdiv($sourceHeight, 2) * 2, $smallest->bitrate, $smallest->maxBitrate, $smallest->bufferSize)];
    }

    /**
     * The scale filter of a rendition, scaling the short side of landscape or portrait video.
     */
    public function scale(Rendition $rendition, VideoStream $source): Filter
    {
        $portrait = ($source->height ?? 0) > ($source->width ?? 0);

        return $this->acceleration()->scale($portrait ? $rendition->height : -2, $portrait ? -2 : $rendition->height, uploaded: ! $this->hardwareDecoding);
    }

    /**
     * The arguments placed before the input: those that decode on the GPU, or that open it for
     * frames decoded on the CPU.
     *
     * @return list<string>
     */
    public function inputArguments(): array
    {
        $hardware = $this->acceleration();

        return $this->hardwareDecoding ? $hardware->inputArguments() : $hardware->uploadArguments();
    }

    /**
     * The output format of a rendition: its bitrates, AAC audio and keyframes at fixed times, with
     * scene-cut keyframes turned off so every rendition has the same ones.
     */
    public function format(Rendition $rendition): Format
    {
        $hardware = $this->acceleration();
        $software = $hardware === HardwareAcceleration::None;
        $peak = $this->codec !== VideoCodec::Av1 || ! $software;

        return new Format(
            container: 'mp4',
            videoCodec: $software ? $this->codec : null,
            audioCodec: AudioCodec::Aac,
            preset: $software ? ($this->preset ?? ($this->codec === VideoCodec::Av1 ? 8 : 'medium')) : null,
            videoBitrate: $rendition->bitrate,
            maxVideoBitrate: $peak ? $rendition->peakBitrate() : null,
            bufferSize: $peak ? $rendition->buffer() : null,
            audioBitrate: $this->audioBitrate,
            arguments: [
                ...($software ? [] : ['-c:v', $hardware->encoder($this->codec)]),
                ...$this->codecArguments($software),
                ...$this->keyframeArguments(),
                '-movflags', '+faststart',
            ],
        );
    }

    public function acceleration(): HardwareAcceleration
    {
        return $this->hardware ?? HardwareAcceleration::configured();
    }

    /**
     * Keyframes every interval, or at the given seconds only. Those are forced a millisecond early,
     * so they land on the frame at that time, and other keyframes are capped at the longest GOP
     * every encoder accepts, so they can't start segments of their own.
     *
     * @return list<string>
     */
    protected function keyframeArguments(): array
    {
        if ($this->keyframes === []) {
            return ['-force_key_frames', 'expr:gte(t,n_forced*'.Number::format($this->interval()).')'];
        }

        return [
            '-force_key_frames', implode(',', array_map(fn (float $second): string => Number::format(max(0.0, $second - 0.001)), $this->keyframes)),
            '-g', '65535',
        ];
    }

    /**
     * @return list<string>
     */
    protected function codecArguments(bool $software): array
    {
        return match (true) {
            ! $software => $this->codec === VideoCodec::Hevc ? ['-tag:v', 'hvc1'] : [],
            $this->codec === VideoCodec::H264 => ['-pix_fmt', 'yuv420p', '-sc_threshold', '0'],
            $this->codec === VideoCodec::Hevc => ['-tag:v', 'hvc1', '-x265-params', 'scenecut=0'],
            default => ['-svtav1-params', 'scd=0'],
        };
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    protected function with(array $changes): self
    {
        return new self(...[...get_object_vars($this), ...$changes]);
    }
}
