<?php

declare(strict_types=1);

namespace Foxws\Media\Concerns;

trait ResolvesFromContainer
{
    /**
     * Resolve the class from the container, so bindings and fakes apply.
     */
    public static function make(): static
    {
        return app(static::class);
    }
}
