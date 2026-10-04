<?php

declare(strict_types=1);

use Foxws\Media\Delivery\LookAheadStrategy;

it('is read from the look_ahead_via config value', function () {
    expect(LookAheadStrategy::tryFrom('queue'))->toBe(LookAheadStrategy::Queue)
        ->and(LookAheadStrategy::tryFrom('defer'))->toBe(LookAheadStrategy::Defer)
        ->and(LookAheadStrategy::tryFrom(''))->toBeNull();
});
