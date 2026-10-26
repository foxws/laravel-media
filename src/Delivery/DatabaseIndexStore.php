<?php

declare(strict_types=1);

namespace Foxws\Media\Delivery;

use Closure;
use Foxws\Media\Filesystem\Media;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * Keeps probes and keyframe indexes in the media_indexes table, on the media.delivery.index_connection,
 * without expiring them. Rows of changed or deleted files are removed by media:prune.
 */
class DatabaseIndexStore implements IndexStore
{
    public const string TABLE = 'media_indexes';

    public function remember(Media $media, string $type, Closure $callback): array
    {
        $key = $media->versionKey();

        $stored = $this->table()->where('version_key', $key)->where('type', $type)->value('data');

        if (is_string($stored)) {
            /** @var array<string, mixed> */
            return json_decode($stored, true, flags: JSON_THROW_ON_ERROR);
        }

        $data = $callback();

        $this->table()->insertOrIgnore([
            'version_key' => $key,
            'type' => $type,
            'disk' => $media->disk()->name(),
            'path' => $media->path(),
            'data' => json_encode($data, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $data;
    }

    public function forget(Media $media): void
    {
        $this->table()->where('disk', $media->disk()->name())->where('path', $media->path())->delete();
    }

    /**
     * The disk, path and version key of every stored file version, for media:prune.
     *
     * @return iterable<int, object{disk: string, path: string, version_key: string}>
     */
    public function versions(): iterable
    {
        /** @var iterable<int, object{disk: string, path: string, version_key: string}> */
        return $this->table()->select(['disk', 'path', 'version_key'])->distinct()->orderBy('version_key')->lazy();
    }

    /**
     * @param  list<string>  $keys
     */
    public function delete(array $keys): int
    {
        return $this->table()->whereIn('version_key', $keys)->delete();
    }

    protected function table(): Builder
    {
        return DB::connection(Config::get('media.delivery.index_connection'))->table(self::TABLE);
    }
}
