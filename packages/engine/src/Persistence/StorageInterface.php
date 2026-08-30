<?php

declare(strict_types=1);

namespace Nythros\Persistence;

/**
 * 存储契约：按集合分区的键值持久化原语（异步归档与同步双写的共同底层）。
 * Storage contract: key-value persistence primitives partitioned by collection (the shared base for async archiving and synchronous dual-writes).
 */
interface StorageInterface
{
    /**
     * 保存单条记录；失败返回 false（不抛异常）。
     * Save a single record; returns false on failure (never throws).
     *
     * @param string $collection 集合名 Collection name.
     * @param string $id 记录标识 Record identifier.
     * @param array<string, mixed> $data 记录数据 Record data.
     */
    public function save(string $collection, string $id, array $data): bool;

    /**
     * 读取单条记录；不存在返回 null。
     * Load a single record; null when it does not exist.
     *
     * @param string $collection 集合名 Collection name.
     * @param string $id 记录标识 Record identifier.
     * @return ?array<string, mixed> 记录数据，不存在 null Record data, or null when missing.
     */
    public function load(string $collection, string $id): ?array;

    /**
     * 删除单条记录；不存在视为成功（幂等）。
     * Delete a single record; deleting a missing record counts as success (idempotent).
     *
     * @param string $collection 集合名 Collection name.
     * @param string $id 记录标识 Record identifier.
     */
    public function delete(string $collection, string $id): bool;

    /**
     * 批量保存；返回失败 id 列表（供归档重试与日志归因）。
     * Batch save; returns the list of failed ids (for archive retry and log attribution).
     *
     * @param string $collection 集合名 Collection name.
     * @param array<string, array<string, mixed>> $records id => 数据 的映射 Map of id => data.
     * @return list<string> 失败 id 列表 Failed id list.
     */
    public function saveBatch(string $collection, array $records): array;
}
