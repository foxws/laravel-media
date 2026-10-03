<?php

declare(strict_types=1);

namespace Foxws\Media\Probe;

final readonly class ContainerFormat
{
    /**
     * @param  array<string, string>  $tags
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public ?string $name,
        public ?string $longName,
        public ?float $duration,
        public ?int $size,
        public ?int $bitRate,
        public array $tags,
        public array $raw,
    ) {}

    /**
     * @param  array<string, mixed>  $format
     */
    public static function fromArray(array $format): self
    {
        /** @var array<string, string> $tags */
        $tags = is_array($format['tags'] ?? null) ? $format['tags'] : [];

        return new self(
            name: isset($format['format_name']) ? (string) $format['format_name'] : null,
            longName: isset($format['format_long_name']) ? (string) $format['format_long_name'] : null,
            duration: is_numeric($format['duration'] ?? null) ? (float) $format['duration'] : null,
            size: is_numeric($format['size'] ?? null) ? (int) $format['size'] : null,
            bitRate: is_numeric($format['bit_rate'] ?? null) ? (int) $format['bit_rate'] : null,
            tags: $tags,
            raw: $format,
        );
    }

    /**
     * Read any field from the raw ffprobe output, using dot notation.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return data_get($this->raw, $key, $default);
    }
}
