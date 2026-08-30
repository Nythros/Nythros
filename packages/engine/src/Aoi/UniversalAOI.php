<?php

declare(strict_types=1);

namespace Nythros\Aoi;

use Nythros\Contracts\AOIProviderInterface;
use Nythros\Contracts\EntityInterface;
use Nythros\Contracts\EntityManagerInterface;
use Nythros\Contracts\ShapeInterface;

/**
 * 全量视野 AOI（平凡 AOI）：不对实体做空间索引，把整个世界视为每个实体的视野。
 * - query()：返回实体管理器全表（含自身，与 GridAOI::query 含自身的口径一致）。
 * - updateEntity()：恒返回空差分——全量可见下不存在「进入/离开视野」，事件语义由上层全量广播路径保证。
 * - remove()：空操作——没有索引可摘除；实体摘除由调用方直接走实体管理器（与 GridAOI::remove 对未登记实体静默忽略对齐）。
 *
 * 用途：FULL_BROADCAST 型 World（副本/竞技场等小人数高隔离空间）的空间语义载体。引入本类后
 * 「AOI 永远存在」成为引擎不变式：全量广播不是「无 AOI」，而是「AOI 即全世界」，消费方
 * （MapServer/CombatService/MonsterActor）不再需要判空。
 *
 * Universal-view AOI (the trivial AOI): keeps no spatial index — the whole world is every entity's view.
 * - query(): returns the full entity table (self included, matching GridAOI::query which includes self).
 * - updateEntity(): always returns an empty delta — under full visibility nothing "enters/leaves"; event semantics
 *   are guaranteed by the upper-layer full-broadcast path.
 * - remove(): a no-op — there is no index to remove from; callers remove entities through the entity manager
 *   (aligned with GridAOI::remove, which silently ignores entities that were never registered).
 *
 * Purpose: the spatial-semantics carrier of FULL_BROADCAST Worlds (dungeons/arenas — small headcount, high isolation).
 * With this class, "an AOI always exists" becomes an engine invariant: full broadcast is not "no AOI" but
 * "the AOI is the whole world", so consumers (MapServer/CombatService/MonsterActor) never null-check.
 * @internal 引擎内部实现，非公开 API。Engine-internal implementation, not part of the public API.
 */
final class UniversalAOI implements AOIProviderInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * 全量可见：实体间无视野关系，不存在进入/离开差分（出生/加入通知由上层全量广播承担）。
     * Full visibility: no per-pair vision relationship exists, so there is never an enter/leave delta
     * (birth/join notices are carried by the upper-layer full broadcast).
     *
     * @return array{entered: list<EntityInterface>, left: list<EntityInterface>} 恒为空差分 Always an empty delta.
     */
    public function updateEntity(EntityInterface $entity): array
    {
        return ['entered' => [], 'left' => []];
    }

    /**
     * 空操作：无索引可摘除；若实体随后被移除，请调用方走实体管理器。
     * No-op: there is no index to remove from; if the entity is later removed, callers go through the entity manager.
     */
    public function remove(EntityInterface $entity): void
    {
    }

    /**
     * 全量可见 = 整个世界都在视野内；含自身（与 GridAOI::query 含自身一致，避免两实现对消费方语义分叉）。
     * Full visibility = the whole world is in view; includes self (consistent with GridAOI::query's
     * self-inclusion, so the two implementations never diverge in consumer semantics).
     *
     * @return list<EntityInterface> 实体管理器全表 The full entity table.
     */
    public function query(EntityInterface $entity): array
    {
        return $this->entityManager->all();
    }

    /**
     * 形状查询：无空间索引，全表逐实体 contains 精判（与 query 全表口径一致）；
     * 含自身若在内、按 id 去重、只读。
     * Shape query: no spatial index, so the full table is filtered per-entity via contains (matching query's
     * full-table semantics); self included if inside, deduplicated by id, read-only.
     *
     * @return list<EntityInterface> 形状覆盖内实体列表 List of entities covered by the shape.
     */
    public function queryShape(ShapeInterface $shape): array
    {
        $result = [];
        foreach ($this->entityManager->walk() as $entity) {
            ['x' => $x, 'y' => $y] = $entity->getPosition();
            if ($shape->contains($x, $y)) {
                // 以 id 为键天然去重（实体管理器以 id 为键，结构性不重复） keying by id dedups naturally (the entity manager is id-keyed, duplicates structurally impossible)
                $result[$entity->getId()] = $entity;
            }
        }

        return array_values($result);
    }
}
