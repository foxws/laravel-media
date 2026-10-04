<?php

declare(strict_types=1);

namespace Foxws\Media\Facades;

use Foxws\Media\Delivery\StreamRegistry;
use Illuminate\Support\Facades\Facade;

/**
 * @method static \Foxws\Media\Delivery\StreamDefinition define(string $name, \Closure $resolver)
 * @method static \Foxws\Media\Delivery\StreamDefinition definition(string $name)
 * @method static string url(string $name, array<string, mixed> $parameters = [])
 * @method static string hlsUrl(string $name, array<string, mixed> $parameters = [])
 * @method static string dashUrl(string $name, array<string, mixed> $parameters = [])
 * @method static string chaptersUrl(string $name, array<string, mixed> $parameters = [])
 *
 * @see StreamRegistry
 */
class MediaStream extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return StreamRegistry::class;
    }
}
