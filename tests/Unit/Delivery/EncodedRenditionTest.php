<?php

declare(strict_types=1);

use Foxws\Media\Delivery\Codecs;
use Foxws\Media\Delivery\EncodedRendition;
use Foxws\Media\Encoding\HardwareAcceleration;
use Foxws\Media\Encoding\Ladder;
use Foxws\Media\Encoding\Rendition;
use Foxws\Media\Probe\Probe;
use Foxws\Media\Testing\FakeProbe;

function encodedRendition(int $height = 720, int $width = 1920, int $sourceHeight = 1080, ?Ladder $ladder = null): EncodedRendition
{
    $source = Probe::fromArray(FakeProbe::video(width: $width, height: $sourceHeight, frameRate: 25))->videoStream();

    return new EncodedRendition($ladder ?? Ladder::standard(), new Rendition($height, 2800), $source);
}

it('scales the short side and keeps the aspect ratio at even sizes', function () {
    expect([encodedRendition()->width(), encodedRendition()->height()])->toBe([1280, 720])
        ->and([encodedRendition(480, 1080, 1920)->width(), encodedRendition(480, 1080, 1920)->height()])->toBe([480, 854])
        ->and([encodedRendition(480, 2560, 1080)->width(), encodedRendition(480, 2560, 1080)->height()])->toBe([1138, 480]);
});

it('picks the h264 level for the frame size', function () {
    expect(encodedRendition(1080, 3840, 2160)->level())->toBe(42)
        ->and(encodedRendition(1440, 3840, 2160)->level())->toBe(51)
        ->and(encodedRendition(2880, 7680, 4320)->level())->toBe(52);
});

it('describes its segments before they are encoded', function () {
    $video = encodedRendition()->probe()->videoStream();

    expect([$video->width, $video->height, $video->frameRate])->toBe([1280, 720, 25.0])
        ->and(Codecs::video($video))->toBe('avc1.64002a')
        ->and(encodedRendition()->probe()->format()->bitRate)->toBe(2996000);
});

it('keys its cache by every setting that changes the encode', function () {
    expect(encodedRendition()->key())->toStartWith('720p-2800-')
        ->and(encodedRendition()->key())->toBe(encodedRendition()->key())
        ->and(encodedRendition()->key())->not->toBe(encodedRendition(ladder: Ladder::standard()->hardware(HardwareAcceleration::Vaapi))->key());
});

it('encodes a segment scaled, with one keyframe at its start, in high profile', function () {
    $arguments = encodedRendition()->outputArguments(12.0);

    expect($arguments)->toContain('scale=-2:720', 'libx264', '2800k', '11.999', '65535', 'high', '4.2', '-an', '-sn')
        ->and(encodedRendition()->inputArguments())->toBe([])
        ->and(encodedRendition(ladder: Ladder::standard()->hardware(HardwareAcceleration::Vaapi))->inputArguments())->toContain('-hwaccel', 'vaapi');
});
