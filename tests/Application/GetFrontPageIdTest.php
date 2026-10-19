<?php

declare(strict_types=1);

use App\Application\UseCase\Page\GetFrontPageId;
use App\Domain\ValueObject\Locale;
use App\Domain\ValueObject\PageStatus;
use Tests\Doubles\InMemoryPageRepository;

it('returns the designated front page when set', function (): void {
    $repo = new InMemoryPageRepository();
    $page1 = $repo->create('Home Page', 'Welcome Home', PageStatus::Published, Locale::English);
    $page2 = $repo->create('Blog Post 1', 'Latest post', PageStatus::Published, Locale::English);

    $repo->setFrontPageId($page1->value);

    $useCase = new GetFrontPageId($repo);
    $output = $useCase->handle();

    expect($output->id)->not->toBeNull()
        ->and($output->id?->value)->toBe($page1->value);
});

it('falls back to the latest published page when no front page is designated', function (): void {
    $repo = new InMemoryPageRepository();
    $page1 = $repo->create('First Post', 'First', PageStatus::Published, Locale::English);
    $page2 = $repo->create('Second Post', 'Second', PageStatus::Published, Locale::English);

    $useCase = new GetFrontPageId($repo);
    $output = $useCase->handle();

    expect($output->id)->not->toBeNull()
        ->and($output->id?->value)->toBe($page2->value);
});

it('falls back to latest published page if designated front page was deleted or unpublished', function (): void {
    $repo = new InMemoryPageRepository();
    $page1 = $repo->create('Old Home', 'Home', PageStatus::Draft, Locale::English);
    $page2 = $repo->create('Active Post', 'Post', PageStatus::Published, Locale::English);

    $repo->setFrontPageId($page1->value);

    $useCase = new GetFrontPageId($repo);
    $output = $useCase->handle();

    expect($output->id)->not->toBeNull()
        ->and($output->id?->value)->toBe($page2->value);
});
