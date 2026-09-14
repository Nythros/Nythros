<?php

declare(strict_types=1);

namespace Nythros\Framework\Social;

use Nythros\Cluster\ServiceInstance;
use Nythros\Cluster\ServiceRegistryInterface;

/**
 * map 频道分配器：auth 登录与 map:enter 重入共用的选频道过滤（ADR-015 §1.4⑤ / §1.7②）。
 * discover('map') → mapId 过滤 → 排除 stopping/draining 与满员实例 → 恢复模式优先原频道，
 * 未命中/新登录取最少在线；channelId 解析（meta 优先，回退 serviceId 编码）也在此收口。
 * The map channel assigner: the shared channel-selection filter for auth login and map:enter re-entry
 * (ADR-015 §1.4⑤ / §1.7②). discover('map') → mapId filter → skip stopping/draining and at-capacity
 * instances → recovery prefers the original channel, miss/fresh login takes least-loaded; channelId
 * resolution (meta wins, serviceId encoding as fallback) is funnelled through here too.
 *
 * @internal SocialService 的内部协作件，随门面公开面冻结；不属于公开 API。
 *           An internal collaborator of SocialService, frozen behind its facade; not part of the public API.
 */
final class ChannelSelector
{
    public function __construct(
        private readonly ServiceRegistryInterface $registry,
    ) {
    }

    /**
     * 选频道：恢复模式优先原频道（未命中/新登录 → 最少在线）。
     * Channel selection: recovery prefers the original channel (miss / fresh login → least-loaded).
     *
     * @param string $mapId 目标地图 id The target map id.
     * @param ?array{mapId: string, channelId: string, x: ?float, y: ?float, updatedAt: float} $location 恢复用位置快照；null = 新登录 Location snapshot for recovery; null = fresh login.
     * @return ?ServiceInstance 选中实例；无可分配 null Selected instance; null when none assignable.
     */
    public function select(string $mapId, ?array $location): ?ServiceInstance
    {
        // P16 动态扩缩容路由过滤：draining/stopping 实例不再接入新会话；声明了 maxCapacity 的实例
        // 达顶即跳过（auth 侧另有硬守卫兜住 select 与 attach 之间的并发窗口）。
        // The P16 dynamic-scaling routing filter: draining/stopping instances take no new sessions; instances
        // declaring maxCapacity are skipped once at the cap (the auth-side hard guard backstops the concurrent
        // window between select and attach).
        // 注册表读失败归一为「无可用频道」（故障演练 redis-down 的契约确定性）：discover 在 Redis
        // 不可用时可能 throw（phpredis 异常 / 空 hash 读失败 RuntimeException），裸传会落到 dispatch
        // catch-all 的通用 500——与实测观察到的 auth_failed 503 "no available channel" 降级契约不一致。
        // 归一 + 日志归因，保证宕机窗口内登录一律走 503、恢复后心跳重注册即自愈。
        // Registry read failures normalize to "no available channel" (the redis-down drill contract): discover
        // may throw when Redis is unavailable, and letting it propagate lands on the dispatch catch-all's
        // generic 500 — inconsistent with the measured auth_failed 503 "no available channel" degradation.
        // Normalizing (with an attribution log) guarantees 503s during the outage and self-heal on heartbeat
        // re-registration after recovery.
        try {
            $discovered = $this->registry->discover('map');
        } catch (\Throwable $e) {
            error_log(sprintf('[Social] discover failed: mapId=%s err=%s', $mapId, $e->getMessage()));

            return null;
        }
        $channels = array_filter(
            $discovered,
            static fn (ServiceInstance $instance): bool => ($instance->meta['mapId'] ?? null) === $mapId
                && !in_array(($instance->meta['status'] ?? 'serving'), ['stopping', 'draining'], true)
                && !(
                    is_int($instance->meta['maxCapacity'] ?? null)
                    && $instance->meta['maxCapacity'] > 0
                    && (int) ($instance->meta['playerCount'] ?? 0) >= $instance->meta['maxCapacity']
                ),
        );
        if ($channels === []) {
            return null;
        }

        if ($location !== null) {
            $channelId = $location['channelId'];
            foreach ($channels as $instance) {
                if ($this->channelIdOf($instance) === $channelId) {
                    return $instance;
                }
            }
            // 原频道已死/stopping → 最少在线
        }

        return $this->minPlayerCount($channels);
    }

    /**
     * 取实例 channelId：meta.channelId 优先，缺失时从 serviceId 编码解析（{mapId}#{channelId}，最后一个 # 之后）。
     * Resolve an instance's channelId: meta.channelId wins; when absent, parse the serviceId encoding ({mapId}#{channelId}, after the last #).
     */
    public function channelIdOf(ServiceInstance $instance): string
    {
        $channelId = $instance->meta['channelId'] ?? null;
        if (is_string($channelId) && $channelId !== '') {
            return $channelId;
        }

        $hash = strrpos($instance->id, '#');

        return $hash === false ? $instance->id : substr($instance->id, $hash + 1);
    }

    /**
     * 最少在线选频道：取过滤结果中 playerCount 最小的实例（playerCount 缺失视为满员，避免被误选）。
     * Least-loaded selection: the instance with the smallest playerCount (a missing playerCount counts as fully loaded, never mis-picked).
     *
     * @param array<string, ServiceInstance> $channels 已过滤的存活频道（非空） Filtered live channels (non-empty).
     */
    private function minPlayerCount(array $channels): ServiceInstance
    {
        $best = null;
        $bestCount = PHP_INT_MAX;
        foreach ($channels as $instance) {
            $count = (int) ($instance->meta['playerCount'] ?? PHP_INT_MAX);
            if ($count < $bestCount) {
                $best = $instance;
                $bestCount = $count;
            }
        }

        if ($best === null) {
            // 不可达：调用方已判空过滤结果 Unreachable: the caller already rejected an empty filtered set
            throw new \LogicException('channels must not be empty');
        }

        return $best;
    }
}
