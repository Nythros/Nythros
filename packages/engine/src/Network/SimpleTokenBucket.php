<?php

declare(strict_types=1);

namespace Nythros\Network;

/**
 * 简单令牌桶限流器：按连接维度独立维护令牌，匀速补充、超限拒绝。
 * Simple token bucket rate limiter: each connection keeps its own bucket that refills at a steady rate and rejects over-limit requests.
 * @internal 引擎内部实现，非公开 API。Engine-internal implementation, not part of the public API.
 */
final class SimpleTokenBucket implements RateLimiterInterface
{
    /** @var array<string, array{tokens: float, lastRefill: float}> 各连接的令牌桶状态 token bucket state per connection. */
    private array $buckets = [];

    /** @var callable(): float 时钟函数（可注入，便于测试） Clock function (injectable for testing). */
    private $clock;

    /**
     * 构造令牌桶。
     * Creates a token bucket.
     *
     * @param float $refillPerSecond 每秒补充的令牌数 Number of tokens refilled per second.
     * @param int $capacity 桶容量（令牌上限） Bucket capacity (token ceiling).
     * @param null|callable(): float $clock 可选时钟注入（缺省 microtime(true)） Optional clock injection (defaults to microtime(true)).
     */
    public function __construct(
        private readonly float $refillPerSecond,
        private readonly int $capacity,
        ?callable $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): float => microtime(true);
    }

    /**
     * 为指定连接消费指定数量的令牌。
     * Consumes the given number of tokens for the connection.
     *
     * @param string $connectionId 连接标识符 Connection identifier.
     * @param int $tokens 要消费的令牌数量 Number of tokens to consume.
     * @return bool true 表示消费成功；false 表示令牌不足或数量非法 true on success; false on insufficient tokens or invalid count.
     */
    public function consume(string $connectionId, int $tokens = 1): bool
    {
        // 非法请求量直接拒绝 invalid request count is rejected outright
        if ($tokens <= 0) {
            return false;
        }

        $now = ($this->clock)();
        // 首次访问按满桶初始化，lastRefill 记为当前时间 first access initializes a full bucket with lastRefill set to now
        $bucket = $this->buckets[$connectionId] ?? ['tokens' => (float) $this->capacity, 'lastRefill' => $now];

        // 先按流逝时间补充令牌，再记录本次补充时间点 refill tokens by elapsed time, then record the refill timestamp
        $elapsed = $now - $bucket['lastRefill'];
        $bucket['tokens'] = min((float) $this->capacity, $bucket['tokens'] + $elapsed * $this->refillPerSecond);
        $bucket['lastRefill'] = $now;

        // 令牌不足：持久化已补充的状态并拒绝 insufficient tokens: persist the refilled state and reject
        if ($bucket['tokens'] < $tokens) {
            $this->buckets[$connectionId] = $bucket;

            return false;
        }

        // 扣除令牌并持久化 deduct tokens and persist
        $bucket['tokens'] -= $tokens;
        $this->buckets[$connectionId] = $bucket;

        return true;
    }

    /**
     * 断连时释放指定连接的令牌桶：防止连接反复重连时 $buckets 无限增长；释放后同 id 重新消费按满桶初始化。
     * Releases the given connection's token bucket on disconnect: prevents $buckets from growing unbounded across reconnects; a subsequent consume with the same id re-initializes a full bucket.
     *
     * @param string $connectionId 连接标识符 Connection identifier.
     */
    public function forget(string $connectionId): void
    {
        unset($this->buckets[$connectionId]);
    }
}
