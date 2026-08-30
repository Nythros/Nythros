<?php

declare(strict_types=1);

namespace Nythros\Persistence;

use PDO;
use PDOStatement;

/**
 * MySQL 存储：PDO 单表 upsert 实现 StorageInterface（异步归档的目标存储，ADR-013 10.5）。
 * MySQL storage: a PDO single-table upsert implementation of StorageInterface (the async archive's target store, ADR-013 10.5).
 *
 * 表结构（单表 id+data 约定：一张物理表承载全部逻辑集合，collection 为分区列；用 createSchema() 幂等建表）：
 * Table layout (single-table id+data convention: one physical table hosts every logical collection; collection is the partition column; create it idempotently with createSchema()):
 *
 *   CREATE TABLE IF NOT EXISTS `{table}` (
 *       `collection` VARCHAR(64)  NOT NULL,
 *       `id`         VARCHAR(64)  NOT NULL,
 *       `data`       JSON         NOT NULL,
 *       `updated_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 *       PRIMARY KEY (`collection`, `id`)
 *   ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
 *
 * 语义要点：
 * Semantic points:
 * - upsert：INSERT ... ON DUPLICATE KEY UPDATE（同 (collection, id) 再保存覆盖旧数据，与 InMemoryStorage 对齐）；
 * - upsert: INSERT ... ON DUPLICATE KEY UPDATE (re-saving the same (collection, id) overwrites the previous data, aligned with InMemoryStorage);
 * - 连接 lazy：构造不建连，首次读写才调用 pdoFactory（多进程场景在 worker 进程内首用建连，与
 *   ADR-013 10.6 的共享资源工厂 lazy 建连模式一致）；建连/执行失败不抛异常——save/delete 返回
 *   false、load 返回 null、saveBatch 返回失败 id 列表（契约要求）；连接失败后下次调用会重试工厂（自愈）；
 * - lazy connection: nothing connects at construction; the factory runs on the first read/write (in
 *   multi-process scenarios the connection is established on first use inside the worker, matching
 *   the ADR-013 10.6 lazy shared-resource factory pattern); connection/execution failures never
 *   throw — save/delete return false, load returns null and saveBatch returns the failed-id list
 *   (as the contract demands); after a failed connection the next call retries the factory (self-healing);
 * - 可裁剪标记：缺 ext-pdo_mysql 时构造抛 RuntimeException——MySQL 为阶段 4 可裁剪项（ADR-013
 *   1.1/10.5），无该扩展的环境应裁剪 MySqlStorage 改用 InMemoryStorage，异常信息即裁剪提示；
 * - trimmability marker: constructing without ext-pdo_mysql throws a RuntimeException — MySQL is a
 *   phase-4 trimmable item (ADR-013 1.1/10.5); environments lacking the extension should trim
 *   MySqlStorage and use InMemoryStorage instead, and the exception message states exactly that.
 * @internal 引擎内部实现，非公开 API。Engine-internal implementation, not part of the public API.
 */
final class MySqlStorage implements StorageInterface
{
    /** 缺省表名 Default table name. */
    public const DEFAULT_TABLE = 'nythros_archive';

    /** @var callable(): PDO 连接工厂（lazy：首次读写才调用） Connection factory (lazy; invoked on first read/write). */
    private $pdoFactory;

    /** @var ?PDO 惰性缓存连接；建连失败保持 null 以便下次重试 Lazily cached connection; kept null after a failed connect so the next call retries. */
    private ?PDO $pdo = null;

