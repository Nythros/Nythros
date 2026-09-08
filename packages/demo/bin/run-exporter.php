<?php

declare(strict_types=1);

// 定位：packages/demo/bin/run-exporter.php — storage-exporter 导出进程（「Redis 管热数据、MySQL 只落盘」模型）。
// 消费 Redis Stream（RedisExportPipeline 发布的脏玩家快照），按 uid 最新态 upsert 到 MySQL 归档表；
// 游戏 worker 进程内因此零 PDO,MySQL 抖动从「帧延迟问题」降级为「落盘延迟问题」。
// 拓扑：deploy.yaml 声明 `type: storage` 的 service,由 bin/server spawn 本脚本。
//
// Located at: packages/demo/bin/run-exporter.php — the storage-exporter process ("Redis holds hot data, MySQL is
// only the durable sink"). Consumes the Redis Stream that RedisExportPipeline publishes (dirty player snapshots)
// and upserts the latest state per uid into the MySQL archive table, so game workers hold zero PDO — MySQL latency
// demotes from a frame-latency problem to an export-latency problem.
// Topology: a deploy.yaml service with `type: storage`; bin/server spawns this script.
//
// 用法 / Usage:
//   php packages/demo/bin/run-exporter.php --redisHost=127.0.0.1 --redisPort=6379 \
//     --mysqlHost=127.0.0.1 --mysqlPort=3306 --mysqlUser=root --mysqlPass= --mysqlDb=nythros \
//     [--streamKey=nythros:export:players] [--group=storage-exporter] [--port=18300 --pidFile=...]
//   php packages/demo/bin/run-exporter.php --self-test   （离线自检：纯函数,不连真库真 Redis）
//
// 一致性语义（单消费者前提,count>1 被强制降回 1 并告警;分区消费属后续项）：
// Consistency semantics (single-consumer premise; count>1 is forced back to 1 with a warning — partitioned
// consumption is a follow-up):
// - 本进程是消费组 `storage-exporter` 的唯一消费者——同一 uid 的快照按 Stream 追加序 upsert,最新态即权威,
//   无需 CAS/版本裁决（游戏侧单调版本仅用于审计）;
//   sole consumer of the group: a uid's snapshots upsert in Stream append order, latest wins, no CAS needed
//   (the monotonic version stays for audit);
// - at-least-once:upsert 失败不 XACK,条目滞留 PEL,由下一轮 XAUTOCLAIM 重放;毒消息（坏 JSON）直接 ack+记日志
//   ——Redis 态已权威,导出失败只老化报表（裁决同「丢失可解释」）;
//   at-least-once: failed upserts stay unacked for XAUTOCLAIM replay; poison entries (malformed payloads) are
//   acked with a log — the Redis state is already authoritative, export failure only ages reports;
// - 积压治理:消费成功后 XTRIM 近似截断（发布侧 MAXLEN 保险丝双保险）。
//   backlog control: approximate XTRIM after each successful round (the publisher-side MAXLEN fuse is the backstop).

require __DIR__ . '/../../../vendor/autoload.php';

use Nythros\Persistence\MySqlStorage;
use Workerman\Timer;
use Workerman\Worker;

/** @return never 打印 fatal 并退出（与 run-worker 同口径）. Print a fatal and exit (the run-worker convention). */
function exporterFail(string $message): never
{
    fwrite(STDERR, sprintf('[run-exporter] fatal: %s%s', $message, PHP_EOL));
    exit(1);
}

/**
 * 解析命令行参数（纯函数,自检复用）。
 * Parse CLI options (pure; reused by the self-test).
 *
 * @param array<string, string|false> $raw getopt output
 * @return array<string, string|int>
 */
