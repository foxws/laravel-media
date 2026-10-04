<?php

declare(strict_types=1);

use Foxws\Media\Exceptions\FailureReason;
use Foxws\Media\Exceptions\ProcessFailedException;
use Foxws\Media\Executables\Executable;
use Foxws\Media\Process\Result;

function failedResult(string $errorOutput, int $exitCode = 1): Result
{
    return new Result(Executable::FFMpeg, 'ffmpeg -i video.mp4 out.mp4', $exitCode, '', $errorOutput, 2.5);
}

it('names the reason and the last lines of the error output in its message', function () {
    $lines = implode("\n", array_map(fn (int $line) => "line {$line}", range(1, 30)))."\nvideo.mp4: Invalid data found when processing input";

    $exception = ProcessFailedException::for(failedResult($lines));

    expect($exception->getMessage())->toBe("ffmpeg exited with code 1 (invalid_input): line 27\nline 28\nline 29\nline 30\nvideo.mp4: Invalid data found when processing input")
        ->and($exception->reason)->toBe(FailureReason::InvalidInput)
        ->and($exception->isRetryable())->toBeFalse()
        ->and($exception->getCode())->toBe(1);
});

it('adds the command and error output to reports', function () {
    $exception = ProcessFailedException::for(failedResult(implode("\n", range(1, 30))));

    expect($exception->context())->toBe([
        'executable' => 'ffmpeg',
        'exit_code' => 1,
        'reason' => 'unknown',
        'retryable' => true,
        'duration' => 2.5,
        'command' => 'ffmpeg -i video.mp4 out.mp4',
        'error_output' => implode("\n", range(11, 30)),
    ]);
});

it('describes a timeout', function () {
    $exception = ProcessFailedException::timedOut(failedResult('', 124), timeout: 600);

    expect($exception->getMessage())->toBe('ffmpeg was stopped after the timeout of 600 seconds.')
        ->and($exception->reason)->toBe(FailureReason::Timeout)
        ->and($exception->isRetryable())->toBeTrue();
});
