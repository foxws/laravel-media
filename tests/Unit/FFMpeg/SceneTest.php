<?php

declare(strict_types=1);

use Foxws\Media\FFMpeg\Scene;

it('turns into a clip, optionally limited in length', function () {
    $scene = new Scene(10, 25, 0.6);

    expect($scene->duration())->toBe(15.0)
        ->and($scene->toClip())->from->toBe(10.0)->to->toBe(25.0)
        ->and($scene->toClip(maximumDuration: 4, path: 'b.mp4'))->to->toBe(14.0)->path->toBe('b.mp4');
});
