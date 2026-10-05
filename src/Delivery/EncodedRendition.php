<?php

declare(strict_types=1);

namespace Foxws\Media\Delivery;

use Foxws\Media\Encoding\Ladder;
use Foxws\Media\Encoding\Rendition;
use Foxws\Media\Probe\Probe;
use Foxws\Media\Probe\VideoStream;

/**
 * A smaller H.264 variant of a direct stream's video, encoded one segment at a time when it's first
 * requested. Every segment is cut where the source's starts and begins with a keyframe, so the
 * rendition lines up with the source, and is encoded with the same settings, so they all share
 * one initialization segment. The profile and level are fixed, so manifests can name the codec
 * before anything is encoded.
 *
 * @internal
 */
final readonly class EncodedRendition
{
    public function __construct(
        public Ladder $ladder,
        public Rendition $rendition,
        public VideoStream $source,
    ) {}

    public function width(): int
    {
        return $this->portrait() ? $this->rendition->height : $this->scaled($this->source->width, $this->source->height);
    }

    public function height(): int
    {
        return $this->portrait() ? $this->scaled($this->source->height, $this->source->width) : $this->rendition->height;
    }

    /**
     * The H.264 level for the frame size: 4.2 up to 1080p (at up to 64 frames per second), 5.1
     * up to 4K at 30, and 5.2 beyond.
     */
    public function level(): int
    {
        $macroblocks = (int) (ceil($this->width() / 16) * ceil($this->height() / 16));

        return match (true) {
            $macroblocks <= 8192 => 42,
            $macroblocks <= 36864 => 51,
            default => 52,
        };
    }

    /**
     * What the rendition's segments will be, for manifests and codec strings.
     */
    public function probe(): Probe
    {
        return Probe::fromArray([
            'streams' => [array_filter([
                'index' => 0,
                'codec_type' => 'video',
                'codec_name' => 'h264',
                'profile' => 'High',
                'level' => $this->level(),
                'width' => $this->width(),
                'height' => $this->height(),
                'pix_fmt' => 'yuv420p',
                'avg_frame_rate' => $this->source->get('avg_frame_rate'),
            ], fn (mixed $value): bool => $value !== null)],
            'format' => ['bit_rate' => (string) ($this->rendition->peakBitrate() * 1000)],
        ]);
    }

    /**
     * A directory name for the rendition's cached segments, which changes with every setting that
     * changes what's encoded.
     */
    public function key(): string
    {
        $settings = [
            $this->ladder->codec->value,
            $this->ladder->preset,
            $this->ladder->acceleration()->value,
            $this->rendition->peakBitrate(),
            $this->rendition->buffer(),
            $this->level(),
        ];

        return "{$this->rendition->height}p-{$this->rendition->bitrate}-".substr(hash('xxh128', serialize($settings)), 0, 8);
    }

    /**
     * The arguments placed before the input, to decode on the GPU when the ladder encodes there.
     *
     * @return list<string>
     */
    public function inputArguments(): array
    {
        return $this->ladder->acceleration()->inputArguments();
    }

    /**
     * The output arguments to encode the segment starting at the given second: scaled, with one
     * keyframe at its start and none elsewhere, in High profile at the rendition's level.
     *
     * @return list<string>
     */
    public function outputArguments(float $start): array
    {
        return [
            '-filter:v', (string) $this->ladder->scale($this->rendition, $this->source),
            ...$this->ladder->keyframesAt([$start])->format($this->rendition)->toArguments(),
            '-profile:v', 'high',
            '-level:v', number_format($this->level() / 10, 1, '.', ''),
            '-an',
            '-sn',
        ];
    }

    protected function portrait(): bool
    {
        return ($this->source->height ?? 0) > ($this->source->width ?? 0);
    }

    /**
     * The side that follows the aspect ratio, rounded to an even number like scale=-2 does.
     */
    protected function scaled(?int $side, ?int $other): int
    {
        if ($side === null || $other === null || $other === 0) {
            return $this->rendition->height;
        }

        return max(2, (int) round($side * $this->rendition->height / $other / 2) * 2);
    }
}
