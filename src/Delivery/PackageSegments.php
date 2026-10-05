<?php

declare(strict_types=1);

namespace Foxws\Media\Delivery;

use Foxws\Media\Exceptions\InvalidMediaException;
use Foxws\Media\Exceptions\SegmentNotFoundException;
use Foxws\Media\MediaFactory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Packages segments of a direct stream ahead of their requests, so they're cached by the time
 * the player asks for them. Segments that got cached in the meantime are skipped.
 */
class PackageSegments implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * @param  list<int>  $segments
     */
    public function __construct(
        public readonly string $disk,
        public readonly string $path,
        public readonly array $segments,
        public readonly ?Track $track,
        public readonly float $segmentDuration,
        public readonly string $cacheDisk,
        public readonly int $stream = 0,
    ) {}

    /**
     * @throws SegmentNotFoundException
     * @throws InvalidMediaException
     */
    public function handle(MediaFactory $media): void
    {
        $stream = $media->fromDisk($this->disk)
            ->open($this->path)
            ->stream()
            ->segmentDuration($this->segmentDuration)
            ->toCache($this->cacheDisk)
            ->lookAhead(0);

        foreach ($this->segments as $segment) {
            $stream->segment(0, $segment, $this->track, $this->stream);
        }
    }

    public function uniqueId(): string
    {
        return hash('xxh128', implode('|', [
            $this->disk,
            $this->path,
            implode(',', $this->segments),
            $this->track?->name($this->stream) ?? 'ts',
            $this->segmentDuration,
            $this->cacheDisk,
        ]));
    }
}
