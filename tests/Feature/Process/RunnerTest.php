<?php

declare(strict_types=1);

use Foxws\Media\Exceptions\FailureReason;
use Foxws\Media\Exceptions\ProcessCancelledException;
use Foxws\Media\Exceptions\ProcessFailedException;
use Foxws\Media\Executables\Binary;
use Foxws\Media\Executables\Executable;
use Foxws\Media\Executables\Executables;
use Foxws\Media\Process\Events\ProcessCompleted;
use Foxws\Media\Process\Events\ProcessFailed;
use Foxws\Media\Process\Events\ProcessStarted;
use Foxws\Media\Process\Runner;
use Foxws\Media\Tests\Fixtures\AddOnExecutable;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Process\FakeProcessResult;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Process;
use Psr\Log\AbstractLogger;
use Symfony\Component\Process\Exception\ProcessTimedOutException as SymfonyProcessTimedOutException;
use Symfony\Component\Process\Process as SymfonyProcess;

it('runs the resolved executable and returns its output', function () {
    $path = fakeExecutable(Executable::FFProbe);
    Process::fake(['*' => Process::result(output: '{"streams":[]}')]);

    $result = Runner::make()->run(Executable::FFProbe, ['-version']);

    expect($result)
        ->successful()->toBeTrue()
        ->output->toContain('{"streams":[]}');
    Process::assertRan(fn ($process) => $process->command === [$path, '-version']);
});

it('passes extra environment variables to the process', function () {
    fakeExecutable(Executable::FFMpeg);
    Process::fake();

    Runner::make()->run(Executable::FFMpeg, ['-version'], environment: ['SVT_LOG' => '1']);

    Process::assertRan(fn ($process) => $process->environment === ['SVT_LOG' => '1']);
});

it('runs executables of other packages', function () {
    config(['add-on.executables.encoder' => fakeExecutable(Executable::FFMpeg)]);
    Process::fake(['*' => Process::result(output: 'encoded')]);

    $result = Runner::make()->run(AddOnExecutable::Encoder, ['--input', 'video.mp4']);

    expect($result->executable)->toBe(AddOnExecutable::Encoder)
        ->and($result->output)->toContain('encoded');
});

it('throws with the error output when the process fails', function () {
    fakeExecutable(Executable::FFMpeg);
    Process::fake(['*' => Process::result(errorOutput: 'Invalid data found when processing input', exitCode: 1)]);

    Runner::make()->run(Executable::FFMpeg, ['-i', 'broken.mp4']);
})->throws(ProcessFailedException::class, 'ffmpeg exited with code 1 (invalid_input): Invalid data found when processing input');

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
    config(['add-on.executables.encoder' => fakeExecutable(Executable::FFMpeg)]);
    Process::fake();
    Event::fake([ProcessStarted::class]);

    Runner::make()->run(AddOnExecutable::Encoder, [
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

it('turns a timeout into a retryable failure and passes the reason to the failed event', function () {
    fakeExecutable(Executable::FFMpeg);
    Event::fake([ProcessFailed::class]);
    $runner = new class(app(Executables::class)) extends Runner
    {
        protected function execute(Binary $executable, array $command, int $timeout, ?callable $onOutput, array $environment = []): array
        {
            throw new ProcessTimedOutException(
                new SymfonyProcessTimedOutException(new SymfonyProcess($command, timeout: $timeout), SymfonyProcessTimedOutException::TYPE_GENERAL),
                new FakeProcessResult(output: 'partial'),
            );
        }
    };

    expect(fn () => $runner->run(Executable::FFMpeg, ['-i', 'video.mp4'], timeout: 30))
        ->toThrow(fn (ProcessFailedException $exception) => expect($exception)
            ->reason->toBe(FailureReason::Timeout)
            ->result->exitCode->toBe(124)
            ->getMessage()->toBe('ffmpeg was stopped after the timeout of 30 seconds.'));

    Event::assertDispatched(ProcessFailed::class, fn (ProcessFailed $event) => $event->reason === FailureReason::Timeout);
});

it('logs failures with their report context and warnings of successful runs', function () {
    fakeExecutable(Executable::FFMpeg);
    $logger = new class extends AbstractLogger
    {
        /** @var list<array{level: mixed, message: string, context: array<string, mixed>}> */
        public array $records = [];

        public function log($level, Stringable|string $message, array $context = []): void
        {
            $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
        }
    };
    $runner = new Runner(app(Executables::class), $logger);
    Process::fake(['*' => Process::sequence()
        ->push(Process::result(errorOutput: 'Past duration 0.99 too large'))
        ->push(Process::result(errorOutput: 'No space left on device', exitCode: 1))]);

    $runner->run(Executable::FFMpeg, ['-i', 'video.mp4']);
    rescue(fn () => $runner->run(Executable::FFMpeg, ['-i', 'video.mp4']), report: false);

    $records = collect($logger->records)->whereIn('level', ['warning', 'error'])->values();

    expect($records[0])->level->toBe('warning')->context->toMatchArray(['warnings' => 'Past duration 0.99 too large'])
        ->and($records[1]['level'])->toBe('error')
        ->and($records[1]['context'])->toMatchArray(['reason' => 'no_space', 'retryable' => true, 'error_output' => 'No space left on device']);
});

it('stops a running process, as when a queue job times out', function () {
    $script = sys_get_temp_dir().'/laravel-media-sleeping-ffmpeg';
    file_put_contents($script, "#!/bin/sh\nsleep 10\n");
    chmod($script, 0755);
    config(['media.executables.ffmpeg' => $script]);
    app(Executables::class)->flush();
    $runner = Runner::make();
    pcntl_async_signals(true);
    pcntl_signal(SIGALRM, fn () => $runner->stopRunning(timeout: 1));
    pcntl_alarm(1);
    $startedAt = microtime(true);

    expect(fn () => $runner->run(Executable::FFMpeg, []))->toThrow(ProcessFailedException::class);

    expect(microtime(true) - $startedAt)->toBeLessThan(5);
})->skip(! function_exists('pcntl_alarm'), 'Needs pcntl to send an alarm while the process runs.')->skipOnWindows();

it('stops a running process that is cancelled from its output callback', function () {
    $script = sys_get_temp_dir().'/laravel-media-progressing-ffmpeg';
    file_put_contents($script, "#!/bin/sh\necho 'progress=continue'\nsleep 10\n");
    chmod($script, 0755);
    config(['media.executables.ffmpeg' => $script]);
    app(Executables::class)->flush();
    $startedAt = microtime(true);

    expect(fn () => Runner::make()->run(Executable::FFMpeg, [], onOutput: fn () => throw ProcessCancelledException::make()))
        ->toThrow(fn (ProcessFailedException $exception) => expect($exception)
            ->reason->toBe(FailureReason::Cancelled)
            ->getMessage()->toBe('ffmpeg was cancelled.'));

    expect(microtime(true) - $startedAt)->toBeLessThan(5);
})->skipOnWindows();

it('passes on output written as soon as the process starts', function () {
    $script = sys_get_temp_dir().'/laravel-media-quick-ffmpeg';
    file_put_contents($script, "#!/bin/sh\necho 'progress=end'\n");
    chmod($script, 0755);
    config(['media.executables.ffmpeg' => $script]);
    app(Executables::class)->flush();
    $output = '';

    foreach (range(1, 5) as $run) {
        Runner::make()->run(Executable::FFMpeg, [], onOutput: function (string $chunk) use (&$output) {
            $output .= $chunk;
        });
    }

    expect(substr_count($output, 'progress=end'))->toBe(5);
})->skipOnWindows();
