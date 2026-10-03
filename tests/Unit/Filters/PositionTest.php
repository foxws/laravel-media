<?php

declare(strict_types=1);

use Foxws\Media\Filters\Position;

it('places an overlay with a margin from the edges', function (Position $position, string $overlay) {
    expect($position->overlay(16))->toBe($overlay);
})->with([
    'top left' => [Position::TopLeft, 'x=16:y=16'],
    'top right' => [Position::TopRight, 'x=W-w-16:y=16'],
    'bottom left' => [Position::BottomLeft, 'x=16:y=H-h-16'],
    'bottom right' => [Position::BottomRight, 'x=W-w-16:y=H-h-16'],
    'center' => [Position::Center, 'x=(W-w)/2:y=(H-h)/2'],
]);
