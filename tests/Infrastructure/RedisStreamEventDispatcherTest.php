<?php

declare(strict_types=1);

use App\Domain\Event\PageCreated;
use App\Domain\ValueObject\PostId;
use App\Infrastructure\Async\RedisStreamEventDispatcher;
use App\Infrastructure\Events\SynchronousEventDispatcher;

it('falls back to synchronous execution when redis is null or heartbeat is missing', function (): void {
    $fallback = new SynchronousEventDispatcher();
    $dispatched = false;

    $fallback->addListener(PageCreated::class, function () use (&$dispatched): void {
        $dispatched = true;
    });

    $dispatcher = new RedisStreamEventDispatcher(null, $fallback);
    expect($dispatcher->shouldFallback())->toBeTrue();

    $dispatcher->dispatch(new PageCreated(new PostId(42), new DateTimeImmutable()));
    expect($dispatched)->toBeTrue();
});
