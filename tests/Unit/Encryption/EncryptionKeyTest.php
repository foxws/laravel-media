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

it('derives the same key for the same secret and context', function () {
    $key = EncryptionKey::derive('secret', 'video:1:0');

    expect(EncryptionKey::derive('secret', 'video:1:0'))->toEqual($key)
        ->and(EncryptionKey::derive('secret', 'video:1:1')->key)->not->toBe($key->key)
        ->and(EncryptionKey::derive('other', 'video:1:0')->key)->not->toBe($key->key)
        ->and($key->keyId)->not->toBe($key->key)
        ->and($key->key)->toMatch('/^[0-9a-f]{32}$/');
});

it('needs a secret to derive a key', function () {
    EncryptionKey::derive('', 'video:1');
})->throws(InvalidArgumentException::class, 'needs a secret');

it('formats the key id as a uuid', function () {
    $key = new EncryptionKey(str_repeat('0', 32), '0123456789abcdef0123456789abcdef');

    expect($key->keyIdUuid())->toBe('01234567-89ab-cdef-0123-456789abcdef');
});

it('describes the key as a json web key with base64url values', function () {
    $key = new EncryptionKey('fbfeffffffffffffffffffffffffffff', '00000000000000000000000000000001');

    expect($key->toJsonWebKey())->toBe(['kty' => 'oct', 'kid' => 'AAAAAAAAAAAAAAAAAAAAAQ', 'k' => '-_7__________________w']);
});
