<?php

declare(strict_types=1);

namespace Tests\Doubles;

use App\Domain\Event\DomainEventDispatcherInterface;
use Override;

final class RecordingEventDispatcher implements DomainEventDispatcherInterface
{
    /** @var list<object> */
    public array $dispatched = [];

    #[Override]
    public function dispatch(object $event): void
    {
        $this->dispatched[] = $event;
    }
}
