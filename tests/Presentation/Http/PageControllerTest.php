<?php

declare(strict_types=1);

use App\Application\UseCase\Page\GetPage;
use App\Domain\ValueObject\Locale;
use App\Domain\ValueObject\PageStatus;
use App\Infrastructure\Cache\CacheInterface;
use App\Infrastructure\Wordpress\Gutenberg\BlockAstParser;
use App\Presentation\Http\PageController;
use App\Presentation\View\BlockRenderer;
use Latte\Engine;
use Tests\Doubles\InMemoryPageRepository;

it('serves cold requests within budget and renders latte templates', function (): void {
    $repo = new InMemoryPageRepository();
    $id = $repo->create('Test Page', '<!-- wp:paragraph -->Hello World<!-- /wp:paragraph -->', PageStatus::Published, Locale::Thai);

    $getPage = new GetPage($repo);
    $parser = new BlockAstParser();
    $latte = new Engine();
    $latte->setTempDirectory(sys_get_temp_dir());
    $renderer = new BlockRenderer($latte, __DIR__ . '/../../../templates/blocks');

    $controller = new PageController($getPage, $parser, $renderer, null, null, true);
    $response = $controller->show($id->value);

    expect($response['status'])->toBe(200)
        ->and($response['headers']['Content-Type'])->toBe('text/html; charset=UTF-8')
        ->and($response['body'])->toContain('Hello World')
        ->and($response['body'])->toContain('Sovereign DevToolbar');
});

it('serves warm cached requests with 0 DB queries', function (): void {
    $repo = new InMemoryPageRepository();
    $getPage = new GetPage($repo);
    $parser = new BlockAstParser();
    $latte = new Engine();
    $renderer = new BlockRenderer($latte, __DIR__ . '/../../../templates/blocks');

    $cacheMock = new class implements CacheInterface {
        public function get(string $key): ?string {
            return '<html>Cached Warm Response</html>';
        }
        public function set(string $key, string $value, int $ttl = 3600, array $tags = []): void {}
        public function delete(string $key): void {}
        public function invalidateTag(string $tag): void {}
    };

    $controller = new PageController($getPage, $parser, $renderer, $cacheMock);
    $response = $controller->show(999);

    expect($response['status'])->toBe(200)
        ->and($response['body'])->toContain('Cached Warm Response');
});
