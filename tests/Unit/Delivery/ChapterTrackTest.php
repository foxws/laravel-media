<?php

declare(strict_types=1);

use Foxws\Media\Delivery\ChapterTrack;
use Foxws\Media\Delivery\Marker;

it('lists the markers as webvtt cues in order of their start', function () {
    $track = new ChapterTrack([
        new Marker(65.25, 3725.5, 'The <end> & after', 'chapter'),
        new Marker(0, 65.25, 'Opening', 'chapter'),
    ], 3725.5);

    expect($track->toWebVtt())->toBe(implode("\n", [
        'WEBVTT',
        '',
        'chapter-0',
        '00:00:00.000 --> 00:01:05.250',
        'Opening',
        '',
        'chapter-1',
        '00:01:05.250 --> 01:02:05.500',
        'The &lt;end&gt; &amp; after',
        '',
    ]));
});

it('ends each cue where the next starts, and an open one at the end of the stream', function () {
    $track = new ChapterTrack([new Marker(0, 20, "Line\nbreak"), new Marker(10), new Marker(30)], 40);

    expect($track->toWebVtt())->toBe(implode("\n", [
        'WEBVTT',
        '',
        'marker-0',
        '00:00:00.000 --> 00:00:10.000',
        'Line break',
        '',
        'marker-1',
        '00:00:10.000 --> 00:00:30.000',
        '',
        'marker-2',
        '00:00:30.000 --> 00:00:40.000',
        '',
    ]));
});

it('fills the gaps around the markers with a cue of their own', function () {
    $track = new ChapterTrack([new Marker(5, 10, 'Intro', 'chapter'), new Marker(15, 20, 'Credits', 'chapter')], 30, 'Main');

    expect($track->toWebVtt())->toBe(implode("\n", [
        'WEBVTT',
        '',
        'gap-0',
        '00:00:00.000 --> 00:00:05.000',
        'Main',
        '',
        'chapter-0',
        '00:00:05.000 --> 00:00:10.000',
        'Intro',
        '',
        'gap-1',
        '00:00:10.000 --> 00:00:15.000',
        'Main',
        '',
        'chapter-1',
        '00:00:15.000 --> 00:00:20.000',
        'Credits',
        '',
        'gap-2',
        '00:00:20.000 --> 00:00:30.000',
        'Main',
        '',
    ]));
});

it('skips markers without a length', function () {
    $track = new ChapterTrack([new Marker(5, 5), new Marker(8)], 0, 'Main');

    expect($track->isEmpty())->toBeFalse()
        ->and($track->toWebVtt())->toBe("WEBVTT\n")
        ->and(new ChapterTrack([], 30))->isEmpty()->toBeTrue();
});
