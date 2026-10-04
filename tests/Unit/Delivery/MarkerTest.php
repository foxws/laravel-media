<?php

declare(strict_types=1);

use Foxws\Media\Delivery\Marker;
use Foxws\Media\FFMpeg\Scene;
use Foxws\Media\Probe\Chapter;

it('marks a range or a single moment', function () {
    expect(new Marker(12.4, 20.6, 'Intro', 'intro'))->duration()->toEqualWithDelta(8.2, 0.0001)->class->toBe('intro')
        ->and(new Marker(30))->duration()->toBeNull()->title->toBeNull()->class->toBe('marker');
});

it('turns chapters and scenes into markers', function () {
    expect(Marker::fromChapter(new Chapter('Opening', 0, 95.5)))
        ->start->toBe(0.0)->end->toBe(95.5)->title->toBe('Opening')->class->toBe('chapter')
        ->and(Marker::fromScene(new Scene(10, 25, 0.6)))
        ->start->toBe(10.0)->end->toBe(25.0)->title->toBeNull()->class->toBe('scene');
});

it('refuses ranges that start before 0 or end before their start, and an empty class', function (float $start, ?float $end, string $class) {
    new Marker($start, $end, class: $class);
})->throws(InvalidArgumentException::class)->with([
    'negative start' => [-1, null, 'marker'],
    'end before start' => [10, 5, 'marker'],
    'empty class' => [0, null, ''],
]);