function exporterOptions(array $raw): array
{
    $str = static fn (string $key, string $default): string => isset($raw[$key]) && is_string($raw[$key]) && $raw[$key] !== '' ? $raw[$key] : $default;
    $int = static function (string $key, int $default, int $min, int $max) use ($raw): int {
        if (!isset($raw[$key]) || !is_string($raw[$key]) || $raw[$key] === '') {
            return $default;
        }
        $n = (int) $raw[$key];
        if ($n < $min || $n > $max) {
            exporterFail(sprintf('--%s must be an integer in %d~%d', $key, $min, $max));
        }

        return $n;
    };

    return [
        'redisHost' => $str('redisHost', '127.0.0.1'),
        'redisPort' => $int('redisPort', 6379, 1, 65535),
        'mysqlHost' => $str('mysqlHost', '127.0.0.1'),
        'mysqlPort' => $int('mysqlPort', 3306, 1, 65535),
        'mysqlUser' => $str('mysqlUser', 'root'),
        'mysqlPass' => isset($raw['mysqlPass']) && is_string($raw['mysqlPass']) ? $raw['mysqlPass'] : '',
        'mysqlDb' => $str('mysqlDb', 'nythros'),
        'streamKey' => $str('streamKey', 'nythros:export:players'),
        'group' => $str('group', 'storage-exporter'),
        'port' => $int('port', 18300, 1, 65535),
        'count' => $int('count', 1, 1, 8),
    ];
}

/**
 * XREADGROUP 回复摊平:phpredis 权威形态为 [streamName => [entryId => fieldsMap, ...]]——
 * entryId 是 Stream 分配的消息 id（数组键）,fieldsMap 即发布时的字段（id/data/version）。
 * Flattens an XREADGROUP reply. phpredis returns [streamName => [entryId => fieldsMap]] — the message id is the
 * array key, fieldsMap is the published payload (id/data/version).
 *
 * @param mixed $reply xReadGroup 返回值（mixed:array 已归一）
 *
 * @return list<array{0: string, 1: array<string, string>}> [entryId, fields]
 */
function exporterFlattenReadReply(mixed $reply): array
{
    $entries = [];
    if (!is_array($reply)) {
        return $entries;
    }

    foreach ($reply as $streamMessages) {
        if (!is_array($streamMessages)) {
            continue;
        }
        foreach ($streamMessages as $entryId => $fields) {
            if (is_string($entryId) && is_array($fields)) {
                $entries[] = [$entryId, array_map(static fn ($v): string => (string) $v, $fields)];
            }
        }
    }

    return $entries;
}

/**
 * XAUTOCLAIM 载荷摊平:[entryId => fieldsMap] → entry 列表（xAutoClaim 回复第二元素的原生形态）。
 * Flattens an XAUTOCLAIM payload (the native [entryId => fieldsMap] shape of reply element 1).
 *
 * @param array<array-key, mixed> $claimedMap
 *
 * @return list<array{0: string, 1: array<string, string>}>
 */
function exporterFlattenClaimMap(array $claimedMap): array
{
    $entries = [];
    foreach ($claimedMap as $entryId => $fields) {
        if (is_string($entryId) && is_array($fields)) {
            $entries[] = [$entryId, array_map(static fn ($v): string => (string) $v, $fields)];
        }
    }

    return $entries;
}

// ── 自检:纯函数离线断言（参数解析/两种回复形态）──
// Self-test: offline pure-function assertions (option parsing + both reply shapes).
if (in_array('--self-test', $argv, true)) {
    $failures = [];

    $opts = exporterOptions(['redisPort' => '6380', 'mysqlDb' => 'game']);
    if ($opts['redisPort'] !== 6380 || $opts['streamKey'] !== 'nythros:export:players' || $opts['mysqlDb'] !== 'game') {
        $failures[] = 'option defaults/overrides mismatch';
    }

    $xread = exporterFlattenReadReply([
        'nythros:export:players' => [
            '1-0' => ['id' => '1001', 'data' => '{"inventory":{"gold":2}}'],
            '1-1' => ['id' => '1002', 'data' => '{"inventory":{"gold":5}}'],
        ],
    ]);
    if (count($xread) !== 2 || $xread[0][0] !== '1-0' || ($xread[1][1]['id'] ?? '') !== '1002') {
        $failures[] = 'XREADGROUP reply flattening failed';
    }

    $claim = exporterFlattenClaimMap([
        '2-0' => ['id' => '1003', 'data' => '{"inventory":{"gold":9}}'],
    ]);
    if (count($claim) !== 1 || $claim[0][0] !== '2-0' || ($claim[0][1]['id'] ?? '') !== '1003') {
        $failures[] = 'XAUTOCLAIM payload flattening failed';
    }

    if (exporterFlattenReadReply(false) !== [] || exporterFlattenReadReply(null) !== []) {
        $failures[] = 'empty replies must flatten to []';
    }

    if ($failures !== []) {
        exporterFail('SELF-TEST FAIL: ' . implode('; ', $failures));
    }
    echo "[run-exporter] SELF-TEST PASS\n";
    exit(0);
}

