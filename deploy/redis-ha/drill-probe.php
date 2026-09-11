<?php

declare(strict_types=1);

// 定位：deploy/redis-ha/drill-probe.php —— 哨兵切换演练探针（单进程贯穿切换，验证「连接原地重指向」）。
// Located at: deploy/redis-ha/drill-probe.php — the Sentinel failover drill probe (one process spans the
// failover, proving the in-place connection re-targeting).
//
// 断言链（任一失败 → exit 1）：
//  ① 经 RedisConnector 用哨兵解析主库并写入标记，WAIT 1 等副本确认（耐久屏障的语义验证）；
//  ② 触发 SENTINEL FAILOVER，随后周期 refresh——同一 \Redis 对象必须在切换完成后仍能写入（原地重指向）；
//  ③ 写入后读回值必须是最新值，且该值独立可读于**新主**（证明写确实落在新主而非旧主）。
// Assertion chain (any failure → exit 1):
//  1. Resolve the master through RedisConnector via sentinels, write a marker and WAIT 1 for a replica ack
//     (the durability barrier's semantic check);
//  2. Trigger SENTINEL FAILOVER, then refresh periodically — the SAME \Redis object must still write once the
//     failover completes (in-place re-targeting);
//  3. The read-back must show the newest value, and that value must be independently readable on the NEW
//     master (proving the write landed there, not on the old one).
//
// 由 failover-drill.sh 调用（环境变量：NYTHROS_REDIS_SENTINELS / NYTHROS_REDIS_MASTER /
// NYTHROS_REDIS_PASSWORD / NYTHROS_REDIS_SENTINEL_PASSWORD）。
// Invoked by failover-drill.sh (env: NYTHROS_REDIS_SENTINELS / NYTHROS_REDIS_MASTER / NYTHROS_REDIS_PASSWORD /
// NYTHROS_REDIS_SENTINEL_PASSWORD).

require __DIR__ . '/../../vendor/autoload.php';

use Nythros\Framework\Cluster\RedisConnector;

// 等待上限取 60s：哨兵在 tilt 模式（时钟跳变保护，WSL2 上会间歇触发，最多延迟 30s）下切换会明显变慢，
// 演练关注的是「切换后连接自愈」这一事实，耗时只作观测值输出，不作为失败判据。
// The wait bound is 60s: in tilt mode (the clock-jump guard, intermittently triggered on WSL2, delaying up to
// 30s) a failover gets noticeably slower — the drill asserts the self-healing FACT and reports timing as an
// observation, never as a failure criterion.
const HEAL_DEADLINE_SECONDS = 60.0;
const PROGRESS_INTERVAL_SECONDS = 10.0;
const POLL_INTERVAL_MICROSECONDS = 200000;

/**
 * 单调时钟（秒）：所有 elapsed/超时判断必须用单调钟——WSL2 墙钟每 ~34s 向前跳 ~1.85s（同一现象让哨兵
 * 持续进入 tilt 模式、切换最长延迟 30s），用 microtime 计时会把钟跳算成「等待超时」而推出假失败。
 * Monotonic clock (s): every elapsed/timeout check must use it — the WSL2 wall clock jumps forward ~1.85s
 * every ~34s (the same phenomenon that keeps Sentinel in tilt mode, delaying failovers up to 30s), so
 * microtime-based timing counts clock jumps as elapsed wait and yields false failures.
 */
function drillNow(): float
{
    return hrtime(true) / 1e9;
}

$sentinelList = getenv(RedisConnector::ENV_SENTINELS);
$masterName = getenv(RedisConnector::ENV_MASTER) ?: RedisConnector::DEFAULT_MASTER_NAME;
if (!is_string($sentinelList) || trim($sentinelList) === '') {
    fwrite(STDERR, "[drill] fatal: 未设置 NYTHROS_REDIS_SENTINELS（由 failover-drill.sh 注入）\n");
    exit(1);
}

