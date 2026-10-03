<?php

declare(strict_types=1);

namespace Foxws\Media\Filters;

final readonly class Rotate implements Filter
{
    protected function __construct(protected string $filter) {}

    public static function clockwise(): self
    {
        return new self('transpose=clock');
    }

    public static function counterClockwise(): self
    {
        return new self('transpose=cclock');
    }

    public static function upsideDown(): self
    {
        return new self('hflip,vflip');
    }

    public static function flipHorizontally(): self
    {
        return new self('hflip');
    }

    public static function flipVertically(): self
    {
        return new self('vflip');
    }

    public function type(): FilterType
    {
        return FilterType::Video;
    }

    public function __toString(): string
    {
        return $this->filter;
    }
}
