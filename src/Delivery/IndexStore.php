<?php

declare(strict_types=1);

namespace Foxws\Media\Delivery;

use Closure;
use Foxws\Media\Filesystem\Media;

/**
 * Keeps what direct streams learn about a file, its probe and its keyframe index, per version of
 * the file, so it's read with ffprobe only once.
 */
interface IndexStore
{
    /**
     * The stored data of the type ("probe" or "keyframes") for the file's current version, or the
     * callback's result, which is stored.
     *
     * @param  Closure(): array<string, mixed>  $callback
     * @return array<string, mixed>
     */
    public function remember(Media $media, string $type, Closure $callback): array;

    /**
     * Forget everything stored for the file, e.g. before it's deleted.
     */
    public function forget(Media $media): void;
}
