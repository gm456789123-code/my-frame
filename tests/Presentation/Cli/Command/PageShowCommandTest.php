<?php

declare(strict_types=1);

use App\Domain\ValueObject\Locale;
use App\Domain\ValueObject\PageStatus;
use App\Presentation\Cli\Command\PageShowCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\Doubles\InMemoryPageRepository;

it('shows an existing page', function (): void {
    $repository = new InMemoryPageRepository();
    $id = $repository->create('Hello', '<p>World</p>', PageStatus::Draft, Locale::English);

    $tester = new CommandTester(new PageShowCommand($repository));
    $exitCode = $tester->execute(['id' => $id->toString()]);

    expect($exitCode)->toBe(Command::SUCCESS)
        ->and($tester->getDisplay())->toContain('Hello')
        ->and($tester->getDisplay())->toContain('<p>World</p>');
});

it('reports not found for a page that does not exist', function (): void {
    $repository = new InMemoryPageRepository();

    $tester = new CommandTester(new PageShowCommand($repository));
    $exitCode = $tester->execute(['id' => '999']);

    expect($exitCode)->toBe(Command::FAILURE)
        ->and($tester->getDisplay())->toContain('Not found');
});

it('rejects a non-numeric id with a friendly error instead of a raw PostId exception', function (): void {
    $repository = new InMemoryPageRepository();

    $tester = new CommandTester(new PageShowCommand($repository));
    $tester->execute(['id' => 'abc']);
})->throws(InvalidArgumentException::class, "Invalid id 'abc', expected a positive integer.");
