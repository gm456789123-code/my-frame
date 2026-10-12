<?php

declare(strict_types=1);

use App\Infrastructure\Storage\SignedUrlGenerator;

it('generates valid signed URLs for sensitive files', function (): void {
    $generator = new SignedUrlGenerator('super-secret-key-1234');
    $uuid = '018f2536-1234-7abc-8def-0123456789ab';

    $url = $generator->generateUrl($uuid, 300);

    expect($url)->toContain('/secure-file/' . $uuid)
        ->and($url)->toContain('sig=')
        ->and($url)->toContain('exp=');

    // Extract signature and exp
    parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $query);

    expect(isset($query['sig']))->toBeTrue()
        ->and(isset($query['exp']))->toBeTrue()
        ->and($generator->verifyUrl($uuid, (string) $query['sig'], (int) $query['exp']))->toBeTrue();
});

it('rejects expired or forged signatures', function (): void {
    $generator = new SignedUrlGenerator('super-secret-key-1234');
    $uuid = '018f2536-1234-7abc-8def-0123456789ab';

    $url = $generator->generateUrl($uuid, 300);
    parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $query);

    // Expired timestamp
    expect($generator->verifyUrl($uuid, (string) $query['sig'], time() - 10))->toBeFalse();

    // Forged signature
    expect($generator->verifyUrl($uuid, 'invalid-signature-hash', (int) $query['exp']))->toBeFalse();
});

it('rejects TTL greater than 10 minutes (600s)', function (): void {
    $generator = new SignedUrlGenerator('super-secret-key-1234');
    $uuid = '018f2536-1234-7abc-8def-0123456789ab';

    expect(fn () => $generator->generateUrl($uuid, 601))
        ->toThrow(InvalidArgumentException::class, 'TTL must be between 1 and 600 seconds.');
});

it('rejects a malformed uuid before it ever reaches a filesystem path', function (): void {
    $generator = new SignedUrlGenerator('super-secret-key-1234');

    expect(fn () => $generator->generateUrl('../../etc/passwd', 300))
        ->toThrow(InvalidArgumentException::class);
});
