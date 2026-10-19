<?php

declare(strict_types=1);

use App\Application\UseCase\Page\GetPage;
use App\Application\UseCase\Page\SearchPages;
use App\Domain\Model\Page;
use App\Domain\ValueObject\Locale;
use App\Domain\ValueObject\PageStatus;
use App\Domain\ValueObject\PostId;
use App\Infrastructure\Env;
use App\Infrastructure\Security\CsrfTokenManager;
use App\Infrastructure\Security\RateLimiter;
use App\Infrastructure\Storage\SignedUrlGenerator;
use App\Infrastructure\Wordpress\Gutenberg\BlockAstParser;
use App\Presentation\Http\CsrfController;
use App\Presentation\Http\PageController;
use App\Presentation\Http\Router;
use App\Presentation\Http\SearchController;
use App\Presentation\Http\SecureFileController;
use App\Presentation\View\BlockRenderer;
use Latte\Engine;
use Tests\Doubles\InMemoryPageRepository;
use Tests\Doubles\InMemorySearchRepository;

function createTestRouter(
    InMemoryPageRepository $repo,
    ?InMemorySearchRepository $searchRepo = null,
    ?RateLimiter $rateLimiter = null,
): Router {
    $latte = new Engine();
    $latte->setTempDirectory(sys_get_temp_dir());
    $renderer = new BlockRenderer($latte, __DIR__ . '/../../../templates/blocks');
    $parser = new BlockAstParser();
    $getPage = new GetPage($repo);

    $pageController = new PageController(
        getPage: $getPage,
        parser: $parser,
        renderer: $renderer,
        latte: $latte,
        layoutPath: __DIR__ . '/../../../templates/layout.latte',
        siteName: 'Test Sovereign',
        siteUrl: 'https://example.com',
    );

    $csrfManager = new CsrfTokenManager('test-secret-key-1234567890123456');
    $csrfController = new CsrfController($csrfManager);

    $urlGenerator = new SignedUrlGenerator('test-url-secret-key-12345678901');
    $secureFileController = new SecureFileController($urlGenerator, sys_get_temp_dir());

    $searchController = null;
    if ($searchRepo !== null) {
        $searchController = new SearchController(
            searchPages: new SearchPages($searchRepo),
            latte: $latte,
            templatePath: __DIR__ . '/../../../templates/search.latte',
            siteName: 'Test Sovereign',
            siteUrl: 'https://example.com',
        );
    }

    return new Router(
        repository: $repo,
        pageController: $pageController,
        secureFileController: $secureFileController,
        csrfController: $csrfController,
        searchController: $searchController,
        siteUrl: 'https://example.com',
        rateLimiter: $rateLimiter,
    );
}

it('serves robots.txt with sitemap directive and security headers', function (): void {
    $repo = new InMemoryPageRepository();
    $router = createTestRouter($repo);

    $response = $router->dispatch('GET', '/robots.txt');

    expect($response['status'])->toBe(200)
        ->and($response['headers']['Content-Type'])->toBe('text/plain; charset=UTF-8')
        ->and($response['headers']['X-Content-Type-Options'])->toBe('nosniff')
        ->and($response['body'])->toContain('User-agent: *')
        ->and($response['body'])->toContain('Disallow: /wp-admin/')
        ->and($response['body'])->toContain('Sitemap: https://example.com/sitemap.xml');
});

it('serves sitemap.xml with valid xml structure', function (): void {
    $repo = new InMemoryPageRepository();
    $router = createTestRouter($repo);

    $response = $router->dispatch('GET', '/sitemap.xml');

    expect($response['status'])->toBe(200)
        ->and($response['headers']['Content-Type'])->toBe('application/xml; charset=UTF-8')
        ->and($response['body'])->toContain('<?xml version="1.0"')
        ->and($response['body'])->toContain('<urlset')
        ->and($response['body'])->toContain('<loc>https://example.com/</loc>');
});