// ── 生产路径 ──
$rawOpts = getopt('', ['streamKey:', 'group:', 'redisHost:', 'redisPort:', 'mysqlHost:', 'mysqlPort:', 'mysqlUser:', 'mysqlPass:', 'mysqlDb:', 'count:', 'port:', 'pidFile:']);
$options = exporterOptions(is_array($rawOpts) ? $rawOpts : []);

// Workerman parseCommand 兼容:自定义 --xxx 参数消费后注入显式 start（run-worker 同口径）
// Workerman parseCommand compatibility: strip consumed custom flags and inject an explicit start.
$GLOBALS['argv'] = [$argv[0], 'start'];

$worker = new Worker();
$worker->name = 'storage-exporter';
if ($options['count'] > 1) {
    // 单消费者是一致性前提（Stream 追加序 = 全序）;多实例分区消费是后续项,先硬降。
    // Single consumer is the consistency premise (Stream append order = total order); partitioned multi-instance
    // consumption is a follow-up — force back to 1 for now.
    error_log('[run-exporter] count>1 is not supported (single-consumer ordering premise); forcing count=1');
}
$worker->count = 1;
// pidFile 缺省按 port 生成（与 run-worker 的 type+port 缺省同款,避开其他服务陈旧锁）
// Default pidFile keyed by port (the run-worker type+port convention, never colliding with other services).
$pidFileOpt = isset($rawOpts['pidFile']) && is_string($rawOpts['pidFile']) && $rawOpts['pidFile'] !== '' ? $rawOpts['pidFile'] : null;
Worker::$pidFile = $pidFileOpt ?? sprintf('%s/nythros-storage-exporter-%d.pid', sys_get_temp_dir(), $options['port']);

