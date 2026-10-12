<?php

declare(strict_types=1);

namespace App\Application\UseCase\Page;

use App\Domain\Repository\PageRepositoryInterface;
use App\Domain\ValueObject\PageStatus;

final readonly class CreatePage
{
    public function __construct(
        private PageRepositoryInterface $pages,
    ) {
    }

    public function handle(CreatePageInput $input): CreatePageOutput
    {
        $id = $this->pages->create(
            $input->title,
            $input->content,
            PageStatus::Draft,
            $input->locale,
        );

        return new CreatePageOutput($id);
    }
}
