<?php

declare(strict_types=1);

use App\Domain\ValueObject\Locale;
use App\Domain\ValueObject\PageStatus;
use App\Domain\ValueObject\PostId;
use App\Infrastructure\Wordpress\WpPageRepository;
use App\Infrastructure\Wordpress\WpdbConnection;
use Tests\Infrastructure\WpPageRepositoryTestCase;

uses(WpPageRepositoryTestCase::class);

/**
 * These tests need a real MySQL with WordPress's schema installed and the
 * soft-delete/locale migration applied (see README: docker compose up -d,
 * wp core install, bin/console db:migrate). They skip themselves instead of
 * failing when that infrastructure isn't available, e.g. running `pest`
 * directly on a machine without Docker.
 */
function wpPageRepositoryTestConnectionOrNull(): ?PDO
{
    try {
        $pdo = WpdbConnection::get();
        $pdo->query('SELECT 1');

        return $pdo;
    } catch (PDOException) {
        return null;
    }
}

beforeEach(function (): void {
    $pdo = wpPageRepositoryTestConnectionOrNull();

    if ($pdo === null) {
        $this->markTestSkipped('No reachable MySQL (run via docker-compose to exercise WpPageRepository).');
    }

    $hasPostsTable = $pdo->query("SHOW TABLES LIKE 'wp_posts'")->fetch() !== false;
    if (!$hasPostsTable) {
        $this->markTestSkipped('wp_posts table does not exist (run bin/console db:migrate first).');
    }

    $hasSoftDeleteColumns = $pdo->query("SHOW COLUMNS FROM wp_posts LIKE 'deleted_at'")->fetch() !== false;

    if (!$hasSoftDeleteColumns) {
        $this->markTestSkipped('wp_posts is missing deleted_at/locale columns (run bin/console db:migrate first).');
    }

    $this->pdo = $pdo;
    $this->repository = new WpPageRepository($pdo);
});

afterEach(function (): void {
    foreach ($this->createdIds as $id) {
        $this->pdo->prepare('DELETE FROM wp_posts WHERE ID = :id')->execute(['id' => $id]);
    }
});

it('creates a published page and retrieves it back', function (): void {
    $id = $this->repository->create('Hello', '<p>World</p>', PageStatus::Published, Locale::Thai);
    $this->createdIds = [$id->value];

    $page = $this->repository->find($id);

    expect($page)->not->toBeNull()
        ->and($page->title)->toBe('Hello')
        ->and($page->content)->toBe('<p>World</p>')
        ->and($page->status)->toBe(PageStatus::Published)
        ->and($page->locale)->toBe(Locale::Thai);
});

it('does not return draft pages from find()', function (): void {
    $id = $this->repository->create('Secret Draft', '<p>not public</p>', PageStatus::Draft, Locale::English);
    $this->createdIds = [$id->value];

    expect($this->repository->find($id))->toBeNull();
});

it('soft-deletes a page instead of removing the row', function (): void {
    $id = $this->repository->create('Hello', '<p>World</p>', PageStatus::Draft, Locale::English);
    $this->createdIds = [$id->value];

    $wasDeleted = $this->repository->softDelete($id, new PostId(1));

    expect($wasDeleted)->toBeTrue()
        ->and($this->repository->find($id))->toBeNull();

    $statement = $this->pdo->prepare('SELECT deleted_at, deleted_by FROM wp_posts WHERE ID = :id');
    $statement->execute(['id' => $id->value]);
    $data = $statement->fetch(PDO::FETCH_ASSOC);

    expect($data['deleted_at'])->not->toBeNull()
        ->and((int) $data['deleted_by'])->toBe(1);
});

it('returns false when soft-deleting a page that does not exist', function (): void {
    expect($this->repository->softDelete(new PostId(999999999), new PostId(1)))->toBeFalse();
});

it('returns false when soft-deleting an already-deleted page', function (): void {
    $id = $this->repository->create('Hello', '<p>World</p>', PageStatus::Draft, Locale::English);
    $this->createdIds = [$id->value];

    $this->repository->softDelete($id, new PostId(1));
    $secondAttempt = $this->repository->softDelete($id, new PostId(1));

    expect($secondAttempt)->toBeFalse();
});

it('returns null for a page that does not exist', function (): void {
    expect($this->repository->find(new PostId(999999999)))->toBeNull();
});

it('finds the most recently published page for the homepage fallback', function (): void {
    $draft = $this->repository->create('Draft', '<p>not published</p>', PageStatus::Draft, Locale::English);
    $published = $this->repository->create('Published', '<p>latest</p>', PageStatus::Published, Locale::English);
    $this->createdIds = [$draft->value, $published->value];

    $latest = $this->repository->findLatestPublishedId();

    expect($latest)->not->toBeNull()
        ->and($latest->equals($published))->toBeTrue();
});

it('prefers Yoast SEO postmeta over the auto-generated title/description/image', function (): void {
    $hasPostmetaTable = $this->pdo->query("SHOW TABLES LIKE 'wp_postmeta'")->fetch() !== false;
    if (!$hasPostmetaTable) {
        $this->markTestSkipped('wp_postmeta does not exist (run a full WordPress core install first).');
    }

    $id = $this->repository->create(
        'Auto Title',
        '<p>Auto-generated description text that would otherwise be used.</p>',
        PageStatus::Published,
        Locale::English,
    );
    $this->createdIds = [$id->value];

    $insertMeta = $this->pdo->prepare(
        'INSERT INTO wp_postmeta (post_id, meta_key, meta_value) VALUES (:post_id, :meta_key, :meta_value)'
    );
    $insertMeta->execute(['post_id' => $id->value, 'meta_key' => '_yoast_wpseo_title', 'meta_value' => 'Yoast Title']);
    $insertMeta->execute(['post_id' => $id->value, 'meta_key' => '_yoast_wpseo_metadesc', 'meta_value' => 'Yoast description.']);
    $insertMeta->execute(['post_id' => $id->value, 'meta_key' => '_yoast_wpseo_opengraph-image', 'meta_value' => 'https://example.com/og.jpg']);

    $page = $this->repository->find($id);
    expect($page)->not->toBeNull();

    $seo = $page->buildSeoData('https://example.com');

    expect($seo->title)->toBe('Yoast Title')
        ->and($seo->description)->toBe('Yoast description.')
        ->and($seo->ogImage)->toBe('https://example.com/og.jpg')
        // canonical still comes from this app's own routing, not Yoast.
        ->and($seo->canonicalUrl)->toContain('https://example.com/');

    $this->pdo->prepare('DELETE FROM wp_postmeta WHERE post_id = :post_id')->execute(['post_id' => $id->value]);
});

it('falls back to auto-generated SEO data when no SEO plugin meta exists', function (): void {
    $id = $this->repository->create(
        'Plain Page',
        '<p>No SEO plugin installed, so this content becomes the description.</p>',
        PageStatus::Published,
        Locale::English,
    );
    $this->createdIds = [$id->value];

    $page = $this->repository->find($id);
    expect($page)->not->toBeNull();

    $seo = $page->buildSeoData('https://example.com');

    expect($seo->title)->toBe('Plain Page')
        ->and($seo->description)->toContain('No SEO plugin installed')
        ->and($seo->ogImage)->toBeNull();
});
