<?php

declare(strict_types=1);

use App\Application\UseCase\Page\CreatePage;
use App\Application\UseCase\Page\CreatePageInput;
use App\Application\UseCase\Page\DeletePage;
use App\Application\UseCase\Page\DeletePageInput;
use App\Application\UseCase\Page\GetPage;
use App\Application\UseCase\Page\GetPageInput;
use App\Domain\Event\PageDeleted;
use App\Domain\ValueObject\Locale;
use App\Domain\ValueObject\PostId;
use Tests\Doubles\InMemoryPageRepository;
use Tests\Doubles\RecordingEventDispatcher;

it('soft-deletes a page so it is no longer retrievable', function (): void {
    $repository = new InMemoryPageRepository();
    $events = new RecordingEventDispatcher();

    $created = (new CreatePage($repository, $events))
        ->handle(new CreatePageInput('Hello', '<p>World</p>', Locale::English));

    $result = (new DeletePage($repository, $events))
        ->handle(new DeletePageInput($created->id, new PostId(1)));

    $output = (new GetPage($repository))->handle(new GetPageInput($created->id));

    expect($result->wasDeleted)->toBeTrue()
        ->and($output->page)->toBeNull();
});

it('dispatches a PageDeleted event recording who deleted it', function (): void {
    $repository = new InMemoryPageRepository();
    $events = new RecordingEventDispatcher();
    $deletedBy = new PostId(42);

    $created = (new CreatePage($repository, $events))
        ->handle(new CreatePageInput('Hello', '<p>World</p>', Locale::English));

    (new DeletePage($repository, $events))
        ->handle(new DeletePageInput($created->id, $deletedBy));

    $deleteEvent = $events->dispatched[1];

    expect($deleteEvent)->toBeInstanceOf(PageDeleted::class)
        ->and($deleteEvent->pageId->equals($created->id))->toBeTrue()
        ->and($deleteEvent->deletedBy->equals($deletedBy))->toBeTrue();
});

it('does not dispatch PageDeleted and reports failure for a page that does not exist', function (): void {
    $repository = new InMemoryPageRepository();
    $events = new RecordingEventDispatcher();

    $result = (new DeletePage($repository, $events))
        ->handle(new DeletePageInput(new PostId(999), new PostId(1)));

    expect($result->wasDeleted)->toBeFalse()
        ->and($events->dispatched)->toBe([]);
});

it('does not dispatch PageDeleted a second time for an already-deleted page', function (): void {
    $repository = new InMemoryPageRepository();
    $events = new RecordingEventDispatcher();

    $created = (new CreatePage($repository, $events))
        ->handle(new CreatePageInput('Hello', '<p>World</p>', Locale::English));

    $useCase = new DeletePage($repository, $events);
    $useCase->handle(new DeletePageInput($created->id, new PostId(1)));
    $secondAttempt = $useCase->handle(new DeletePageInput($created->id, new PostId(1)));

    $deleteEvents = array_filter($events->dispatched, fn (object $event): bool => $event instanceof PageDeleted);

    expect($secondAttempt->wasDeleted)->toBeFalse()
        ->and($deleteEvents)->toHaveCount(1);
});
