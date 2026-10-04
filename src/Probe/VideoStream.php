<?php

declare(strict_types=1);

namespace Foxws\Media\Probe;

readonly class VideoStream extends Stream
{
    /**
     * @param  array<string, string>  $tags
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        int $index,
        string $codecType,
        ?string $codecName,
        ?float $duration,
        ?int $bitRate,
        ?string $language,
        array $tags,
        array $raw,
        public ?int $width,
        public ?int $height,
        public ?float $frameRate,
        public ?string $pixelFormat,
        public bool $attachedPicture,
        public ?string $colorTransfer = null,
        public ?string $colorPrimaries = null,
        public ?string $colorSpace = null,
    ) {
        parent::__construct($index, $codecType, $codecName, $duration, $bitRate, $language, $tags, $raw);
    }

    /**
     * @param  array<string, mixed>  $stream
     */
    public static function fromArray(array $stream): self
    {
        return new self(
            ...static::baseAttributes($stream),
            width: static::int($stream['width'] ?? null),
            height: static::int($stream['height'] ?? null),
            frameRate: static::rate($stream['avg_frame_rate'] ?? null) ?? static::rate($stream['r_frame_rate'] ?? null),
            pixelFormat: isset($stream['pix_fmt']) ? (string) $stream['pix_fmt'] : null,
            attachedPicture: (int) data_get($stream, 'disposition.attached_pic', 0) === 1,
            colorTransfer: isset($stream['color_transfer']) ? (string) $stream['color_transfer'] : null,
            colorPrimaries: isset($stream['color_primaries']) ? (string) $stream['color_primaries'] : null,
            colorSpace: isset($stream['color_space']) ? (string) $stream['color_space'] : null,
        );
    }

    /**
     * Whether the video is HDR, using the PQ (HDR10, Dolby Vision) or HLG transfer function.
     * HDR video looks washed out when encoded as SDR without tone mapping.
     */
    public function isHdr(): bool
    {
        return in_array($this->colorTransfer, ['smpte2084', 'arib-std-b67'], true);
    }

    /**
     * Parse a rational like "30000/1001" into frames per second.
     */
    protected static function rate(mixed $value): ?float
    {
        if (! is_string($value) || ! str_contains($value, '/')) {
            return static::float($value);
        }

        [$numerator, $denominator] = array_map(floatval(...), explode('/', $value, 2));

        return $numerator > 0 && $denominator > 0 ? $numerator / $denominator : null;
    }
}
