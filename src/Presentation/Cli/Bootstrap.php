<?php

declare(strict_types=1);

namespace App\Presentation\Cli;

use App\Domain\Event\DomainEventDispatcherInterface;
use App\Domain\Repository\PageRepositoryInterface;
use App\Infrastructure\Events\SynchronousEventDispatcher;
use App\Infrastructure\Wordpress\WpdbConnection;
use App\Infrastructure\Wordpress\WpPageRepository;

/**
 * Composition root for CLI commands: wires concrete Infrastructure adapters
 * for Application use cases to depend on through their interfaces.
 */
final class Bootstrap
{
    private static ?DomainEventDispatcherInterface $eventDispatcher = null;

    public static function eventDispatcher(): DomainEventDispatcherInterface
    {
        return self::$eventDispatcher ??= new SynchronousEventDispatcher();
    }

    public static function pageRepository(): PageRepositoryInterface
    {
        return new WpPageRepository(WpdbConnection::get());
    }
}
