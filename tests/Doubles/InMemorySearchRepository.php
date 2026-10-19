<?php

declare(strict_types=1);

namespace Tests\Doubles;

use App\Domain\Model\Page;
use App\Domain\Repository\SearchRepositoryInterface;
use App\Domain\ValueObject\Locale;
use App\Domain\ValueObject\PageStatus;
use Override;

final class InMemorySearchRepository implements SearchRepositoryInterface
{
    /** @var list<Page> */
    private array $pages = [];

    public function addPage(Page $page): void
    {
        $this->pages[] = $page;
    }

    #[Override]
    public function search(string $term, ?Locale $locale = null, int $limit = 20): array
    {
        $cleanTerm = mb_strtolower(trim($term), 'UTF-8');
        if ($cleanTerm === '') {
            return [];
        }

        $results = [];
        foreach ($this->pages as $page) {
            if ($page->status !== PageStatus::Published) {
                continue;
            }

            if ($locale !== null && $page->locale !== $locale) {
                continue;
            }

            $haystack = mb_strtolower($page->title . ' ' . $page->content . ' ' . $page->excerpt, 'UTF-8');
            if (str_contains($haystack, $cleanTerm)) {
                $results[] = $page;
            }

            if (count($results) >= $limit) {
                break;
            }
        }

        return $results;
    }
}
