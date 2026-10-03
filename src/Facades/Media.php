<?php

declare(strict_types=1);

namespace Foxws\Media\Facades;

use Foxws\Media\MediaFactory;
use Illuminate\Support\Facades\Facade;

/**
 * @method static \Foxws\Media\Opener fromDisk(\Foxws\Media\Filesystem\Disk|\Illuminate\Contracts\Filesystem\Filesystem|string $disk)
 * @method static \Foxws\Media\Opener open(string|list<string> ...$paths)
 *
 * @see MediaFactory
 */
class Media extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return MediaFactory::class;
    }
}
