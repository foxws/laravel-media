<?php

declare(strict_types=1);

namespace Foxws\Media\Filters;

/**
 * Formats numbers for filter graphs and command-line options without trailing zeros or locale separators.
 */
final class Number
{
    public static function format(float $number, int $decimals = 3): string
    {
        $formatted = number_format($number, max(0, $decimals), '.', '');

        if (str_contains($formatted, '.')) {
            $formatted = rtrim(rtrim($formatted, '0'), '.');
        }

        return $formatted === '-0' ? '0' : $formatted;
    }
}
