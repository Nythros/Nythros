<?php

declare(strict_types=1);

namespace Nythros\World\Tests;

use Nythros\Contracts\EntityInterface;
use Nythros\World\SimpleEntityManager;
use PHPUnit\Framework\TestCase;

/**
 * SimpleEntityManagerTest - 覆盖 SimpleEntityManager 的添加、查询、移除与全量列举契约。
 * Tests covering SimpleEntityManager add/get/remove/all contracts.
 */
final class SimpleEntityManagerTest extends TestCase
{
    public function testAddGetRemoveAll(): void
    {
        $manager = new SimpleEntityManager();

        $a = $this->createStub(EntityInterface::class);
        $a->method('getId')->willReturn('a');

        $b = $this->createStub(EntityInterface::class);
        $b->method('getId')->willReturn('b');

        self::assertNull($manager->get('a'));

        $manager->add($a);
        $manager->add($b);

        self::assertSame($a, $manager->get('a'));
        self::assertSame([$a, $b], $manager->all());

        $manager->remove('a');

        self::assertNull($manager->get('a'));
        self::assertSame([$b], $manager->all());
    }

    public function testWalkIteratesAllEntitiesWithoutCopy(): void
    {
        // walk() 契约：遍历全部实体（与 all() 同一集合语义），且返回内部表本身——
        // 遍历期间对管理器做增删不影响进行中的迭代（COW 分离），也不触发全表复制。
        // walk() contract: iterates the same entity set as all(), returning the internal table itself —
        // add/remove during iteration neither disturbs the in-flight iteration (COW separation) nor triggers a full-table copy.
        $manager = new SimpleEntityManager();

        $a = $this->createStub(EntityInterface::class);
        $a->method('getId')->willReturn('a');

        $b = $this->createStub(EntityInterface::class);
        $b->method('getId')->willReturn('b');

        $manager->add($a);
        $manager->add($b);

        /** @var list<EntityInterface> $seen walk 产出的实体序列 Entities yielded by walk. */
        $seen = [];
        foreach ($manager->walk() as $entity) {
            $seen[] = $entity;
            // 遍历中变更内部表：迭代继续走原表快照，不丢不重 mutate the internal table mid-iteration: iteration keeps the original snapshot, no loss or duplication
            $manager->remove('a');
            $manager->add($b);
        }

        self::assertCount(2, $seen);
        self::assertSame([$a, $b], $seen, 'walk yields each entity exactly once');
        self::assertSame([$b], $manager->all(), 'mutations during iteration applied to the live table');
    }
}