$sentinelEndpoints = array_values(array_filter(array_map('trim', explode(',', $sentinelList))));
$firstSentinel = explode(':', $sentinelEndpoints[0], 2);
$sentinelHost = $firstSentinel[0];
$sentinelPort = isset($firstSentinel[1]) ? (int) $firstSentinel[1] : 26379;

// NYTHROS_HA_DRILL_DEBUG=1：逐轮打印 refresh 耗时与解析地址（哨兵假死/VM 调度停顿的归因工具）。
// NYTHROS_HA_DRILL_DEBUG=1: per-iteration refresh timing and resolved address (the attribution tool for
// sentinel stalls / VM scheduling pauses).
$debug = getenv('NYTHROS_HA_DRILL_DEBUG') === '1';

$connector = RedisConnector::fromEnv('127.0.0.1', 6379);

// ── ① 解析主库 + 写标记 + 副本确认 ── Resolve the master, write the marker, await a replica ack
$marker = 'nythros:ha:drill:' . bin2hex(random_bytes(6));

try {
    $redis = $connector->client();
} catch (\RuntimeException $e) {
    fwrite(STDERR, sprintf("[drill] FAIL: 无法建立连接：%s\n", $e->getMessage()));
    exit(1);
}

$before = $connector->masterAddress();
$redis->set($marker, 'v1');
$acked = $redis->wait(1, 500);
printf(
    "[drill] ① 标记已写入 %s；WAIT 1 → %s 个副本确认；当前主库 %s:%d\n",
    $marker,
    var_export($acked, true),
    $before[0],
    $before[1],
);

if (!is_int($acked) || $acked < 1) {
    fwrite(STDERR, "[drill] FAIL: 写入未获得副本确认（检查从库链路与 min-replicas 配置；耐久断言不可靠）\n");
    exit(1);
}

// ── ② 触发切换 + 周期 refresh，等同一连接恢复写入 ── Trigger the failover, refresh periodically, wait for the same connection to write again
$sentinelOptions = ['host' => $sentinelHost, 'port' => $sentinelPort];
$sentinelPassword = getenv(RedisConnector::ENV_SENTINEL_PASSWORD);
if (is_string($sentinelPassword) && $sentinelPassword !== '') {
    $sentinelOptions['auth'] = $sentinelPassword;
}

try {
    (new \RedisSentinel($sentinelOptions))->failover($masterName);
} catch (\Throwable $e) {
    fwrite(STDERR, sprintf("[drill] FAIL: 触发哨兵切换失败（%s:%d）：%s\n", $sentinelHost, $sentinelPort, $e->getMessage()));
    exit(1);
}

$triggeredAt = drillNow();
$switchedAt = null;
$healedAt = null;
$lastError = '';
$nextProgressAt = $triggeredAt + PROGRESS_INTERVAL_SECONDS;

