<?php

declare(strict_types=1);

namespace Foxws\Media\Delivery;

use Foxws\Media\Encoding\Ladder;
use Foxws\Media\Exceptions\InvalidMediaException;
use Foxws\Media\Exceptions\SegmentNotFoundException;
use Foxws\Media\Exceptions\SegmentNotReadyException;
use Foxws\Media\MediaFactory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Config;

/**
 * Packages segments of a direct stream ahead of their requests, so they're cached by the time
 * the player asks for them. Segments that got cached in the meantime, or that a request is still
 * packaging, are skipped. With a ladder, the variant is one of its renditions, encoded on request.
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
        public readonly ?Ladder $renditions = null,
        public readonly int $variant = 0,
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
            ->withRenditions($this->renditions)
            ->lookAhead(0);

        foreach ($this->segments as $segment) {
            try {
                $stream->segment($this->variant, $segment, $this->track, $this->stream);
            } catch (SegmentNotReadyException) {
                continue;
            }
        }
    }

    /**
     * How long the job stays unique: as long as packaging may hold a segment's lock, so a job lost
     * with its worker doesn't keep its segments from being queued again.
     */
    public function uniqueFor(): int
    {
        return Config::integer('media.delivery.lock_timeout', 120);
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
            $this->variant,
        ]));
    }
}
