<?php

declare(strict_types=1);

namespace Foxws\Media\Probe;

use Illuminate\Contracts\Support\Arrayable;

/**
 * @implements Arrayable<string, mixed>
 */
readonly class Stream implements Arrayable
{
    /**
     * @param  array<string, string>  $tags
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public int $index,
        public string $codecType,
        public ?string $codecName,
        public ?float $duration,
        public ?int $bitRate,
        public ?string $language,
        public array $tags,
        public array $raw,
    ) {}

    /**
     * @param  array<string, mixed>  $stream
     */
    public static function fromArray(array $stream): self
    {
        return match ($stream['codec_type'] ?? null) {
            'video' => VideoStream::fromArray($stream),
            'audio' => AudioStream::fromArray($stream),
            'subtitle' => SubtitleStream::fromArray($stream),
            default => new self(...static::baseAttributes($stream)),
        };
    }

    /**
     * Read any field from the raw ffprobe output, using dot notation.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return data_get($this->raw, $key, $default);
    }

    public function toArray(): array
    {
        return $this->raw;
    }

    /**
     * @param  array<string, mixed>  $stream
     * @return array{index: int, codecType: string, codecName: ?string, duration: ?float, bitRate: ?int, language: ?string, tags: array<string, string>, raw: array<string, mixed>}
     */
    protected static function baseAttributes(array $stream): array
    {
        /** @var array<string, string> $tags */
        $tags = is_array($stream['tags'] ?? null) ? $stream['tags'] : [];

        return [
            'index' => (int) ($stream['index'] ?? 0),
            'codecType' => (string) ($stream['codec_type'] ?? 'data'),
            'codecName' => isset($stream['codec_name']) ? (string) $stream['codec_name'] : null,
            'duration' => static::float($stream['duration'] ?? null),
            'bitRate' => static::int($stream['bit_rate'] ?? null),
            'language' => isset($tags['language']) ? (string) $tags['language'] : null,
            'tags' => $tags,
            'raw' => $stream,
        ];
    }

    protected static function float(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    protected static function int(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
