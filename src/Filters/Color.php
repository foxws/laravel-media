<?php

declare(strict_types=1);

namespace Foxws\Media\Filters;

use InvalidArgumentException;

/**
 * Checks colours before they're written into a filter graph, where "," and ":" start new filters and options.
 *
 * @internal
 */
final class Color
{
    /**
     * A colour name, #RRGGBB[AA] or 0xRRGGBB[AA], with an optional @alpha.
     */
    protected const string PATTERN = '/^(?:[a-z]+|(?:#|0x)[0-9a-f]{6}(?:[0-9a-f]{2})?)(?:@(?:0x[0-9a-f]{2}|[01](?:\.[0-9]+)?|\.[0-9]+))?$/iD';

    /**
     * @throws InvalidArgumentException
     */
    public static function validate(string $color): string
    {
        if (preg_match(self::PATTERN, $color) !== 1) {
            throw new InvalidArgumentException("[{$color}] is not a colour. Use a name, #RRGGBB or 0xRRGGBB, with an optional @alpha.");
        }

        return $color;
    }
}
