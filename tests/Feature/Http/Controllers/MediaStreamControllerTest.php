<?php

declare(strict_types=1);

use Foxws\Media\Encryption\EncryptionKey;
use Foxws\Media\Facades\Media;
use Foxws\Media\Facades\MediaStream;
use Foxws\Media\Testing\FakeProbe;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('videos');
    Storage::fake('segments');
    config(['media.delivery.cache_disk' => 'segments']);
    Media::fake(['video-1.mp4' => FakeProbe::video(duration: 13)]);
    Route::mediaStream('videos/{video}', 'videos');
});

it('serves the mpeg-ts master playlist with links to the media playlists', function () {
    MediaStream::define('videos', fn (string $video) => Media::fromDisk('videos')->open("video-{$video}.mp4"));

    $this->get('videos/1/hls.m3u8')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/vnd.apple.mpegurl')
        ->assertSee('http://localhost/videos/1/0/index.m3u8');
});

it('serves media playlists with links to the segments', function () {
    MediaStream::define('videos', fn (string $video) => Media::fromDisk('videos')->open("video-{$video}.mp4"));

    $this->get('videos/1/0/index.m3u8')
        ->assertOk()
        ->assertSee(['http://localhost/videos/1/0/0.ts', 'http://localhost/videos/1/0/2.ts'])
        ->assertDontSee('#EXT-X-KEY');
});

it('packages and serves segments', function () {
    MediaStream::define('videos', fn (string $video) => Media::fromDisk('videos')->open("video-{$video}.mp4"));

    $this->get('videos/1/0/1.ts')->assertOk()->assertHeader('Content-Type', 'video/mp2t');
    $this->get('videos/1/0/9.ts')->assertNotFound();
    $this->get('videos/1/3/index.m3u8')->assertNotFound();
});

it('links and serves the keys of encrypted streams', function () {
    MediaStream::define('videos', fn (string $video) => Media::fromDisk('videos')->open("video-{$video}.mp4")->stream()
        ->withEncryption(fn (int $period) => EncryptionKey::derive('secret', "video:{$video}:{$period}"), rotateEvery: 2));

    $this->get('videos/1/0/index.m3u8')
        ->assertSee(['#EXT-X-KEY:METHOD=AES-128,URI="http://localhost/videos/1/0/keys/0.key"', 'URI="http://localhost/videos/1/0/keys/1.key"'], escape: false);

    expect($this->get('videos/1/0/keys/1.key')->assertOk()->getContent())->toBe(EncryptionKey::derive('secret', 'video:1:1')->binary());
});

it('has no keys for unencrypted streams', function () {
    MediaStream::define('videos', fn (string $video) => Media::fromDisk('videos')->open("video-{$video}.mp4"));

    $this->get('videos/1/0/keys/0.key')->assertNotFound();
});

it('only serves signed streams with a valid signature and signs every url', function () {
    MediaStream::define('videos', fn (string $video) => Media::fromDisk('videos')->open("video-{$video}.mp4"))->signed();

    $this->get('videos/1/hls.m3u8')->assertForbidden();

    $master = $this->get(MediaStream::hlsUrl('videos', ['video' => 1]))->assertOk()->getContent();
    $playlistUrl = collect(explode("\n", (string) $master))->first(fn (string $line) => str_starts_with($line, 'http'));
    $playlist = $this->get($playlistUrl)->assertOk()->getContent();
    $segmentUrl = collect(explode("\n", (string) $playlist))->first(fn (string $line) => str_starts_with($line, 'http'));

    expect($segmentUrl)->toContain('signature=');
    $this->get($segmentUrl)->assertOk();
    $this->get(strtok($segmentUrl, '?'))->assertForbidden();
});

it('serves cmaf streams with track playlists, initialization segments and fragments', function () {
    MediaStream::define('videos', fn (string $video) => Media::fromDisk('videos')->open("video-{$video}.mp4"));

    $this->get('videos/1/cmaf.m3u8')
        ->assertSee('#EXT-X-VERSION:7')
        ->assertSee(['URI="http://localhost/videos/1/0/audio/index.m3u8"', 'http://localhost/videos/1/0/video/index.m3u8'], escape: false);

    $this->get('videos/1/0/video/index.m3u8')
        ->assertOk()
        ->assertSee(['#EXT-X-MAP:URI="http://localhost/videos/1/0/video/init.mp4"', 'http://localhost/videos/1/0/video/2.m4s'], escape: false);

    $this->get('videos/1/0/audio/1.m4s')->assertOk()->assertHeader('Content-Type', 'audio/mp4');
    $this->get('videos/1/0/video/init.mp4')->assertOk()->assertHeader('Content-Type', 'video/mp4');
    $this->get('videos/1/0/subtitles/init.mp4')->assertNotFound();
});

it('serves a dash manifest with signed segment urls', function () {
    MediaStream::define('videos', fn (string $video) => Media::fromDisk('videos')->open("video-{$video}.mp4"))->signed();

    $response = $this->get(MediaStream::dashUrl('videos', ['video' => 1]))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/dash+xml');

    $manifest = simplexml_load_string((string) $response->getContent());
    $segmentUrl = (string) $manifest->Period->AdaptationSet[0]->Representation->SegmentList->SegmentURL[1]['media'];
    $initUrl = (string) $manifest->Period->AdaptationSet[1]->Representation->SegmentList->Initialization['sourceURL'];

    expect($segmentUrl)->toStartWith('http://localhost/videos/1/0/video/1.m4s?expires=');
    $this->get($segmentUrl)->assertOk();
    $this->get($initUrl)->assertOk()->assertHeader('Content-Type', 'audio/mp4');
});

it('picks the segment format by route, whatever the resolver set', function () {
    MediaStream::define('videos', fn (string $video) => Media::fromDisk('videos')->open("video-{$video}.mp4")->stream()->fragmented());

    $this->get('videos/1/hls.m3u8')->assertSee(['#EXT-X-VERSION:3', 'http://localhost/videos/1/0/index.m3u8']);
    $this->get('videos/1/0/index.m3u8')->assertSee('http://localhost/videos/1/0/0.ts');
});
