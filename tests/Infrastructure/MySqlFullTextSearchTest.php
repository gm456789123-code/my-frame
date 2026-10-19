<?php

declare(strict_types=1);

use App\Domain\ValueObject\Locale;
use App\Domain\ValueObject\PageStatus;
use App\Infrastructure\Search\MySqlFullTextSearch;
use App\Infrastructure\Wordpress\WpPageRepository;
use App\Infrastructure\Wordpress\WpdbConnection;

/**
 * These tests need a real MySQL with WordPress's schema installed, the
 * soft-delete/locale migration applied, and a FULLTEXT index on
 * (post_title, post_content). They skip themselves instead of failing when
 * that infrastructure isn't available (see README: docker compose up -d,
 * wp core install, bin/console db:migrate).
 */
beforeEach(function (): void {
    try {
        $pdo = WpdbConnection::get();
        $pdo->query('SELECT 1');
    } catch (PDOException) {
        $this->markTestSkipped('No reachable MySQL (run via docker-compose to exercise MySqlFullTextSearch).');
    }

    $hasPostsTable = $pdo->query("SHOW TABLES LIKE 'wp_posts'")->fetch() !== false;
    if (!$hasPostsTable) {
        $this->markTestSkipped('wp_posts table does not exist (run bin/console db:migrate first).');
    }

    $hasFulltextIndex = $pdo->query("SHOW INDEX FROM wp_posts WHERE Index_type = 'FULLTEXT'")->fetch() !== false;
    if (!$hasFulltextIndex) {
        $this->markTestSkipped('wp_posts has no FULLTEXT index on (post_title, post_content).');
    }

    $this->pdo = $pdo;
    $this->repository = new WpPageRepository($pdo);
    $this->search = new MySqlFullTextSearch($pdo);
    $this->createdIds = [];
});

afterEach(function (): void {
    foreach ($this->createdIds ?? [] as $id) {
        $this->pdo->prepare('DELETE FROM wp_posts WHERE ID = :id')->execute(['id' => $id]);
    }
});

it('never returns a draft post to an unauthenticated search', function (): void {
    $marker = 'Zzyzx' . bin2hex(random_bytes(4));
    $draftId = $this->repository->create("Secret {$marker}", "<p>Confidential {$marker} content</p>", PageStatus::Draft, Locale::English);
    $this->createdIds[] = $draftId->value;

    $results = $this->search->search($marker);

    $foundIds = array_map(static fn ($page) => $page->id->value, $results);
    expect($foundIds)->not->toContain($draftId->value);
});

it('returns a published post matching the search term', function (): void {
    $marker = 'Zzyzx' . bin2hex(random_bytes(4));
    $publishedId = $this->repository->create("Public {$marker}", "<p>Visible {$marker} content</p>", PageStatus::Published, Locale::English);
    $this->createdIds[] = $publishedId->value;

    $results = $this->search->search($marker);

    $foundIds = array_map(static fn ($page) => $page->id->value, $results);
    expect($foundIds)->toContain($publishedId->value);
});
