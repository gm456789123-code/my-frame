<?php

declare(strict_types=1);

use App\Application\UseCase\Page\CreatePage;
use App\Application\UseCase\Page\CreatePageInput;
use App\Domain\Model\Page;
use App\Domain\Repository\PageRepositoryInterface;
use App\Domain\ValueObject\Locale;
use App\Domain\ValueObject\PageStatus;
use App\Domain\ValueObject\PostId;

final class InMemoryPageRepository implements PageRepositoryInterface
{
    /** @var array<int, Page> */
    private array $pages = [];
    private int $nextId = 1;

    public function create(string $title, string $content, PageStatus $status, Locale $locale): PostId
    {
        $id = new PostId($this->nextId++);
        $this->pages[$id->value] = new Page($id, $title, $content, $status, $locale, new DateTimeImmutable());

        return $id;
    }

    public function find(PostId $id): ?Page
    {
        return $this->pages[$id->value] ?? null;
    }

    public function softDelete(PostId $id, PostId $deletedBy): void
    {
        unset($this->pages[$id->value]);
    }
}

it('creates a page and makes it retrievable', function (): void {
    $repository = new InMemoryPageRepository();
    $useCase = new CreatePage($repository);

    $output = $useCase->handle(new CreatePageInput('Hello', '<p>World</p>', Locale::English));

    $page = $repository->find($output->id);

    expect($page)->not->toBeNull()
        ->and($page->title)->toBe('Hello')
        ->and($page->status)->toBe(PageStatus::Draft);
});
