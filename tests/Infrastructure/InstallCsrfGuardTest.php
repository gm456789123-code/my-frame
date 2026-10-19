<?php

declare(strict_types=1);

use App\Infrastructure\Security\InstallCsrfGuard;

it('rejects a request with no cookie and no posted token', function (): void {
    $guard = new InstallCsrfGuard();

    expect($guard->isValid([], []))->toBeFalse();
});

it('rejects a request with a posted token but no matching cookie (forged cross-site form)', function (): void {
    $guard = new InstallCsrfGuard();

    // An attacker's cross-site page can guess or hardcode a field value,
    // but cannot read or set a cookie on the victim origin -- this is
    // exactly the shape of a CSRF attempt.
    $forgedPost = ['install_csrf' => 'attacker-guessed-value'];

    expect($guard->isValid([], $forgedPost))->toBeFalse();
});

it('rejects a request with a cookie but no posted token', function (): void {
    $guard = new InstallCsrfGuard();

    $realCookie = ['install_csrf' => bin2hex(random_bytes(32))];

    expect($guard->isValid($realCookie, []))->toBeFalse();
});

it('rejects mismatched cookie and posted token values', function (): void {
    $guard = new InstallCsrfGuard();

    $cookie = ['install_csrf' => bin2hex(random_bytes(32))];
    $post = ['install_csrf' => bin2hex(random_bytes(32))];

    expect($guard->isValid($cookie, $post))->toBeFalse();
});

it('accepts a request where the cookie and posted token match', function (): void {
    $guard = new InstallCsrfGuard();

    $token = bin2hex(random_bytes(32));
    $cookie = ['install_csrf' => $token];
    $post = ['install_csrf' => $token];

    expect($guard->isValid($cookie, $post))->toBeTrue();
});

it('rejects non-string cookie or posted values instead of coercing them', function (): void {
    $guard = new InstallCsrfGuard();

    expect($guard->isValid(['install_csrf' => ['array' => 'value']], ['install_csrf' => 'x']))->toBeFalse()
        ->and($guard->isValid(['install_csrf' => 'x'], ['install_csrf' => null]))->toBeFalse();
});

it('issues a non-empty, sufficiently random token', function (): void {
    $guard = new InstallCsrfGuard();

    $tokenA = $guard->issueToken(isHttps: false);
    $tokenB = $guard->issueToken(isHttps: false);

    expect($tokenA)->not->toBe('')
        ->and(strlen($tokenA))->toBeGreaterThanOrEqual(32)
        ->and($tokenA)->not->toBe($tokenB);
});
