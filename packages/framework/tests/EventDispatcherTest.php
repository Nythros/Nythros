<?php

declare(strict_types=1);

namespace Nythros\Framework\Tests;

use Nythros\Framework\Event\EventDispatcher;
use PHPUnit\Framework\TestCase;

/**
 * EventDispatcherTest - 覆盖同步即时派发、多监听器、无监听器派发与监听器移除。
 * Tests covering synchronous immediate dispatch, multiple listeners, dispatch without listeners and listener removal.
 */
final class EventDispatcherTest extends TestCase
{
    public function testDispatchInvokesListenersWithPayload(): void
    {
        $dispatcher = new EventDispatcher();
        $received = [];

        $dispatcher->listen('player.killed', static function (array $payload) use (&$received): void {
            $received[] = $payload;
        });
        $dispatcher->listen('player.killed', static function (array $payload) use (&$received): void {
            $received[] = $payload;
        });

        $dispatcher->dispatch('player.killed', ['id' => 'player-1']);

        self::assertSame(
            [['id' => 'player-1'], ['id' => 'player-1']],
            $received,
            '同一事件的多个监听器必须全部按注册顺序调用。All listeners of an event must be invoked in registration order.',
        );
    }

    public function testDispatchIsSynchronousAndImmediate(): void
    {
        $dispatcher = new EventDispatcher();
        $calls = 0;

        $dispatcher->listen('skill.cast', static function () use (&$calls): void {
            $calls++;
        });

        $dispatcher->dispatch('skill.cast');

        self::assertSame(1, $calls, 'dispatch 必须同步即时派发。Dispatch must be synchronous and immediate.');
    }

    public function testDispatchWithoutListenersDoesNotError(): void
    {
        $dispatcher = new EventDispatcher();

        $dispatcher->dispatch('unknown.event', ['id' => 'player-1']);

        self::assertTrue(true);
    }

    public function testRemoveListenerStopsFutureDispatch(): void
    {
        $dispatcher = new EventDispatcher();
        $calls = 0;
        $listener = static function () use (&$calls): void {
            $calls++;
        };

        $dispatcher->listen('buff.applied', $listener);
        $dispatcher->removeListener('buff.applied', $listener);

        $dispatcher->dispatch('buff.applied');

        self::assertSame(0, $calls, '移除后不得再派发。A removed listener must not be dispatched.');
    }

    public function testRemoveListenerRemovesOnlyTheFirstMatch(): void
    {
        $dispatcher = new EventDispatcher();
        $calls = 0;
        $listener = static function () use (&$calls): void {
            $calls++;
        };

        $dispatcher->listen('buff.applied', $listener);
        $dispatcher->listen('buff.applied', $listener);

        $dispatcher->removeListener('buff.applied', $listener);
        $dispatcher->dispatch('buff.applied');

        self::assertSame(1, $calls, '同一监听器注册两次，移除只移除首个匹配。Registering the same listener twice removes only the first match.');
    }

    public function testRemoveListenerSilentlyIgnoresMissingEntries(): void
    {
        $dispatcher = new EventDispatcher();
        $listener = static function (): void {
        };

        $dispatcher->removeListener('never.listened', $listener);

        self::assertTrue(true);
    }

    public function testRemovingOneListenerKeepsTheOthers(): void
    {
        $dispatcher = new EventDispatcher();
        $kept = 0;
        $removed = 0;
        $toRemove = static function () use (&$removed): void {
            $removed++;
        };

        $dispatcher->listen('item.dropped', $toRemove);
        $dispatcher->listen('item.dropped', static function () use (&$kept): void {
            $kept++;
        });

        $dispatcher->removeListener('item.dropped', $toRemove);
        $dispatcher->dispatch('item.dropped');

        self::assertSame(0, $removed);
        self::assertSame(1, $kept, '移除一个监听器不得影响其他监听器。Removing one listener must not affect the others.');
    }
}
