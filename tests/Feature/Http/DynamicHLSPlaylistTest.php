<?php

declare(strict_types=1);

use Foxws\Media\Exceptions\ManifestException;
use Foxws\Media\Facades\Media;
use Foxws\Media\Http\DynamicHLSPlaylist;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

function signedPlaylist(): DynamicHLSPlaylist
{
    return Media::fromDisk('streams')->open('videos/1/master.m3u8')->hlsPlaylist()
        ->resolveKeyUrlsUsing(fn (string $path) => "https://app.test/keys?path={$path}")
        ->resolveMediaUrlsUsing(fn (string $path) => "https://cdn.test/{$path}?signature=abc")
        ->resolvePlaylistUrlsUsing(fn (string $path) => "https://app.test/playlists?path={$path}");
}

it('signs the media playlists and session key of the master playlist with their disk paths', function () {
    storeStreams();

    $playlist = signedPlaylist()->get();

    expect($playlist)
        ->toContain('#EXT-X-SESSION-KEY:METHOD=SAMPLE-AES,URI="https://app.test/keys?path=videos/1/key",KEYFORMAT="identity"')
        ->toContain('URI="https://app.test/playlists?path=videos/1/stream_1.m3u8"')
        ->toContain('URI="https://app.test/playlists?path=videos/1/captions/stream_2.m3u8"')
        ->toContain("\nhttps://app.test/playlists?path=videos/1/stream_0.m3u8\n")
        ->toContain('#EXT-X-STREAM-INF:BANDWIDTH=4537000');
});

it('signs segments, init segments and keys of a media playlist, keeping the other tags', function () {
    storeStreams();

    $playlist = signedPlaylist()->process('videos/1/stream_0.m3u8');

    expect($playlist)->toBe(implode("\n", [
        '#EXTM3U',
        '#EXT-X-VERSION:6',
        '#EXT-X-TARGETDURATION:6',
        '#EXT-X-PLAYLIST-TYPE:VOD',
        '#EXT-X-MAP:URI="https://cdn.test/videos/1/0_video.mp4?signature=abc",BYTERANGE="823@0"',
        '#EXT-X-KEY:METHOD=SAMPLE-AES,URI="https://app.test/keys?path=videos/1/key",KEYFORMAT="identity"',
        '#EXTINF:6.000,',
        '#EXT-X-BYTERANGE:1500000@823',
        'https://cdn.test/videos/1/0_video.mp4?signature=abc',
        '#EXTINF:4.000,',
        '#EXT-X-BYTERANGE:1000000',
        'https://cdn.test/videos/1/0_video.mp4?signature=abc',
        '#EXT-X-ENDLIST',
        '',
    ]));
});

it('resolves uris relative to the playlist that contains them', function () {
    storeStreams();

    expect(signedPlaylist()->process('videos/1/captions/stream_2.m3u8'))->toContain('https://cdn.test/videos/1/nld.mp4?signature=abc')
        ->and(signedPlaylist()->process('videos/1/stream_1.m3u8'))->toContain('https://cdn.test/videos/1/segments/audio_1.m4s?signature=abc');
});

it('rewrites the master playlist and every media playlist it references', function () {
    storeStreams();

    expect(array_keys(signedPlaylist()->all()))->toBe([
        'videos/1/master.m3u8',
        'videos/1/stream_1.m3u8',
        'videos/1/captions/stream_2.m3u8',
        'videos/1/stream_0.m3u8',
    ]);
});

it('leaves uris alone without resolvers and never touches absolute urls', function () {
    Storage::fake('streams');
    Storage::disk('streams')->put('a/master.m3u8', "#EXTM3U\nstream_0.m3u8\nhttps://other.test/stream.m3u8");

    expect(new DynamicHLSPlaylist('streams')->open('a/master.m3u8')->get())->toBe("#EXTM3U\nstream_0.m3u8\nhttps://other.test/stream.m3u8")
        ->and(new DynamicHLSPlaylist('streams')->open('a/master.m3u8')->resolvePlaylistUrlsUsing(fn ($path) => "signed:{$path}")->get())
        ->toBe("#EXTM3U\nsigned:a/stream_0.m3u8\nhttps://other.test/stream.m3u8");
});

it('calls each resolver once per path', function () {
    storeStreams();
    $calls = 0;

    Media::fromDisk('streams')->open('videos/1/stream_0.m3u8')->hlsPlaylist()
        ->resolveMediaUrlsUsing(function (string $path) use (&$calls) {
            $calls++;

            return $path;
        })
        ->get();

    expect($calls)->toBe(1);
});

it('responds with the hls content type', function () {
    storeStreams();

    $response = signedPlaylist()->toResponse(Request::create('/'));

    expect($response->headers->get('Content-Type'))->toBe('application/vnd.apple.mpegurl')
        ->and($response->getContent())->toStartWith('#EXTM3U');
});

it('fails clearly without an opened or existing playlist', function () {
    Storage::fake('streams');

    expect(fn () => new DynamicHLSPlaylist('streams')->get())->toThrow(ManifestException::class, 'No manifest has been opened.')
        ->and(fn () => new DynamicHLSPlaylist('streams')->open('missing.m3u8')->get())->toThrow(ManifestException::class, "The manifest [missing.m3u8] doesn't exist");
});
