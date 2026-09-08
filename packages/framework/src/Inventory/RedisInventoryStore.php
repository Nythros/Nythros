<?php

declare(strict_types=1);

namespace Nythros\Framework\Inventory;

use Nythros\Framework\Inventory;

/**
 * 背包 Redis 权威实现：玩家物品清单存在 Redis hash（itemId => count），与 CurrencyLedger 同风格。
 * 读写口径（best-practices §1 写回缓冲纪律）：MapServer 运行期只持进程内 Inventory 会话缓存，
 * 本存储只在同步点被触碰——attach 载入（load）、冲刷回写（save）、导出器/运维读取；
 * 每消息热路径不直写本存储。
 * Redis-authoritative inventory store: a player's item ledger lives in a Redis hash (itemId => count), in the
 * style of CurrencyLedger. Access discipline (the write-back-buffer rule of best-practices §1): MapServer keeps
 * the session's in-memory Inventory during play and this store is touched only at sync points — the attach load,
 * the buffered write-back (save), and exporters/ops reads; the per-message hot path never writes it directly.
 *
 * 键设计：nythros:bag:{uid}  hash {itemId => int(count)}
 * Key design: nythros:bag:{uid} hash {itemId => int(count)}.
 *
 * add/remove 提供服务端侧原子辅助（导出回灌/运维直改用），玩家游戏路径不消费它们；
 * 游戏路径的背包变更走进程内会话缓存 + 快照回写（见 save）。
 * add/remove are server-side atomic helpers (exporter replay / ops direct edits); the player-facing game path
 * mutates the in-process session cache and writes whole snapshots back via save().
 */
final class RedisInventoryStore
{
    /** uid 格式白名单（进入键构造，收敛注入面） uid format whitelist (enters key construction). */
    private const UID_PATTERN = '/^[A-Za-z0-9_-]{1,64}$/';

    /** itemId 格式白名单（进入 hash field，收敛注入面） itemId format whitelist (enters hash field). */
    private const ITEM_PATTERN = '/^[A-Za-z0-9_.:-]{1,64}$/';

    /** @var \Redis|\Closure(): \Redis 已连接客户端或工厂 Connected client or factory. */
    private \Redis|\Closure $redis;

    private string $prefix;

    /**
     * 构造背包 Redis 权威存储。
     * Construct the Redis-authoritative inventory store.
     *
     * @param \Redis|\Closure(): \Redis $redis 已连接 phpredis 客户端或工厂 Connected phpredis client or factory
     * @param string $prefix 键基前缀（默认 nythros:bag:） Base key prefix (default nythros:bag:)
     */
    public function __construct(\Redis|\Closure $redis, string $prefix = 'nythros:bag:')
    {
        $this->redis = $redis;
        $this->prefix = $prefix;
    }

    /**
     * 增加物品（正数；0/负数异常）。
     * Add a positive amount of an item.
     */
    public function add(string $uid, string $itemId, int $count): void
    {
        $this->assertUid($uid);
        $this->assertItem($itemId);
        if ($count <= 0) {
            throw new \InvalidArgumentException(sprintf('RedisInventoryStore: 增加数量必须为正: %d', $count));
        }

        $this->redis()->hIncrBy($this->bagKey($uid), $itemId, $count);
    }

    /**
     * 移除物品（正数；不足时原子拒绝，返回 false；扣至 0 自动 HDEL）。
     * Remove a positive amount; atomically reject and return false if insufficient; HDEL when reaching zero.
     */
    public function remove(string $uid, string $itemId, int $count): bool
    {
        $this->assertUid($uid);
        $this->assertItem($itemId);
        if ($count <= 0) {
            throw new \InvalidArgumentException(sprintf('RedisInventoryStore: 移除数量必须为正: %d', $count));
        }

        return $this->redis()->eval(
            self::REMOVE_SCRIPT,
            [$this->bagKey($uid), $itemId, (string) $count],
            1,
        ) === 1;
    }

    /**
     * 读取整表。
     * Read the whole item ledger.
     *
     * @return array<string,int> itemId => count
     */
    public function load(string $uid): array
    {
        $this->assertUid($uid);

        $raw = $this->redis()->hGetAll($this->bagKey($uid));
        if (!is_array($raw)) {
            return [];
        }

        $items = [];
        foreach ($raw as $itemId => $count) {
            if (is_string($itemId) && is_numeric($count)) {
                $c = (int) $count;
                if ($c > 0) {
                    $items[$itemId] = $c;
                }
            }
        }

        return $items;
    }

