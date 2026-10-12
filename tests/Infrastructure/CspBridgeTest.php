<?php

declare(strict_types=1);

use App\Infrastructure\Security\CspBridge;

it('generates random nonces and valid CSP header strings', function (): void {
    $bridge = new CspBridge(['https://custom-analytics.example.com']);
    $nonce = $bridge->generateNonce();

    expect($nonce)->not->toBeEmpty();

    $header = $bridge->buildHeader($nonce);
    expect($header)->toContain("nonce-{$nonce}")
        ->and($header)->toContain('https://custom-analytics.example.com')
        ->and($header)->toContain('report-uri /api/v1/telemetry/csp-reports');
});

it('renders visual diagnostic observer script', function (): void {
    $bridge = new CspBridge();
    $nonce = $bridge->generateNonce();

    $script = $bridge->renderDiagnosticObserverScript($nonce);
    expect($script)->toContain('nonce="' . $nonce . '"')
        ->and($script)->toContain('securitypolicyviolation')
        ->and($script)->toContain('sovereign-csp-diagnostic');
});
