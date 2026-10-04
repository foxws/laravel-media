<?php

declare(strict_types=1);

namespace Foxws\Media\Delivery;

use Foxws\Media\Filesystem\Disk;

/**
 * A WebVTT subtitle track of a direct stream: an external file on a disk, or a text subtitle
 * stream of the first opened file, converted to WebVTT when it's first requested.
 */
final readonly class Subtitle
{
    public function __construct(
        public string $label,
        public ?string $language = null,
        public ?Disk $disk = null,
        public ?string $path = null,
        public ?int $stream = null,
    ) {}

    public function isEmbedded(): bool
    {
        return $this->stream !== null;
    }
}
