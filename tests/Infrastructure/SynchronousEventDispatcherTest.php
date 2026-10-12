<?php

declare(strict_types=1);

use App\Infrastructure\Events\SynchronousEventDispatcher;

it('invokes listeners registered for the dispatched event class', function (): void {
    $dispatcher = new SynchronousEventDispatcher();
    $received = [];

    $dispatcher->addListener(stdClass::class, function (object $event) use (&$received): void {
        $received[] = $event;
    });

    $event = new stdClass();
    $dispatcher->dispatch($event);

    expect($received)->toBe([$event]);
});

it('does not invoke listeners registered for a different event class', function (): void {
    $dispatcher = new SynchronousEventDispatcher();
    $invoked = false;

    $dispatcher->addListener('SomeOtherEvent', function () use (&$invoked): void {
        $invoked = true;
    });

    $dispatcher->dispatch(new stdClass());

    expect($invoked)->toBeFalse();
});

it('invokes multiple listeners in registration order', function (): void {
    $dispatcher = new SynchronousEventDispatcher();
    $order = [];

    $dispatcher->addListener(stdClass::class, function () use (&$order): void {
        $order[] = 'first';
    });
    $dispatcher->addListener(stdClass::class, function () use (&$order): void {
        $order[] = 'second';
    });

    $dispatcher->dispatch(new stdClass());

    expect($order)->toBe(['first', 'second']);
});
