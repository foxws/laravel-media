<?php

declare(strict_types=1);

namespace Foxws\Media\Encryption;

use Illuminate\Contracts\Support\Arrayable;
use InvalidArgumentException;

/**
 * A 128-bit AES key and key ID, both hex encoded.
 *
 * @implements Arrayable<string, string>
 */
final readonly class EncryptionKey implements Arrayable
{
    public function __construct(
        public string $key,
        public string $keyId,
    ) {
        foreach (['key' => $key, 'key ID' => $keyId] as $name => $value) {
            if (strlen($value) !== 32 || ! ctype_xdigit($value)) {
                throw new InvalidArgumentException("The encryption {$name} must be 32 hexadecimal characters.");
            }
        }
    }

    public static function generate(): self
    {
        return new self(bin2hex(random_bytes(16)), bin2hex(random_bytes(16)));
    }

    /**
     * A key derived from a secret and a context, e.g. derive(config('app.key'), "video:1:0"), so the
     * same context always gives the same key and keys don't have to be stored.
     */
    public static function derive(string $secret, string $context): self
    {
        if ($secret === '') {
            throw new InvalidArgumentException('Deriving an encryption key needs a secret.');
        }

        return new self(
            substr(hash_hmac('sha256', "key|{$context}", $secret), 0, 32),
            substr(hash_hmac('sha256', "id|{$context}", $secret), 0, 32),
        );
    }

    /**
     * The raw 16-byte key, as served to HLS players from a key URI.
     */
    public function binary(): string
    {
        return (string) hex2bin($this->key);
    }

    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'key_id' => $this->keyId,
        ];
    }
}
