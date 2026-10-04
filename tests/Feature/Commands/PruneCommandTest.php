<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('segments');
    config(['media.delivery.cache_disk' => 'segments']);
});

/**
 * A cached segment, packaged the given number of minutes ago.
 */
function cachedSegment(string $path, int $minutes): string
{
    Storage::disk('segments')->put($path, 'segment');
    touch(Storage::disk('segments')->path($path), time() - $minutes * 60);

    return $path;
}

it('deletes segments packaged more than a week ago and their empty directories', function () {
    $stale = cachedSegment('media-segments/aaaa/6/0.ts', minutes: 10081);
    $recent = cachedSegment('media-segments/bbbb/6/0.ts', minutes: 60);

    $this->artisan('media:prune')
        ->expectsOutputToContain('Deleted 1 cached segment older than 10080 minutes.')
        ->assertSuccessful();

    Storage::disk('segments')->assertMissing($stale);
    Storage::disk('segments')->assertExists($recent);
    expect(Storage::disk('segments')->directories('media-segments'))->toBe(['media-segments/bbbb']);
});

it('deletes fragmented segments, their initialization segments and converted subtitles too', function () {
    $fragment = cachedSegment('media-segments/aaaa/6/video/0.m4s', minutes: 10081);
    $init = cachedSegment('media-segments/aaaa/6/video/init.mp4', minutes: 10081);
    $subtitle = cachedSegment('media-segments/aaaa/subtitles/2.vtt', minutes: 10081);

    $this->artisan('media:prune')
        ->expectsOutputToContain('Deleted 3 cached segments older than 10080 minutes.')
        ->assertSuccessful();

    Storage::disk('segments')->assertMissing([$fragment, $init, $subtitle]);
});

it('only touches segments in the cache path', function () {
    $other = cachedSegment('other/0.ts', minutes: 99999);
    $notSegment = cachedSegment('media-segments/notes.txt', minutes: 99999);

    $this->artisan('media:prune', ['--older-than' => 0])->assertSuccessful();

    Storage::disk('segments')->assertExists([$other, $notSegment]);
});

it('only counts what it would delete in a dry run', function () {
    $stale = cachedSegment('media-segments/aaaa/6/0.ts', minutes: 120);

    $this->artisan('media:prune', ['--older-than' => 60, '--dry-run' => true])
        ->expectsOutputToContain('Found 1 cached segment older than 60 minutes.')
        ->assertSuccessful();

    Storage::disk('segments')->assertExists($stale);
});
