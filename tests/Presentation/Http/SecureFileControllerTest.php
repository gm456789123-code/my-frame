<?php

declare(strict_types=1);

use App\Infrastructure\Storage\SignedUrlGenerator;
use App\Presentation\Http\SecureFileController;

function makeVaultDirectory(): string
{
    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'vault_test_' . bin2hex(random_bytes(8));
    mkdir($dir, 0700, true);

    return $dir;
}

it('serves a file that exists in the vault with a valid signed URL', function (): void {
    $vaultDir = makeVaultDirectory();
    $generator = new SignedUrlGenerator('super-secret-key-1234');
    $uuid = '018f2536-1234-7abc-8def-0123456789ab';

    file_put_contents($vaultDir . DIRECTORY_SEPARATOR . $uuid . '.vault', 'secret contents');

    $url = $generator->generateUrl($uuid, 300);
    parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $query);

    $controller = new SecureFileController($generator, $vaultDir);
    $result = $controller->download($uuid, (string) $query['sig'], (int) $query['exp']);

    expect($result['status'])->toBe(200)
        ->and($result['filePath'])->toBe($vaultDir . DIRECTORY_SEPARATOR . $uuid . '.vault')
        ->and($result['headers']['Cache-Control'])->toBe('no-store, private');
});

it('returns 403 for a tampered or expired signature', function (): void {
    $vaultDir = makeVaultDirectory();
    $generator = new SignedUrlGenerator('super-secret-key-1234');
    $uuid = '018f2536-1234-7abc-8def-0123456789ab';

    $controller = new SecureFileController($generator, $vaultDir);
    $result = $controller->download($uuid, 'forged-signature', time() + 300);

    expect($result['status'])->toBe(403)
        ->and($result['filePath'])->toBeNull();
});

it('returns 404 when the signature is valid but the file is missing', function (): void {
    $vaultDir = makeVaultDirectory();
    $generator = new SignedUrlGenerator('super-secret-key-1234');
    $uuid = '018f2536-1234-7abc-8def-0123456789ab';

    $url = $generator->generateUrl($uuid, 300);
    parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $query);

    $controller = new SecureFileController($generator, $vaultDir);
    $result = $controller->download($uuid, (string) $query['sig'], (int) $query['exp']);

    expect($result['status'])->toBe(404);
});

it('rejects a path-traversal uuid with 400 instead of building a filesystem path from it', function (): void {
    $vaultDir = makeVaultDirectory();
    $generator = new SignedUrlGenerator('super-secret-key-1234');

    // Can't even go through generateUrl() (it validates too), so forge a
    // "signature" directly to prove the controller itself rejects this
    // independently of whether the signature would otherwise be valid.
    $controller = new SecureFileController($generator, $vaultDir);
    $result = $controller->download('../../../../etc/passwd', 'anything', time() + 300);

    expect($result['status'])->toBe(400)
        ->and($result['filePath'])->toBeNull();
});