it('serves CSRF token via /api/v1/auth/csrf-token', function (): void {
    $repo = new InMemoryPageRepository();
    $router = createTestRouter($repo);

    $response = $router->dispatch('GET', '/api/v1/auth/csrf-token', ['session_id' => 'session-abc-123']);

    expect($response['status'])->toBe(200)
        ->and($response['headers']['Content-Type'])->toBe('application/json; charset=UTF-8')
        ->and($response['body'])->toContain('csrf_token');
});

it('routes homepage to the designated front page', function (): void {
    $repo = new InMemoryPageRepository();
    $id = $repo->create('Home Page', '<!-- wp:paragraph -->Welcome to Sovereign<!-- /wp:paragraph -->', PageStatus::Published, Locale::English);
    $repo->setFrontPageId($id->value);

    $router = createTestRouter($repo);
    $response = $router->dispatch('GET', '/');

    expect($response['status'])->toBe(200)
        ->and($response['body'])->toContain('Welcome to Sovereign')
        ->and($response['headers'])->toHaveKey('ETag')
        ->and($response['headers'])->toHaveKey('Last-Modified');
});

it('routes by slug including Thai Unicode characters', function (): void {
    $repo = new InMemoryPageRepository();
    $repo->create('ภาษาไทย', '<!-- wp:paragraph -->เนื้อหาภาษาไทย<!-- /wp:paragraph -->', PageStatus::Published, Locale::Thai);

    $router = createTestRouter($repo);
    // Request with percent-encoded URL
    $encodedPath = '/' . rawurlencode('ภาษาไทย');
    $response = $router->dispatch('GET', $encodedPath);

    expect($response['status'])->toBe(200)
        ->and($response['body'])->toContain('เนื้อหาภาษาไทย');
});

it('routes legacy ?page_id=X to PageController', function (): void {
    $repo = new InMemoryPageRepository();
    $id = $repo->create('About Us', '<!-- wp:paragraph -->About Sovereign<!-- /wp:paragraph -->', PageStatus::Published, Locale::English);

    $router = createTestRouter($repo);
    $response = $router->dispatch('GET', '/', ['page_id' => (string) $id->value]);

    expect($response['status'])->toBe(200)
        ->and($response['body'])->toContain('About Sovereign');
});

it('returns real 404 with noindex for unknown paths', function (): void {
    $repo = new InMemoryPageRepository();
    $router = createTestRouter($repo);

    $response = $router->dispatch('GET', '/non-existent-article-slug');

    expect($response['status'])->toBe(404)
        ->and($response['headers']['Content-Type'])->toBe('text/html; charset=UTF-8')
        ->and($response['body'])->toContain('Page Not Found')
        ->and($response['body'])->toContain('noindex');
});

it('refuses unauthenticated draft preview requests in production', function (): void {
    $repo = new InMemoryPageRepository();
    $id = $repo->create('Secret Draft', 'Confidential Launch Plan', PageStatus::Draft, Locale::English);

    $router = createTestRouter($repo); // isLocal = false by default
    $response = $router->dispatch('GET', '/', ['p' => (string) $id->value, 'preview' => 'true']);

    expect($response['status'])->toBe(403)
        ->and($response['body'])->toContain('403 Forbidden: Unauthorized Preview Request');
});

it('rejects a forged WordPress cookie for draft preview (no HMAC validation == anyone can set it)', function (): void {
    $repo = new InMemoryPageRepository();
    $id = $repo->create('Secret Draft', '<!-- wp:paragraph -->Confidential Launch Plan<!-- /wp:paragraph -->', PageStatus::Draft, Locale::English);

    $router = createTestRouter($repo);
    $futureTime = time() + 3600;
    // A real wordpress_logged_in_* cookie's 4th segment is an HMAC signed
    // with WordPress's own secret salts; an attacker can set any Cookie
    // header they like, so a cookie shaped like this but signed with
    // nothing (faketoken|fakehmac) must never be trusted on its own.
    $forgedWpCookie = "wordpress_logged_in_test_hash=editor|{$futureTime}|faketoken|fakehmac";

    $response = $router->dispatch(
        'GET',
        '/',
        ['p' => (string) $id->value, 'preview' => 'true'],
        ['HTTP_COOKIE' => $forgedWpCookie]
    );

    expect($response['status'])->toBe(403)
        ->and($response['body'])->not->toContain('Confidential Launch Plan');
});

