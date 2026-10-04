<?php

declare(strict_types=1);

use Foxws\Media\FFMpeg\Scene;

it('turns into a clip, optionally limited in length', function () {
    $scene = new Scene(10, 25, 0.6);

    expect($scene->duration())->toBe(15.0)
        ->and($scene->toClip())->from->toBe(10.0)->to->toBe(25.0)
        ->and($scene->toClip(maximumDuration: 4, path: 'b.mp4'))->to->toBe(14.0)->path->toBe('b.mp4');
});

it('is stored with toArray and restored with fromArray', function () {
    $scene = new Scene(10, 25, 0.6);

    expect($scene->toArray())->toBe(['start' => 10.0, 'end' => 25.0, 'score' => 0.6])
        ->and(Scene::fromArray($scene->toArray()))->toEqual($scene)
        ->and(Scene::fromArray(['start' => 0, 'end' => '4.5']))->toEqual(new Scene(0, 4.5, null));
});
