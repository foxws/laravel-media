<?php

declare(strict_types=1);

namespace Foxws\Media\Filters;

/**
 * Any ffmpeg filter (or comma-separated chain) the package has no class for.
 */
final readonly class Custom implements Filter
{
    public function __construct(
        public string $filter,
        public FilterType $filterType = FilterType::Video,
    ) {}

    public static function video(string $filter): self
    {
        return new self($filter, FilterType::Video);
    }

    public static function audio(string $filter): self
    {
        return new self($filter, FilterType::Audio);
    }

    public function type(): FilterType
    {
        return $this->filterType;
    }

    public function __toString(): string
    {
        return $this->filter;
    }
}