it('allows draft preview with a valid signed preview token', function (): void {
    $previousAppKey = $_ENV['APP_KEY'] ?? null;
    $_ENV['APP_KEY'] = 'test-only-secret-key-for-preview-hmac';

    try {
        $repo = new InMemoryPageRepository();
        $id = $repo->create('Secret Draft', '<!-- wp:paragraph -->Confidential Launch Plan<!-- /wp:paragraph -->', PageStatus::Draft, Locale::English);

        $router = createTestRouter($repo);
        $validToken = hash_hmac('sha256', "preview:{$id->value}", Env::string('APP_KEY', ''));

        $response = $router->dispatch(
            'GET',
            '/',
            ['p' => (string) $id->value, 'preview' => 'true', 'preview_token' => $validToken],
        );

        expect($response['status'])->toBe(200)
            ->and($response['body'])->toContain('Confidential Launch Plan')
            ->and($response['body'])->toContain('noindex'); // drafts automatically receive noindex
    } finally {
        if ($previousAppKey === null) {
            unset($_ENV['APP_KEY']);
        } else {
            $_ENV['APP_KEY'] = $previousAppKey;
        }
    }
});

it('renders pages linking to static stylesheet without cdn.tailwindcss.com and with strict CSP', function (): void {
    $repo = new InMemoryPageRepository();
    $id = $repo->create('Styled Page', '<!-- wp:paragraph -->Hello styling<!-- /wp:paragraph -->', PageStatus::Published, Locale::English);

    $router = createTestRouter($repo);
    $response = $router->dispatch('GET', '/', ['page_id' => (string) $id->value]);

    expect($response['status'])->toBe(200)
        ->and($response['body'])->toContain('<link rel="stylesheet" href="')
        ->and($response['body'])->not->toContain('cdn.tailwindcss.com')
        ->and($response['headers'])->toHaveKey('Content-Security-Policy')
        ->and($response['headers']['Content-Security-Policy'])->not->toContain('unsafe-eval')
        ->and($response['headers']['Content-Security-Policy'])->not->toContain('cdn.tailwindcss.com');
});

it('routes by locale prefix and injects hreflang alternate links', function (): void {
    $repo = new InMemoryPageRepository();
    $repo->create('คู่มือการใช้งาน', '<!-- wp:paragraph -->เนื้อหาภาษาไทย<!-- /wp:paragraph -->', PageStatus::Published, Locale::Thai);
    $repo->create('User Manual', '<!-- wp:paragraph -->English Content<!-- /wp:paragraph -->', PageStatus::Published, Locale::English);

    $router = createTestRouter($repo);

    // 1. Thai page via /th/
    $responseTh = $router->dispatch('GET', '/th/' . rawurlencode('คู่มือการใช้งาน'));
    expect($responseTh['status'])->toBe(200)
        ->and($responseTh['body'])->toContain('เนื้อหาภาษาไทย')
        ->and($responseTh['body'])->toContain('<link rel="alternate" hreflang="th"')
        ->and($responseTh['body'])->toContain('<link rel="alternate" hreflang="en"')
        ->and($responseTh['body'])->toContain('<link rel="alternate" hreflang="x-default"')
        ->and($router->getLocaleContext()->getLocale())->toBe(Locale::Thai);

    // 2. English page via /en/
    $responseEn = $router->dispatch('GET', '/en/user-manual');
    expect($responseEn['status'])->toBe(200)
        ->and($responseEn['body'])->toContain('English Content')
        ->and($responseEn['body'])->toContain('<link rel="alternate" hreflang="en"')
        ->and($responseEn['body'])->toContain('<link rel="alternate" hreflang="th"')
        ->and($router->getLocaleContext()->getLocale())->toBe(Locale::English);
});

