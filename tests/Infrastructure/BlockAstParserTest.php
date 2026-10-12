<?php

declare(strict_types=1);

use App\Infrastructure\Wordpress\Gutenberg\BlockAstParser;
use App\Presentation\View\BlockRenderer;
use Latte\Engine;

it('parses raw gutenberg block content into typed BlockDto array', function (): void {
    $parser = new BlockAstParser('https://example.com');

    $rawContent = <<<'HTML'
<!-- wp:heading {"level":2} -->
<h2>Welcome to Sovereign Engine</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>High-performance headless architecture on top of WordPress.</p>
<!-- /wp:paragraph -->
HTML;

    $blocks = $parser->parse($rawContent);

    expect($blocks)->toHaveCount(2)
        ->and($blocks[0]->blockName)->toBe('core/heading')
        ->and($blocks[0]->attrs['level'])->toBe(2)
        ->and($blocks[0]->innerHTML)->toBe('<h2>Welcome to Sovereign Engine</h2>')
        ->and($blocks[1]->blockName)->toBe('core/paragraph')
        ->and($blocks[1]->innerHTML)->toBe('<p>High-performance headless architecture on top of WordPress.</p>');
});

it('rewrites legacy media paths to storage with APP_URL origin', function (): void {
    $parser = new BlockAstParser('https://sovereign.local');

    $rawContent = '<p><img src="https://old-domain.com/wp-content/uploads/2026/01/logo.png" /></p>';
    $blocks = $parser->parse($rawContent);

    expect($blocks[0]->innerHTML)->toContain('src="https://sovereign.local/storage/2026/01/logo.png"');
});

it('resolves synced reusable blocks in a single batch query without N+1', function (): void {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE wp_posts (
        ID INTEGER PRIMARY KEY,
        post_content TEXT,
        deleted_at DATETIME
    )');

    $pdo->exec("INSERT INTO wp_posts (ID, post_content, deleted_at) VALUES
        (10, '<!-- wp:paragraph --><p>Synced Callout A</p><!-- /wp:paragraph -->', NULL),
        (20, '<!-- wp:paragraph --><p>Synced Callout B</p><!-- /wp:paragraph -->', NULL)
    ");

    $parser = new BlockAstParser();
    $rawContent = <<<'HTML'
<!-- wp:heading {"level":1} --><h1>Main Title</h1><!-- /wp:heading -->
<!-- wp:block {"ref":10} /-->
<!-- wp:block {"ref":20} /-->
HTML;

    $blocks = $parser->parse($rawContent);
    expect($blocks)->toHaveCount(3);

    // Eager batch resolve
    $resolved = $parser->resolveSyncedPatterns($blocks, $pdo);

    expect($resolved)->toHaveCount(3)
        ->and($resolved[0]->blockName)->toBe('core/heading')
        ->and($resolved[1]->blockName)->toBe('core/paragraph')
        ->and($resolved[1]->innerHTML)->toBe('<p>Synced Callout A</p>')
        ->and($resolved[2]->blockName)->toBe('core/paragraph')
        ->and($resolved[2]->innerHTML)->toBe('<p>Synced Callout B</p>');
});

it('renders parsed blocks using Latte engine with tailwind classes', function (): void {
    $parser = new BlockAstParser();
    $latte = new Engine();
    $latte->setTempDirectory(sys_get_temp_dir());

    $renderer = new BlockRenderer($latte, __DIR__ . '/../../templates/blocks');

    $rawContent = '<!-- wp:heading {"level":2} -->Section Title<!-- /wp:heading --><!-- wp:paragraph -->Some text<!-- /wp:paragraph -->';
    $blocks = $parser->parse($rawContent);

    $html = $renderer->renderAll($blocks);

    expect($html)->toContain('<h2 class="text-2xl font-bold tracking-tight text-slate-900 my-5">')
        ->and($html)->toContain('Section Title')
        ->and($html)->toContain('<p class="my-4 text-base leading-relaxed text-slate-800">')
        ->and($html)->toContain('Some text');
});
