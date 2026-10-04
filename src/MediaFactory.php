<?php

declare(strict_types=1);

namespace Foxws\Media;

use Foxws\Media\Concerns\ResolvesFromContainer;
use Foxws\Media\Filesystem\Disk;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Traits\Macroable;

/**
 * Opens media; packages can add their own entry points with macros, which the Media facade forwards.
 */
class MediaFactory
{
    use Macroable;
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
