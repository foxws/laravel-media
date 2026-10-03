<?php

declare(strict_types=1);

use Foxws\Media\Encoding\Format;
use Foxws\Media\FFMpeg\Output;
use Foxws\Media\Filters\Fade;
use Foxws\Media\Filters\Scale;

it('builds its options in order and ends with the file', function () {
    $output = new Output('preview.mp4')
        ->map('0:v', '0:a')
        ->addFilter(Scale::to(480), Fade::audioIn(1))
        ->inFormat(Format::copy('mp4'))
        ->addArgs(['-metadata', 'title=Preview']);

    expect($output->toArguments('/tmp/out/preview.mp4'))->toBe([
        '-map', '0:v', '-map', '0:a',
        '-vf', 'scale=480:-2', '-af', 'afade=t=in:st=0:d=1',
        '-c:v', 'copy', '-c:a', 'copy', '-f', 'mp4',
        '-metadata', 'title=Preview',
        '/tmp/out/preview.mp4',
    ])->and($output->path)->toBe('preview.mp4');
});

it('writes only the file without options', function () {
    expect(new Output('copy.mkv')->toArguments('copy.mkv'))->toBe(['copy.mkv']);
});
