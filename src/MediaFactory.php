<?php

declare(strict_types=1);

namespace Foxws\Media;

use Foxws\Media\Concerns\ResolvesFromContainer;
use Foxws\Media\Filesystem\Disk;
use Illuminate\Contracts\Filesystem\Filesystem;

class MediaFactory
{
    use ResolvesFromContainer;

    public function fromDisk(Disk|Filesystem|string $disk): Opener
    {
        return app(Opener::class)->fromDisk($disk);
    }

    /**
     * Open media from the default disk.
     *
     * @param  string|list<string>  ...$paths
     */
    public function open(string|array ...$paths): Opener
    {
        return app(Opener::class)->open(...$paths);
    }
}
