<?php

declare(strict_types=1);

namespace Nythros\Aoi\Tests;

use Nythros\Aoi\UniversalAOI;
use Nythros\Contracts\EntityInterface;
use Nythros\Entity\BaseEntity;
use Nythros\Entity\CircleShape;
use Nythros\Entity\Position;
use Nythros\Entity\SectorShape;
use Nythros\World\SimpleEntityManager;
use PHPUnit\Framework\TestCase;

/**
 * UniversalAOITest - 覆盖全量视野 AOI 的三项契约：
 * query 返回实体管理器全表（含自身）；updateEntity 恒返回空差分；remove 为空操作（实体摘除走实体管理器）。
 * UniversalAOITest - covers the three full-view AOI contracts:
 * query returns the whole entity table (self included); updateEntity always returns an empty delta;
 * remove is a no-op (entities are removed through the entity manager).
 */
final class UniversalAOITest extends TestCase
{
    public function testQueryReturnsWholeEntityTableIncludingSelf(): void
    {
        $em = new SimpleEntityManager();
        $aoi = new UniversalAOI($em);

        $a = new BaseEntity('a', new Position(1, 1));
        $b = new BaseEntity('b', new Position(99, -5));
        $em->add($a);
        $em->add($b);

        $ids = array_map(static fn (EntityInterface $e): string => $e->getId(), $aoi->query($a));

        self::assertSame(['a', 'b'], $ids, 'UniversalAOI::query 必须返回全实体表（含自身），与 GridAOI::query 含自身口径一致。');
    }

    public function testUpdateEntityAlwaysReturnsEmptyDelta(): void
    {
        $em = new SimpleEntityManager();
        $aoi = new UniversalAOI($em);
        $entity = new BaseEntity('a', new Position(0, 0));
        $em->add($entity);
        $entity->move(5, 5);

        self::assertSame(['entered' => [], 'left' => []], $aoi->updateEntity($entity), '全量可见下不存在进入/离开差分。');
    }

    public function testRemoveIsNoOpAndEntityStaysVisible(): void
    {
        $em = new SimpleEntityManager();
        $aoi = new UniversalAOI($em);
        $entity = new BaseEntity('a', new Position(0, 0));
        $em->add($entity);

        $aoi->remove($entity);

        // 索引无状态：remove 不影响实体管理器与查询结果（实体摘除由调用方走实体管理器）
        // The index is stateless: remove leaves the entity manager and the query result untouched
        // (callers remove entities through the entity manager)
        self::assertSame(['a'], array_map(static fn (EntityInterface $e): string => $e->getId(), $aoi->query($entity)));
        self::assertSame($entity, $em->get('a'), 'remove 不得触碰实体管理器。');
    }

    /**
     * queryShape：全表过滤口径——无空间索引，逐实体 contains 精判。
     * queryShape: full-table filtering — no spatial index, per-entity contains precision check.
     */
    public function testQueryShapeFiltersFullTable(): void
    {
        $em = new SimpleEntityManager();
        $aoi = new UniversalAOI($em);

        $inside = new BaseEntity('in', new Position(5, 0));
        $outside = new BaseEntity('out', new Position(50, 50));
        $boundary = new BaseEntity('edge', new Position(10, 0)); // 圆周含入 circumference inclusive
        $em->add($inside);
        $em->add($outside);
        $em->add($boundary);

        $ids = array_map(
            static fn (EntityInterface $e): string => $e->getId(),
            $aoi->queryShape(new CircleShape(0, 0, 10)),
        );
        sort($ids);

        self::assertSame(['edge', 'in'], $ids);
    }

    public function testQueryShapeSectorCenterAlwaysContained(): void
    {
        $em = new SimpleEntityManager();
        $aoi = new UniversalAOI($em);

        $caster = new BaseEntity('caster', new Position(0, 0));
        $behind = new BaseEntity('behind', new Position(-3, 0)); // 朝向反侧 opposite the facing
        $em->add($caster);
        $em->add($behind);

        // 朝向 350°、张角 30°：施法者（圆心）恒命中，反侧实体排除 facing 350° aperture 30°: the caster (center) is always contained, the entity behind is excluded
        $ids = array_map(
            static fn (EntityInterface $e): string => $e->getId(),
            $aoi->queryShape(new SectorShape(0, 0, 20, 350, 30)),
        );

        self::assertSame(['caster'], $ids);
    }

    public function testQueryShapeReturnsEmptyWhenNothingMatches(): void
    {
        $em = new SimpleEntityManager();
        $aoi = new UniversalAOI($em);

        $em->add(new BaseEntity('a', new Position(1, 1)));

        self::assertSame([], $aoi->queryShape(new CircleShape(100, 100, 3)));
    }
}
