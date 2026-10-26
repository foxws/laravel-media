<?php

declare(strict_types=1);

namespace Foxws\Media\Delivery;

use Foxws\Media\Concerns\ResolvesFromContainer;
use Foxws\Media\Executables\Executable;
use Foxws\Media\Filesystem\Media;
use Foxws\Media\Process\Runner;

/**
 * Finds the keyframes of a video by listing its packets with ffprobe, which reads the container
 * without decoding. Indexes are kept in the IndexStore per file version, so a changed file is
 * indexed again.
 */
class KeyframeIndexer
{
    use ResolvesFromContainer;

    public function __construct(
        protected Runner $runner,
        protected IndexStore $store,
    ) {}

    public function index(Media $media, float $duration, bool $hasVideo = true): KeyframeIndex
    {
        if (! $hasVideo) {
            return new KeyframeIndex([], $duration);
        }

        /** @var array{keyframes: list<float>, duration: float} $cached */
        $cached = $this->store->remember($media, 'keyframes', function () use ($media, $duration): array {
            $index = KeyframeIndex::fromPackets($this->packets($media), $duration);

            return ['keyframes' => $index->keyframes, 'duration' => $index->duration];
        });

        return new KeyframeIndex($cached['keyframes'], $cached['duration']);
    }

    protected function packets(Media $media): string
    {
        return $this->runner->run(Executable::FFProbe, [
            '-v', 'error',
            '-select_streams', 'v:0',
            '-show_entries', 'packet=pts_time,flags',
            '-of', 'csv=print_section=0',
            ...$media->inputArguments(),
            $media->inputPath(),
        ])->output;
    }
}
