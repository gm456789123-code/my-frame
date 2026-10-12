<?php

declare(strict_types=1);

namespace App\Presentation\Cli;

use App\Domain\Event\DomainEventDispatcherInterface;
use App\Domain\Repository\PageRepositoryInterface;
use App\Infrastructure\Events\SynchronousEventDispatcher;

/**
 * Composition root for CLI commands: wires concrete Infrastructure adapters
 * for Application use cases to depend on through their interfaces.
 *
 * pageRepository() returns a LazyPageRepository rather than connecting
 * immediately: bin/console constructs every page:* command up front so
 * Symfony can list/route them, and an eager connection there would make
 * every invocation (including `make:page`, which touches no database at
 * all) require a reachable database.
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
        return new LazyPageRepository();
    }
}