it('routes homepage by locale prefix and updates RequestLocaleContext', function (): void {
    $repo = new InMemoryPageRepository();
    $repo->create('หน้าแรกภาษาไทย', '<!-- wp:paragraph -->สวัสดีชาวโลก<!-- /wp:paragraph -->', PageStatus::Published, Locale::Thai);
    $repo->create('English Homepage', '<!-- wp:paragraph -->Hello World<!-- /wp:paragraph -->', PageStatus::Published, Locale::English);

    $router = createTestRouter($repo);

    // /en routes to English homepage
    $responseEn = $router->dispatch('GET', '/en');
    expect($responseEn['status'])->toBe(200)
        ->and($responseEn['body'])->toContain('Hello World')
        ->and($router->getLocaleContext()->getLocale())->toBe(Locale::English);

    // /th routes to Thai homepage
    $responseTh = $router->dispatch('GET', '/th');
    expect($responseTh['status'])->toBe(200)
        ->and($responseTh['body'])->toContain('สวัสดีชาวโลก')
        ->and($router->getLocaleContext()->getLocale())->toBe(Locale::Thai);
});

it('routes /search and /api/v1/search to SearchController', function (): void {
    $repo = new InMemoryPageRepository();
    $searchRepo = new InMemorySearchRepository();
    $now = new DateTimeImmutable();
    $searchRepo->addPage(new Page(
        id: new PostId(505),
        title: 'Redis Caching Deep Dive',
        content: 'Optimizing Redis tagged cache performance.',
        status: PageStatus::Published,
        locale: Locale::English,
        createdAt: $now,
        slug: 'redis-caching',
        excerpt: 'Redis guide',
    ));

    $router = createTestRouter($repo, $searchRepo);

    // 1. HTML search route
    $htmlResponse = $router->dispatch('GET', '/search', ['q' => 'Redis']);
    expect($htmlResponse['status'])->toBe(200)
        ->and($htmlResponse['headers']['Content-Type'])->toBe('text/html; charset=UTF-8')
        ->and($htmlResponse['body'])->toContain('Redis Caching Deep Dive')
        ->and($htmlResponse['body'])->toContain('<meta name="robots" content="noindex');

    // 2. Legacy WordPress ?s= search param
    $wpResponse = $router->dispatch('GET', '/', ['s' => 'Redis']);
    expect($wpResponse['status'])->toBe(200)
        ->and($wpResponse['body'])->toContain('Redis Caching Deep Dive');

    // 3. JSON API search route
    $apiResponse = $router->dispatch('GET', '/api/v1/search', ['q' => 'Redis']);
    expect($apiResponse['status'])->toBe(200)
        ->and($apiResponse['headers']['Content-Type'])->toBe('application/json; charset=UTF-8')
        ->and($apiResponse['body'])->toContain('"query":"Redis"')
        ->and($apiResponse['body'])->toContain('Redis Caching Deep Dive');
});

it('rate limits repeated search requests from the same IP', function (): void {
    $repo = new InMemoryPageRepository();
    $searchRepo = new InMemorySearchRepository();
    $rateLimitDir = sys_get_temp_dir() . '/router_search_rl_' . bin2hex(random_bytes(4));
    $rateLimiter = new RateLimiter($rateLimitDir);

    $router = createTestRouter($repo, $searchRepo, $rateLimiter);
    $server = ['REMOTE_ADDR' => '203.0.113.9'];

    for ($i = 0; $i < 30; $i++) {
        $response = $router->dispatch('GET', '/search', ['q' => 'anything'], $server);
        expect($response['status'])->not->toBe(429);
    }

    // 31st request from the same IP within the window is throttled.
    $throttled = $router->dispatch('GET', '/search', ['q' => 'anything'], $server);
    expect($throttled['status'])->toBe(429)
        ->and($throttled['headers'])->toHaveKey('Retry-After');

    // A different IP is unaffected by the first IP's usage.
    $otherIp = $router->dispatch('GET', '/search', ['q' => 'anything'], ['REMOTE_ADDR' => '198.51.100.4']);
    expect($otherIp['status'])->not->toBe(429);

    $files = glob($rateLimitDir . '/*.json') ?: [];
    foreach ($files as $file) {
        @unlink($file);
    }
    @rmdir($rateLimitDir);
});

