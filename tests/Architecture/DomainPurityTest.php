<?php

declare(strict_types=1);

arch('domain layer never depends on WordPress')
    ->expect('App\Domain')
    ->not->toUse([
        'WP_Post',
        'WP_Query',
        'wpdb',
        'App\Infrastructure',
    ]);

arch('application layer never depends on WordPress')
    ->expect('App\Application')
    ->not->toUse([
        'WP_Post',
        'WP_Query',
        'wpdb',
        'App\Infrastructure',
    ]);

arch('domain and application layers are strictly typed')
    ->expect(['App\Domain', 'App\Application'])
    ->toUseStrictTypes();

test('domain and application layers never reference WordPress globals', function (): void {
    // pest-plugin-arch's toUse() only matches dependencies that resolve to a
    // scanned project layer, so it silently no-ops for external symbols like
    // WP_Post/wpdb that have no src/ file of their own. Grep for them directly.
    $forbidden = ['WP_Post', 'WP_Query', 'wpdb', 'get_posts', 'do_blocks'];
    $violations = [];

    foreach (['src/Domain', 'src/Application'] as $dir) {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(__DIR__ . '/../../' . $dir, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $contents = file_get_contents($file->getPathname());

            foreach ($forbidden as $symbol) {
                if (preg_match('/\b' . preg_quote($symbol, '/') . '\b/', (string) $contents) === 1) {
                    $violations[] = "{$file->getPathname()} references {$symbol}";
                }
            }
        }
    }

    expect($violations)->toBe([]);
});
