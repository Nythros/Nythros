<?php

declare(strict_types=1);

namespace Nythros\Framework\Cluster;

/**
 * 副本确认屏障（ADR-031 §3）：经济域权威写返回前的 `WAIT 1 <timeout>` 耐久加固。
 * Replica-acknowledgement barrier (ADR-031 §3): a `WAIT 1 <timeout>` durability hardening before economic
 * authoritative writes return.
 *
 * 解决的问题：Redis 复制是异步的，主从切换会丢「最后几毫秒的写」。token/位置快照/票据本就是短 TTL
 * 可失（ADR-028 已记录），但**金钱/背包/邮件/拍卖**的丢失是事故。启用后，这些写返回前等至少 1 个
 * 副本确认，把丢失窗口压到「未及确认的写」——配 `min-replicas-to-write 1` 的主库侧护栏构成双保险。
 * The problem: Redis replication is asynchronous, so a failover drops the last few ms of writes. Tokens /
 * location snapshots / tickets are short-TTL and lossy by design (ADR-028), but losing money / inventory /
 * mail / auction state is an incident. When enabled, those writes wait for at least one replica ack before
 * returning, shrinking the loss window to "writes not yet acked" — paired with the master-side
 * `min-replicas-to-write 1` guard as a second line of defence.
 *
 * 边界（必须知情）：① WAIT 只保证「此刻至少一个副本已收到」，不保证该副本必被提升为主，也不保证
 * 零丢失——它是窗口收窄，不是事务；② 单实例/无从库环境启用会让每次写阻塞满 timeout，故缺省关闭，
 * 仅由装配层按 `NYTHROS_REDIS_AWAIT_REPLICAS=1` 显式开启（HA 部署与演练）；③ 屏障失败不改变写入
 * 结果（写已提交主库），只记日志（进程内 5s 限流），绝不因屏障把成功的写判成失败。
 * Boundaries (must know): (1) WAIT only guarantees a replica received the write at that instant — not that
 * the replica gets promoted, and not zero loss; it narrows the window, it is not a transaction; (2) enabling
 * it against a single-instance / replica-less Redis blocks every write for the full timeout, so it is OFF by
 * default and turned on explicitly by the assembly layer via NYTHROS_REDIS_AWAIT_REPLICAS=1 (HA deployments
 * and drills); (3) a barrier failure never changes the write's outcome (the write is already committed on the
 * master) — it only logs (throttled to once per 5s per process), and a successful write is never reported as
 * failed because of the barrier.
 */
final class ReplicaBarrier
{
    /** 启用开关环境变量（'1' = 启用；缺省关闭）。 Enable-flag env var ('1' = on; off by default). */
    public const ENV_FLAG = 'NYTHROS_REDIS_AWAIT_REPLICAS';

    /** 缺省等待上限（毫秒；LAN 副本确认通常在 1ms 内，100ms 是余量）。 Default wait bound (ms; a LAN replica acks within ~1ms, 100ms is headroom). */
    public const DEFAULT_TIMEOUT_MS = 100;

    /** 超时日志限流窗口（秒；避免无副本环境刷日志）。 Timeout-log throttle window (s; keeps a replica-less environment from flooding the log). */
    private const LOG_INTERVAL_SECONDS = 5.0;

    private static bool $enabled = false;

    private static int $timeoutMs = self::DEFAULT_TIMEOUT_MS;

    private static float $lastLogAt = 0.0;

    /**
     * 配置屏障（装配层按环境变量调用一次；测试可直接调用）。
     * Configures the barrier (the assembly layer calls it once from the environment variable; tests call it directly).
     */
    public static function configure(bool $enabled, int $timeoutMs = self::DEFAULT_TIMEOUT_MS): void
    {
        self::$enabled = $enabled;
        self::$timeoutMs = max(1, $timeoutMs);
    }

    /** 环境开关读取（NYTHROS_REDIS_AWAIT_REPLICAS=1）。 Reads the env flag (NYTHROS_REDIS_AWAIT_REPLICAS=1). */
    public static function enabledFromEnv(): bool
    {
        $flag = getenv(self::ENV_FLAG);

        return is_string($flag) && trim($flag) === '1';
    }

    /** 是否启用。 Whether the barrier is enabled. */
    public static function enabled(): bool
    {
        return self::$enabled;
    }

    /** 复位（测试隔离用）。 Resets state (test isolation). */
    public static function reset(): void
    {
        self::$enabled = false;
        self::$timeoutMs = self::DEFAULT_TIMEOUT_MS;
        self::$lastLogAt = 0.0;
    }

    /**
     * 等待至少 1 个副本确认（未启用或抛错时为无操作/仅日志）。
     * Waits for at least one replica acknowledgement (a no-op / log-only call when disabled or failing).
     *
     * @param \Redis $redis 已连接客户端（写路径所用的同一条连接） The connected client (the same connection the write used).
     * @param ?int $timeoutMs 覆盖等待上限（毫秒；null = 配置值） Overrides the wait bound (ms; null = the configured value).
     */
    public static function await(\Redis $redis, ?int $timeoutMs = null): void
    {
        if (!self::$enabled) {
            return;
        }

        $timeout = $timeoutMs ?? self::$timeoutMs;
        try {
            $acked = $redis->wait(1, $timeout);
        } catch (\Throwable $e) {
            self::logThrottled(sprintf('WAIT 不可用（不影响写入结果）：%s', $e->getMessage()));

            return;
        }

        if ($acked === false || (int) $acked < 1) {
            self::logThrottled(sprintf(
                'WAIT %dms 内无副本确认（写已提交主库；主从切换存在丢失窗口，检查副本健康与 min-replicas-to-write）',
                $timeout,
            ));
        }
    }

    private static function logThrottled(string $message): void
    {
        // 单调钟（秒）：限流是间隔测量——WSL2 墙钟会周期跳变（实测每 ~34s 跳 ~1.85s），microtime 会让限流漂移。
        // Monotonic clock (s): the throttle is an interval measurement — the WSL2 wall clock jumps periodically
        // (measured ~1.85s every ~34s), so microtime would make it drift.
        $now = hrtime(true) / 1e9;
        if (($now - self::$lastLogAt) < self::LOG_INTERVAL_SECONDS) {
            return;
        }
        self::$lastLogAt = $now;
        error_log('[ReplicaBarrier] ' . $message);
    }
}
