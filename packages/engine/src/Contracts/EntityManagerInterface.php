<?php

declare(strict_types=1);

namespace Nythros\Contracts;

/**
 * 实体管理器契约：按 id 维护实体注册表，提供增删、查找与全量列举。
 * Entity manager contract: maintains a registry of entities keyed by id, supporting add, remove, lookup and full enumeration.
 */
interface EntityManagerInterface
{
    /**
     * 注册实体；id 冲突时的行为由实现约定（通常覆盖或抛出异常）。
     * Register an entity; behavior on id conflicts is implementation-defined (usually overwrite or throw).
     *
     * @param EntityInterface $entity 目标实体 The target entity.
     */
    public function add(EntityInterface $entity): void;

    /**
     * 按 id 移除实体；id 不存在时应静默忽略。
     * Remove the entity with the given id; unknown ids should be silently ignored.
     *
     * @param string $id 实体 id The entity id.
     */
    public function remove(string $id): void;

    /**
     * 按 id 查找实体，未找到返回 null。
     * Look up an entity by id, returning null when not found.
     *
     * @param string $id 实体 id The entity id.
     * @return EntityInterface|null 匹配的实体或 null The matching entity, or null.
     */
    public function get(string $id): ?EntityInterface;

    /**
     * 获取全部已注册实体。
     * Get all registered entities.
     *
     * @return list<EntityInterface> 实体列表 List of entities.
     */
    public function all(): array;

    /**
     * 遍历全部已注册实体（零拷贝）：直接走内部表迭代，不做 array_values 强制复制。
     * 供每帧全量扫描的热路径使用（PHP 数组 foreach 是 COW 安全的，遍历期间不触发复制）；
     * 需要可索引数组快照时仍应使用 all()。
     * Iterate over all registered entities (zero-copy): walks the internal table directly without the
     * forced array_values copy. Intended for per-frame full-scan hot paths (PHP array foreach is COW-safe,
     * no copy is triggered during iteration); use all() when an indexable array snapshot is required.
     *
     * @return iterable<EntityInterface> 实体可迭代对象（顺序不保证） An iterable of entities (no guaranteed order).
     */
    public function walk(): iterable;

    /**
     * 取走并清空「本帧已移动」实体集合：返回自上次 drain 后发生位置变更（含首次登记）的全部实体，
     * 并复位其 moved 标志。同一实体同帧多次移动只出现一次。AOI moved-dirty 增量刷新的数据源。
     * Takes and clears the "moved this frame" entity set: returns every entity that changed position since
     * the last drain (first registration included) and resets its moved flag. An entity moving multiple times
     * within one frame appears exactly once. This is the data source for the AOI moved-dirty incremental refresh.
     *
     * @return list<EntityInterface> 本帧已移动实体（顺序不保证） Entities moved this frame (no guaranteed order).
     */
    public function drainMoved(): array;
}
