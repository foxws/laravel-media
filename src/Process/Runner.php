<?php

declare(strict_types=1);

namespace Foxws\Media\Process;

use Foxws\Media\Concerns\ResolvesFromContainer;
use Foxws\Media\Exceptions\ProcessFailedException;
use Foxws\Media\Executables\Executable;
use Foxws\Media\Executables\Executables;
use Foxws\Media\Process\Events\ProcessCompleted;
use Foxws\Media\Process\Events\ProcessFailed;
use Foxws\Media\Process\Events\ProcessStarted;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Process;
use Psr\Log\LoggerInterface;

class Runner
{
    use ResolvesFromContainer;

    /**
     * Option names whose values are hidden from logs and events.
     *
     * @var list<string>
     */
    protected const array SENSITIVE_OPTIONS = ['keys', 'key', 'key_id', 'pssh', 'protection_systems', 'raw_key', 'iv', 'decryption_key'];

    public function __construct(
        protected Executables $executables,
        protected ?LoggerInterface $logger = null,
        protected int $timeout = 14400,
    ) {}

    /**
     * Run the executable with the given arguments. The output callback receives
     * standard output as it streams in, e.g. to parse progress.
     *
     * @param  list<string>  $arguments
     * @param  (callable(string): mixed)|null  $onOutput
     *
     * @throws ProcessFailedException
     */
    public function run(Executable $executable, array $arguments, ?int $timeout = null, ?callable $onOutput = null): Result
    {
        $command = [$this->executables->path($executable), ...$arguments];

        $redacted = $this->redact($command);

        Event::dispatch(new ProcessStarted($executable, $redacted));

        $this->logger?->debug("Running {$executable->value}", ['command' => $redacted]);

        $startedAt = hrtime(true);

        $timeout ??= $this->timeout;

        try {
            [$exitCode, $output, $errorOutput] = $this->execute($executable, $command, $timeout, $onOutput);
        } catch (ProcessTimedOutException $exception) {
            $this->fail(ProcessFailedException::timedOut(
                new Result($executable, $redacted, 124, $exception->result->output(), $exception->result->errorOutput(), (hrtime(true) - $startedAt) / 1e9),
                $timeout,
            ));
        }

        $result = new Result(
            executable: $executable,
            command: $redacted,
            exitCode: $exitCode,
            output: $output,
            errorOutput: $errorOutput,
            duration: (hrtime(true) - $startedAt) / 1e9,
        );

        if ($result->failed()) {
            $this->fail(ProcessFailedException::for($result));
        }

        if (trim($result->errorOutput) !== '') {
            $this->logger?->warning("{$executable->value} reported warnings", [
                'command' => $redacted,
                'warnings' => trim($result->errorOutput),
            ]);
        }

        Event::dispatch(new ProcessCompleted($result));

        return $result;
    }

    /**
     * Dispatch the failure, log it with its report context and throw it.
     *
     * @throws ProcessFailedException
     */
    protected function fail(ProcessFailedException $exception): never
    {
        Event::dispatch(new ProcessFailed($exception->result, $exception->reason));

        $this->logger?->error($exception->getMessage(), $exception->context());

        throw $exception;
    }

    /**
     * Run the command and return its exit code, output and error output.
     *
     * @param  list<string>  $command
     * @param  (callable(string): mixed)|null  $onOutput
     * @return array{int, string, string}
     */
    protected function execute(Executable $executable, array $command, int $timeout, ?callable $onOutput): array
    {
        $result = Process::timeout($timeout)
            ->start($command)
            ->wait(function (string $type, string $output) use ($onOutput): void {
                if ($type === 'out' && $onOutput !== null) {
                    $onOutput($output);
                }
            });

        return [$result->exitCode() ?? 1, $result->output(), $result->errorOutput()];
    }

    /**
     * The command line the executable would run with, with sensitive values redacted.
     *
     * @param  list<string>  $arguments
     */
    public function commandLine(Executable $executable, array $arguments): string
    {
        return $this->redact([$this->executables->path($executable), ...$arguments]);
    }

    /**
     * Hide the values of sensitive options, such as encryption keys.
     *
     * @param  list<string>  $command
     */
    public function redact(array $command): string
    {
        $options = implode('|', array_map(fn (string $option): string => preg_quote($option, '/'), self::SENSITIVE_OPTIONS));

        $redacted = [];
        $hideNext = false;

        foreach ($command as $argument) {
            if ($hideNext) {
                $redacted[] = '[REDACTED]';
                $hideNext = false;

                continue;
            }

            if (preg_match("/^-{1,2}({$options})$/", $argument) === 1) {
                $redacted[] = $argument;
                $hideNext = true;

                continue;
            }

            $argument = preg_replace("/^(-{1,2}(?:{$options}))=.+$/", '$1=[REDACTED]', $argument) ?? '[REDACTED]';
            $argument = preg_replace('/\\b(key_id|key)=[0-9a-fA-F]+/', '$1=[REDACTED]', $argument) ?? '[REDACTED]';

            $redacted[] = $argument;
        }

        return implode(' ', array_map(fn (string $argument): string => str_contains($argument, ' ') ? escapeshellarg($argument) : $argument, $redacted));
    }
}
