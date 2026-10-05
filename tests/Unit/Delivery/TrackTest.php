<?php

declare(strict_types=1);

use Foxws\Media\Delivery\Track;

it('maps the first stream of its type with its content type', function () {
    expect(Track::Video->map())->toBe('0:v:0')
        ->and(Track::Audio->map())->toBe('0:a:0')
        ->and(Track::Video->contentType())->toBe('video/mp4')
        ->and(Track::Audio->contentType())->toBe('audio/mp4');
});

it('takes i-frames from the first video stream', function () {
    expect(Track::IFrames->map())->toBe('0:v:0')
        ->and(Track::IFrames->contentType())->toBe('video/mp4')
        ->and(Track::IFrames->isVideo())->toBeTrue()
        ->and(Track::Video->isVideo())->toBeTrue()
        ->and(Track::Audio->isVideo())->toBeFalse();
});

it('maps and names the other streams of a track by their position', function () {
    expect(Track::Audio->map(2))->toBe('0:a:2')
        ->and(Track::Video->map(1))->toBe('0:v:1')
        ->and(Track::Audio->name())->toBe('audio')
        ->and(Track::Audio->name(2))->toBe('audio-2');
});
