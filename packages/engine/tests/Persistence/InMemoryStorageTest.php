<?php

declare(strict_types=1);

namespace Nythros\Persistence\Tests;

use Nythros\Persistence\InMemoryStorage;
use PHPUnit\Framework\TestCase;

/**
 * InMemoryStorage 语义测试：save（upsert）/load/delete（幂等）/saveBatch（失败 id 列表契约）+ 集合隔离。
 * Tests for InMemoryStorage semantics: save (upsert) / load / delete (idempotent) / saveBatch (failed-id list contract) plus collection isolation.
 */
final class InMemoryStorageTest extends TestCase
{
    public function testSaveThenLoadRoundtrip(): void
    {
        $storage = new InMemoryStorage();

        self::assertTrue($storage->save('players', 'u1', ['uid' => 'u1', 'hp' => 100]));

        self::assertSame(['uid' => 'u1', 'hp' => 100], $storage->load('players', 'u1'));
    }

    public function testSaveUpsertsSameId(): void
    {
        $storage = new InMemoryStorage();
        $storage->save('players', 'u1', ['hp' => 100]);
        $storage->save('players', 'u1', ['hp' => 50]);

        // 同 (collection, id) 再次保存覆盖旧数据（upsert，与 MySqlStorage 的 ON DUPLICATE KEY UPDATE 对拍）
        // Re-saving the same (collection, id) overwrites the previous data (upsert, mirrored against MySqlStorage's ON DUPLICATE KEY UPDATE)
        self::assertSame(['hp' => 50], $storage->load('players', 'u1'));
    }

    public function testLoadMissingReturnsNull(): void
    {
        $storage = new InMemoryStorage();

        self::assertNull($storage->load('players', 'missing'));
    }

    public function testDeleteRemovesRecord(): void
    {
        $storage = new InMemoryStorage();
        $storage->save('players', 'u1', ['hp' => 100]);

        self::assertTrue($storage->delete('players', 'u1'));
        self::assertNull($storage->load('players', 'u1'));
    }

    public function testDeleteMissingIsIdempotent(): void
    {
        $storage = new InMemoryStorage();

        // 删除不存在的记录视为成功（幂等，与 MySqlStorage 的零影响行 DELETE 对齐）
        // Deleting a missing record counts as success (idempotent, aligned with MySqlStorage's zero-row DELETE)
        self::assertTrue($storage->delete('players', 'missing'));
    }

    public function testSaveBatchPersistsAllAndReturnsNoFailures(): void
    {
        $storage = new InMemoryStorage();

        $failed = $storage->saveBatch('players', [
            'u1' => ['hp' => 100],
            'u2' => ['hp' => 80],
        ]);

        // 失败 id 列表契约：内存实现无失败路径恒返回空列表
        // Failed-id list contract: the in-memory implementation has no failure path and always returns an empty list
        self::assertSame([], $failed);
        self::assertSame(['hp' => 100], $storage->load('players', 'u1'));
        self::assertSame(['hp' => 80], $storage->load('players', 'u2'));
    }

    public function testSaveBatchUpsertsExistingRecords(): void
    {
        $storage = new InMemoryStorage();
        $storage->save('players', 'u1', ['hp' => 100]);

        $storage->saveBatch('players', ['u1' => ['hp' => 30]]);

        self::assertSame(['hp' => 30], $storage->load('players', 'u1'));
    }

    public function testSaveBatchEmptyReturnsNoFailures(): void
    {
        $storage = new InMemoryStorage();

        self::assertSame([], $storage->saveBatch('players', []));
    }

    public function testCollectionsAreIsolated(): void
    {
        $storage = new InMemoryStorage();
        $storage->save('players', 'u1', ['hp' => 100]);
        $storage->save('accounts', 'u1', ['level' => 5]);

        // 同 id 不同 collection 互不干扰
        // The same id in different collections stays independent
        self::assertSame(['hp' => 100], $storage->load('players', 'u1'));
        self::assertSame(['level' => 5], $storage->load('accounts', 'u1'));

        $storage->delete('players', 'u1');
        self::assertNull($storage->load('players', 'u1'));
        self::assertSame(['level' => 5], $storage->load('accounts', 'u1'));
    }
}
