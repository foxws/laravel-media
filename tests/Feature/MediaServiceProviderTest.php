<?php

declare(strict_types=1);

use Foxws\Media\Executables\Executable;
use Foxws\Media\Executables\Executables;
use Foxws\Media\Filesystem\TemporaryDirectories;
use Foxws\Media\Process\Runner;
use Foxws\Media\Tests\Fixtures\AddOnExecutable;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobTimedOut;
use Illuminate\Queue\Events\WorkerStopping;
use Illuminate\Support\Facades\Artisan;

it('deletes temporary directories after every queue job', function (Closure $event) {
    $directory = app(TemporaryDirectories::class)->create();

    event($event(Mockery::mock(Job::class)));

    expect($directory->path())->not->toBeDirectory();
})->with([
    'processed' => [fn (Job $job) => new JobProcessed('redis', $job)],
    'exception' => [fn (Job $job) => new JobExceptionOccurred('redis', $job, new RuntimeException('Failed'))],
]);

it('keeps temporary directories after jobs when cleanup is turned off', function () {
    config(['media.temporary_files.cleanup_after_jobs' => false]);
    $directory = app(TemporaryDirectories::class)->create();

    event(new JobProcessed('redis', Mockery::mock(Job::class)));

    expect($directory->path())->toBeDirectory();
});

it('stops running processes and cleans up before a timed out or stopping worker exits', function (Closure $event) {
    $runner = Mockery::spy(Runner::class);
    app()->instance(Runner::class, $runner);
    $directory = app(TemporaryDirectories::class)->create();

    event($event());

    $runner->shouldHaveReceived('stopRunning')->once();
    expect($directory->path())->not->toBeDirectory();
})->with([
    'timed out' => [fn () => new JobTimedOut('redis', Mockery::mock(Job::class), 60)],
    'stopping' => [fn () => new WorkerStopping],
]);

it('adds a media section to php artisan about', function () {
    $ffmpeg = fakeExecutable(Executable::FFMpeg);
    config([
        'media.disk' => 'media',
        'media.timeout' => 600,
        'add-on.executables.encoder' => 'laravel-media-missing-encoder',
    ]);
    app(Executables::class)->register(AddOnExecutable::Encoder);

    Artisan::call('about', ['--only' => 'media', '--json' => true]);

    $about = json_decode(Artisan::output(), true)['media'] ?? [];

    expect($about)->toMatchArray([
        'disk' => 'media',
        'temporary_files' => config('media.temporary_files.root'),
        'timeout' => '600s',
        'ffmpeg' => $ffmpeg,
        'encoder' => 'not found',
    ]);
});
