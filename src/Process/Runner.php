<?php

declare(strict_types=1);

namespace Foxws\Media\Process;

use Foxws\Media\Concerns\ResolvesFromContainer;
use Foxws\Media\Exceptions\ProcessCancelledException;
use Foxws\Media\Exceptions\ProcessFailedException;
use Foxws\Media\Executables\Binary;
use Foxws\Media\Executables\Executable;
use Foxws\Media\Executables\Executables;
use Foxws\Media\Process\Events\ProcessCompleted;
use Foxws\Media\Process\Events\ProcessFailed;
use Foxws\Media\Process\Events\ProcessStarted;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Process\FakeInvokedProcess;
use Illuminate\Process\InvokedProcess;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Process;
use Psr\Log\LoggerInterface;
use Throwable;

class Runner
{
    use ResolvesFromContainer;

    /**
     * Option names whose values are hidden from logs and events.
     *
     * @var list<string>
     */
    protected const array SENSITIVE_OPTIONS = ['keys', 'key', 'key_id', 'pssh', 'protection_systems', 'raw_key', 'iv', 'decryption_key', 'aes_signing_key', 'aes_signing_iv', 'client_cert_private_key_password', 'encryption_key', 'encryption_keys', 'decryption_keys', 'cenc_decryption_key', 'cenc_decryption_keys', 'headers', 'cookies', 'audible_key', 'audible_iv', 'activation_bytes', 'cryptokey', 'password', 'passphrase'];

    /**
     * The credentials in a URL: "://user:password@".
     */
    protected const string URL_CREDENTIALS = '~://([^\s/@?#"]++)@~';

    /**
     * The query of a URL, which holds the signature of temporary URLs.
     */
    protected const string URL_QUERY = '~://[^\s?"]*+\?\K[^\s"]++~';

    /** @var array<int, \Illuminate\Contracts\Process\InvokedProcess> */
    protected array $running = [];

    /**
     * Runs whose output is still passed on, keyed by run; removed when a run is being stopped.
     *
     * @var array<int, true>
     */
    protected array $streaming = [];

    protected int $runs = 0;

    public function __construct(
        protected Executables $executables,
        protected ?LoggerInterface $logger = null,
        protected int $timeout = 14400,
    ) {}

    /**
     * Run the executable with the given arguments. The output callbacks receive standard
     * output and error output as they stream in, e.g. to parse progress. Turn off
     * logWarnings for programs that report their progress or results on the error output.
     *
     * @param  list<string>  $arguments
     * @param  (callable(string): mixed)|null  $onOutput
     * @param  array<string, string>  $environment  Extra environment variables for the process.
     * @param  (callable(string): mixed)|null  $onErrorOutput
     *
     * @throws ProcessFailedException
     */
    public function run(Binary $executable, array $arguments, ?int $timeout = null, ?callable $onOutput = null, array $environment = [], ?callable $onErrorOutput = null, bool $logWarnings = true): Result
    {
        $command = [$this->executables->path($executable), ...$arguments];

        $redacted = $this->redact($command);

        Event::dispatch(new ProcessStarted($executable, $redacted));

        $this->logger?->debug("Running {$executable->identifier()}", ['command' => $redacted]);

        $startedAt = hrtime(true);

        $timeout ??= $this->timeout;

        try {
            [$exitCode, $output, $errorOutput] = $this->execute($executable, $command, $timeout, $this->outputHandler($onOutput, $onErrorOutput), $environment);
        } catch (ProcessCancelledException) {
            $this->fail(ProcessFailedException::cancelled(
                new Result($executable, $redacted, 130, '', '', (hrtime(true) - $startedAt) / 1e9),
            ));
        } catch (ProcessTimedOutException $exception) {
            $this->fail(ProcessFailedException::timedOut(
                new Result(
                    $executable,
                    $redacted,
                    124,
                    $this->redactOutput($exception->result->output(), $command),
                    $this->redactUrls($exception->result->errorOutput()),
                    (hrtime(true) - $startedAt) / 1e9,
                ),
                $timeout,
            ));
        }

        $result = new Result(
            executable: $executable,
            command: $redacted,
            exitCode: $exitCode,
            output: $this->redactOutput($output, $command),
            errorOutput: $this->redactUrls($errorOutput),
            duration: (hrtime(true) - $startedAt) / 1e9,
        );

        if ($result->failed()) {
            $this->fail(ProcessFailedException::for($result));
        }

        if ($logWarnings && trim($result->errorOutput) !== '') {
            $this->logger?->warning("{$executable->identifier()} reported warnings", [
                'command' => $redacted,
                'warnings' => trim($result->errorOutput),
            ]);
        }

        Event::dispatch(new ProcessCompleted($result));

        return $result;
    }

