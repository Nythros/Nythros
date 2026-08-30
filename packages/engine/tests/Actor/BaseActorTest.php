<?php

declare(strict_types=1);

namespace Nythros\Actor\Tests;

use Nythros\Actor\BaseActor;
use Nythros\Contracts\EntityInterface;
use PHPUnit\Framework\TestCase;

/**
 * BaseActorTest - 覆盖 BaseActor 绑定实体前后的状态行为。
 * Tests covering BaseActor entity binding state before and after bindEntity.
 */
final class BaseActorTest extends TestCase
{
    public function testEntityIsNullUntilBound(): void
    {
        $entity = $this->createStub(EntityInterface::class);
        $actor = new class () extends BaseActor {
            public function update(): void
            {
            }

            public function getBoundEntity(): ?EntityInterface
            {
                return $this->entity;
            }
        };

        self::assertNull($actor->getBoundEntity());

        $actor->bindEntity($entity);

        self::assertSame($entity, $actor->getBoundEntity());
    }
}
