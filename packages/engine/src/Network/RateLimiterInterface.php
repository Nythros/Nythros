<?php

declare(strict_types=1);

namespace Nythros\Network;

/**
 * 限流器抽象：按连接维度消费令牌，用于防刷/流量整形。
 * Rate limiter abstraction: consumes tokens per connection for anti-flood / traffic shaping.
 */
interface RateLimiterInterface
{
    /**
     * 为指定连接消费令牌。
     * Consumes tokens for the given connection.
     *
     * @param string $connectionId 连接标识符 Connection identifier.
     * @param int $tokens 要消费的令牌数量 Number of tokens to consume.
     * @return bool false = 超限（令牌不足） false = over limit (insufficient tokens).
     */
    public function consume(string $connectionId, int $tokens = 1): bool;

    /**
     * 断连时释放指定连接的令牌桶。
     * Releases the given connection's token bucket on disconnect.
     *
     * @param string $connectionId 连接标识符 Connection identifier.
     */
    public function forget(string $connectionId): void;
}
