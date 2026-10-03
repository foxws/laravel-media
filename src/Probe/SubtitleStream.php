<?php

declare(strict_types=1);

namespace Foxws\Media\Probe;

readonly class SubtitleStream extends Stream
{
    /**
     * @param  array<string, mixed>  $stream
     */
    public static function fromArray(array $stream): self
    {
        return new self(...static::baseAttributes($stream));
    }

    public function forced(): bool
    {
        return (int) $this->get('disposition.forced', 0) === 1;
    }
}
