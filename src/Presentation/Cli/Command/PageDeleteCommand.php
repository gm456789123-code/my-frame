<?php

declare(strict_types=1);

namespace App\Presentation\Cli\Command;

use App\Application\UseCase\Page\DeletePage;
use App\Application\UseCase\Page\DeletePageInput;
use App\Domain\ValueObject\PostId;
use App\Infrastructure\Events\SynchronousEventDispatcher;
use App\Infrastructure\Wordpress\WpdbConnection;
use App\Infrastructure\Wordpress\WpPageRepository;
use Override;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'page:delete', description: 'Soft-delete a page by id.')]
final class PageDeleteCommand extends Command
{
    #[Override]
    protected function configure(): void
    {
        $this
            ->addArgument('id', InputArgument::REQUIRED, 'Page id')
            ->addOption('deleted-by', null, InputOption::VALUE_REQUIRED, 'User id performing the delete', '1');
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var string $idArgument */
        $idArgument = $input->getArgument('id');
        /** @var string $deletedByArgument */
        $deletedByArgument = $input->getOption('deleted-by');

        $repository = new WpPageRepository(WpdbConnection::get());
        $useCase = new DeletePage($repository, new SynchronousEventDispatcher());

        $useCase->handle(new DeletePageInput(
            new PostId((int) $idArgument),
            new PostId((int) $deletedByArgument),
        ));

        $output->writeln("<info>Deleted page #{$idArgument}</info>");

        return Command::SUCCESS;
    }
}
