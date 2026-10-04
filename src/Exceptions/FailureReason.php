<?php

declare(strict_types=1);

namespace Foxws\Media\Exceptions;

/**
 * Why a process failed, recognised from its error output, so jobs can decide whether to retry.
 */
enum FailureReason: string
{
    case InvalidInput = 'invalid_input';
    case MissingInput = 'missing_input';
    case UnsupportedCodec = 'unsupported_codec';
    case InvalidOptions = 'invalid_options';
    case PermissionDenied = 'permission_denied';
    case NoSpace = 'no_space';
    case Network = 'network';
    case Timeout = 'timeout';
    case Unknown = 'unknown';

    /**
     * Error output fragments (case-insensitive) for each reason, checked in order.
     *
     * @var array<string, list<string>>
     */
    private const array PATTERNS = [
        'no_space' => ['no space left on device', 'disk quota exceeded'],
        'permission_denied' => ['permission denied', 'operation not permitted', 'access denied', 'server returned 403'],
        'missing_input' => ['no such file or directory', 'server returned 404', 'does not exist'],
        'network' => [
            'connection refused', 'connection reset', 'connection timed out', 'network is unreachable',
            'failed to resolve hostname', 'temporary failure in name resolution', 'server returned 5',
            'error in the pull function', 'tls handshake',
        ],
        'unsupported_codec' => [
            'unknown encoder', 'unknown decoder', 'encoder not found', 'decoder not found',
            'unsupported codec', 'could not find tag for codec', 'not currently supported in container',
            'no such filter',
        ],
        'invalid_options' => ['unrecognized option', 'option not found', 'error parsing options', 'invalid argument', 'error splitting the argument list'],
        'invalid_input' => [
            'invalid data found when processing input', 'moov atom not found', 'could not find codec parameters',
            'invalid nal unit', 'error while decoding', 'corrupt',
        ],
    ];

    public static function fromErrorOutput(string $output): self
    {
        $output = strtolower($output);

        foreach (self::PATTERNS as $reason => $patterns) {
            foreach ($patterns as $pattern) {
                if (str_contains($output, $pattern)) {
                    return self::from($reason);
                }
            }
        }

        return self::Unknown;
    }

    /**
     * Whether trying again later may succeed: the network, a timeout or a full disk can recover,
     * while broken input, unsupported codecs or wrong options fail the same way every time.
     */
    public function isRetryable(): bool
    {
        return match ($this) {
            self::Network, self::Timeout, self::NoSpace, self::Unknown => true,
            default => false,
        };
    }
}
