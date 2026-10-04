<?php

declare(strict_types=1);

namespace Foxws\Media\Packaging;

use Foxws\Media\Encryption\EncryptionKey;
use Foxws\Media\Encryption\ProtectionScheme;
use InvalidArgumentException;

/**
 * How packaged segments are encrypted with a raw AES key (Common Encryption).
 */
final readonly class Encryption
{
    /**
     * @param  ProtectionScheme|null  $scheme  Null uses the packager's default (cenc for Shaka). Use cbcs for one set of segments that plays with both HLS and DASH, including Safari.
     * @param  string|null  $keyFile  A file name for the raw key, written next to the segments; null to serve the key yourself.
     * @param  string|null  $keyUri  The key URI written into HLS playlists; defaults to the key file name.
     * @param  int|null  $rotation  Seconds after which a new key is used. Later keys are derived from this one by the packager.
     * @param  float  $clearLead  Seconds at the start left unencrypted, so playback can start before the key is fetched.
     */
    public function __construct(
        public EncryptionKey $key,
        public ?ProtectionScheme $scheme = null,
        public ?string $keyFile = 'key',
        public ?string $keyUri = null,
        public ?int $rotation = null,
        public float $clearLead = 0.0,
        public ?string $label = null,
    ) {
        if ($rotation !== null && $rotation <= 0) {
            throw new InvalidArgumentException('The key rotation must be a positive number of seconds.');
        }

        if ($clearLead < 0) {
            throw new InvalidArgumentException("The clear lead can't be negative.");
        }

        if ($keyFile !== null && preg_match('/^[A-Za-z0-9._-]+$/', $keyFile) !== 1) {
            throw new InvalidArgumentException("The key file [{$keyFile}] must be a plain file name.");
        }
    }

    /**
     * The URI players fetch the key from, written into HLS playlists.
     */
    public function keyUri(): ?string
    {
        return $this->keyUri ?? $this->keyFile;
    }

    /**
     * A copy with the given properties changed.
     *
     * @param  array<string, mixed>  $changes
     */
    public function with(array $changes): self
    {
        return new self(...[...get_object_vars($this), ...$changes]);
    }
}
