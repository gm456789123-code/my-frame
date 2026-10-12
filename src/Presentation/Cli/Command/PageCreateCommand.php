<?php

declare(strict_types=1);

namespace App\Presentation\Cli\Command;

use App\Application\UseCase\Page\CreatePage;
use App\Application\UseCase\Page\CreatePageInput;
use App\Domain\ValueObject\Locale;
use App\Infrastructure\Events\SynchronousEventDispatcher;
use App\Infrastructure\Wordpress\WpdbConnection;
use App\Infrastructure\Wordpress\WpPageRepository;
use InvalidArgumentException;
use Override;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use ValueError;

#[AsCommand(name: 'page:create', description: 'Create a draft page.')]
final class PageCreateCommand extends Command
{
    #[Override]
    protected function configure(): void
    {
        $this
            ->addArgument('title', InputArgument::REQUIRED, 'Page title')
            ->addArgument('content', InputArgument::REQUIRED, 'Page HTML content')
            ->addOption('locale', null, InputOption::VALUE_REQUIRED, 'Locale (th or en)', 'en');
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var string $title */
        $title = $input->getArgument('title');
        /** @var string $content */
        $content = $input->getArgument('content');
        /** @var string $localeValue */
        $localeValue = $input->getOption('locale');

        try {
            $locale = Locale::from($localeValue);
        } catch (ValueError) {
            throw new InvalidArgumentException("Invalid locale '{$localeValue}', expected 'th' or 'en'.");
        }

        $repository = new WpPageRepository(WpdbConnection::get());
        $useCase = new CreatePage($repository, new SynchronousEventDispatcher());

        $result = $useCase->handle(new CreatePageInput($title, $content, $locale));

        $output->writeln("<info>Created page #{$result->id->toString()}</info>");

        return Command::SUCCESS;
    }
}
