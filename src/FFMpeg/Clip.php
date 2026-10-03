<?php

declare(strict_types=1);

namespace Foxws\Media\FFMpeg;

use InvalidArgumentException;

/**
 * A part of an opened file, used to build a reel.
 */
final readonly class Clip
{
    /**
     * @param  string|null  $path  An opened path; the first opened file when null.
     */
    public function __construct(
        public float $from,
        public float $to,
        public ?string $path = null,
    ) {
        if ($from < 0 || $to <= $from) {
            throw new InvalidArgumentException("A clip must end after it starts, got {$from} to {$to}.");
        }
    }

    public static function make(float $from, float $to, ?string $path = null): self
    {
        return new self($from, $to, $path);
    }

    public function duration(): float
    {
        return $this->to - $this->from;
    }
}
