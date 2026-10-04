<?php

declare(strict_types=1);

use Foxws\Media\Facades\Media;
use Foxws\Media\Http\DynamicDASHManifest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

it('expands numbered segment templates into a signed segment list', function () {
    storeStreams();

    $manifest = Media::fromDisk('streams')->open('videos/1/manifest.mpd')->dashManifest()
        ->resolveMediaUrlsUsing(fn (string $path) => "https://cdn.test/{$path}?a=1&b=2")
        ->get();

    expect($manifest)
        ->toContain('<SegmentList timescale="90000"><Initialization sourceURL="https://cdn.test/videos/1/video/init.mp4?a=1&amp;b=2"/>')
        ->toContain('<S t="0" d="540000" r="2"/>')
        ->toContain('<SegmentURL media="https://cdn.test/videos/1/video/1.m4s?a=1&amp;b=2" duration="540000"/>')
        ->toContain('<SegmentURL media="https://cdn.test/videos/1/video/3.m4s?a=1&amp;b=2" duration="540000"/>')
        ->toContain('<SegmentURL media="https://cdn.test/videos/1/video/4.m4s?a=1&amp;b=2" duration="180000"/>')
        ->not->toContain('video/5.m4s')
        ->not->toContain('SegmentTemplate')
        ->toContain('<BaseURL>https://cdn.test/videos/1/audio.mp4?a=1&amp;b=2</BaseURL>');
});

it('signs init segments with their own resolver', function () {
    storeStreams();

    $manifest = Media::fromDisk('streams')->open('videos/1/manifest.mpd')->dashManifest()
        ->resolveInitUrlsUsing(fn (string $path) => "init:{$path}")
        ->resolveMediaUrlsUsing(fn (string $path) => "media:{$path}")
        ->get();

    expect($manifest)->toContain('sourceURL="init:videos/1/video/init.mp4"')
        ->toContain('media="media:videos/1/video/1.m4s"');
});

it('keeps templates it cannot expand and adds a missing xml declaration', function () {
    Storage::fake('streams');
    Storage::disk('streams')->put('a/manifest.mpd', '<MPD><SegmentTemplate media="$RepresentationID$/$Time$.m4s" initialization="init.mp4"/></MPD>');

    $manifest = new DynamicDASHManifest('streams')->open('a/manifest.mpd')->resolveMediaUrlsUsing(fn ($path) => "signed:{$path}")->get();

    expect($manifest)->toBe('<?xml version="1.0" encoding="UTF-8"?>'."\n".'<MPD><SegmentTemplate media="$RepresentationID$/$Time$.m4s" initialization="signed:a/init.mp4"/></MPD>');
});

it('responds with the dash content type', function () {
    storeStreams();

    $response = Media::fromDisk('streams')->open('videos/1/manifest.mpd')->dashManifest()->toResponse(Request::create('/'));

    expect($response->headers->get('Content-Type'))->toBe('application/dash+xml');
});
