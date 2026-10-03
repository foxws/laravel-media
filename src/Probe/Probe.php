<?php

declare(strict_types=1);

namespace Foxws\Media\Probe;

use JsonException;

/**
 * The result of probing a media file with ffprobe.
 */
final readonly class Probe
{
    /**
     * @param  list<Stream>  $streams
     * @param  list<Chapter>  $chapters
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public array $streams,
        public ContainerFormat $format,
        public array $chapters,
        public array $raw,
    ) {}

    /**
     * @throws JsonException
     */
    public static function fromJson(string $json): self
    {
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        return self::fromArray(is_array($data) ? $data : []);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        /** @var list<array<string, mixed>> $streams */
        $streams = array_values(array_filter((array) ($data['streams'] ?? []), is_array(...)));

        /** @var list<array<string, mixed>> $chapters */
        $chapters = array_values(array_filter((array) ($data['chapters'] ?? []), is_array(...)));

        /** @var array<string, mixed> $format */
        $format = is_array($data['format'] ?? null) ? $data['format'] : [];

        return new self(
            streams: array_map(Stream::fromArray(...), $streams),
            format: ContainerFormat::fromArray($format),
            chapters: array_map(Chapter::fromArray(...), $chapters),
            raw: $data,
        );
    }

    /**
     * The duration in seconds, from the container or else the longest stream.
     */
    public function duration(): float
    {
        if ($this->format->duration !== null) {
            return $this->format->duration;
        }

        return (float) max([0.0, ...array_map(fn (Stream $stream): float => $stream->duration ?? 0.0, $this->streams)]);
    }

    public function hasVideo(): bool
    {
        return $this->videoStream() !== null;
    }

    public function hasAudio(): bool
    {
        return $this->audioStreams() !== [];
    }

    /**
     * The first video stream that isn't an attached picture, such as cover art.
     */
    public function videoStream(): ?VideoStream
    {
        foreach ($this->videoStreams() as $stream) {
            if (! $stream->attachedPicture) {
                return $stream;
            }
        }

        return null;
    }

    /**
     * @return list<VideoStream>
     */
    public function videoStreams(): array
    {
        return array_values(array_filter($this->streams, fn (Stream $stream): bool => $stream instanceof VideoStream));
    }

    public function audioStream(): ?AudioStream
    {
        return $this->audioStreams()[0] ?? null;
    }

    /**
     * @return list<AudioStream>
     */
    public function audioStreams(): array
    {
        return array_values(array_filter($this->streams, fn (Stream $stream): bool => $stream instanceof AudioStream));
    }

    /**
     * @return list<SubtitleStream>
     */
    public function subtitleStreams(): array
    {
        return array_values(array_filter($this->streams, fn (Stream $stream): bool => $stream instanceof SubtitleStream));
    }

    public function stream(int $index): ?Stream
    {
        foreach ($this->streams as $stream) {
            if ($stream->index === $index) {
                return $stream;
            }
        }

        return null;
    }

    /**
     * @return list<Chapter>
     */
    public function chapters(): array
    {
        return $this->chapters;
    }

    public function format(): ContainerFormat
    {
        return $this->format;
    }

    /**
     * The decoded ffprobe output.
     *
     * @return array<string, mixed>
     */
    public function raw(): array
    {
        return $this->raw;
    }
}