    /**
     * 构造 MySQL 存储（不建连）。
     * Creates a MySQL storage (no connection yet).
     *
     * @param callable(): PDO $pdoFactory 连接工厂：首次读写时才调用；工厂创建的 PDO 建议
     *        携带 charset=utf8mb4 与 ERRMODE_EXCEPTION（本实现同时兼容无 ERRMODE 的 PDO）
     *        Connection factory: invoked on first read/write; the PDO it creates should carry
     *        charset=utf8mb4 and ERRMODE_EXCEPTION (this implementation also tolerates a PDO without ERRMODE)
     * @param string $table 表名；白名单 [a-zA-Z0-9_]+，进 SQL 一律反引号包裹（collection/id 只走绑定参数）
     *        Table name; whitelisted to [a-zA-Z0-9_]+, always backtick-quoted in SQL (collection/id are bound parameters only)
     */
    public function __construct(callable $pdoFactory, private readonly string $table = self::DEFAULT_TABLE)
    {
        if (!extension_loaded('pdo_mysql')) {
            throw new \RuntimeException(
                'MySqlStorage 需要 ext-pdo_mysql：缺少该扩展时本实现为可裁剪项，请改用 InMemoryStorage（ADR-013 10.5）',
            );
        }

        self::assertTableName($table);
        $this->pdoFactory = $pdoFactory;
    }

