<?php

declare(strict_types=1);

namespace Foxws\Media\Tests\Fixtures;

use Illuminate\Contracts\Routing\UrlRoutable;

/**
 * A route-bindable record without a database: ids 1 to 9 exist.
 */
class RoutableVideo implements UrlRoutable
{
    public function __construct(public int $id = 0) {}

    public function getRouteKey(): int
    {
        return $this->id;
    }

    public function getRouteKeyName(): string
    {
        return 'id';
    }

    public function resolveRouteBinding($value, $field = null): ?self
    {
        return (int) $value >= 1 && (int) $value <= 9 ? new self((int) $value) : null;
    }

    public function resolveChildRouteBinding($childType, $value, $field): ?self
    {
        return null;
    }
}
