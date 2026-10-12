<?php

declare(strict_types=1);

use App\Application\UseCase\Page\CreatePage;
use App\Application\UseCase\Page\CreatePageInput;
use App\Application\UseCase\Page\GetPage;
use App\Application\UseCase\Page\GetPageInput;
use App\Domain\ValueObject\Locale;
use App\Domain\ValueObject\PostId;
use Tests\Doubles\InMemoryPageRepository;
use Tests\Doubles\RecordingEventDispatcher;

it('retrieves a previously created page', function (): void {
    $repository = new InMemoryPageRepository();
    $events = new RecordingEventDispatcher();

    $created = (new CreatePage($repository, $events))
        ->handle(new CreatePageInput('Hello', '<p>World</p>', Locale::English));

    $output = (new GetPage($repository))->handle(new GetPageInput($created->id));

    expect($output->page)->not->toBeNull()
        ->and($output->page->id->equals($created->id))->toBeTrue()
        ->and($output->page->title)->toBe('Hello');
});

it('returns null for a page that does not exist', function (): void {
    $repository = new InMemoryPageRepository();

    $output = (new GetPage($repository))->handle(new GetPageInput(new PostId(999)));

    expect($output->page)->toBeNull();
});
