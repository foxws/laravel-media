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

it('serves the master playlist with links to the media playlists', function () {
    MediaStream::define('videos', fn (string $video) => Media::fromDisk('videos')->open("video-{$video}.mp4"));

    $this->get('videos/1/master.m3u8')
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

    $this->get('videos/1/master.m3u8')->assertForbidden();

    $master = $this->get(MediaStream::url('videos', ['video' => 1]))->assertOk()->getContent();
    $playlistUrl = collect(explode("\n", (string) $master))->first(fn (string $line) => str_starts_with($line, 'http'));
    $playlist = $this->get($playlistUrl)->assertOk()->getContent();
    $segmentUrl = collect(explode("\n", (string) $playlist))->first(fn (string $line) => str_starts_with($line, 'http'));

    expect($segmentUrl)->toContain('signature=');
    $this->get($segmentUrl)->assertOk();
    $this->get(strtok($segmentUrl, '?'))->assertForbidden();
});
