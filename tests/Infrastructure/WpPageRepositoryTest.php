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

it('creates a page and retrieves it back', function (): void {
    $id = $this->repository->create('Hello', '<p>World</p>', PageStatus::Draft, Locale::Thai);
    $this->createdIds = [$id->value];

    $page = $this->repository->find($id);

    expect($page)->not->toBeNull()
        ->and($page->title)->toBe('Hello')
        ->and($page->content)->toBe('<p>World</p>')
        ->and($page->status)->toBe(PageStatus::Draft)
        ->and($page->locale)->toBe(Locale::Thai);
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
