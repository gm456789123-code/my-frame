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

    (new DeletePage($repository, $events))
        ->handle(new DeletePageInput($created->id, new PostId(1)));

    $output = (new GetPage($repository))->handle(new GetPageInput($created->id));

    expect($output->page)->toBeNull();
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
