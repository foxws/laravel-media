<?php

declare(strict_types=1);

use Foxws\Media\Filters\Pad;
use Foxws\Media\Filters\Scale;

it('pads to a size with the video centred', function () {
    expect((string) new Pad(1920, 1080))->toBe('pad=1920:1080:(ow-iw)/2:(oh-ih)/2:color=black')
        ->and((string) new Pad(1920, 1080, 'white'))->toEndWith(':color=white');
});

it('accepts colours and refuses anything that could add a filter', function () {
    foreach (['black', 'White@0.5', '#ff8800', '0xFF8800CC', 'red@0x80', 'blue@.5'] as $color) {
        expect((string) new Pad(400, 300, $color))->toEndWith("color={$color}");
    }

    foreach (['black,hflip', 'black:eval=frame', "black\n", 'black;[0:v]null', ''] as $color) {
        expect(fn () => new Pad(400, 300, $color))->toThrow(InvalidArgumentException::class)
            ->and(fn () => Scale::fit(400, 300, $color))->toThrow(InvalidArgumentException::class);
    }
});
