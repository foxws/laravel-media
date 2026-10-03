<?php

declare(strict_types=1);

use Foxws\Media\Encryption\EncryptionKey;

it('generates a 128-bit key and key id', function () {
    $key = EncryptionKey::generate();

    expect($key->key)->toMatch('/^[0-9a-f]{32}$/')
        ->and($key->keyId)->toMatch('/^[0-9a-f]{32}$/')
        ->and($key->key)->not->toBe($key->keyId)
        ->and(strlen($key->binary()))->toBe(16);
});

it('rejects a key that is not 32 hexadecimal characters', function () {
    new EncryptionKey('not-a-key', str_repeat('a', 32));
})->throws(InvalidArgumentException::class, 'The encryption key must be 32 hexadecimal characters.');
