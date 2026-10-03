<?php

declare(strict_types=1);

namespace Foxws\Media\FFMpeg;

use Foxws\Media\Filters\Filter;
use Foxws\Media\Filters\FilterType;

/**
 * Joins filters of one type into an ffmpeg filter chain.
 *
 * @internal
 */
final class FilterChain
{
    /**
     * @param  list<Filter>  $filters
     */
    public static function of(array $filters, FilterType $type): string
    {
        return implode(',', array_map(
            strval(...),
            array_filter($filters, fn (Filter $filter): bool => $filter->type() === $type),
        ));
    }

    /**
     * The -vf and -af arguments for the filters.
     *
     * @param  list<Filter>  $filters
     * @return list<string>
     */
    public static function arguments(array $filters): array
    {
        $video = self::of($filters, FilterType::Video);
        $audio = self::of($filters, FilterType::Audio);

        return [
            ...($video !== '' ? ['-vf', $video] : []),
            ...($audio !== '' ? ['-af', $audio] : []),
        ];
    }
}
