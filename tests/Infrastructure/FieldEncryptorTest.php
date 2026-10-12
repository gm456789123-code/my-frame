<?php

declare(strict_types=1);

use App\Infrastructure\Security\FieldEncryptor;

it('encrypts and decrypts sensitive values accurately', function (): void {
    $key = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    $encryptor = new FieldEncryptor($key);

    $secret = 'citizen-id-1-2345-67890-12-3';
    $encrypted = $encryptor->encrypt($secret);

    expect($encryptor->isEncrypted($encrypted))->toBeTrue()
        ->and($encrypted)->toStartWith('enc:v1:')
        ->and($encrypted)->not->toBe($secret);

    $decrypted = $encryptor->decrypt($encrypted);
    expect($decrypted)->toBe($secret);
});

it('rejects tampered ciphertexts', function (): void {
    $key = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    $encryptor = new FieldEncryptor($key);

    $encrypted = $encryptor->encrypt('confidential-patient-note');

    // Tamper with payload by replacing a character
    $tampered = substr($encrypted, 0, -2) . 'AA';

    expect(fn () => $encryptor->decrypt($tampered))->toThrow(RuntimeException::class);
});

it('rejects keys with invalid byte length', function (): void {
    expect(fn () => new FieldEncryptor('short-key'))->toThrow(InvalidArgumentException::class);
});