while ((drillNow() - $triggeredAt) < HEAL_DEADLINE_SECONDS) {
    $iterationStartedAt = drillNow();
    try {
        $connector->refresh(force: true);
    } catch (\Throwable $e) {
        $lastError = $e->getMessage();
    }
    if ($debug) {
        fprintf(
            STDERR,
            "[drill][debug] t=%.2fs refresh=%.2fs addr=%s:%d err=%s\n",
            $iterationStartedAt - $triggeredAt,
            drillNow() - $iterationStartedAt,
            $connector->masterAddress()[0],
            $connector->masterAddress()[1],
            $lastError === '' ? '无' : $lastError,
        );
    }

    if ($switchedAt === null && $connector->masterAddress() !== $before) {
        $switchedAt = drillNow();
        printf(
            "[drill] ② 哨兵切换已生效：新主 %s:%d（触发后 %.1fs）\n",
            $connector->masterAddress()[0],
            $connector->masterAddress()[1],
            $switchedAt - $triggeredAt,
        );
    }

    // 写入判定必须在「切换已生效」之后：切换前写旧主当然会成功，那不是自愈的证据。
    // The write check runs only AFTER the switch took effect: writing to the old master before it is trivially
    // successful and proves nothing about self-healing.
    if ($switchedAt !== null) {
        try {
            $setStartedAt = drillNow();
            if ($redis->set($marker, 'v2') === true) {
                $readBack = $redis->get($marker);
                if ($readBack === 'v2') {
                    $healedAt = drillNow();
                    if ($debug) {
                        fprintf(STDERR, "[drill][debug] 写+读回成功（set 耗时 %.2fs）\n", drillNow() - $setStartedAt);
                    }
                    break;
                }
                $lastError = sprintf('写后读回不一致（期望 v2，实得 %s）——可能仍指向旧主', var_export($readBack, true));
            } else {
                // set 返回 false（NOREPLICAS 等拒写）时 phpredis 不抛异常，错误在 getLastError。
                // A false set (NOREPLICAS-style refusal) does not throw in phpredis — the reason lives in getLastError().
                $lastError = sprintf('写入被拒（%s）', (string) $redis->getLastError());
            }
            if ($debug) {
                fprintf(STDERR, "[drill][debug] set 耗时 %.2fs err=%s\n", drillNow() - $setStartedAt, $lastError === '' ? '无' : $lastError);
            }
        } catch (\Throwable $e) {
            $lastError = $e->getMessage();
            if ($debug) {
                fprintf(STDERR, "[drill][debug] set 抛错（耗时 %.2fs）：%s\n", drillNow() - $setStartedAt, $lastError);
            }
        }
    } else {
        $lastError = '（等待切换生效，尚未开始写入判定）';
    }

    if (drillNow() >= $nextProgressAt) {
        $nextProgressAt = drillNow() + PROGRESS_INTERVAL_SECONDS;
        printf(
            "[drill] ... 等待切换生效（已 %.0fs；哨兵 tilt 模式可能延迟至 30s；当前解析 %s:%d；最近错误：%s）\n",
            drillNow() - $triggeredAt,
            $connector->masterAddress()[0],
            $connector->masterAddress()[1],
            $lastError === '' ? '无' : $lastError,
        );
    }

    usleep(POLL_INTERVAL_MICROSECONDS);
}

if ($switchedAt === null) {
    fwrite(STDERR, sprintf("[drill] FAIL: %.0fs 内哨兵未切换主库（sentinel log 见运行目录）\n", HEAL_DEADLINE_SECONDS));
    exit(1);
}
if ($healedAt === null) {
    fwrite(STDERR, sprintf("[drill] FAIL: 切换后 %.0fs 内原连接未恢复可写：%s\n", HEAL_DEADLINE_SECONDS, $lastError));
    exit(1);
}

printf("[drill] ③ 原连接恢复写入（切换后 %.1fs，总历时 %.1fs）\n", $healedAt - $switchedAt, $healedAt - $triggeredAt);

// ── ③ 独立连接直达新主复核 ── Independently verify the marker on the new master
$master = $connector->masterAddress();
$direct = new \Redis();
$directOptions = [];
$password = getenv(RedisConnector::ENV_PASSWORD);
if (is_string($password) && $password !== '') {
    $directOptions['auth'] = $password;
}
$connected = @$direct->connect($master[0], $master[1], 1.0);
if ($connected !== true) {
    fwrite(STDERR, sprintf("[drill] FAIL: 无法直连新主 %s:%d 复核\n", $master[0], $master[1]));
    exit(1);
}
if (isset($directOptions['auth'])) {
    @$direct->auth($directOptions['auth']);
}
$onNewMaster = $direct->get($marker);
if ($onNewMaster !== 'v2') {
    fwrite(STDERR, sprintf("[drill] FAIL: 新主 %s:%d 上标记值异常：%s\n", $master[0], $master[1], var_export($onNewMaster, true)));
    exit(1);
}

// 清理：删除演练标记（新主上删除，逐出后从库随之同步）
$direct->del($marker);
$direct->close();

printf(
    "[drill] PASS：主从切换 + 连接自愈 + 副本确认耐久链全部成立（切换检测 %.1fs，自愈 %.1fs）\n",
    $switchedAt - $triggeredAt,
    $healedAt - $triggeredAt,
);
exit(0);
