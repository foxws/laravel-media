<?php

declare(strict_types=1);

namespace Foxws\Media\Exceptions;

use Foxws\Media\Process\Result;
use RuntimeException;

class ProcessFailedException extends RuntimeException
{
    /**
     * How many trailing lines of error output are kept in the message and report context.
     */
    protected const int TAIL_LINES = 20;

    public function __construct(
        string $message,
        public readonly Result $result,
        public readonly FailureReason $reason,
    ) {
        parent::__construct($message, $result->exitCode);
    }

    public static function for(Result $result, ?FailureReason $reason = null): self
    {
        $reason ??= FailureReason::fromErrorOutput($result->errorOutput.' '.$result->output);

        return new self(
            sprintf('%s exited with code %d (%s): %s', $result->executable->value, $result->exitCode, $reason->value, static::tail($result, 5)),
            $result,
            $reason,
        );
    }

    public static function timedOut(Result $result, int $timeout): self
    {
        return new self(
            sprintf('%s was stopped after the timeout of %d seconds.', $result->executable->value, $timeout),
            $result,
            FailureReason::Timeout,
        );
    }

    /**
     * Whether a retry may succeed, e.g. to decide between release() and fail() in a job.
     */
    public function isRetryable(): bool
    {
        return $this->reason->isRetryable();
    }

    /**
     * Context Laravel adds when this exception is reported, so error trackers show what ran.
     *
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return [
            'executable' => $this->result->executable->value,
            'exit_code' => $this->result->exitCode,
            'reason' => $this->reason->value,
            'retryable' => $this->isRetryable(),
            'duration' => round($this->result->duration, 3),
            'command' => $this->result->command,
            'error_output' => static::tail($this->result, static::TAIL_LINES),
        ];
    }

    /**
     * The last lines of the error output, or of the output when there is no error output.
     */
    protected static function tail(Result $result, int $lines): string
    {
        $output = trim($result->errorOutput) !== '' ? $result->errorOutput : $result->output;

        $tail = array_slice(preg_split('/\R/', trim($output)) ?: [], -$lines);

        return implode("\n", $tail);
    }
}
