<?php

declare(strict_types=1);

use Foxws\Media\Exceptions\ProcessFailedException;
use Foxws\Media\Executables\Executable;
use Foxws\Media\Process\Events\ProcessCompleted;
use Foxws\Media\Process\Events\ProcessFailed;
use Foxws\Media\Process\Events\ProcessStarted;
use Foxws\Media\Process\Runner;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Process;

it('runs the resolved executable and returns its output', function () {
    $path = fakeExecutable(Executable::FFProbe);
    Process::fake(['*' => Process::result(output: '{"streams":[]}')]);

    $result = Runner::make()->run(Executable::FFProbe, ['-version']);

    expect($result)
        ->successful()->toBeTrue()
        ->output->toContain('{"streams":[]}');
    Process::assertRan(fn ($process) => $process->command === [$path, '-version']);
});

it('throws with the error output when the process fails', function () {
    fakeExecutable(Executable::FFMpeg);
    Process::fake(['*' => Process::result(errorOutput: 'Invalid data found when processing input', exitCode: 1)]);

    Runner::make()->run(Executable::FFMpeg, ['-i', 'broken.mp4']);
})->throws(ProcessFailedException::class, 'ffmpeg exited with code 1: Invalid data found when processing input');

it('dispatches started and completed events', function () {
    fakeExecutable(Executable::FFMpeg);
    Process::fake();
    Event::fake([ProcessStarted::class, ProcessCompleted::class, ProcessFailed::class]);

    Runner::make()->run(Executable::FFMpeg, ['-version']);

    Event::assertDispatched(ProcessStarted::class, fn (ProcessStarted $event) => $event->executable === Executable::FFMpeg);
    Event::assertDispatched(ProcessCompleted::class);
    Event::assertNotDispatched(ProcessFailed::class);
});

it('dispatches a failed event when the process fails', function () {
    fakeExecutable(Executable::FFMpeg);
    Process::fake(['*' => Process::result(exitCode: 1)]);
    Event::fake([ProcessCompleted::class, ProcessFailed::class]);

    rescue(fn () => Runner::make()->run(Executable::FFMpeg, ['-version']), report: false);

    Event::assertDispatched(ProcessFailed::class, fn (ProcessFailed $event) => $event->result->exitCode === 1);
    Event::assertNotDispatched(ProcessCompleted::class);
});

it('hides encryption keys in the command it reports', function () {
    fakeExecutable(Executable::Packager);
    Process::fake();
    Event::fake([ProcessStarted::class]);

    Runner::make()->run(Executable::Packager, [
        '--keys', 'label=:key_id=0123456789abcdef0123456789abcdef:key=fedcba9876543210fedcba9876543210',
        '--iv=00112233445566778899aabbccddeeff',
        '--enable_raw_key_encryption',
        'in=video.mp4,stream=video,output=video key=abc.mp4',
    ]);

    Event::assertDispatched(ProcessStarted::class, function (ProcessStarted $event): bool {
        expect($event->command)
            ->toContain('--keys [REDACTED]')
            ->toContain('--iv=[REDACTED]')
            ->toContain('--enable_raw_key_encryption')
            ->not->toContain('0123456789abcdef0123456789abcdef')
            ->not->toContain('00112233445566778899aabbccddeeff');

        return true;
    });
});

it('streams standard output to the output callback', function () {
    fakeExecutable(Executable::FFMpeg);
    Process::fake(['*' => Process::result(output: "first\nsecond\n", errorOutput: 'warning')]);
    $output = '';

    Runner::make()->run(Executable::FFMpeg, ['-version'], onOutput: function (string $chunk) use (&$output) {
        $output .= $chunk;
    });

    expect($output)->toContain('first')->toContain('second')->not->toContain('warning');
});

it('dispatches to an event fake set up after the runner was resolved', function () {
    fakeExecutable(Executable::FFMpeg);
    Process::fake();
    $runner = Runner::make();
    Event::fake([ProcessCompleted::class]);

    $runner->run(Executable::FFMpeg, ['-version']);

    Event::assertDispatched(ProcessCompleted::class);
});
