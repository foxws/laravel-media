<?php

declare(strict_types=1);

namespace Foxws\Media\Probe;

readonly class AudioStream extends Stream
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
        public ?int $channels,
        public ?string $channelLayout,
        public ?int $sampleRate,
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
            channels: static::int($stream['channels'] ?? null),
            channelLayout: isset($stream['channel_layout']) ? (string) $stream['channel_layout'] : null,
            sampleRate: static::int($stream['sample_rate'] ?? null),
        );
    }
}