$worker->onWorkerStart = static function () use ($options): void {
    $consumer = sprintf('exporter-%s-%d', gethostname(), getmypid());

    $connectRedis = static function () use ($options): \Redis {
        $redis = new \Redis();
        $ok = @$redis->connect($options['redisHost'], $options['redisPort'], 2.0);
        if ($ok !== true) {
            throw new \RuntimeException(sprintf('Redis connect failed: %s:%d', $options['redisHost'], $options['redisPort']));
        }
        $redisPassword = getenv('NYTHROS_REDIS_PASSWORD');
        if (is_string($redisPassword) && $redisPassword !== '') {
            @$redis->auth($redisPassword);
        }

        return $redis;
    };
    $connectPdo = static function () use ($options): PDO {
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $options['mysqlHost'], $options['mysqlPort'], $options['mysqlDb']);
        // 与 run-worker 的 pdoFactory 同参:异常模式 + 真预处理（归档侧写 JSON 列需要 native prepare）
        // Same options as run-worker's pdoFactory: exceptions + native prepares (JSON column writes need them).
        return new PDO($dsn, $options['mysqlUser'], $options['mysqlPass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    };

    // 启动期：消费组幂等创建（BUSYGROUP 忽略）+ 归档表幂等创建。失败 = fatal(缺依赖盲重启无益)。
    // Boot: idempotent group creation (BUSYGROUP ignored) + idempotent schema. Failures here are fatal
    // (a missing dependency makes blind restarts useless).
    try {
        $bootRedis = $connectRedis();
        try {
            $bootRedis->xGroup('CREATE', $options['streamKey'], $options['group'], '0', true);
        } catch (\Throwable $e) {
            if (!str_contains($e->getMessage(), 'BUSYGROUP')) {
                throw $e;
            }
        }
        $bootRedis->close();
        MySqlStorage::createSchema($connectPdo(), MySqlStorage::DEFAULT_TABLE);
    } catch (\Throwable $e) {
        exporterFail($e->getMessage());
    }

    error_log(sprintf(
        '[run-exporter] started: stream=%s group=%s consumer=%s mysql=%s:%d/%s',
        $options['streamKey'],
        $options['group'],
        $consumer,
        $options['mysqlHost'],
        $options['mysqlPort'],
        $options['mysqlDb'],
    ));

    // 每 0.5s 一轮:XAUTOCLAIM 回收滞留 PEL（idle≥2s,覆盖「upsert 失败/claim 后崩溃」）→ XREADGROUP '>' 新消息
    // → 逐条按 uid 收敛最新态 → saveBatch 复用 MySqlStorage upsert → 成功/毒 ack,失败留 PEL → XTRIM。
    // 一切轮内异常只记日志不抛出——at-least-once 下下一轮自愈;失联则下轮重连（与 metrics-exporter 口径一致）。
    // Every 500ms: XAUTOCLAIM stuck PEL entries (idle >= 2s — previous upsert failures / crash-after-claim), then
    // XREADGROUP '>', collapse to the latest payload per uid, upsert via MySqlStorage::saveBatch, ack successes
    // and poison, leave failures unacked, then XTRIM. Round failures are logged, never thrown: at-least-once
    // self-heals next round, and a lost client is rebuilt (the metrics-exporter stance).
    Timer::add(0.5, static function () use ($options, $consumer, $connectRedis, $connectPdo): void {
        static $redis = null;
        static $claimCursor = '0-0';
        static $storage = null;
        static $tick = 0;
        // backlog 上报周期（tick 数）:0.5s × 10 = 5s,与 PerfSampler 缺省同节奏,不给消费轮加显著负载
        // Backlog report cadence (ticks): 0.5s × 10 = 5s, the PerfSampler default's rhythm, no added consume cost
        $reportEveryTicks = 10;

        try {
            if ($redis === null) {
                $redis = $connectRedis();
                $claimCursor = '0-0';
            }
            if ($storage === null) {
                $storage = new MySqlStorage($connectPdo);
            }

            // ③ 积压监控（上线安全网）:每 $reportEveryTicks tick 把 PEL 滞留数 + Stream 长度写进
            //    perf gauge 键族（metrics-exporter 已自动发现 → nythros_perf_gauge{service="storage-exporter"}），
            //    并刷新 :last 活性心跳——exporter 失联时 lag 停走即告警（deployment §4）。失败静默（不影响消费）。
            // ③ Backlog watchdog (the pre-launch safety net): every N ticks publish the PEL pending count + Stream
            //    length into the perf gauge family (metrics-exporter auto-discovers it as
            //    nythros_perf_gauge{service="storage-exporter"}) and refresh the :last liveness heartbeat — a stalled
            //    lag alerts on exporter loss (deployment §4). Failures are silent (never block consumption).
            if (++$tick % $reportEveryTicks === 0) {
                try {
                    $pending = $redis->xPending($options['streamKey'], $options['group']);
                    // phpredis summary 两形态兼容:关联 ['count'=>N,…] 与索引 [N,start,end,consumers]
                    // phpredis summary has two shapes across versions: the assoc ['count'=>N,…] and the indexed [N,start,end,consumers]
                    $backlog = is_array($pending) ? (int) ($pending['count'] ?? $pending[0] ?? 0) : 0;
                    $streamLen = (int) $redis->xLen($options['streamKey']);
                    $gaugeKey = 'nythros:perf:storage-exporter:gauge';
                    $pipeline = $redis->multi(\Redis::PIPELINE);
                    $pipeline->hMSet($gaugeKey, ['backlog' => (string) $backlog, 'stream_len' => (string) $streamLen]);
                    $pipeline->set('nythros:perf:storage-exporter:last', (string) json_encode([
                        'ts' => microtime(true),
                        'serviceId' => 'storage-exporter',
                    ], JSON_UNESCAPED_UNICODE));
                    $pipeline->exec();
                } catch (\Throwable $e) {
                    error_log('[run-exporter] backlog report failed: ' . $e->getMessage());
                }
            }

            $rounds = [];

            // ① 回收滞留 PEL（本组 idle≥2s 的未 ack 条目）
            // ① Reclaim the group's stuck PEL (unacked, idle >= 2s)
            try {
                $claimed = $redis->xAutoClaim($options['streamKey'], $options['group'], $consumer, 2000, $claimCursor, 100);
                if (is_array($claimed)) {
                    $claimCursor = (string) ($claimed[0] ?? '0-0');
                    $rounds[] = exporterFlattenClaimMap(is_array($claimed[1] ?? null) ? $claimed[1] : []);
                }
            } catch (\Throwable $e) {
                // xAutoClaim 不可得（低版本 phpredis/Redis）:降级为「失败条目滞留 PEL 至进程重启后首轮回收」
                // xAutoClaim unavailable (older phpredis/Redis): stuck entries stay in the PEL until the next boot
                error_log('[run-exporter] xautoclaim degraded: ' . $e->getMessage());
                $claimCursor = '0-0';
            }

            // ② 新消息
            // ② New messages
            $rounds[] = exporterFlattenReadReply($redis->xReadGroup($options['group'], $consumer, [$options['streamKey'] => '>'], 100));

            foreach ($rounds as $entries) {
                if ($entries === []) {
                    continue;
                }

                /** @var array<string, array<string, mixed>> $latest 按 uid 收敛本轮最新态 */
                $latest = [];
                $ackIds = [];
                foreach ($entries as [$entryId, $fields]) {
                    $uid = $fields['id'] ?? '';
                    $payload = json_decode((string) ($fields['data'] ?? ''), true);
                    if ($uid === '' || !is_array($payload)) {
                        // 毒消息:直接 ack（Redis 权威已在游戏侧,坏条目不挡流水线）
                        // Poison: ack immediately (the authoritative state is already in Redis; bad rows never jam the pipeline)
                        $ackIds[] = $entryId;
                        error_log(sprintf('[run-exporter] poison entry acked: %s (uid=%s)', $entryId, $uid));

                        continue;
                    }
                    $latest[$uid] = $payload;
                }

                $failed = [];
                if ($latest !== []) {
                    try {
                        $failed = array_map(static fn ($f): string => (string) $f, $storage->saveBatch('players', $latest));
                    } catch (\Throwable $e) {
                        error_log(sprintf('[run-exporter] upsert failed (%d records): %s', count($latest), $e->getMessage()));
                        $failed = array_keys($latest);
                        // 丢存储实例:MySqlStorage 缓存的 PDO 可能已失联,下轮重建（lazy pdo 工厂自愈）
                        // Drop the storage instance: its cached PDO may be dead; rebuild lazily next round
                        $storage = null;
                    }
                }
                $failedSet = array_flip($failed);

                foreach ($entries as [$entryId, $fields]) {
                    $uid = $fields['id'] ?? '';
                    if ($uid !== '' && !isset($failedSet[$uid])) {
                        $ackIds[] = $entryId;
                    }
                }

                if ($ackIds !== []) {
                    $redis->xAck($options['streamKey'], $options['group'], $ackIds);
                    $redis->xTrim($options['streamKey'], '100000', true);
                }
            }
        } catch (\Throwable $e) {
            // 轮级异常（Redis 失联等）:丢连接,下轮重建重连;绝不退出
            // Round-level failure (Redis loss etc.): drop the client, rebuild next round; never exit.
            error_log('[run-exporter] round error: ' . $e->getMessage());
            $redis = null;
        }
    }); // Workerman\Timer::add 第三参是 args 数组（persistent 缺省 true）——勿与 engine TimerInterface 签名混淆
    // Workerman\Timer::add's 3rd arg is the args array (persistent defaults true) — distinct from the engine TimerInterface signature
};

Worker::runAll();
