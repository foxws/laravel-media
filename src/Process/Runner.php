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
use Illuminate\Contracts\Events\Dispatcher;
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
        protected Dispatcher $events,
        protected ?LoggerInterface $logger = null,
        protected int $timeout = 14400,
    ) {}

    /**
     * Run the executable with the given arguments.
     *
     * @param  list<string>  $arguments
     *
     * @throws ProcessFailedException
     */
    public function run(Executable $executable, array $arguments, ?int $timeout = null): Result
    {
        $command = [$this->executables->path($executable), ...$arguments];

        $redacted = $this->redact($command);

        $this->events->dispatch(new ProcessStarted($executable, $redacted));

        $this->logger?->debug("Running {$executable->value}", ['command' => $redacted]);

        $startedAt = hrtime(true);

        $processResult = Process::timeout($timeout ?? $this->timeout)->run($command);

        $result = new Result(
            executable: $executable,
            command: $redacted,
            exitCode: $processResult->exitCode() ?? 1,
            output: $processResult->output(),
            errorOutput: $processResult->errorOutput(),
            duration: (hrtime(true) - $startedAt) / 1e9,
        );

        if ($result->failed()) {
            $this->events->dispatch(new ProcessFailed($result));

            $this->logger?->error("{$executable->value} failed", [
                'command' => $redacted,
                'exit_code' => $result->exitCode,
                'error' => $result->errorOutput,
            ]);

            throw ProcessFailedException::for($result);
        }

        $this->events->dispatch(new ProcessCompleted($result));

        return $result;
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
