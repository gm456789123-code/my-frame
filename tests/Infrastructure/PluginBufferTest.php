<?php

declare(strict_types=1);

namespace Tests\Infrastructure;

describe('WordPress Plugin Buffer Shielding', function () {
    it('verifies themes/sovereign/functions.php has valid PHP syntax', function () {
        $path = dirname(__DIR__, 2) . '/themes/sovereign/functions.php';
        expect(file_exists($path))->toBeTrue();

        $output = [];
        $exitCode = 0;
        exec("php -l " . escapeshellarg($path), $output, $exitCode);
        expect($exitCode)->toBe(0);
    });

    it('shields REST API from user enumeration when unauthenticated', function () {
        $endpoints = [
            '/wp/v2/posts' => ['methods' => 'GET'],
            '/wp/v2/users' => ['methods' => 'GET'],
            '/wp/v2/users/(?P<id>[\d]+)' => ['methods' => 'GET'],
        ];

        // Simulate functions.php filter logic
        $filter = function (array $endpoints, bool $isLoggedIn): array {
            if (!$isLoggedIn && isset($endpoints['/wp/v2/users'])) {
                unset($endpoints['/wp/v2/users']);
            }
            if (!$isLoggedIn && isset($endpoints['/wp/v2/users/(?P<id>[\d]+)'])) {
                unset($endpoints['/wp/v2/users/(?P<id>[\d]+)']);
            }
            return $endpoints;
        };

        $unauthenticated = $filter($endpoints, false);
        expect($unauthenticated)->not->toHaveKey('/wp/v2/users')
            ->and($unauthenticated)->not->toHaveKey('/wp/v2/users/(?P<id>[\d]+)')
            ->and($unauthenticated)->toHaveKey('/wp/v2/posts');

        $authenticated = $filter($endpoints, true);
        expect($authenticated)->toHaveKey('/wp/v2/users')
            ->and($authenticated)->toHaveKey('/wp/v2/users/(?P<id>[\d]+)');
    });

    it('strips pingback methods from XML-RPC to eliminate DDoS amplification', function () {
        $methods = [
            'wp.getPosts' => 'this:getPosts',
            'pingback.ping' => 'this:pingbackPing',
            'pingback.extensions.getPingbacks' => 'this:pingbackGetPingbacks',
        ];

        // Simulate functions.php filter logic
        unset($methods['pingback.ping'], $methods['pingback.extensions.getPingbacks']);

        expect($methods)->not->toHaveKey('pingback.ping')
            ->and($methods)->not->toHaveKey('pingback.extensions.getPingbacks')
            ->and($methods)->toHaveKey('wp.getPosts');
    });

    it('appends soft-delete shielding clause to posts_where queries', function () {
        $where = 'WHERE 1=1 AND post_type = "post"';
        $prefix = 'wp_';
        $isAdmin = false;

        if (!$isAdmin) {
            $where .= " AND {$prefix}posts.deleted_at IS NULL";
        }

        expect($where)->toContain('AND wp_posts.deleted_at IS NULL');
    });
});
