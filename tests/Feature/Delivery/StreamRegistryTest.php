<?php

declare(strict_types=1);

use Foxws\Media\Facades\Media;
use Foxws\Media\Facades\MediaStream;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;

it('defines streams by name', function () {
    $definition = MediaStream::define('videos', fn (string $video) => Media::open("{$video}.mp4"));

    expect(MediaStream::definition('videos'))->toBe($definition);
});

it('throws for streams that are not defined', function () {
    MediaStream::definition('missing');
})->throws(InvalidArgumentException::class, 'Media stream [missing] is not defined.');

it('builds the url of the cmaf playlist wherever the routes are registered', function () {
    MediaStream::define('videos', fn (string $video) => Media::open("{$video}.mp4"));
    Route::name('admin.')->prefix('admin')->group(fn () => Route::mediaStream('videos/{video}', 'videos'));

    expect(MediaStream::url('videos', ['video' => 1]))->toBe('http://localhost/admin/videos/1/cmaf.m3u8');
});

it('signs the url of signed streams', function () {
    MediaStream::define('videos', fn (string $video) => Media::open("{$video}.mp4"))->signed();
    Route::mediaStream('videos/{video}', 'videos');

    $url = MediaStream::url('videos', ['video' => 1]);

    expect($url)->toContain('expires=', 'signature=')
        ->and(URL::hasValidSignature(request()->create($url)))->toBeTrue();
});

it('needs routes to build urls', function () {
    MediaStream::define('videos', fn () => Media::open('video.mp4'));

    MediaStream::url('videos');
})->throws(InvalidArgumentException::class, 'Media stream [videos] has no route.');

it('builds the urls of the mpeg-ts playlist and the dash manifest', function () {
    MediaStream::define('videos', fn (string $video) => Media::open("{$video}.mp4"));
    Route::mediaStream('videos/{video}', 'videos');

    expect(MediaStream::hlsUrl('videos', ['video' => 1]))->toBe('http://localhost/videos/1/hls.m3u8')
        ->and(MediaStream::dashUrl('videos', ['video' => 1]))->toBe('http://localhost/videos/1/dash.mpd')
        ->and(MediaStream::chaptersUrl('videos', ['video' => 1]))->toBe('http://localhost/videos/1/chapters.vtt');
});
