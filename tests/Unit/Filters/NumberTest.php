<?php

declare(strict_types=1);

use Foxws\Media\Filters\Number;

it('formats numbers without trailing zeros or locale separators', function (float $number, string $expected) {
    expect(Number::format($number))->toBe($expected);
})->with([
    'integer' => [30.0, '30'],
    'fraction' => [0.5, '0.5'],
    'rounded' => [1.23456, '1.235'],
    'thousands' => [1920.25, '1920.25'],
    'negative zero' => [-0.0001, '0'],
]);

it('formats with the given number of decimals', function () {
    expect(Number::format(30.25, 4))->toBe('30.25')
        ->and(Number::format(95.123456, 4))->toBe('95.1235')
        ->and(Number::format(30.0, 0))->toBe('30')
        ->and(Number::format(100.4, 0))->toBe('100');
});
