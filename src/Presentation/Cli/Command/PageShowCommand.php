<?php

declare(strict_types=1);

namespace App\Presentation\Cli\Command;

use App\Application\UseCase\Page\GetPage;
use App\Application\UseCase\Page\GetPageInput;
use App\Domain\ValueObject\PostId;
use App\Infrastructure\Wordpress\WpdbConnection;
use App\Infrastructure\Wordpress\WpPageRepository;
use Override;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'page:show', description: 'Show a page by id.')]
final class PageShowCommand extends Command
{
    #[Override]
    protected function configure(): void
    {
        $this->addArgument('id', InputArgument::REQUIRED, 'Page id');
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var string $idArgument */
        $idArgument = $input->getArgument('id');

        $repository = new WpPageRepository(WpdbConnection::get());
        $useCase = new GetPage($repository);

        $result = $useCase->handle(new GetPageInput(new PostId((int) $idArgument)));

        if ($result->page === null) {
            $output->writeln('<comment>Not found.</comment>');

            return Command::FAILURE;
        }

        $output->writeln("<info>#{$result->page->id->toString()}</info> {$result->page->title}");
        $output->writeln("Status: {$result->page->status->value}  Locale: {$result->page->locale->value}");
        $output->writeln($result->page->content);

        return Command::SUCCESS;
    }
}
