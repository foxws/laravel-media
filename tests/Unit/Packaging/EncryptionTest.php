<?php

declare(strict_types=1);

use Foxws\Media\Encryption\EncryptionKey;
use Foxws\Media\Encryption\ProtectionScheme;
use Foxws\Media\Packaging\Encryption;

it('points hls playlists at the key file unless a key uri is given', function () {
    $key = EncryptionKey::generate();

    expect(new Encryption($key)->keyUri())->toBe('key')
        ->and(new Encryption($key, keyFile: 'video.key')->keyUri())->toBe('video.key')
        ->and(new Encryption($key, keyFile: null, keyUri: 'https://app.test/keys/1')->keyUri())->toBe('https://app.test/keys/1')
        ->and(new Encryption($key, keyFile: null)->keyUri())->toBeNull();
});

it('returns changed copies', function () {
    $encryption = new Encryption(EncryptionKey::generate(), ProtectionScheme::Cbcs);

    $rotated = $encryption->with(['rotation' => 60]);

    expect($rotated)->rotation->toBe(60)->scheme->toBe(ProtectionScheme::Cbcs)
        ->and($encryption->rotation)->toBeNull();
});

it('rejects invalid settings', function (array $settings, string $message) {
    new Encryption(EncryptionKey::generate(), ...$settings);
})->throws(InvalidArgumentException::class)->with([
    'no rotation' => [['rotation' => 0], 'positive'],
    'negative clear lead' => [['clearLead' => -1.0], 'negative'],
    'key file path' => [['keyFile' => '../keys/key'], 'plain file name'],
]);
