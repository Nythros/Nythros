<?php

declare(strict_types=1);

namespace Nythros\Framework\Observability;

use Nythros\Contracts\PerfSnapshotProviderInterface;

/**
 * 运行期性能采样器（组装层通用件）：周期性读取引擎性能快照，把指标写入 Redis 供观测端查询。
 * 职责分层：引擎只管记账（探针零依赖），本采样器是唯一接触 Redis 的一方——按业务层注入的
 * Redis 连接工厂写 Hash（计数器/直方图/累计值）与 HLL（唯一连接与实体估数）。
 * 依赖口径（ADR-023 D2）：快照来源经 PerfSnapshotProviderInterface 契约注入，
 * 不直依引擎 @internal 实现类；组装层负责绑定具体探针。
 *
 * 键约定（同一服务实例内自洽；跨实例聚合由观测端按 serviceId 前缀扫描做）：
 * Runtime performance sampler (an assembly-layer component): periodically reads the engine performance snapshot and
 * writes the metrics into Redis for observability. Layer separation: the engine only books metrics (the probe has
 * zero dependencies); this sampler is the only component touching Redis — it writes Hash (counters/histograms/totals)
 * and HLL (unique-connection/entity estimates) through the business-injected Redis factory.
 * Dependency rule (ADR-023 D2): the snapshot source is injected via the PerfSnapshotProviderInterface contract,
 * never as a direct dependency on an @internal engine class; the assembly layer binds the concrete probe.
 *
 *   nythros:perf:{serviceId}:counters   Hash events => count           （单调累计，观测端取差值） monotonic sums (observers diff between snapshots)
 *   nythros:perf:{serviceId}:hist       Hash metric.bucket => count
 *   nythros:perf:{serviceId}:totals     Hash metric => sum ms
 *   nythros:perf:{serviceId}:unique     HLL（PFADD）抽样 uid/conn 唯一基数   HLL (PFADD) unique uid/conn cardinality
 *   nythros:perf:{serviceId}:last       String JSON 最近快照时间戳+业务指标   last snapshot timestamp + business metrics as JSON
 */
final class PerfSampler
{
    /**
     * 构造采样器。
     * Creates the sampler.
     *
     * @param PerfSnapshotProviderInterface $probe 性能快照供给者（组装层绑定具体探针） Performance snapshot provider (the assembly layer binds the concrete probe).
     * @param \Closure(): \Redis $redisFactory Redis 连接工厂（fork 后 lazy 建连，首采样建连一次后复用） Redis connection factory (lazily connected once on first sample, then reused).
     * @param string $serviceId 实例标识（如 map-1#ch-1） Instance id (e.g. map-1#ch-1).
     * @param int $sampleSeconds 采样间隔（秒） Sampling interval in seconds.
     */
    public function __construct(
        private readonly PerfSnapshotProviderInterface $probe,
        private readonly \Closure $redisFactory,
        private readonly string $serviceId,
        private readonly int $sampleSeconds = 5,
    ) {
    }

    /**
     * @var \Redis|null 记忆化连接（照 RedisFriendStore/AuctionStore 先例:工厂产物本进程复用）。
     *                  不设属性类型以兼容测试 Fake 注入(工厂签名 \Closure(): \Redis 为 phpstan 口径)。
     * Memoized connection (the RedisFriendStore/AuctionStore precedent: the factory result is reused per process).
     * Untyped on purpose so test fakes can be injected (the \Closure(): \Redis factory signature is the phpstan contract).
     */
    private $client = null;

    /** 执行一次采样：读探针快照 → 写 Redis。 Runs one sample: collect the probe snapshot → write Redis. */
    public function sample(): void
    {
        // 真实时钟采样间隔门控（休眠在调用方定时器里做，这里只保证不空转）
        // The real sampling gate lives in the caller's timer; here we only make sure a fresh read happens.
        $snapshot = $this->probe->collect();
        if ($snapshot['counters'] === [] && $snapshot['histograms'] === [] && $snapshot['totals'] === []) {
            return; // 无活动：不写 Redis（静默期零开销） No activity: skip Redis (silent periods cost nothing)
        }

        try {
            // 旧行为：每 5s 经工厂新建连接（connect+auth+select ≈3 RTT + 连接churn）——与全部 store 的
            // 记忆化模式不一致；现照先例缓存,失败时丢弃连接令下一轮重建（保持原「每轮新建」的自愈性）
            // Previously every sample opened a fresh connection (connect+auth+select ≈3 RTTs plus churn),
            // inconsistent with every store's memoization; the cached client is dropped on failure so the next
            // round reconnects (preserving the self-healing the old per-sample connect implicitly had)
            $redis = $this->client ??= ($this->redisFactory)();

            $pipeline = $redis->multi(\Redis::PIPELINE);

            // 计数器：按事件名累加进 Hash（老值读一次 + 写新值 = 单调累计；采样窗口清零由契约 collect 语义保证）
            // Counters: accumulate per event into a Hash (read old + write new = monotonic; the sampling window is reset by the contract's collect semantics)
            $countersKey = 'nythros:perf:' . $this->serviceId . ':counters';
            foreach ($snapshot['counters'] as $event => $count) {
                $pipeline->hIncrBy($countersKey, $event, $count);
            }

            // 直方图：metric.bucket => count 累加
            // Histograms: metric.bucket => count accumulated
            $histKey = 'nythros:perf:' . $this->serviceId . ':hist';
            foreach ($snapshot['histograms'] as $metric => $buckets) {
                foreach ($buckets as $bucket => $count) {
                    $pipeline->hIncrBy($histKey, $metric . '.' . $bucket, $count);
                }
            }

            // 累计值：metric => 累计毫秒（观测端可推算均值 = totals / counters 对应事件数）
            // Totals: metric => accumulated ms (observers derive the mean as totals / the matching counter)
            $totalsKey = 'nythros:perf:' . $this->serviceId . ':totals';
            foreach ($snapshot['totals'] as $metric => $ms) {
                $pipeline->hIncrByFloat($totalsKey, $metric, $ms);
            }

            // 最近快照时间戳
            $pipeline->set('nythros:perf:' . $this->serviceId . ':last', json_encode([
                'ts' => microtime(true),
                'serviceId' => $this->serviceId,
            ], JSON_UNESCAPED_UNICODE));

            $pipeline->exec();
        } catch (\Throwable $e) {
            // 采样失败只记日志，绝不抛给上游（探针不能拖垮游戏主循环）；并丢弃缓存连接令下一轮重连
            // Sampling failures are logged only and never thrown upstream (probes must never stall the game
            // loop); the cached connection is dropped so the next round reconnects
            $this->client = null;
            error_log(sprintf('[PerfSampler] sample failed: %s', $e->getMessage()));
        }
    }

    /** 采样间隔（秒）：供调用方注册定时器。 Sampling interval in seconds: for the caller's timer registration. */
    public function intervalSeconds(): int
    {
        return $this->sampleSeconds;
    }
}