    /**
     * 保存单条记录（upsert：同 (collection, id) 覆盖写）；失败返回 false（不抛异常）。
     * Saves a single record (upsert: same (collection, id) overwrites); false on failure (never throws).
     *
     * @param string $collection 集合名 Collection name.
     * @param string $id 记录标识 Record identifier.
     * @param array<string, mixed> $data 记录数据 Record data.
     */
    public function save(string $collection, string $id, array $data): bool
    {
        try {
            $stmt = $this->prepareUpsert();
            $json = $this->encode($data);
            $stmt->bindValue(':collection', $collection);
            $stmt->bindValue(':id', $id);
            $stmt->bindValue(':data', $json);
            $stmt->bindValue(':data2', $json);

            return $stmt->execute();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * 读取单条记录；不存在返回 null。畸形行（data 列非合法 JSON）防御性视为不存在返回 null。
     * Loads a single record; null when missing. A malformed row (data column not valid JSON) is defensively treated as missing and yields null.
     *
     * @param string $collection 集合名 Collection name.
     * @param string $id 记录标识 Record identifier.
     * @return ?array<string, mixed> 记录数据，不存在 null Record data, or null when missing.
     */
    public function load(string $collection, string $id): ?array
    {
        try {
            $stmt = $this->pdo()->prepare(sprintf(
                'SELECT `data` FROM `%s` WHERE `collection` = :collection AND `id` = :id LIMIT 1',
                $this->table,
            ));
            if ($stmt === false) {
                return null;
            }
            $stmt->bindValue(':collection', $collection);
            $stmt->bindValue(':id', $id);
            if (!$stmt->execute()) {
                return null;
            }

            $raw = $stmt->fetchColumn();
            if (!is_string($raw)) {
                return null;
            }

            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

            return is_array($data) ? $data : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * 删除单条记录；不存在视为成功（幂等，DELETE 零影响行仍返回 true）。
     * Deletes a single record; deleting a missing record counts as success (idempotent — a DELETE with zero affected rows still returns true).
     *
     * @param string $collection 集合名 Collection name.
     * @param string $id 记录标识 Record identifier.
     */
    public function delete(string $collection, string $id): bool
    {
        try {
            $stmt = $this->pdo()->prepare(sprintf(
                'DELETE FROM `%s` WHERE `collection` = :collection AND `id` = :id',
                $this->table,
            ));
            if ($stmt === false) {
                return false;
            }
            $stmt->bindValue(':collection', $collection);
            $stmt->bindValue(':id', $id);

            return $stmt->execute();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * 批量保存：共享一条预处理语句逐条 upsert，单条失败只记入失败 id 列表、不影响其余；
     * 连接失败/预处理失败视为整批失败（返回全部 id）。返回失败 id 列表供归档重试与日志归因。
     * Batch save: one shared prepared statement upserting record by record; a single failure is
     * collected into the failed-id list without affecting the rest; a connection/prepare failure
     * fails the whole batch (every id returned). The failed-id list feeds archive retry and log attribution.
     *
     * @param string $collection 集合名 Collection name.
     * @param array<string, array<string, mixed>> $records id => 数据 的映射 Map of id => data.
     * @return list<string> 失败 id 列表 Failed id list.
     */
    public function saveBatch(string $collection, array $records): array
    {
        if ($records === []) {
            return [];
        }

        $failed = [];
        try {
            $stmt = $this->prepareUpsert();
            foreach ($records as $id => $data) {
                try {
                    $stmt->bindValue(':collection', $collection);
                    $stmt->bindValue(':id', $id);
                    $json = $this->encode($data);
                    $stmt->bindValue(':data', $json);
                    $stmt->bindValue(':data2', $json);
                    if (!$stmt->execute()) {
                        $failed[] = $id;
                    }
                } catch (\Throwable) {
                    $failed[] = $id;
                }
            }
        } catch (\Throwable) {
            return array_keys($records);
        }

        return $failed;
    }

    /**
     * 幂等建表（部署脚本/集成测试用）：构造器为 lazy 设计不建连，因此不自动建表，表须在首次读写前就绪。
     * Idempotent schema creation (for deployment scripts / integration tests): the constructor is lazy and never connects, so it never auto-creates the table — it must exist before the first read/write.
     *
     * @param PDO $pdo 已建连的 PDO（须为 MySQL 驱动） An established PDO (must be the MySQL driver).
     * @param string $table 表名 Table name.
     */
    public static function createSchema(PDO $pdo, string $table = self::DEFAULT_TABLE): void
    {
        self::assertTableName($table);

        $result = $pdo->exec(sprintf(
            'CREATE TABLE IF NOT EXISTS `%s` ('
            . '`collection` VARCHAR(64) NOT NULL,'
            . '`id` VARCHAR(64) NOT NULL,'
            . '`data` JSON NOT NULL,'
            . '`updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,'
            . 'PRIMARY KEY (`collection`, `id`)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
            $table,
        ));
        if ($result === false) {
            throw new \RuntimeException(sprintf('MySqlStorage 建表失败：`%s`', $table));
        }
    }

    /**
     * 惰性取连接：首次调用才经工厂建连并缓存；工厂抛异常不缓存，下次调用重试（MySQL 恢复后自愈）。
     * Lazily obtains the connection: created via the factory and cached on first use; a factory
     * exception is not cached, so the next call retries (self-healing once MySQL recovers).
     */
    private function pdo(): PDO
    {
        return $this->pdo ??= ($this->pdoFactory)();
    }

    /**
     * 准备 upsert 语句（shared by save/saveBatch）。data 与 data2 绑定同一 JSON：VALUES 与
     * ON DUPLICATE KEY UPDATE 两侧各一个占位符，避免 MySQL 8.0.20+ 对 VALUES() 的弃用告警。
     * Prepares the upsert statement (shared by save/saveBatch). data and data2 bind the same JSON:
     * one placeholder on each side of VALUES / ON DUPLICATE KEY UPDATE, avoiding the MySQL 8.0.20+
     * deprecation warning on VALUES().
     */
    private function prepareUpsert(): PDOStatement
    {
        $stmt = $this->pdo()->prepare(sprintf(
            'INSERT INTO `%s` (`collection`, `id`, `data`) VALUES (:collection, :id, :data)'
            . ' ON DUPLICATE KEY UPDATE `data` = :data2',
            $this->table,
        ));
        if ($stmt === false) {
            throw new \RuntimeException(sprintf('MySqlStorage upsert 语句预处理失败：`%s`', $this->table));
        }

        return $stmt;
    }

    /**
     * JSON 编码；失败抛 JsonException（调用方按契约转 false / 失败 id）。
     * JSON-encodes; throws JsonException on failure (callers convert it to false / a failed id per the contract).
     *
     * @param array<string, mixed> $data 记录数据 Record data.
     */
    private function encode(array $data): string
    {
        return json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    /**
     * 表名白名单校验：仅 [a-zA-Z0-9_]+，进 SQL 一律反引号包裹，杜绝表名注入面。
     * Table-name whitelist check: [a-zA-Z0-9_]+ only; names are always backtick-quoted in SQL, eliminating the table-name injection surface.
     */
    private static function assertTableName(string $table): void
    {
        if (preg_match('/^[a-zA-Z0-9_]+$/', $table) !== 1) {
            throw new \InvalidArgumentException(sprintf(
                '非法表名 `%s`：仅允许 [a-zA-Z0-9_]+（防 SQL 注入）',
                $table,
            ));
        }
    }
}
