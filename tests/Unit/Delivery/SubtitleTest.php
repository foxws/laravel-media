<?php

declare(strict_types=1);

use Foxws\Media\Delivery\Subtitle;
use Foxws\Media\Filesystem\Disk;

it('is embedded when it refers to a stream', function () {
    expect(new Subtitle('English', 'eng', stream: 2)->isEmbedded())->toBeTrue()
        ->and(new Subtitle('English', 'eng', Disk::make('local'), 'en.vtt')->isEmbedded())->toBeFalse();
});
