<?php

declare(strict_types=1);

use Foxws\Media\Delivery\IndexStore;
use Foxws\Media\Executables\Executable;
use Foxws\Media\Facades\Media;
use Foxws\Media\Testing\FakeProbe;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    (require __DIR__.'/../../../database/migrations/create_media_indexes_table.php.stub')->up();

    config([
        'filesystems.disks.videos' => ['driver' => 'local', 'root' => sys_get_temp_dir()],
        'media.delivery.cache_disk' => 'segments',
        'media.delivery.index_store' => 'database',
    ]);

    Storage::fake('videos');
    Storage::fake('segments');
});

it('keeps probes and keyframe indexes in the database after the cache is flushed', function () {
    Media::fake(['video.mp4' => FakeProbe::video(duration: 13)]);

    Media::fromDisk('videos')->open('video.mp4')->stream()->warm();
    Cache::flush();
    $keyframes = Media::fromDisk('videos')->open('video.mp4')->rememberProbes()->keyframes();

    expect($keyframes->keyframes)->toBe([0.0, 2.0, 4.0, 6.0, 8.0, 10.0, 12.0])
        ->and(DB::table('media_indexes')->pluck('type')->sort()->values()->all())->toBe(['keyframes', 'probe']);
    Media::assertRanTimes(Executable::FFProbe, 2);
});

it('indexes a changed file again', function () {
    Media::fake(['video.mp4' => FakeProbe::video(duration: 13)]);
    Storage::disk('videos')->put('video.mp4', 'v1');

    Media::fromDisk('videos')->open('video.mp4')->stream()->warm();
    Storage::disk('videos')->put('video.mp4', 'version two');
    Media::fromDisk('videos')->open('video.mp4')->stream()->warm();

    Media::assertRanTimes(Executable::FFProbe, 4);
});

it('converts embedded subtitles when warming a stream', function () {
    Media::fake(['video.mp4' => FakeProbe::video(subtitles: ['eng'])]);

    Media::fromDisk('videos')->open('video.mp4')->stream()->withEmbeddedSubtitles()->warm();

    Media::assertRan(Executable::FFMpeg, fn (array $arguments) => in_array('webvtt', $arguments, true));
});

it('forgets the stored indexes of a file', function () {
    Media::fake(['a.mp4' => FakeProbe::video(), 'b.mp4' => FakeProbe::video()]);
    Media::fromDisk('videos')->open('a.mp4', 'b.mp4')->stream()->warm();

    app(IndexStore::class)->forget(Media::fromDisk('videos')->open('a.mp4')->mediaFor());

    expect(DB::table('media_indexes')->distinct()->pluck('path')->all())->toBe(['b.mp4']);
});

it('prunes the stored indexes of changed and deleted files', function () {
    Media::fake(['kept.mp4' => FakeProbe::video(), 'changed.mp4' => FakeProbe::video(), 'deleted.mp4' => FakeProbe::video()]);
    Storage::disk('videos')->put('kept.mp4', 'v1');
    Storage::disk('videos')->put('changed.mp4', 'v1');
    Storage::disk('videos')->put('deleted.mp4', 'v1');
    Media::fromDisk('videos')->open('kept.mp4', 'changed.mp4', 'deleted.mp4')->stream()->warm();

    Storage::disk('videos')->put('changed.mp4', 'version two');
    Storage::disk('videos')->delete('deleted.mp4');

    $this->artisan('media:prune')
        ->expectsOutputToContain('Deleted 2 stored indexes of changed or deleted files.')
        ->assertSuccessful();

    expect(DB::table('media_indexes')->distinct()->pluck('path')->all())->toBe(['kept.mp4']);
});

it('keeps the stored indexes on a dry run', function () {
    Media::fake(['deleted.mp4' => FakeProbe::video()]);
    Storage::disk('videos')->put('deleted.mp4', 'v1');
    Media::fromDisk('videos')->open('deleted.mp4')->stream()->warm();
    Storage::disk('videos')->delete('deleted.mp4');

    $this->artisan('media:prune', ['--dry-run' => true])
        ->expectsOutputToContain('Found 1 stored index of changed or deleted files.')
        ->assertSuccessful();

    expect(DB::table('media_indexes')->count())->toBe(2);
});
