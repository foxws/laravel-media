<?php

declare(strict_types=1);

namespace Foxws\Media\Probe;

final readonly class Chapter
{
    public function __construct(
        public ?string $title,
        public float $start,
        public float $end,
    ) {}

    /**
     * @param  array<string, mixed>  $chapter
     */
    public static function fromArray(array $chapter): self
    {
        $title = data_get($chapter, 'tags.title');

        return new self(
            title: is_string($title) && $title !== '' ? $title : null,
            start: (float) ($chapter['start_time'] ?? 0),
            end: (float) ($chapter['end_time'] ?? 0),
        );
    }

    public function duration(): float
    {
        return max(0.0, $this->end - $this->start);
    }
}
