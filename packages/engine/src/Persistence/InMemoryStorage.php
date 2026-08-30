<?php

declare(strict_types=1);

namespace Nythros\Persistence;

/**
 * 内存存储：进程内嵌套数组实现 StorageInterface，供单进程与测试场景使用，语义与 MySqlStorage 对拍。
 * In-memory storage: implements StorageInterface over in-process nested arrays for single-process and test scenarios, mirrored against MySqlStorage.
 *
 * 语义要点（与 MySqlStorage 对齐）：
 * Semantic points (aligned with MySqlStorage):
 * - save 为 upsert：同 (collection, id) 再次保存覆盖旧数据（等价 MySQL 的 ON DUPLICATE KEY UPDATE）；
 * - save is an upsert: re-saving the same (collection, id) overwrites the previous data (equivalent to MySQL's ON DUPLICATE KEY UPDATE);
 * - delete 幂等：删除不存在的记录视为成功；
 * - delete is idempotent: deleting a missing record counts as success;
 * - saveBatch 失败 id 契约：内存实现没有失败路径，恒返回空列表。契约本身保留（消费方如
 *   ArchivePipeline 只消费失败 id 列表做重试与放弃，不感知具体实现）。
 * - saveBatch failed-id contract: the in-memory implementation has no failure path and always returns
 *   an empty list. The contract itself is preserved (consumers such as ArchivePipeline consume only
 *   the failed-id list for retry and give-up, never the concrete implementation).
 * @internal 引擎内部实现，非公开 API。Engine-internal implementation, not part of the public API.
 */
final class InMemoryStorage implements StorageInterface
{
    /** @var array<string, array<string, array<string, mixed>>> collection => id => data */
    private array $collections = [];

    /**
     * 保存单条记录（upsert：同 id 覆盖写）。
     * Saves a single record (upsert: same id overwrites).
     *
     * @param string $collection 集合名 Collection name.
     * @param string $id 记录标识 Record identifier.
     * @param array<string, mixed> $data 记录数据 Record data.
     */
    public function save(string $collection, string $id, array $data): bool
    {
        $this->collections[$collection][$id] = $data;

        return true;
    }

    /**
     * 读取单条记录；不存在返回 null。
     * Loads a single record; null when it does not exist.
     *
     * @param string $collection 集合名 Collection name.
     * @param string $id 记录标识 Record identifier.
     * @return ?array<string, mixed> 记录数据，不存在 null Record data, or null when missing.
     */
    public function load(string $collection, string $id): ?array
    {
        return $this->collections[$collection][$id] ?? null;
    }

    /**
     * 删除单条记录；不存在视为成功（幂等）。
     * Deletes a single record; deleting a missing record counts as success (idempotent).
     *
     * @param string $collection 集合名 Collection name.
     * @param string $id 记录标识 Record identifier.
     */
    public function delete(string $collection, string $id): bool
    {
        unset($this->collections[$collection][$id]);

        return true;
    }

    /**
     * 批量保存（逐条 upsert）；内存实现无失败路径，恒返回空列表（失败 id 契约保留）。
     * Batch save (per-record upsert); the in-memory implementation has no failure path and always returns an empty list (the failed-id contract is preserved).
     *
     * @param string $collection 集合名 Collection name.
     * @param array<string, array<string, mixed>> $records id => 数据 的映射 Map of id => data.
     * @return list<string> 失败 id 列表（本实现恒为空） Failed id list (always empty here).
     */
    public function saveBatch(string $collection, array $records): array
    {
        $failed = [];
        foreach ($records as $id => $data) {
            // (string) 收敛：PHP 数组数字键自动 int 化（如 uid '1001'），save 契约是 string id——
            // 不收敛则数字 uid 在 saveBatch 路径 TypeError（MySqlStorage 经 bindValue 天然容忍）
            // (string) normalization: PHP array numeric keys auto-int (e.g. uid '1001') while save's
            // contract is a string id — without this, numeric uids TypeError on the saveBatch path
            // (MySqlStorage tolerates naturally via bindValue).
            if (!$this->save($collection, (string) $id, $data)) {
                $failed[] = (string) $id;
            }
        }

        return $failed;
    }
}
