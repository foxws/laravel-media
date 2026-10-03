<?php

declare(strict_types=1);

namespace Foxws\Media\Filters;

/**
 * Formats numbers for filter graphs without trailing zeros or locale separators.
 *
 * @internal
 */
final class Number
{
    public static function format(float $number): string
    {
        $formatted = rtrim(rtrim(number_format($number, 3, '.', ''), '0'), '.');

        return $formatted === '-0' ? '0' : $formatted;
    }
}
