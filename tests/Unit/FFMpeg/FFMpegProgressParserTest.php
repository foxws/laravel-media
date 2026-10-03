<?php

declare(strict_types=1);

use Foxws\Media\FFMpeg\FFMpegProgressParser;

$block = "frame=250\nfps=50.12\nout_time_us=10000000\nout_time=00:00:10.000000\nspeed=2.01x\nprogress=continue\n";

it('reports each completed progress block', function () use ($block) {
    $parser = new FFMpegProgressParser(duration: 40);

    $updates = $parser->feed($block.str_replace(['10000000', 'continue'], ['40000000', 'end'], $block));

    expect($updates)->toHaveCount(2)
        ->and($updates[0])->seconds->toBe(10.0)->duration->toBe(40.0)->speed->toBe(2.01)->fps->toBe(50.12)->frame->toBe(250)->finished->toBeFalse()
        ->and($updates[0]->percentage())->toBe(25.0)
        ->and($updates[1])->seconds->toBe(40.0)->finished->toBeTrue();
});

it('waits for blocks split across output chunks', function () use ($block) {
    $parser = new FFMpegProgressParser;

    expect($parser->feed(substr($block, 0, 30)))->toBe([])
        ->and($parser->feed(substr($block, 30)))->toHaveCount(1);
});

it('handles values ffmpeg reports as N/A', function () {
    $progress = new FFMpegProgressParser()->feed("out_time_us=N/A\nspeed=N/A\nprogress=continue\n")[0];

    expect($progress)->seconds->toBe(0.0)->speed->toBeNull()->frame->toBeNull();
});
