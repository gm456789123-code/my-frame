<?php

declare(strict_types=1);

use App\Application\UseCase\Page\CreatePage;
use App\Application\UseCase\Page\CreatePageInput;
use App\Domain\Event\PageCreated;
use App\Domain\ValueObject\Locale;
use App\Domain\ValueObject\PageStatus;
use Tests\Doubles\InMemoryPageRepository;
use Tests\Doubles\RecordingEventDispatcher;

it('creates a page and makes it retrievable', function (): void {
    $repository = new InMemoryPageRepository();
    $events = new RecordingEventDispatcher();
    $useCase = new CreatePage($repository, $events);

    $output = $useCase->handle(new CreatePageInput('Hello', '<p>World</p>', Locale::English));

    $page = $repository->find($output->id);

    expect($page)->not->toBeNull()
        ->and($page->title)->toBe('Hello')
        ->and($page->status)->toBe(PageStatus::Draft);
});

it('dispatches a PageCreated event with the new page id', function (): void {
    $repository = new InMemoryPageRepository();
    $events = new RecordingEventDispatcher();
    $useCase = new CreatePage($repository, $events);

    $output = $useCase->handle(new CreatePageInput('Hello', '<p>World</p>', Locale::English));

    expect($events->dispatched)->toHaveCount(1);

    $event = $events->dispatched[0];
    expect($event)->toBeInstanceOf(PageCreated::class)
        ->and($event->pageId->equals($output->id))->toBeTrue();
});
