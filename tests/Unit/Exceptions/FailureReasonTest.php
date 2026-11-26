<?php

declare(strict_types=1);

use Foxws\Media\Exceptions\FailureReason;

it('recognises why ffmpeg failed from its error output', function (string $output, FailureReason $reason, bool $retryable) {
    expect(FailureReason::fromErrorOutput($output))->toBe($reason)
        ->and($reason->isRetryable())->toBe($retryable);
})->with([
    'corrupt upload' => ['upload.mp4: Invalid data found when processing input', FailureReason::InvalidInput, false],
    'truncated mp4' => ['[mov,mp4] moov atom not found', FailureReason::InvalidInput, false],
    'missing file' => ['video.mp4: No such file or directory', FailureReason::MissingInput, false],
    'missing object' => ['HTTP error 404 Not Found / Server returned 404 Not Found', FailureReason::MissingInput, false],
    'missing encoder' => ['Unknown encoder \'libsvtav1\'', FailureReason::UnsupportedCodec, false],
    'missing filter' => ['No such filter: \'zscale\'', FailureReason::UnsupportedCodec, false],
    'wrong option' => ['Unrecognized option \'foo\'. Error splitting the argument list: Option not found', FailureReason::InvalidOptions, false],
    'forbidden' => ['Server returned 403 Forbidden (access denied)', FailureReason::PermissionDenied, false],
    'full disk' => ['Error writing trailer: No space left on device', FailureReason::NoSpace, true],
    'network' => ['Connection reset by peer', FailureReason::Network, true],
    'storage outage' => ['Server returned 5XX Server Error reply', FailureReason::Network, true],
    'missing library' => ['ffmpeg: error while loading shared libraries: libx264.so.164: cannot open shared object file: No such file or directory', FailureReason::BrokenExecutable, false],
    'missing library on macOS' => ['dyld[123]: Library not loaded: /opt/homebrew/opt/x264/lib/libx264.164.dylib', FailureReason::BrokenExecutable, false],
    'missing symbol' => ['ffmpeg: symbol lookup error: /usr/lib64/libavcodec.so.62: undefined symbol: x264_encoder_open', FailureReason::BrokenExecutable, false],
    'anything else' => ['Conversion failed!', FailureReason::Unknown, true],
]);

it('retries timeouts but not broken input or cancelled runs', function () {
    expect(FailureReason::Timeout->isRetryable())->toBeTrue()
        ->and(FailureReason::InvalidInput->isRetryable())->toBeFalse()
        ->and(FailureReason::Cancelled->isRetryable())->toBeFalse();
});

it('treats exit codes of an executable that could not start as a broken executable', function (int $exitCode) {
    expect(FailureReason::fromResult($exitCode, ''))->toBe(FailureReason::BrokenExecutable);
})->with([126, 127]);

it('reads other exit codes from the error output', function () {
    expect(FailureReason::fromResult(1, 'upload.mp4: Invalid data found when processing input'))->toBe(FailureReason::InvalidInput)
        ->and(FailureReason::fromResult(187, 'Conversion failed!'))->toBe(FailureReason::Unknown);
});
