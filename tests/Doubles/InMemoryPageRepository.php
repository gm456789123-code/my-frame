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
    /** @var array<string, int> */
    private array $slugs = [];
    private int $nextId = 1;

    #[Override]
    public function create(string $title, string $content, PageStatus $status, Locale $locale): PostId
    {
        $id = new PostId($this->nextId++);
        $slug = mb_strtolower(trim($title), 'UTF-8');
        $slug = preg_replace('/[^\p{L}\p{M}\p{N}]+/u', '-', $slug) ?? '';
        $slug = trim($slug, '-');
        $slug = $slug !== '' ? $slug : 'post-' . $id->value;
        $now = new DateTimeImmutable();

        $this->pages[$id->value] = new Page(
            id: $id,
            title: $title,
            content: $content,
            status: $status,
            locale: $locale,
            createdAt: $now,
            slug: $slug,
            excerpt: '',
            modifiedAt: $now,
        );
        $this->slugs[$slug] = $id->value;

        return $id;
    }

    #[Override]
    public function find(PostId $id): ?Page
    {
        return $this->pages[$id->value] ?? null;
    }

    #[Override]
    public function findForPreview(PostId $id): ?Page
    {
        return $this->pages[$id->value] ?? null;
    }

    #[Override]
    public function findBySlug(string $slug, ?Locale $locale = null): ?Page
    {
        $id = $this->slugs[$slug] ?? null;
        if ($id === null) {
            foreach ($this->pages as $page) {
                if ($page->slug === $slug && $page->status === PageStatus::Published && ($locale === null || $page->locale === $locale)) {
                    return $page;
                }
            }
            return null;
        }

        $page = $this->pages[$id] ?? null;
        if ($page === null || $page->status !== PageStatus::Published) {
            return null;
        }

        if ($locale !== null && $page->locale !== $locale) {
            foreach ($this->pages as $candidate) {
                if ($candidate->slug === $slug && $candidate->status === PageStatus::Published && $candidate->locale === $locale) {
                    return $candidate;
                }
            }
        }

        return $page;
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

    #[Override]
    public function findLatestPublishedId(?Locale $locale = null): ?PostId
    {
        $published = array_values(array_filter(
            $this->pages,
            static fn (Page $page): bool => $page->status === PageStatus::Published && ($locale === null || $page->locale === $locale),
        ));

        if ($published === []) {
            return $locale !== null ? $this->findLatestPublishedId(null) : null;
        }

        usort($published, static fn (Page $a, Page $b): int => $b->createdAt <=> $a->createdAt);

        return $published[0]->id;
    }

    private ?int $frontPageId = null;

    public function setFrontPageId(?int $id): void
    {
        $this->frontPageId = $id;
    }

    #[Override]
    public function findFrontPageId(?Locale $locale = null): ?PostId
    {
        if ($this->frontPageId !== null) {
            $page = $this->find(new PostId($this->frontPageId));
            if ($page !== null && $page->status === PageStatus::Published && ($locale === null || $page->locale === $locale)) {
                return $page->id;
            }
        }

        return $this->findLatestPublishedId($locale);
    }
}
