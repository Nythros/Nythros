<?php

declare(strict_types=1);

namespace Nythros\World;

use Nythros\Contracts\EntityInterface;
use Nythros\Contracts\EntityManagerInterface;

/**
 * 简单实体管理器：以实体 id 为键维护实体表，提供登记、移除、查询与全量遍历。
 * Simple entity manager: keeps an entity table keyed by entity id, providing registration, removal, lookup and full iteration.
 * @internal 引擎内部实现，非公开 API。Engine-internal implementation, not part of the public API.
 */
final class SimpleEntityManager implements EntityManagerInterface
{
    /** @var array<string, EntityInterface> 实体表：实体 id 映射到实体实例 Entity table: entity id mapped to the entity instance. */
    private array $entities = [];

    /**
     * 登记实体；相同 id 的实体会被后添加者覆盖。
     * 首次登记即视为「本帧已移动」：新实体必须进入 AOI 索引，moved-dirty 增量刷新据此感知。
     * Registers an entity; a later entity with the same id overwrites the earlier one.
     * First registration counts as "moved this frame": a new entity must enter the AOI index, which the
     * moved-dirty incremental refresh picks up.
     *
     * @param EntityInterface $entity 要登记的实体 The entity to register.
     */
    public function add(EntityInterface $entity): void
    {
        $this->entities[$entity->getId()] = $entity;
        $entity->markMoved();
    }

    /**
     * 按 id 移除实体；不存在的 id 会被静默忽略。
     * Removes an entity by id; an unknown id is silently ignored.
     *
     * @param string $id 实体 id Entity id.
     */
    public function remove(string $id): void
    {
        unset($this->entities[$id]);
    }

    /**
     * 按 id 查询实体。
     * Looks up an entity by id.
     *
     * @param string $id 实体 id Entity id.
     * @return ?EntityInterface 对应实体；不存在时返回 null The matching entity; null when it does not exist.
     */
    public function get(string $id): ?EntityInterface
    {
        return $this->entities[$id] ?? null;
    }

    /**
     * 返回全部实体列表。
     * Returns the full list of entities.
     *
     * @return list<EntityInterface> 全部实体列表（不保证特定顺序） All entities (no guaranteed order).
     */
    public function all(): array
    {
        // array_values 把以 id 为键的实体表重排为顺序列表，供 World 每帧遍历 array_values re-indexes the id-keyed table into a sequential list for the World's per-frame iteration
        return array_values($this->entities);
    }

    /**
     * 遍历全部实体（零拷贝）：直接返回内部表，由调用方 foreach（PHP 数组 COW 安全，遍历不复制）。
     * Iterates over all entities (zero-copy): returns the internal table directly; the caller's foreach is
     * COW-safe on PHP arrays (no copy during iteration).
     *
     * @return iterable<EntityInterface> 实体可迭代对象（不保证特定顺序） An iterable of entities (no guaranteed order).
     */
    public function walk(): iterable
    {
        return $this->entities;
    }

    /**
     * 取走并清空「本帧已移动」实体集合：扫描内部表收集 moved 置位的实体并复位标志。
     * 同一实体同帧多次移动只置一次位，故只出现一次；静止实体仅付一次 bool 检查成本。
     * Takes and clears the "moved this frame" entity set: scans the internal table collecting entities whose
     * moved flag is set and resets it. An entity moving multiple times within one frame sets the flag once and
     * appears exactly once; a stationary entity pays only a single bool check.
     *
     * @return list<EntityInterface> 本帧已移动实体（顺序不保证） Entities moved this frame (no guaranteed order).
     */
    public function drainMoved(): array
    {
        $moved = [];
        foreach ($this->entities as $entity) {
            if ($entity->consumeMoved()) {
                $moved[] = $entity;
            }
        }

        return $moved;
    }
}
