<?php

declare(strict_types=1);

use App\Infrastructure\Security\RateLimiter;

function rateLimiterTestDir(): string
{
    $dir = sys_get_temp_dir() . '/ratelimiter_test_' . bin2hex(random_bytes(4));
    mkdir($dir, 0755, true);

    return $dir;
}

function rateLimiterCleanup(string $dir): void
{
    $files = glob($dir . '/*.json') ?: [];
    foreach ($files as $file) {
        @unlink($file);
    }
    @rmdir($dir);
}

it('allows requests under the limit', function (): void {
    $dir = rateLimiterTestDir();
    $limiter = new RateLimiter($dir);

    expect($limiter->tooManyAttempts('ip:1.2.3.4', maxAttempts: 5, windowSeconds: 60))->toBeFalse()
        ->and($limiter->tooManyAttempts('ip:1.2.3.4', maxAttempts: 5, windowSeconds: 60))->toBeFalse()
        ->and($limiter->tooManyAttempts('ip:1.2.3.4', maxAttempts: 5, windowSeconds: 60))->toBeFalse();

    rateLimiterCleanup($dir);
});

it('refuses once the limit is exceeded within the window', function (): void {
    $dir = rateLimiterTestDir();
    $limiter = new RateLimiter($dir);

    for ($i = 0; $i < 3; $i++) {
        expect($limiter->tooManyAttempts('ip:5.6.7.8', maxAttempts: 3, windowSeconds: 60))->toBeFalse();
    }

    // 4th attempt within the same window exceeds the limit of 3.
    expect($limiter->tooManyAttempts('ip:5.6.7.8', maxAttempts: 3, windowSeconds: 60))->toBeTrue();

    rateLimiterCleanup($dir);
});

it('tracks separate keys independently', function (): void {
    $dir = rateLimiterTestDir();
    $limiter = new RateLimiter($dir);

    for ($i = 0; $i < 3; $i++) {
        $limiter->tooManyAttempts('ip:9.9.9.9', maxAttempts: 3, windowSeconds: 60);
    }

    // A different key (different IP) has its own independent counter.
    expect($limiter->tooManyAttempts('ip:1.1.1.1', maxAttempts: 3, windowSeconds: 60))->toBeFalse();

    rateLimiterCleanup($dir);
});

it('resets the count after the window elapses', function (): void {
    $dir = rateLimiterTestDir();
    $limiter = new RateLimiter($dir);

    // Use a 1-second window so the test can observe the reset without
    // needing to mock time.
    for ($i = 0; $i < 2; $i++) {
        $limiter->tooManyAttempts('ip:2.2.2.2', maxAttempts: 2, windowSeconds: 1);
    }
    expect($limiter->tooManyAttempts('ip:2.2.2.2', maxAttempts: 2, windowSeconds: 1))->toBeTrue();

    sleep(2);

    expect($limiter->tooManyAttempts('ip:2.2.2.2', maxAttempts: 2, windowSeconds: 1))->toBeFalse();

    rateLimiterCleanup($dir);
});

it('fails closed (refuses) when the storage directory cannot be created or written', function (): void {
    // A path nested under a file (not a directory) can never be created as
    // a directory, so every open attempt fails -- this proves the limiter
    // denies rather than silently allowing unlimited requests through.
    // mkdir()/fopen() emit a native PHP warning for this (expected, not a
    // test failure), so it's suppressed with a scoped error handler rather
    // than relying on `@`, which PHPUnit's warning capture can still see.
    $blockerFile = sys_get_temp_dir() . '/ratelimiter_blocker_' . bin2hex(random_bytes(4));
    file_put_contents($blockerFile, 'not a directory');
    $impossibleDir = $blockerFile . '/nested/storage';

    $limiter = new RateLimiter($impossibleDir);

    set_error_handler(static fn (): bool => true);
    try {
        $result = $limiter->tooManyAttempts('ip:3.3.3.3', maxAttempts: 5, windowSeconds: 60);
    } finally {
        restore_error_handler();
    }

    expect($result)->toBeTrue();

    @unlink($blockerFile);
});
