<?php

declare(strict_types=1);

namespace Nythros\Framework\Cluster;

/**
 * 转移票据的 Redis 存储（ADR-025）：SETEX 覆盖导出 + Lua GET+DEL 原子消费。
 * The Redis ticket store (ADR-025): SETEX-overwrite export + Lua GET+DEL atomic consume.
 *
 * 键族：`{prefix}transfer:{uid}`——与 token（nythros:token:）/registry（nythros:svc:）严格分离；
 * TTL 兜底（缺省 30s）：源端导出后客户端未在窗口内完成重连，票据过期回落全新入场。
 * Key family: `{prefix}transfer:{uid}` — strictly separated from tokens (nythros:token:) and the registry
 * (nythros:svc:); the TTL backstop (default 30s): if the client fails to reconnect within the window after
 * the source's export, the ticket expires and the entry degrades to a fresh one.
 *
 * 多进程口径与 RedisTokenStore 同源：工厂闭包注入，fork 后各 worker 各自 lazy 建连。
 * The multi-process convention matches RedisTokenStore: a factory closure injected, each forked worker
 * lazily builds its own connection.
 */
final class RedisPlayerTransferStore implements PlayerTransferStoreInterface
{
    /** GET+DEL 原子消费脚本（版本兼容：不依赖 Redis 6.2 GETDEL，eval 两键一 Lua 等价原子）。 The atomic GET+DEL consume script (version-compatible: no Redis 6.2 GETDEL dependency; one Lua script is equivalently atomic). */
    private const CONSUME_SCRIPT = <<<'LUA'
local v = redis.call('GET', KEYS[1])
if v then
    redis.call('DEL', KEYS[1])
end
return v
LUA;

    /** @var \Redis|\Closure(): \Redis 已连接客户端或连接工厂（fork 后 lazy 建连） A connected client or a connection factory (lazy after fork). */
    private \Redis|\Closure $redis;

    /**
     * @param \Redis|\Closure(): \Redis $redis 已连接 phpredis 客户端，或返回客户端的工厂 A connected phpredis client, or a factory returning one.
     * @param string $prefix 键基前缀（测试注入隔离前缀） The base key prefix (tests inject an isolated one).
     * @param int $ttlSeconds 票据存活秒数 The ticket lifetime in seconds.
     */
    public function __construct(
        \Redis|\Closure $redis,
        private readonly string $prefix = 'nythros:',
        private readonly int $ttlSeconds = 30,
    ) {
        $this->redis = $redis;
    }

    public function export(string $uid, array $snapshot): void
    {
        $redis = $this->resolve();
        $redis->setex($this->key($uid), $this->ttlSeconds, json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public function consume(string $uid): ?array
    {
        $redis = $this->resolve();
        $raw = $redis->eval(self::CONSUME_SCRIPT, [$this->key($uid)], 1);
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        $snapshot = json_decode($raw, true);

        return is_array($snapshot) ? $snapshot : null;
    }

    private function key(string $uid): string
    {
        return $this->prefix . 'transfer:' . $uid;
    }

    private function resolve(): \Redis
    {
        $redis = $this->redis;
        if ($redis instanceof \Closure) {
            $redis = $redis();
            $this->redis = $redis;
        }

        return $redis;
    }
}
