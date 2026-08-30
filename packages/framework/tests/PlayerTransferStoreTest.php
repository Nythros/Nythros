<?php

declare(strict_types=1);

namespace Nythros\Framework\Tests;

use Nythros\Framework\Cluster\InMemoryPlayerTransferStore;
use Nythros\Framework\Cluster\RedisPlayerTransferStore;
use PHPUnit\Framework\TestCase;

/**
 * PlayerTransferStoreTest - P15 跨 map 迁移票据存储验收（ADR-025 §3.2/§3.3）：
 * InMemory 语义全集（往返/覆盖/单次消费/无票 null）+ Redis 集成（原子 GET+DEL 单消费、
 * 覆盖导出、坏票 JSON 容错、键前缀隔离）。
 * PlayerTransferStoreTest - the P15 cross-map migration ticket store's acceptance (ADR-025 §3.2/§3.3):
 * the full InMemory semantics (round-trip / overwrite / single consume / null on miss) + the Redis
 * integration (atomic GET+DEL single consume, overwrite export, malformed-ticket tolerance, prefix isolation).
 */
final class PlayerTransferStoreTest extends TestCase
{
    private const SNAPSHOT = [
        'fromMapId' => 'map-1',
        'position' => ['x' => 30, 'y' => 40],
        'hp' => 88,
        'inventory' => ['potion' => 2, 'gold' => 1],
    ];

    // ── InMemory 语义 ──
    // ── InMemory semantics ──

    public function testInMemoryRoundTripConsumeOnceAndOverwrite(): void
    {
        $store = new InMemoryPlayerTransferStore();

        self::assertNull($store->consume('1001'), '无票返回 null / null when no ticket');

        $store->export('1001', self::SNAPSHOT);
        self::assertSame(self::SNAPSHOT, $store->consume('1001'));
        self::assertNull($store->consume('1001'), '原子单消费：取走即删 / atomic single consume: take-and-delete');

        // 同 uid 二次导出覆盖旧票
        // A second export for the same uid overwrites the old ticket.
        $store->export('1001', ['fromMapId' => 'map-2', 'position' => ['x' => 1, 'y' => 2], 'hp' => 50, 'inventory' => []]);
        $store->export('1001', self::SNAPSHOT);
        self::assertSame(self::SNAPSHOT, $store->consume('1001'));
    }

    // ── Redis 集成 ──
    // ── Redis integration ──

    public function testRedisRoundTripAtomicConsumeAndOverwrite(): void
    {
        $redis = $this->redis();
        if ($redis === null) {
            $this->markTestSkipped('Redis 127.0.0.1:6379 不可用，跳过 RedisPlayerTransferStore 集成测试');
        }

        $store = new RedisPlayerTransferStore($redis, 'nythros:test:' . bin2hex(random_bytes(8)) . ':', 30);

        self::assertNull($store->consume('1001'));

        $store->export('1001', self::SNAPSHOT);
        $store->export('1001', self::SNAPSHOT); // 覆盖导出 Overwrite export.
        self::assertSame(self::SNAPSHOT, $store->consume('1001'), 'Lua GET+DEL 原子单消费 / the Lua GET+DEL atomic single consume');
        self::assertNull($store->consume('1001'), '消费后无残留（TTL 未到也已删） / no residue after the consume (deleted before TTL)');
    }

    public function testRedisMalformedTicketReturnsNull(): void
    {
        $redis = $this->redis();
        if ($redis === null) {
            $this->markTestSkipped('Redis 127.0.0.1:6379 不可用，跳过 RedisPlayerTransferStore 集成测试');
        }

        $prefix = 'nythros:test:' . bin2hex(random_bytes(8)) . ':';
        $store = new RedisPlayerTransferStore($redis, $prefix, 30);
        $redis->setex($prefix . 'transfer:1001', 30, 'not-json{');

        self::assertNull($store->consume('1001'), '坏票 JSON 容错回落全新入场 / a malformed ticket degrades to a fresh entry');
    }

    public function testRedisFactoryClosureLazilyConnects(): void
    {
        $redis = $this->redis();
        if ($redis === null) {
            $this->markTestSkipped('Redis 127.0.0.1:6379 不可用，跳过 RedisPlayerTransferStore 集成测试');
        }

        // 工厂闭包注入（fork 后 lazy 建连口径）：首次消费触发建连
        // The factory-closure injection (the lazy-after-fork convention): the first consume triggers the connection.
        $calls = 0;
        $store = new RedisPlayerTransferStore(static function () use ($redis, &$calls): \Redis {
            ++$calls;

            return $redis;
        }, 'nythros:test:' . bin2hex(random_bytes(8)) . ':', 30);
        $store->export('1001', self::SNAPSHOT);
        $store->consume('1001');

        self::assertSame(1, $calls, '闭包仅解析一次（连接缓存） / the closure resolves once (the connection is cached)');
    }

    /**
     * 连接本地 Redis；不可用返回 null（调用方 markTestSkipped）。
     * Connects to the local Redis; returns null when unavailable (the caller marks the test skipped).
     */
    private function redis(): ?\Redis
    {
        $redis = new \Redis();
        try {
            $connected = @$redis->connect('127.0.0.1', 6379, 1.0);
        } catch (\Throwable) {
            $connected = false;
        }
        if ($connected !== true || @$redis->ping() !== true) {
            return null;
        }

        return $redis;
    }
}
