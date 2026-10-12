<?php

declare(strict_types=1);

use App\Presentation\Cli\Command\PageCreateCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\Doubles\InMemoryPageRepository;
use Tests\Doubles\RecordingEventDispatcher;

it('creates a page and reports its id', function (): void {
    $repository = new InMemoryPageRepository();
    $tester = new CommandTester(new PageCreateCommand($repository, new RecordingEventDispatcher()));

    $exitCode = $tester->execute([
        'title' => 'Hello',
        'content' => '<p>World</p>',
    ]);

    expect($exitCode)->toBe(Command::SUCCESS)
        ->and($tester->getDisplay())->toContain('Created page #1');
});

it('rejects an invalid locale with a friendly error instead of a raw exception', function (): void {
    $repository = new InMemoryPageRepository();
    $tester = new CommandTester(new PageCreateCommand($repository, new RecordingEventDispatcher()));

    $tester->execute([
        'title' => 'Hello',
        'content' => '<p>World</p>',
        '--locale' => 'fr',
    ]);
})->throws(InvalidArgumentException::class, "Invalid locale 'fr', expected 'th' or 'en'.");
