<?php

declare(strict_types=1);

use Foxws\Media\Delivery\Track;

it('maps the first stream of its type with its content type', function () {
    expect(Track::Video->map())->toBe('0:v:0')
        ->and(Track::Audio->map())->toBe('0:a:0')
        ->and(Track::Video->contentType())->toBe('video/mp4')
        ->and(Track::Audio->contentType())->toBe('audio/mp4');
});
