<?php

declare(strict_types=1);

namespace Nythros\Persistence;

/**
 * 仓储契约：面向单类聚合的存取门面（find/persist/remove/findBy）。
 * Repository contract: a storage facade over a single aggregate type (find/persist/remove/findBy).
 */
interface RepositoryInterface
{
    /**
     * 按主键查找；不存在返回 null。
     * Find by primary key; null when missing.
     *
     * @param string $id 记录标识 Record identifier.
     * @return ?array<string, mixed> 记录数据，不存在 null Record data, or null when missing.
     */
    public function find(string $id): ?array;

    /**
     * 写入或覆盖记录状态。
     * Persist (insert or overwrite) a record state.
     *
     * @param string $id 记录标识 Record identifier.
     * @param array<string, mixed> $state 记录状态 Record state.
     */
    public function persist(string $id, array $state): void;

    /**
     * 移除记录；不存在视为成功（幂等）。
     * Remove a record; removing a missing record counts as success (idempotent).
     *
     * @param string $id 记录标识 Record identifier.
     */
    public function remove(string $id): void;

    /**
     * 按字段值查找全部匹配记录；无匹配返回空数组。
     * Find all records matching a field value; an empty array when nothing matches.
     *
     * @param string $field 字段名 Field name.
     * @param mixed $value 字段值 Field value.
     * @return list<array<string, mixed>> 匹配记录列表 Matching records.
     */
    public function findBy(string $field, mixed $value): array;
}
