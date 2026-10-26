<?php

declare(strict_types=1);

namespace Foxws\Media\Delivery;

use Closure;
use Foxws\Media\Filesystem\Media;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;

/**
 * Keeps probes and keyframe indexes in the media.delivery.cache_store for index_lifetime seconds.
 */
class CacheIndexStore implements IndexStore
{
    public const array TYPES = ['probe', 'keyframes'];

    public function remember(Media $media, string $type, Closure $callback): array
    {
        /** @var array<string, mixed> */
        return $this->store()->remember(
            "media:{$type}:{$media->versionKey()}",
            Config::integer('media.delivery.index_lifetime', 604800),
            $callback,
        );
    }

    public function forget(Media $media): void
    {
        foreach (self::TYPES as $type) {
            $this->store()->forget("media:{$type}:{$media->versionKey()}");
        }
    }

    protected function store(): Repository
    {
        return Cache::store(Config::get('media.delivery.cache_store'));
    }
}
