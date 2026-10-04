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
 * with keyframes at the same times in every rendition so players can switch between them.
 */
final readonly class Ladder
{
    /**
     * @param  list<Rendition>  $renditions
     * @param  string|int|null  $preset  The encoder preset; the codec's default when null.
     * @param  float|null  $keyframeInterval  Seconds between keyframes; media.delivery.segment_duration when null.
     * @param  HardwareAcceleration|null  $hardware  media.ladder.hardware when null.
     */
    public function __construct(
        public array $renditions,
        public VideoCodec $codec = VideoCodec::H264,
        public string|int|null $preset = null,
        public int $audioBitrate = 128,
        public ?float $keyframeInterval = null,
        public ?HardwareAcceleration $hardware = null,
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

        return $this->acceleration()->scale($portrait ? $rendition->height : -2, $portrait ? -2 : $rendition->height);
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
                '-force_key_frames', 'expr:gte(t,n_forced*'.Number::format($this->keyframeInterval ?? Config::float('media.delivery.segment_duration', 6.0)).')',
                '-movflags', '+faststart',
            ],
        );
    }

    public function acceleration(): HardwareAcceleration
    {
        return $this->hardware ?? HardwareAcceleration::configured();
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
