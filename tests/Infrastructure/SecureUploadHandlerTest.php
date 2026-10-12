<?php

declare(strict_types=1);

use App\Infrastructure\Security\FieldEncryptor;
use App\Infrastructure\Storage\SecureUploadHandler;

it('accepts valid file uploads and stores with UUID v7 and encrypted original filename', function (): void {
    $key = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    $encryptor = new FieldEncryptor($key);
    $vaultDir = sys_get_temp_dir() . '/test_vault_' . bin2hex(random_bytes(4));

    $handler = new SecureUploadHandler($vaultDir, $encryptor, testMode: true);

    // Create a dummy image (1x1 transparent PNG)
    $tmpPng = tempnam(sys_get_temp_dir(), 'test_upload_');
    file_put_contents(
        $tmpPng,
        base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=', true)
    );

    $result = $handler->handleUpload($tmpPng, 'sensitive_receipt.png');

    expect($result['mimeType'])->toBe('image/png')
        ->and(is_file($result['storedPath']))->toBeTrue()
        ->and($encryptor->decrypt($result['encryptedOriginalName']))->toBe('sensitive_receipt.png');

    // Clean up
    @unlink($tmpPng);
    @unlink($result['storedPath']);
    @rmdir($vaultDir);
});

it('rejects files with disallowed MIME types even if filename suggests otherwise', function (): void {
    $key = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    $encryptor = new FieldEncryptor($key);
    $vaultDir = sys_get_temp_dir() . '/test_vault_' . bin2hex(random_bytes(4));

    $handler = new SecureUploadHandler($vaultDir, $encryptor, testMode: true);

    // Create a plain text script masked as png
    $fakePng = tempnam(sys_get_temp_dir(), 'test_fake_');
    file_put_contents($fakePng, '<?php echo "evil script"; ?>');

    expect(fn () => $handler->handleUpload($fakePng, 'trojan.png'))
        ->toThrow(InvalidArgumentException::class, 'Disallowed file type:');

    @unlink($fakePng);
});

it('rejects a path that was not a real HTTP upload outside of test mode', function (): void {
    $key = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    $encryptor = new FieldEncryptor($key);
    $vaultDir = sys_get_temp_dir() . '/test_vault_' . bin2hex(random_bytes(4));

    // Production mode (the default): a plain tempnam() file was never
    // registered by PHP as an HTTP upload, so is_uploaded_file() on it is
    // false -- this is exactly what stops a caller from passing an
    // arbitrary server-side path (e.g. one built from attacker input) into
    // the one place the spec says $_FILES handling must go through.
    $handler = new SecureUploadHandler($vaultDir, $encryptor);

    $tmpFile = tempnam(sys_get_temp_dir(), 'not_an_upload_');
    file_put_contents($tmpFile, 'irrelevant contents');

    expect(fn () => $handler->handleUpload($tmpFile, 'whatever.pdf'))
        ->toThrow(InvalidArgumentException::class, 'File was not submitted as an HTTP upload.');

    @unlink($tmpFile);
});
