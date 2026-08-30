<?php

declare(strict_types=1);

namespace Nythros\Persistence\Tests;

use Nythros\Persistence\MySqlStorage;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * MySqlStorage 集成测试：依赖 127.0.0.1:3306 的 MySQL 与 ext-pdo_mysql，不可用时整体跳过（CI/无 MySQL 环境不红）。
 * Integration tests for MySqlStorage: require MySQL on 127.0.0.1:3306 plus ext-pdo_mysql; they skip entirely when unavailable (no red on CI / MySQL-less machines).
 *
 * 覆盖 upsert 往返：save→load / 同 id 覆盖 / load 缺失 null / delete 幂等 / saveBatch 失败 id 契约 / 集合隔离。
 * Covers the upsert roundtrip: save→load / same-id overwrite / load missing null / idempotent delete / saveBatch failed-id contract / collection isolation.
 *
 * MySQL 为阶段 4 可裁剪项（ADR-013 1.1/10.5）：本机 MySQL 不可用时本文件跳过，不影响验收。
 * MySQL is a phase-4 trimmable item (ADR-013 1.1/10.5): while local MySQL is unavailable this file is skipped and does not affect acceptance.
 *
 * 连接参数：缺省 127.0.0.1:3306 / root / 空密码；环境不同可改下方常量（无环境变量间接层，保持简单）。
 * Connection parameters: defaults are 127.0.0.1:3306 / root / empty password; adjust the constants below for other environments (no env indirection, kept simple).
 */
#[Group('mysql')]
final class MySqlStorageTest extends TestCase
{
    private const MYSQL_DSN = 'mysql:host=127.0.0.1;port=3306;dbname=nythros;charset=utf8mb4';

    private const MYSQL_USER = 'root';

    private const MYSQL_PASSWORD = '';

    private ?PDO $pdo = null;

    private string $table = '';

    protected function setUp(): void
    {
        try {
            $pdo = new PDO(self::MYSQL_DSN, self::MYSQL_USER, self::MYSQL_PASSWORD, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 1,
            ]);
        } catch (\Throwable) {
            $this->markTestSkipped('MySQL 127.0.0.1:3306 不可用（或 ext-pdo_mysql 缺失），跳过 MySqlStorage 集成测试');
        }

        $this->pdo = $pdo;
        $this->table = 'nythros_test_' . bin2hex(random_bytes(6));
        MySqlStorage::createSchema($this->pdo, $this->table);
    }

    protected function tearDown(): void
    {
        if ($this->pdo === null) {
            return;
        }

        $this->pdo->exec(sprintf('DROP TABLE IF EXISTS `%s`', $this->table));
        $this->pdo = null;
    }

    /**
     * 以已建连的 PDO 组装 MySqlStorage（连接工厂 lazy：工厂即返回现成连接）。
     * Builds a MySqlStorage over the established PDO (the lazy connection factory just returns the existing connection).
     */
    private function storage(): MySqlStorage
    {
        return new MySqlStorage(
            fn (): PDO => $this->pdo ?? throw new \LogicException('测试 PDO 尚未建立'),
            $this->table,
        );
    }

    public function testSaveThenLoadRoundtrip(): void
    {
        $storage = $this->storage();

        self::assertTrue($storage->save('players', 'u1', [
            'uid' => 'u1',
            'hp' => 100,
            'inventory' => ['sword', 'shield'],
            'pos' => ['x' => 1.5, 'y' => 2.5],
            'online' => true,
            'note' => '中文玩家',
        ]));

        $loaded = $storage->load('players', 'u1');
        self::assertNotNull($loaded);
        self::assertSame('u1', $loaded['uid']);
        self::assertSame(100, $loaded['hp']);
        // JSON 往返后嵌套结构与标量按值相等（assertEquals：MySQL JSON 列可能规范化对象键序，不做键序断言）
        // Nested structures and scalars compare by value after the JSON roundtrip (assertEquals: the MySQL JSON column may normalize object key order, so key order is not asserted)
        self::assertEquals(['sword', 'shield'], $loaded['inventory']);
        self::assertEquals(['x' => 1.5, 'y' => 2.5], $loaded['pos']);
        self::assertSame(true, $loaded['online']);
        self::assertSame('中文玩家', $loaded['note']);
    }

    public function testSaveUpsertsSameId(): void
    {
        $storage = $this->storage();
        $storage->save('players', 'u1', ['hp' => 100]);

        self::assertTrue($storage->save('players', 'u1', ['hp' => 50]));

        // 同 (collection, id) 再次保存覆盖旧数据（ON DUPLICATE KEY UPDATE）
        // Re-saving the same (collection, id) overwrites the previous data (ON DUPLICATE KEY UPDATE)
        self::assertSame(['hp' => 50], $storage->load('players', 'u1'));
    }

    public function testLoadMissingReturnsNull(): void
    {
        self::assertNull($this->storage()->load('players', 'missing'));
    }

    public function testDeleteThenLoadNullAndDeleteAgainIdempotent(): void
    {
        $storage = $this->storage();
        $storage->save('players', 'u1', ['hp' => 100]);

        self::assertTrue($storage->delete('players', 'u1'));
        self::assertNull($storage->load('players', 'u1'));

        // 删除不存在记录仍返回 true（幂等）
        // Deleting a missing record still returns true (idempotent)
        self::assertTrue($storage->delete('players', 'u1'));
    }

    public function testSaveBatchPersistsAllAndReturnsNoFailures(): void
    {
        $storage = $this->storage();

        $failed = $storage->saveBatch('players', [
            'u1' => ['hp' => 100],
            'u2' => ['hp' => 80],
        ]);

        self::assertSame([], $failed);
        self::assertSame(['hp' => 100], $storage->load('players', 'u1'));
        self::assertSame(['hp' => 80], $storage->load('players', 'u2'));
    }

    public function testSaveBatchUpsertsExistingRecords(): void
    {
        $storage = $this->storage();
        $storage->save('players', 'u1', ['hp' => 100]);

        $storage->saveBatch('players', ['u1' => ['hp' => 30]]);

        self::assertSame(['hp' => 30], $storage->load('players', 'u1'));
    }

    public function testCollectionsAreIsolated(): void
    {
        $storage = $this->storage();
        $storage->save('players', 'u1', ['hp' => 100]);
        $storage->save('accounts', 'u1', ['level' => 5]);

        // 同 id 不同 collection 互不干扰（复合主键 (collection, id)）
        // The same id in different collections stays independent (composite primary key (collection, id))
        self::assertSame(['hp' => 100], $storage->load('players', 'u1'));
        self::assertSame(['level' => 5], $storage->load('accounts', 'u1'));

        $storage->delete('players', 'u1');
        self::assertNull($storage->load('players', 'u1'));
        self::assertSame(['level' => 5], $storage->load('accounts', 'u1'));
    }
}
