<?php

declare(strict_types=1);

namespace Tests\Doubles;

use App\Domain\Model\Page;
use App\Domain\Repository\PageRepositoryInterface;
use App\Domain\ValueObject\Locale;
use App\Domain\ValueObject\PageStatus;
use App\Domain\ValueObject\PostId;
use DateTimeImmutable;
use Override;

final class InMemoryPageRepository implements PageRepositoryInterface
{
    /** @var array<int, Page> */
    private array $pages = [];
    private int $nextId = 1;

    #[Override]
    public function create(string $title, string $content, PageStatus $status, Locale $locale): PostId
    {
        $id = new PostId($this->nextId++);
        $this->pages[$id->value] = new Page($id, $title, $content, $status, $locale, new DateTimeImmutable());

        return $id;
    }

    #[Override]
    public function find(PostId $id): ?Page
    {
        return $this->pages[$id->value] ?? null;
    }

    #[Override]
    public function softDelete(PostId $id, PostId $deletedBy): bool
    {
        if (!isset($this->pages[$id->value])) {
            return false;
        }

        unset($this->pages[$id->value]);

        return true;
    }
}
