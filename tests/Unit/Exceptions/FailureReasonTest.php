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
    'anything else' => ['Conversion failed!', FailureReason::Unknown, true],
]);

it('retries timeouts but not broken input', function () {
    expect(FailureReason::Timeout->isRetryable())->toBeTrue()
        ->and(FailureReason::InvalidInput->isRetryable())->toBeFalse();
});
