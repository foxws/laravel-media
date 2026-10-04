<?php

declare(strict_types=1);

use Foxws\Media\Delivery\KeyframeIndex;
use Foxws\Media\Delivery\Segment;

it('reads the keyframes from ffprobe packets', function () {
    $index = KeyframeIndex::fromPackets("0.000000,K__\n0.033367,___\n4.004000,K__\nN/A,K__\n2.002000,K_D\n4.004000,K__\n", 10);

    expect($index->keyframes)->toBe([0.0, 2.002, 4.004])
        ->and($index->duration)->toBe(10.0);
});

it('cuts segments of at least the target length on keyframes', function () {
    $index = new KeyframeIndex([0, 2, 4, 6, 8, 10, 12, 14], 15.5);

    expect($index->segments(6))->toEqual([
        new Segment(0, 0.0, 6.0),
        new Segment(1, 6.0, 6.0),
        new Segment(2, 12.0, 3.5),
    ]);
});

it('makes longer segments when keyframes are far apart', function () {
    $index = new KeyframeIndex([0, 10, 20], 25);

    expect(array_map(fn (Segment $segment) => [$segment->start, $segment->duration], $index->segments(6)))->toBe([
        [0.0, 10.0],
        [10.0, 10.0],
        [20.0, 5.0],
    ])->and($index->longestSegment(6))->toBe(10.0);
});

it('handles keyframes that do not start at zero and irregular intervals', function () {
    $index = new KeyframeIndex([0.083, 5.5, 6.2, 13.9], 20);

    expect(array_map(fn (Segment $segment) => [$segment->start, $segment->end()], $index->segments(6)))->toBe([
        [0.0, 6.2],
        [6.2, 13.9],
        [13.9, 20.0],
    ]);
});

it('splits audio without keyframes into even segments', function () {
    expect(array_map(fn (Segment $segment) => [$segment->index, $segment->start, $segment->duration], new KeyframeIndex([], 13)->segments(6)))->toBe([
        [0, 0.0, 6.0],
        [1, 6.0, 6.0],
        [2, 12.0, 1.0],
    ]);
});

it('has no segments without a duration and rejects a zero target', function () {
    expect(new KeyframeIndex([0], 0)->segments())->toBe([])
        ->and(fn () => new KeyframeIndex([0], 10)->segments(0))->toThrow(InvalidArgumentException::class);
});
