<?php

declare(strict_types=1);

use App\Infrastructure\Security\CsrfTokenManager;

it('generates and validates session-bound csrf tokens', function (): void {
    $manager = new CsrfTokenManager('super-secret-csrf-key-1234');
    $token = $manager->generateToken('session_user_abc', 300);

    expect($manager->validateToken('session_user_abc', $token))->toBeTrue()
        ->and($manager->validateToken('session_user_xyz', $token))->toBeFalse();
});

it('rejects expired csrf tokens', function (): void {
    $manager = new CsrfTokenManager('super-secret-csrf-key-1234');
    $token = $manager->generateToken('session_user_abc', -10);

    expect($manager->validateToken('session_user_abc', $token))->toBeFalse();
});