    /**
     * 用给定快照覆盖整表（供 attach 从转移票据/归档恢复，或导出回灌；空数组即清空）。
     * DEL+HMSET 经 pipeline 合并为一次往返——本方法是快照写回路径（会话缓存 flush 点），
     * 热路径每消息不直写 Redis（纪律见 best-practices §1 写回缓冲）。
     * Overwrites the whole table with a snapshot (attach restore from a transfer ticket/archive, or export
     * replay; [] clears). DEL+HMSET merge into one round-trip via a pipeline — this is the snapshot write-back
     * path (session-cache flush points); the hot path never writes Redis per message (see the write-back-buffer
     * discipline in best-practices §1).
     *
     * @param array<string,int> $items itemId => count
     */
    public function save(string $uid, array $items): void
    {
        $this->assertUid($uid);

        $key = $this->bagKey($uid);

        $fields = [];
        foreach ($items as $itemId => $count) {
            $this->assertItem((string) $itemId);
            $c = (int) $count;
            if ($c > 0) {
                $fields[(string) $itemId] = (string) $c;
            }
        }

        $redis = $this->redis();
        if ($fields === []) {
            $redis->del($key);

            return;
        }

        $pipeline = $redis->multi(\Redis::PIPELINE);
        $pipeline->del($key);
        $pipeline->hMSet($key, $fields);
        $pipeline->exec();
    }

    /**
     * 查询某物品数量。
     * Count of a single item.
     */
    public function count(string $uid, string $itemId): int
    {
        $this->assertUid($uid);
        $this->assertItem($itemId);

        $raw = $this->redis()->hGet($this->bagKey($uid), $itemId);

        return is_numeric($raw) ? max(0, (int) $raw) : 0;
    }

    /**
     * 把快照数组水化为进程内 Inventory 对象（attach 恢复消费；无状态工具方法）。
     * Hydrates a snapshot array into an in-process Inventory object (consumed by attach restore; a stateless helper).
     *
     * @param array<string,int> $items itemId => count
     */
    public static function hydrate(array $items): Inventory
    {
        $inventory = new Inventory();
        foreach ($items as $itemId => $count) {
            $c = (int) $count;
            if ($c > 0) {
                $inventory->add((string) $itemId, $c);
            }
        }

        return $inventory;
    }

    /** 移除某 uid 全部背包键（测试/长期离线清理）. Delete the whole bag key for a uid (test / long-offline cleanup). */
    public function delete(string $uid): void
    {
        $this->assertUid($uid);
        $this->redis()->del($this->bagKey($uid));
    }

    /**
     * 背包键访问器（导出管线在共享 pipeline 中直写此键时使用;键构造唯一事实源仍是本类）。
     * The bag-key accessor (used by the export pipeline when writing the key inside a shared pipeline; this class
     * remains the single source of truth for key construction).
     */
    public function keyFor(string $uid): string
    {
        $this->assertUid($uid);

        return $this->bagKey($uid);
    }

    /**
     * 扣减 Lua：原子校验+扣减，扣到 0 HDEL；不足返回 0。
     * Debit Lua: atomic check+debit, HDEL on zero, return 0 when insufficient.
     * KEYS[1]=bag hash, ARGV[1]=itemId, ARGV[2]=count
     */
    private const REMOVE_SCRIPT = <<<'LUA'
local current = tonumber(redis.call('HGET', KEYS[1], ARGV[1]) or '0')
local amount = tonumber(ARGV[2])
if current < amount then
    return 0
end
local left = current - amount
if left == 0 then
    redis.call('HDEL', KEYS[1], ARGV[1])
else
    redis.call('HSET', KEYS[1], ARGV[1], tostring(left))
end
return 1
LUA;

    private function bagKey(string $uid): string
    {
        return $this->prefix . $uid;
    }

    /** @return \Redis 当前进程复用连接（工厂模式首用时建连一次）. Reused per-process connection (factory mode connects once). */
    private function redis(): \Redis
    {
        if ($this->redis instanceof \Redis) {
            return $this->redis;
        }

        $factory = $this->redis;
        $client = $factory();

        // 缓存工厂产物：本进程后续调用复用同一连接（与 CurrencyLedger/RedisQuestStore 一致）
        // Cache factory result: subsequent calls reuse the same connection (consistent with CurrencyLedger/RedisQuestStore)
        $this->redis = $client;

        return $client;
    }

    private function assertUid(string $uid): void
    {
        if (preg_match(self::UID_PATTERN, $uid) !== 1) {
            throw new \InvalidArgumentException(sprintf('RedisInventoryStore: 非法 uid: %s', $uid));
        }
    }

    private function assertItem(string $itemId): void
    {
        if (preg_match(self::ITEM_PATTERN, $itemId) !== 1) {
            throw new \InvalidArgumentException(sprintf('RedisInventoryStore: 非法 itemId: %s', $itemId));
        }
    }
}
