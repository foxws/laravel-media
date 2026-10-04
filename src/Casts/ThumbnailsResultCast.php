<?php

declare(strict_types=1);

namespace Foxws\Media\Casts;

use Foxws\Media\FFMpeg\ThumbnailsResult;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use JsonException;

/**
 * Stores a ThumbnailsResult in a JSON column. Cast with `ThumbnailsResult::class`.
 *
 * @implements CastsAttributes<ThumbnailsResult|null, ThumbnailsResult|array<string, mixed>|null>
 */
class ThumbnailsResultCast implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     *
     * @throws JsonException
     */
    public function get(mixed $model, string $key, mixed $value, array $attributes): ?ThumbnailsResult
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        $result = json_decode($value, true, 512, JSON_THROW_ON_ERROR);

        /** @var array<string, mixed>|null $result */
        return is_array($result) && $result !== [] ? ThumbnailsResult::fromArray($result) : null;
    }

    /**
     * @param  array<string, mixed>  $attributes
     *
     * @throws JsonException
     */
    public function set(mixed $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        $result = $value instanceof ThumbnailsResult ? $value : ThumbnailsResult::fromArray($value);

        return json_encode($result->toArray(), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
    }
}
