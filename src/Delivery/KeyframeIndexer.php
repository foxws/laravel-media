<?php

declare(strict_types=1);

namespace Foxws\Media\Delivery;

use Foxws\Media\Concerns\ResolvesFromContainer;
use Foxws\Media\Executables\Executable;
use Foxws\Media\Filesystem\Media;
use Foxws\Media\Process\Runner;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;

/**
 * Finds the keyframes of a video by listing its packets with ffprobe, which reads the container
 * without decoding. Indexes are cached per file version, so a changed file is indexed again.
 */
class KeyframeIndexer
{
    use ResolvesFromContainer;

    public function __construct(protected Runner $runner) {}

    public function index(Media $media, float $duration, bool $hasVideo = true): KeyframeIndex
    {
        if (! $hasVideo) {
            return new KeyframeIndex([], $duration);
        }

        $store = Cache::store(Config::get('media.delivery.cache_store'));

        /** @var array{keyframes: list<float>, duration: float} $cached */
        $cached = $store->remember('media:keyframes:'.$media->versionKey(), Config::integer('media.delivery.index_lifetime', 604800), function () use ($media, $duration): array {
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
            $media->inputPath(),
        ])->output;
    }
}
