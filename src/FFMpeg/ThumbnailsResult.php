<?php

declare(strict_types=1);

namespace Foxws\Media\FFMpeg;

use Foxws\Media\Filesystem\Disk;

/**
 * The sprite sheets and WebVTT file of a thumbnails export.
 */
final readonly class ThumbnailsResult
{
    /**
     * @param  list<string>  $sprites
     */
    public function __construct(
        public Disk $disk,
        public array $sprites,
        public string $vtt,
        public float $interval,
        public int $count,
    ) {}

    /**
     * Every written path: the sprite sheets, then the WebVTT file.
     *
     * @return list<string>
     */
    public function paths(): array
    {
        return [...$this->sprites, $this->vtt];
    }
}
