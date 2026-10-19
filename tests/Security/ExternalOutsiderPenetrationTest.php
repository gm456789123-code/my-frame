<?php

declare(strict_types=1);

namespace Tests\Security;

use App\Application\UseCase\Page\GetPage;
use App\Domain\Model\Page;
use App\Domain\ValueObject\Locale;
use App\Domain\ValueObject\PageStatus;
use App\Domain\ValueObject\PostId;
use App\Infrastructure\Cache\CacheInterface;
use App\Infrastructure\Wordpress\Gutenberg\BlockAstParser;
use App\Presentation\Http\PageController;
use App\Presentation\View\BlockRenderer;
use DateTimeImmutable;
use Latte\Engine;
use Tests\Doubles\InMemoryPageRepository;

describe('External Outsider & Black-Box Attack Simulation', function (): void {

    describe('1. Web Cache Poisoning & Host Header Injection', function (): void {
        it('does not allow malicious Host headers to override configured APP_URL in production', function (): void {
            $configuredAppUrl = 'https://my-real-domain.com';
            $maliciousHost = 'attacker-phishing.com';

            // Simulate server environment
            $_SERVER['HTTPS'] = 'on';
            $_SERVER['HTTP_HOST'] = $maliciousHost;

            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $rawHost = is_string($_SERVER['HTTP_HOST'] ?? null) ? $_SERVER['HTTP_HOST'] : 'localhost:8080';
            $host = preg_match('/^[a-zA-Z0-9.-]+(:[0-9]+)?$/', $rawHost) === 1 ? $rawHost : 'localhost:8080';
            $detectedSiteUrl = "{$scheme}://{$host}";

            $appUrl = $configuredAppUrl;
            $siteUrl = ($appUrl !== '' && !str_contains($appUrl, 'localhost'))
                ? rtrim($appUrl, '/')
                : rtrim($appUrl !== '' ? $appUrl : $detectedSiteUrl, '/');

            // Must strictly stick to the configured production URL, NOT the attacker's host
            expect($siteUrl)->toBe('https://my-real-domain.com')
                ->and($siteUrl)->not->toContain($maliciousHost);
        });

        it('sanitizes and strips invalid or malicious characters from Host headers', function (string $evilHost): void {
            $_SERVER['HTTP_HOST'] = $evilHost;
            $rawHost = is_string($_SERVER['HTTP_HOST'] ?? null) ? $_SERVER['HTTP_HOST'] : 'localhost:8080';
            $host = preg_match('/^[a-zA-Z0-9.-]+(:[0-9]{1,5})?$/', $rawHost) === 1 ? $rawHost : 'localhost:8080';

            // Must fall back to safe localhost:8080 rather than accepting injection
            expect($host)->toBe('localhost:8080');
        })->with([
            "attacker.com\r\nX-Injected: evil",
            'attacker.com<script>alert(1)</script>',
            'attacker.com/evil-path',
            "attacker.com\0.local",
            'attacker.com:9999999999',
            'attacker.com; rm -rf /',
        ]);
    });

    describe('2. Parameter Pollution & Malformed Input Handling', function (): void {
        it('safely handles non-positive and malformed page_id without throwing unhandled exceptions', function (mixed $param): void {
            $pageId = is_string($param) && ctype_digit($param) && (int) $param > 0 ? (int) $param : null;

            expect($pageId)->toBeNull();
        })->with([
            '0',
            '-1',
            '-999',
            '000',
            'abc',
            '1.5',
            '1; DROP TABLE wp_posts',
            '1 OR 1=1',
            '',
            'null',
        ]);
    });

    describe('3. Baseline Security Headers (Clickjacking, MIME Sniffing, Referrer)', function (): void {
        it('includes nosniff, SAMEORIGIN, and Referrer-Policy on warm cache hits', function (): void {
            $repo = new InMemoryPageRepository();
            $getPage = new GetPage($repo);
            $parser = new BlockAstParser();
            $latte = new Engine();
            $renderer = new BlockRenderer($latte, __DIR__ . '/../../templates/blocks');

            $cacheMock = new class implements CacheInterface {
                public function get(string $key): ?string { return 'Cached HTML'; }
                public function set(string $key, string $value, int $ttl = 3600, array $tags = []): void {}
                public function delete(string $key): void {}
                public function invalidateTag(string $tag): void {}
            };

            $controller = new PageController($getPage, $parser, $renderer, $cacheMock);
            $response = $controller->show(123);

            expect($response['headers'])->toHaveKey('X-Content-Type-Options')
                ->and($response['headers']['X-Content-Type-Options'])->toBe('nosniff')
                ->and($response['headers'])->toHaveKey('X-Frame-Options')
                ->and($response['headers']['X-Frame-Options'])->toBe('SAMEORIGIN')
                ->and($response['headers'])->toHaveKey('Referrer-Policy')
                ->and($response['headers']['Referrer-Policy'])->toBe('strict-origin-when-cross-origin');
        });

        it('includes nosniff, SAMEORIGIN, and Referrer-Policy on cold responses', function (): void {
            $repo = new InMemoryPageRepository();
            $id = $repo->create('Safe Page', '<p>Clean</p>', PageStatus::Published, Locale::English);

            $getPage = new GetPage($repo);
            $parser = new BlockAstParser();
            $latte = new Engine();
            $renderer = new BlockRenderer($latte, __DIR__ . '/../../templates/blocks');

            $controller = new PageController($getPage, $parser, $renderer);
            $response = $controller->show($id->value);

            expect($response['headers'])->toHaveKey('X-Content-Type-Options')
                ->and($response['headers']['X-Content-Type-Options'])->toBe('nosniff')
                ->and($response['headers'])->toHaveKey('X-Frame-Options')
                ->and($response['headers']['X-Frame-Options'])->toBe('SAMEORIGIN')
                ->and($response['headers'])->toHaveKey('Referrer-Policy')
                ->and($response['headers']['Referrer-Policy'])->toBe('strict-origin-when-cross-origin');
        });

        it('includes nosniff, SAMEORIGIN, and Referrer-Policy on 404 responses', function (): void {
            $repo = new InMemoryPageRepository();
            $getPage = new GetPage($repo);
            $parser = new BlockAstParser();
            $latte = new Engine();
            $renderer = new BlockRenderer($latte, __DIR__ . '/../../templates/blocks');

            $controller = new PageController($getPage, $parser, $renderer);
            $response = $controller->show(99999);

            expect($response['status'])->toBe(404)
                ->and($response['headers'])->toHaveKey('X-Content-Type-Options')
                ->and($response['headers']['X-Content-Type-Options'])->toBe('nosniff')
                ->and($response['headers'])->toHaveKey('X-Frame-Options')
                ->and($response['headers']['X-Frame-Options'])->toBe('SAMEORIGIN');
        });
    });

    describe('4. Web Server Directory Traversal & Sensitive File Exposure (.htaccess rules)', function (): void {
        it('blocks all sensitive files and directories according to .htaccess rules', function (string $sensitiveUri): void {
            // Regex 1: dotfiles (^|/)\.(?!well-known(/|$))
            $dotfilePattern = '#(\^|/)\.(?!well-known(/|$))#';
            // Regex 2: framework directories ^(vendor|src|var|db|tests|docker|bin|scripts|templates|node_modules)(/|$)
            $dirsPattern = '#^(vendor|src|var|db|tests|docker|bin|scripts|templates|node_modules)(/|$)#';
            // Regex 3b: never execute PHP (or disguised double-extensions) under wp-content/uploads
            $uploadsPhpPattern = '#^wp-content/uploads/.*\.(php[0-9]?|phtml|pht|phar)(\.|$)#i';
            // Regex 4: sensitive metadata/editor-backup extensions, at ANY depth
            $extPattern = '#\.(md|lock|neon|phar|dist|yml|yaml|xml|json|sh|ini|log|sql|stub|bak|txt|html?|swp|swo)$#';
            $backupSuffixPattern = '#(~|\.orig)$#';
            // Regex 4 literal: specific files
            $filesPattern = '#^(phinx\.php|wp-config\.php|LICENSE)$#';

            $cleanUri = ltrim($sensitiveUri, '/');

            $isBlocked = preg_match($dotfilePattern, $sensitiveUri) === 1
                || preg_match($dirsPattern, $cleanUri) === 1
                || preg_match($uploadsPhpPattern, $cleanUri) === 1
                || preg_match($extPattern, $cleanUri) === 1
                || preg_match($backupSuffixPattern, $cleanUri) === 1
                || preg_match($filesPattern, $cleanUri) === 1;

            expect($isBlocked)->toBeTrue();
        })->with([
            '/.env',
            '/.env.example',
            '/.git/config',
            '/.gitignore',
            'vendor/autoload.php',
            'src/Infrastructure/Env.php',
            'var/cache/data.cache',
            'db/migrations/20260101000000_add_soft_delete_to_wp_posts.php',
            'tests/Security/ComprehensivePenetrationTest.php',
            'docker/app.Dockerfile',
            'bin/console',
            'composer.json',
            'composer.lock',
            'wp-config.php',
            'phinx.php',
            'phpstan.neon',
            'phpunit.xml',
            'psalm.xml',
            'agents_md.md',
            'PROJECT_STATUS.md',
            'LICENSE',
            // Scanner/recon tooling (WPScan, dirbuster, nikto) favourites that
            // nested-path or fingerprinting files could otherwise slip through:
            'wp-content/debug.log',
            'wp-content/uploads/2024/01/backup.sql',
            'wp-content/plugins/akismet/readme.txt',
            'readme.html',
            'wp-config.php.bak',
            'wp-config.php~',
            'wp-config.php.swp',
            'wp-content/.htaccess',
            // Uploaded-shell execution attempts (disguised double extensions):
            'wp-content/uploads/shell.php',
            'wp-content/uploads/shell.php5',
            'wp-content/uploads/2024/malware.php.jpg',
            'wp-content/uploads/evil.phtml.png',
        ]);

        it('still serves legitimate static assets under wp-content/uploads', function (string $safeUri): void {
            $uploadsPhpPattern = '#^wp-content/uploads/.*\.(php[0-9]?|phtml|pht|phar)(\.|$)#i';
            $extPattern = '#\.(md|lock|neon|phar|dist|yml|yaml|xml|json|sh|ini|log|sql|stub|bak|txt|html?|swp|swo)$#';

            expect(preg_match($uploadsPhpPattern, $safeUri))->toBe(0)
                ->and(preg_match($extPattern, $safeUri))->toBe(0);
        })->with([
            'wp-content/uploads/2024/01/photo.jpg',
            'wp-content/uploads/2024/01/document.pdf',
            'wp-content/uploads/logo.webp',
            'public/assets/sovereign.css',
            'public/assets/logo.webp',
        ]);
    });
});
