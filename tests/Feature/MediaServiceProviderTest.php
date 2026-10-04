<?php

declare(strict_types=1);

use Foxws\Media\Filesystem\TemporaryDirectories;
use Foxws\Media\Process\Runner;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobTimedOut;
use Illuminate\Queue\Events\WorkerStopping;

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
