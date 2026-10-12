<?php

declare(strict_types=1);

namespace App\Domain\Repository;

use App\Domain\Model\Page;
use App\Domain\ValueObject\Locale;
use App\Domain\ValueObject\PageStatus;
use App\Domain\ValueObject\PostId;

interface PageRepositoryInterface
{
    public function create(string $title, string $content, PageStatus $status, Locale $locale): PostId;

    public function find(PostId $id): ?Page;

    public function softDelete(PostId $id, PostId $deletedBy): void;
}
