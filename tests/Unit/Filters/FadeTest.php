<?php

declare(strict_types=1);

use Foxws\Media\Filters\Fade;
use Foxws\Media\Filters\FilterType;

it('fades the video in and out', function () {
    expect((string) Fade::in(1.5))->toBe('fade=t=in:st=0:d=1.5')
        ->and((string) Fade::out(2, start: 58))->toBe('fade=t=out:st=58:d=2')
        ->and(Fade::in(1)->type())->toBe(FilterType::Video);
});

it('fades the audio in and out', function () {
    expect((string) Fade::audioIn(0.5))->toBe('afade=t=in:st=0:d=0.5')
        ->and((string) Fade::audioOut(1, start: 9.25))->toBe('afade=t=out:st=9.25:d=1')
        ->and(Fade::audioIn(1)->type())->toBe(FilterType::Audio);
});
