<?php

declare(strict_types=1);

use Foxws\Media\Delivery\DirectStream;
use Foxws\Media\Delivery\StreamDefinition;
use Foxws\Media\Facades\Media;
use Foxws\Media\Tests\Fixtures\RoutableVideo;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

beforeEach(fn () => Media::fake());

it('resolves a stream from an opener or a direct stream', function () {
    $fromOpener = new StreamDefinition('videos', fn (string $video) => Media::open("{$video}.mp4"));
    $fromStream = new StreamDefinition('videos', fn (string $video) => Media::open("{$video}.mp4")->stream()->segmentDuration(4));

    expect($fromOpener->resolve(['video' => '1']))->toBeInstanceOf(DirectStream::class)
        ->and($fromStream->resolve(['video' => '1']))->toBeInstanceOf(DirectStream::class);
});

it('binds routable parameters and injects the rest from the container', function () {
    $received = null;
    $definition = new StreamDefinition('videos', function (RoutableVideo $video, Request $request) use (&$received) {
        $received = [$video->id, $request];

        return Media::open("{$video->id}.mp4");
    });

    $definition->resolve(['video' => '7']);

    expect($received[0])->toBe(7)
        ->and($received[1])->toBeInstanceOf(Request::class);
});

it('keeps parameters that are already bound', function () {
    $definition = new StreamDefinition('videos', fn (RoutableVideo $video) => Media::open("{$video->id}.mp4"));

    expect($definition->resolve(['video' => new RoutableVideo(42)]))->toBeInstanceOf(DirectStream::class);
});

it('throws a 404 for records that do not exist', function () {
    new StreamDefinition('videos', fn (RoutableVideo $video) => Media::open('video.mp4'))->resolve(['video' => '10']);
})->throws(NotFoundHttpException::class);

it('refuses resolvers that return anything else', function () {
    new StreamDefinition('videos', fn () => 'video.mp4')->resolve([]);
})->throws(InvalidArgumentException::class, 'The [videos] media stream must resolve to a DirectStream or an Opener.');

it('signs urls for the configured lifetime unless given its own', function () {
    config(['media.delivery.url_lifetime' => 600]);

    $definition = new StreamDefinition('videos', fn () => Media::open('video.mp4'));

    expect($definition->isSigned())->toBeFalse()
        ->and($definition->signed()->isSigned())->toBeTrue()
        ->and($definition->lifetime())->toBe(600)
        ->and($definition->signed(60)->lifetime())->toBe(60);
});
