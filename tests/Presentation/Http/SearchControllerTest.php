<?php

declare(strict_types=1);

use App\Application\UseCase\Page\SearchPages;
use App\Domain\Model\Page;
use App\Domain\ValueObject\Locale;
use App\Domain\ValueObject\PageStatus;
use App\Domain\ValueObject\PostId;
use App\Presentation\Http\SearchController;
use Latte\Engine;
use Tests\Doubles\InMemorySearchRepository;

function createTestSearchController(InMemorySearchRepository $repo): SearchController
{
    $searchPages = new SearchPages($repo);
    $latte = new Engine();
    $latte->setTempDirectory(sys_get_temp_dir());

    return new SearchController(
        searchPages: $searchPages,
        latte: $latte,
        templatePath: __DIR__ . '/../../../templates/search.latte',
        siteName: 'Test Sovereign',
        siteUrl: 'https://example.com',
    );
}

it('returns search results as JSON when isJson is true', function (): void {
    $repo = new InMemorySearchRepository();
    $now = new DateTimeImmutable();
    $repo->addPage(new Page(
        id: new PostId(101),
        title: 'Microservices with Sovereign',
        content: 'Building scalable microservices on PHP.',
        status: PageStatus::Published,
        locale: Locale::English,
        createdAt: $now,
        slug: 'microservices-with-sovereign',
        excerpt: 'Scalable architecture guide',
    ));

    $controller = createTestSearchController($repo);
    $response = $controller->search('Microservices', isJson: true);

    expect($response['status'])->toBe(200)
        ->and($response['headers']['Content-Type'])->toBe('application/json; charset=UTF-8');

    /** @var array{query: string, count: int, results: list<array{id: int, title: string}>} $data */
    $data = json_decode($response['body'], true);
    expect($data['query'])->toBe('Microservices')
        ->and($data['count'])->toBe(1)
        ->and($data['results'][0]['title'])->toBe('Microservices with Sovereign');
});

it('renders HTML search page with noindex robots tag and query results', function (): void {
    $repo = new InMemorySearchRepository();
    $now = new DateTimeImmutable();
    $repo->addPage(new Page(
        id: new PostId(202),
        title: 'ระบบค้นหาประสิทธิภาพสูง',
        content: 'ค้นหาเนื้อหาบทความด้วย MySQL FullText Search ได้อย่างรวดเร็ว',
        status: PageStatus::Published,
        locale: Locale::Thai,
        createdAt: $now,
        slug: 'ระบบค้นหา',
        excerpt: 'แนะนำระบบค้นหา',
    ));

    $controller = createTestSearchController($repo);
    $response = $controller->search('ระบบค้นหา', isJson: false);

    expect($response['status'])->toBe(200)
        ->and($response['headers']['Content-Type'])->toBe('text/html; charset=UTF-8')
        ->and($response['headers']['X-Content-Type-Options'])->toBe('nosniff')
        ->and($response['headers']['X-Frame-Options'])->toBe('SAMEORIGIN')
        ->and($response['headers'])->toHaveKey('Content-Security-Policy')
        ->and($response['body'])->toContain('<meta name="robots" content="noindex')
        ->and($response['body'])->toContain('ระบบค้นหาประสิทธิภาพสูง')
        ->and($response['body'])->toContain('Found 1 result');
});

it('gracefully renders empty state when no pages match query', function (): void {
    $repo = new InMemorySearchRepository();
    $controller = createTestSearchController($repo);

    $response = $controller->search('NonExistentTerm', isJson: false);

    expect($response['status'])->toBe(200)
        ->and($response['body'])->toContain('No matching pages found.')
        ->and($response['body'])->toContain('Found 0 results for');
});
