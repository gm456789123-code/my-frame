<?php

declare(strict_types=1);

use App\Domain\ValueObject\Locale;
use App\Domain\ValueObject\PageStatus;
use App\Presentation\Cli\Command\PageDeleteCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\Doubles\InMemoryPageRepository;
use Tests\Doubles\RecordingEventDispatcher;

it('deletes an existing page', function (): void {
    $repository = new InMemoryPageRepository();
    $id = $repository->create('Hello', '<p>World</p>', PageStatus::Draft, Locale::English);

    $tester = new CommandTester(new PageDeleteCommand($repository, new RecordingEventDispatcher()));
    $exitCode = $tester->execute(['id' => $id->toString()]);

    expect($exitCode)->toBe(Command::SUCCESS)
        ->and($tester->getDisplay())->toContain("Deleted page #{$id->toString()}")
        ->and($repository->find($id))->toBeNull();
});

it('reports failure instead of false success for a page that does not exist', function (): void {
    $repository = new InMemoryPageRepository();
    $events = new RecordingEventDispatcher();

    $tester = new CommandTester(new PageDeleteCommand($repository, $events));
    $exitCode = $tester->execute(['id' => '999']);

    expect($exitCode)->toBe(Command::FAILURE)
        ->and($tester->getDisplay())->toContain('not found')
        ->and($events->dispatched)->toBe([]);
});

it('rejects a non-numeric deleted-by option with a friendly error', function (): void {
    $repository = new InMemoryPageRepository();
    $id = $repository->create('Hello', '<p>World</p>', PageStatus::Draft, Locale::English);

    $tester = new CommandTester(new PageDeleteCommand($repository, new RecordingEventDispatcher()));
    $tester->execute(['id' => $id->toString(), '--deleted-by' => 'nobody']);
})->throws(InvalidArgumentException::class, "Invalid deleted-by 'nobody', expected a positive integer.");
