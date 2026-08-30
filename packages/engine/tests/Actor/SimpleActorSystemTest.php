<?php

declare(strict_types=1);

namespace Nythros\Actor\Tests;

use Nythros\Actor\SimpleActorSystem;
use Nythros\Contracts\ActorInterface;
use PHPUnit\Framework\TestCase;

/**
 * SimpleActorSystemTest - 覆盖 SimpleActorSystem 添加/移除 Actor 与逐帧更新调度行为。
 * Tests covering SimpleActorSystem actor add/remove and per-frame updateAll scheduling.
 */
final class SimpleActorSystemTest extends TestCase
{
    public function testUpdateAllCallsUpdateOnEveryActor(): void
    {
        $system = new SimpleActorSystem();
        $calls = [];

        $actorOne = $this->createStub(ActorInterface::class);
        $actorOne->method('update')->willReturnCallback(static function () use (&$calls): void {
            $calls[] = 'one';
        });

        $actorTwo = $this->createStub(ActorInterface::class);
        $actorTwo->method('update')->willReturnCallback(static function () use (&$calls): void {
            $calls[] = 'two';
        });

        $system->add($actorOne);
        $system->add($actorTwo);

        $system->updateAll();

        self::assertSame(['one', 'two'], $calls);
    }

    public function testRemovedActorIsNotUpdated(): void
    {
        $system = new SimpleActorSystem();
        $calls = [];

        $actorOne = $this->createStub(ActorInterface::class);
        $actorOne->method('update')->willReturnCallback(static function () use (&$calls): void {
            $calls[] = 'one';
        });

        $actorTwo = $this->createStub(ActorInterface::class);
        $actorTwo->method('update')->willReturnCallback(static function () use (&$calls): void {
            $calls[] = 'two';
        });

        $system->add($actorOne);
        $system->add($actorTwo);
        $system->remove($actorOne);

        $system->updateAll();

        self::assertSame(['two'], $calls);
    }
}