    /**
     * One callback for execute() that passes each chunk to the callback for its stream.
     *
     * @param  (callable(string): mixed)|null  $onOutput
     * @param  (callable(string): mixed)|null  $onErrorOutput
     * @return (callable(string, string=): void)|null
     */
    protected function outputHandler(?callable $onOutput, ?callable $onErrorOutput): ?callable
    {
        if ($onOutput === null && $onErrorOutput === null) {
            return null;
        }

        return function (string $output, string $type = 'out') use ($onOutput, $onErrorOutput): void {
            $callback = $type === 'err' ? $onErrorOutput : $onOutput;

            if ($callback !== null) {
                $callback($output);
            }
        };
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
     * The output callback receives each chunk with its type: "out" or "err".
     *
     * @param  list<string>  $command
     * @param  (callable(string, string=): mixed)|null  $onOutput
     * @param  array<string, string>  $environment
     * @return array{int, string, string}
     */
    protected function execute(Binary $executable, array $command, int $timeout, ?callable $onOutput, array $environment = []): array
    {
        $id = ++$this->runs;
        $this->streaming[$id] = true;

        // The callback goes to start(), because Symfony already reads available
        // output when starting, which a callback passed to wait() would miss.
        $process = Process::timeout($timeout)->env($environment)->start($command, function (string $type, string $output) use ($onOutput, $id): void {
            if ($onOutput !== null && isset($this->streaming[$id])) {
                $onOutput($output, $type);
            }
        });

        $this->running[$id] = $process;

        try {
            $result = $process->wait();
        } catch (Throwable $exception) {
            unset($this->streaming[$id]);

            $this->stop($process, 1.0);

            throw $exception;
        } finally {
            unset($this->running[$id], $this->streaming[$id]);
        }

        return [$result->exitCode() ?? 1, $result->output(), $result->errorOutput()];
    }

    /**
     * Stop the processes that are still running, e.g. when a queue worker is about to be killed,
     * so ffmpeg doesn't keep running as an orphan. They get SIGTERM, then SIGKILL after the timeout.
     */
    public function stopRunning(float $timeout = 3.0): void
    {
        foreach ($this->running as $process) {
            $this->stop($process, $timeout);
        }
    }

    /**
     * @param  \Illuminate\Contracts\Process\InvokedProcess  $process
     */
    protected function stop(object $process, float $timeout): void
    {
        if (! $process->running()) {
            return;
        }

        if ($process instanceof InvokedProcess || $process instanceof FakeInvokedProcess) {
            $process->stop($timeout);
        } else {
            $process->signal(15);
        }
    }

    /**
     * The command line the executable would run with, with sensitive values redacted.
     *
     * @param  list<string>  $arguments
     */
    public function commandLine(Binary $executable, array $arguments): string
    {
        return $this->redact([$this->executables->path($executable), ...$arguments]);
    }

    /**
     * Hide the credentials and queries of every URL in the text, like the signatures of temporary URLs.
     */
    protected function redactUrls(string $text): string
    {
        if (! str_contains($text, '://')) {
            return $text;
        }

        $text = preg_replace(self::URL_CREDENTIALS, '://[REDACTED]@', $text) ?? '[REDACTED]';

        return preg_replace(self::URL_QUERY, '[REDACTED]', $text) ?? '[REDACTED]';
    }

    /**
     * Hide the secrets of the command's URLs in its output, which ffprobe repeats as the file name.
     * Only those, as the output is data: a URL in a file's tags stays as it is.
     *
     * @param  list<string>  $command
     */
    protected function redactOutput(string $output, array $command): string
    {
        if (! str_contains($output, '://')) {
            return $output;
        }

        foreach ($command as $argument) {
            if (preg_match(self::URL_CREDENTIALS, $argument, $credentials) === 1) {
                $output = str_replace("://{$credentials[1]}@", '://[REDACTED]@', $output);
            }

            if (preg_match(self::URL_QUERY, $argument, $query) === 1) {
                $output = str_replace("?{$query[0]}", '?[REDACTED]', $output);
            }
        }

        return $output;
    }

    /**
     * Hide the values of sensitive options, such as encryption keys, and the secrets in URLs.
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

            $redacted[] = $this->redactUrls($argument);
        }

        return implode(' ', array_map(fn (string $argument): string => str_contains($argument, ' ') ? escapeshellarg($argument) : $argument, $redacted));
    }
}
