<?php

declare(strict_types=1);

use Foxws\Media\Process\Progress;

it('calculates the percentage and remaining time from the duration and speed', function () {
    $progress = new Progress(seconds: 30, duration: 120, speed: 2);

    expect($progress->percentage())->toBe(25.0)
        ->and($progress->remaining())->toBe(45.0);
});

it('spreads the percentage and remaining time over every pass', function () {
    $first = new Progress(seconds: 60, duration: 120, speed: 4, pass: 1, passes: 2);
    $second = $first->forPass(2, 2);

    expect($first->percentage())->toBe(25.0)
        ->and($first->remaining())->toBe(45.0)
        ->and($second->percentage())->toBe(75.0)
        ->and($second->remaining())->toBe(15.0);
});

it('is complete when the last pass finishes', function () {
    expect(new Progress(seconds: 119.9, duration: 120, finished: true)->percentage())->toBe(100.0)
        ->and(new Progress(seconds: 120, duration: 120, finished: true, pass: 1, passes: 2)->percentage())->toBe(50.0);
});

it('does not guess without a duration or speed', function () {
    expect(new Progress(seconds: 30)->percentage())->toBeNull()
        ->and(new Progress(seconds: 30, duration: 120)->remaining())->toBeNull();
});

it('never reports more than the pass length', function () {
    expect(new Progress(seconds: 130, duration: 120)->percentage())->toBe(100.0);
});
