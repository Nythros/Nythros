<?php

declare(strict_types=1);

// 定位：benchmarks/lib/drill-harness.php —— 长跑（soak）与故障演练（fault-drill）共享的编排工具。
// 职责：服务栈托管（proc_open 启动 bin/server / 端口就绪等待 / 优雅停止）、探针（TCP 端口 / gateway
// 登录链路）、运行期采样（worker RSS / Redis / 日志体积）。设计约定：编排器自身不依赖 Workerman，
// 全部走子进程 + 原生 socket，保证演练器与服务栈故障隔离。
// Located at: benchmarks/lib/drill-harness.php — the orchestration toolkit shared by the soak and fault drills.
// Responsibilities: stack hosting (proc_open bin/server / port-readiness await / graceful stop), probes (TCP
// port / the gateway login chain), and runtime sampling (worker RSS / Redis / log sizes). Convention: the
// orchestrator itself never depends on Workerman — everything runs via subprocesses and raw sockets so the
// driller stays fault-isolated from the stack it drills.

/**
 * 演练配置（CLI 解析后的聚合形态）。
 * Drill configuration (the aggregated form after CLI parsing).
 */
final class DrillConfig
{
    /**
     * @param array<string> $serverEnv 附加环境变量（key=value） Extra environment variables (key=value).
     */
    public function __construct(
        public readonly string $repoRoot,
        public readonly string $serverCmd,
        public readonly bool $manageServer,
        public readonly int $gatewayPort,
        public readonly int $mapPort,
        public readonly string $logDir = '/tmp/nythros-drill',
        public readonly array $serverEnv = [],
        public readonly int $redisPort = 6379,
        public readonly int $mysqlPort = 3306,
    ) {
    }
}

/**
 * 启动服务栈（proc_open，独立进程组，输出落盘）；已就绪端口先探再启，避免与既有实例冲突。
 * Starts the stack (proc_open in its own process group, output to file); probes readiness first to avoid
 * clashing with a running instance.
 *
 * @return array{pid: int, handle: resource, stdout: resource}|null null = 端口已就绪（外部栈托管）
 */
function drillStartServer(DrillConfig $cfg): ?array
{
    if (drillTcpProbe($cfg->gatewayPort)) {
        return null; // 已有实例在跑，交由调用方 --no-server 语义 External stack is live; caller runs in attach mode.
    }
    if (!is_dir($cfg->logDir)) {
        mkdir($cfg->logDir, 0777, true);
    }
    $env = array_merge(
        array_filter($_ENV, 'is_string'),
        array_filter($_SERVER, 'is_string'),
        [
            'NYTHROS_CONFIG_DIR' => $cfg->repoRoot . '/packages/demo/config',
            'NYTHROS_MMORPG' => '1',
            'NYTHROS_GAMEPLAY' => '1',
        ],
        $cfg->serverEnv,
    );
    $stdout = fopen($cfg->logDir . '/server.log', 'wb');
    $handle = proc_open(
        'exec ' . $cfg->serverCmd,
        [0 => ['pipe', 'r'], 1 => $stdout, 2 => $stdout],
        $pipes,
        $cfg->repoRoot,
        $env,
    );
    if (!is_resource($handle)) {
        throw new RuntimeException('服务栈启动失败（proc_open）');
    }
    $status = proc_get_status($handle);
    $pid = $status['pid'];

    // 杀进程组需要会话首进程：setsid 经 exec 前缀不可移植，这里直接记录 pid，停止时 SIGINT 转发
    // （bin/server 前台运行处理 SIGINT/SIGTERM 优雅停止，见 quick-start §4）。
    // 就绪判定 = gateway + map 双端口 + **登录探针通过**：端口监听早于 registry 的频道注册
    // （onWorkerStart 注册 + 首次心跳），只等端口会撞出「登录 503 no available channel」的竞态窗口。
    // Readiness = the gateway and map ports + **a passing login probe**: listening precedes the registry's
    // channel registration (onWorkerStart register + first heartbeat), so waiting on ports alone races into
    // "login 503 no available channel".
    if (!drillAwaitPort($cfg->gatewayPort, 90.0) || !drillAwaitPort($cfg->mapPort, 30.0)) {
        proc_terminate($handle);
        throw new RuntimeException('服务栈未就绪（gateway/map 端口超时），详见 ' . $cfg->logDir . '/server.log');
    }
    $loginReady = false;
    for ($i = 0; $i < 30; ++$i) {
        if (drillGatewayLogin($cfg)['ok']) {
            $loginReady = true;
            break;
        }
        usleep(500_000);
    }
    if (!$loginReady) {
        proc_terminate($handle);
        throw new RuntimeException('服务栈端口已监听但登录探针 15s 未通过（注册表就绪竞态），详见 ' . $cfg->logDir . '/server.log');
    }

    return ['pid' => $pid, 'handle' => $handle, 'stdout' => $stdout];
}

/**
 * 优雅停止服务栈（SIGINT → bin/server 信号转发），等待退出并回收。
 * Gracefully stops the stack (SIGINT → bin/server's signal forwarding), waits and reaps.
 */
function drillStopServer(?array $server): void
{
    if ($server === null) {
        return;
    }
    proc_terminate($server['handle'], SIGINT);
    for ($i = 0; $i < 30; ++$i) {
        if (proc_get_status($server['handle'])['running'] === false) {
            break;
        }
        usleep(1_000_000);
    }
    if (proc_get_status($server['handle'])['running'] !== false) {
        proc_terminate($server['handle'], SIGKILL);
    }
    proc_close($server['handle']);
    fclose($server['stdout']);
}

/**
 * TCP 端口探针。
 * A TCP port probe.
 */
function drillTcpProbe(int $port, float $timeout = 1.0): bool
{
    $conn = @fsockopen('127.0.0.1', $port, $errno, $errstr, $timeout);
    if ($conn === false) {
        return false;
    }
    fclose($conn);

    return true;
}

/**
 * 等待端口就绪（演练启动/自愈恢复共用）。
 * Awaits port readiness (shared by boot and self-heal recovery).
 */
function drillAwaitPort(int $port, float $timeoutSeconds, float $interval = 0.5): bool
{
    $deadline = microtime(true) + $timeoutSeconds;
    while (microtime(true) < $deadline) {
        if (drillTcpProbe($port, 0.5)) {
            return true;
        }
        usleep((int) ($interval * 1e6));
    }

    return false;
}

/**
 * 最小 RFC6455 客户端握手（演练/压测共用）。
 * The minimal RFC6455 client handshake (shared by the drill and the stress generator).
 *
 * @return resource|false
 */
function drillWsHandshake(string $host, int $port, string $path = '/', float $timeout = 3.0)
{
    $stream = @stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, $timeout);
    if ($stream === false) {
        return false;
    }
    $key = base64_encode(random_bytes(16));
    fwrite($stream, "GET {$path} HTTP/1.1\r\nHost: {$host}:{$port}\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: {$key}\r\nSec-WebSocket-Version: 13\r\n\r\n");
    $response = '';
    while (!str_contains($response, "\r\n\r\n")) {
        $line = fgets($stream);
        if ($line === false) {
            fclose($stream);

            return false;
        }
        $response .= $line;
    }
    if (!str_contains($response, '101')) {
        fclose($stream);

        return false;
    }

    return $stream;
}

/**
 * 发送一个掩码客户端帧（文本 0x1 / 二进制 0x2）。
 * Sends one masked client frame (text 0x1 / binary 0x2).
 */
function drillWsSend($stream, string $payload, int $opcode = 0x1): void
{
    $len = strlen($payload);
    $head = chr(0x80 | $opcode);
    $mask = random_bytes(4);
    $head .= $len < 126 ? chr(0x80 | $len) : ($len < 65536 ? chr(0x80 | 126) . pack('n', $len) : chr(0x80 | 127) . pack('J', $len));
    fwrite($stream, $head . $mask . ($payload ^ str_repeat($mask, intdiv($len, 4) + 1)));
}

/**
 * gateway 登录链路探针：最小 RFC6455 客户端握手 → JSON auth（含协议版本）→ 解析 auth_ok/auth_failed。
 * The gateway login-chain probe: a minimal RFC6455 client handshake -> JSON auth (with the protocol version)
 * -> parse auth_ok/auth_failed. 与 gm-console.php 的 ws 客户端同构（演练器独立成库，不反向依赖 demo bin）。
 * Structurally identical to gm-console.php's ws client (the driller lives in its own lib, never back-depending on demo bin).
 *
 * @return array{ok: bool, detail: string} ok=true 表示 auth_ok；ok=false 时 detail 归因
 */
function drillGatewayLogin(DrillConfig $cfg, string $username = '1001', string $password = 'secret'): array
{
    $stream = drillWsHandshake('127.0.0.1', $cfg->gatewayPort);
    if ($stream === false) {
        return ['ok' => false, 'detail' => 'gateway 握手失败（TCP/101）'];
    }

    // 客户端帧：掩码文本 auth（协议版本见 ADR-027）
    $payload = json_encode([
        'type' => 'auth',
        'requestId' => 'drill:' . bin2hex(random_bytes(3)),
        'timestamp' => microtime(true),
        'version' => 1,
        'payload' => ['username' => $username, 'password' => $password, 'mapId' => 'map-1', 'version' => 1],
    ], JSON_UNESCAPED_UNICODE);
    drillWsSend($stream, $payload);

    $deadline = microtime(true) + 8.0;
    while (microtime(true) < $deadline) {
        $frame = drillReadWsFrame($stream, 2.0);
        if ($frame === null) {
            break;
        }
        if (in_array($frame['opcode'], [0x8, 0x9], true)) {
            break; // close/ping 结束等待 close/ping ends the wait
        }
        $msg = json_decode($frame['payload'], true);
        if (($msg['type'] ?? '') === 'auth_ok') {
            fclose($stream);

            return ['ok' => true, 'detail' => 'auth_ok uid=' . ($msg['payload']['uid'] ?? '?')];
        }
        if (($msg['type'] ?? '') === 'auth_failed') {
            fclose($stream);

            return ['ok' => false, 'detail' => 'auth_failed ' . json_encode($msg['payload'] ?? [], JSON_UNESCAPED_UNICODE)];
        }
    }
    fclose($stream);

    return ['ok' => false, 'detail' => 'auth 回执超时（8s）'];
}

/**
 * 读一个服务端 WebSocket 帧（不掩码；EOF/超时返回 null）。
 * Reads one server WebSocket frame (unmasked; null on EOF/timeout).
 *
 * @return ?array{opcode: int, payload: string}
 */
function drillReadWsFrame($stream, float $timeoutSeconds): ?array
{
    stream_set_timeout($stream, (int) $timeoutSeconds, (int) (($timeoutSeconds - (int) $timeoutSeconds) * 1e6));
    $head = fread($stream, 2);
    if ($head === false || strlen($head) < 2) {
        return null;
    }
    $opcode = ord($head[0]) & 0x0f;
    $len = ord($head[1]) & 0x7f;
    if ($len === 126) {
        $b = fread($stream, 2);
        if ($b === false || strlen($b) < 2) {
            return null;
        }
        $len = unpack('n', $b)[1];
    } elseif ($len === 127) {
        $b = fread($stream, 8);
        if ($b === false || strlen($b) < 8) {
            return null;
        }
        $len = unpack('J', $b)[1];
    }
    $payload = $len === 0 ? '' : (string) fread($stream, $len);

    return ['opcode' => $opcode, 'payload' => $payload];
}

/**
 * 玩法发生探针（soak `--play` 波次专用，纯只读 Redis）：证明切图/副本/组队「真的在服务端发生了」，
 * 而非仅客户端自称。三类代理信号——
 * ① transfer：`nythros:transfer:*` 键（跨图/副本迁移的一次性铁证，SETEX 30s + Lua 消费即删——
 *    波内采到即成立，采不到不代表没发生，故只作正向证据不计入熔断）；
 * ② dungeon：`nythros:svc:map` hash 里 mapId=dungeon-A 的 meta.playerCount（副本 worker 首次进负载）；
 * ③ team：`nythros:gw:team:*` 键族非空（组队状态机在 Redis 落地）。
 * 任一键族读失败（redis-down 演练中）记 null，绝不抛——与 drillSample 的观测代码零影响同口径。
 *
 * Play-occurrence probe (soak --play waves; read-only Redis): proves transfers/dungeons/teams really
 * happened server-side. Transfer keys and dungeon playerCount are positive-only evidence (tickets are
 * SETEX'd and consumed via Lua, so absence is not a failure signal); team keys are a live-state scan.
 *
 * @return array{transferKeys: ?int, dungeonPlayerCount: ?int, dungeonOutBytes: ?int, teamKeys: ?int}
 */
function drillPlayProbe(DrillConfig $cfg): array
{
    $out = ['transferKeys' => null, 'dungeonPlayerCount' => null, 'dungeonOutBytes' => null, 'teamKeys' => null];
    try {
        $redis = new \Redis();
        if (@$redis->connect('127.0.0.1', $cfg->redisPort, 1.0)) {
            $password = getenv('NYTHROS_REDIS_PASSWORD');
            if (is_string($password) && $password !== '') {
                @$redis->auth($password);
            }
            $out['transferKeys'] = iterator_count(drillScanKeys($redis, 'nythros:transfer:*'));
            $out['teamKeys'] = iterator_count(drillScanKeys($redis, 'nythros:gw:team:*'));
            $out['dungeonPlayerCount'] = drillDungeonPlayerCount($redis, $cfg);
            // 累计出站字节（11h 实测定型：波间瞬态 playerCount 命中率 ~0，perf 累计计数器才是
            // 长跑旁证的正确形态——托管栈计数器自 0 起、随 worker 生命周期单调）
            $acc = 0;
            $seen = false;
            foreach (drillScanKeys($redis, 'nythros:perf:dungeon-*:counters') as $key) {
                $v = $redis->hGet($key, 'network.out_bytes');
                if (false !== $v && null !== $v) {
                    $acc += (int) $v;
                    $seen = true;
                }
            }
            $out['dungeonOutBytes'] = $seen ? $acc : 0;
            $redis->close();
        }
    } catch (\Throwable) {
        // Redis 不可用：探针记 null（正向证据缺位≠崩塌），由调用方按「静默告警」处理
    }

    return $out;
}

/**
 * 副本 worker 在线数：扫 `nythros:svc:map` 注册 hash，累加 meta.mapId 命中副本前缀（缺省 dungeon-）的
 * playerCount。serviceId 编码 {mapId}#{channelId}，meta 为 JSON。
 * Dungeon worker online count: sum playerCount over map-service metas whose mapId matches the dungeon prefix.
 */
function drillDungeonPlayerCount(\Redis $redis, DrillConfig $cfg, string $dungeonPrefix = 'dungeon-'): ?int
{
    $hash = $redis->hGetAll('nythros:svc:map');
    if ($hash === false || $hash === []) {
        return 0;
    }
    $total = 0;
    foreach ($hash as $serviceId => $metaJson) {
        $meta = json_decode((string) $metaJson, true);
        if (!is_array($meta)) {
            continue;
        }
        $mapId = (string) ($meta['mapId'] ?? $serviceId);
        if (str_starts_with($mapId, $dungeonPrefix)) {
            $total += (int) ($meta['playerCount'] ?? 0);
        }
    }

    return $total;
}

/**
 * 从接收缓冲解析完整 WebSocket 帧（服务端帧不掩码；不完整的尾部保留在缓冲）——非阻塞 select 引擎
 * 共用（stress-map/stress-play）：JSON 文本帧（0x1，社交）与二进制帧（0x2，map）同径解析，按 opcode
 * 分流由调用方负责。
 * Parses complete WebSocket frames from a receive buffer (server frames are unmasked; an incomplete tail
 * stays in the buffer) — shared by the non-blocking select engines (stress-map/stress-play). Text (0x1,
 * social JSON) and binary (0x2, map) frames take the same path; dispatch by opcode is the caller's job.
 *
 * @param string $buffer 引用传递，解析后前移 Passed by reference, advanced after parsing.
 *
 * @return list<array{opcode: int, payload: string}>
 */
function drillParseWsBuffer(string &$buffer): array
{
    $frames = [];
    while (strlen($buffer) >= 2) {
        $opcode = ord($buffer[0]) & 0x0f;
        $len = ord($buffer[1]) & 0x7f;
        $offset = 2;
        if ($len === 126) {
            if (strlen($buffer) < 4) {
                break;
            }
            $len = unpack('n', substr($buffer, 2, 2))[1];
            $offset = 4;
        } elseif ($len === 127) {
            if (strlen($buffer) < 10) {
                break;
            }
            $len = unpack('J', substr($buffer, 2, 8))[1];
            $offset = 10;
        }
        if (strlen($buffer) < $offset + $len) {
            break;
        }
        $frames[] = ['opcode' => $opcode, 'payload' => substr($buffer, $offset, $len)];
        $buffer = substr($buffer, $offset + $len);
    }

    return $frames;
}

/**
 * 组装 JSON 社交信封（gateway/chat/team 文本帧 0x1）：timestamp 必须为数字（网关对全消息校验，
 * blueprint/27 实测踩坑——字符串时间戳直接吃掉 auth/enter）。纯函数，self-test 锚定。
 * Builds a JSON social envelope (text frames on gateway/chat/team): the timestamp MUST be numeric (the
 * gateway validates it for every message — a measured pitfall, blueprint/27). Pure, self-tested.
 */
function drillSocialFrame(string $type, string $requestId, array $payload): string
{
    return json_encode([
        'type' => $type,
        'requestId' => $requestId,
        'timestamp' => microtime(true),
        'payload' => $payload,
    ], JSON_UNESCAPED_UNICODE);
}

/**
 * 键族迭代（phpredis 版本兼容）：新版 scan() 单次返回键数组（游标耗尽后 false），旧版逐键返回字符串。
 * Key-family iteration (phpredis version compatibility): newer phpredis scan() returns a KEY ARRAY per call
 * (false once the cursor exhausts); older builds return one key string per call. Support both shapes.
 *
 * @return \Generator<int, string>
 */
function drillScanKeys(\Redis $redis, string $pattern): \Generator
{
    $it = null;
    do {
        $res = $redis->scan($it, $pattern);
        if ($res === false) {
            return;
        }
        foreach ((array) $res as $key) {
            if (is_string($key) && $key !== '') {
                yield $key;
            }
        }
    } while ($it > 0);
}

/**
 * 运行期采样：worker 进程 RSS（run-worker/start-maps 进程族合计与单进程峰值）、Redis 使用、日志体积、栈存活，
 * 以及系统可用内存与引擎性能计数器（事件总线丢弃累计 / 世界帧均值 ms）。
 * One runtime sample: total & peak worker RSS, Redis usage, log sizes, stack liveness, plus system
 * MemAvailable and the engine's perf counters (cumulative eventbus drops / the world-frame mean in ms).
 *
 * @return array{ts: float, alive: bool, rssTotalKb: int, rssPeakKb: int, workers: int, redisDbsize: ?int, redisMemMb: ?float, logKb: int, memAvailMb: ?float, droppedTotal: ?int, frameMeanMs: ?float}
 */
function drillSample(DrillConfig $cfg): array
{
    $ps = shell_exec('ps -eo rss=,args= 2>/dev/null') ?? '';
    $rssTotal = 0;
    $rssPeak = 0;
    $workers = 0;
    foreach (explode("\n", $ps) as $line) {
        if (!preg_match('/^\s*(\d+)\s+(.*run-worker\.php.*|.*start-maps\.php.*)$/', $line, $m)) {
            continue;
        }
        if (str_contains($line, 'soak-map') || str_contains($line, 'fault-drill') || str_contains($line, 'grep')) {
            continue;
        }
        ++$workers;
        $rss = (int) $m[1];
        $rssTotal += $rss;
        $rssPeak = max($rssPeak, $rss);
    }

    $redisDbsize = null;
    $redisMemMb = null;
    $droppedTotal = null;
    $frameMeanMs = null;
    $memAvailMb = null;
    try {
        $redis = new \Redis();
        if (@$redis->connect('127.0.0.1', 6379, 1.0)) {
            $password = getenv('NYTHROS_REDIS_PASSWORD');
            if (is_string($password) && $password !== '') {
                @$redis->auth($password);
            }
            $redisDbsize = $redis->dbsize();
            $info = $redis->info('memory');
            $redisMemMb = round((float) ($info['used_memory'] ?? 0) / 1048576, 2);

            // 性能计数器聚合（PerfSampler 键族）：dropped 累计 + 世界帧均值（totals 毫秒 / hist 桶计数和——
            // recordDuration 写 histogram+totals，不写 counters，样本数在桶里）
            // The perf counter aggregation (PerfSampler's key family): cumulative drops + the world-frame mean
            // (totals ms / histogram bucket-count sum — recordDuration writes histogram+totals, never counters,
            // so the sample count lives in the buckets).
            $dropped = 0;
            $frameTotalMs = 0.0;
            $frameSamples = 0;
            foreach (drillScanKeys($redis, 'nythros:perf:*:counters') as $key) {
                $h = $redis->hGetAll($key) ?: [];
                $dropped += (int) ($h['eventbus.dropped_total'] ?? 0);
            }
            foreach (drillScanKeys($redis, 'nythros:perf:*:totals') as $key) {
                $h = $redis->hGetAll($key) ?: [];
                $frameTotalMs += (float) ($h['world.frame_ms'] ?? 0);
            }
            foreach (drillScanKeys($redis, 'nythros:perf:*:hist') as $key) {
                $h = $redis->hGetAll($key) ?: [];
                foreach ($h as $field => $count) {
                    if (str_starts_with((string) $field, 'world.frame_ms.')) {
                        $frameSamples += (int) $count;
                    }
                }
            }
            $droppedTotal = $dropped;
            $frameMeanMs = $frameSamples > 0 ? round($frameTotalMs / $frameSamples, 3) : null;
        }
    } catch (\Throwable) {
        // Redis 不可用（故障注入中）：采样记 null 即可，不干扰演练 Redis unavailable (fault injection): record null and move on.
    }
    if (is_file('/proc/meminfo')) {
        foreach (file('/proc/meminfo', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if (preg_match('/^MemAvailable:\s+(\d+)\s*kB/', $line, $m)) {
                $memAvailMb = round((int) $m[1] / 1024, 1);
                break;
            }
        }
    }

    $logKb = 0;
    foreach (glob('/tmp/nythros-server/*.log') ?: [] as $log) {
        $logKb += (int) (filesize($log) / 1024);
    }

    return [
        'ts' => microtime(true),
        'alive' => drillTcpProbe($cfg->gatewayPort, 0.5),
        'rssTotalKb' => $rssTotal,
        'rssPeakKb' => $rssPeak,
        'workers' => $workers,
        'redisDbsize' => $redisDbsize,
        'redisMemMb' => $redisMemMb,
        'logKb' => $logKb,
        'memAvailMb' => $memAvailMb,
        'droppedTotal' => $droppedTotal,
        'frameMeanMs' => $frameMeanMs,
    ];
}

/**
 * 演练裁决（纯函数，self-test 直接驱动）：RSS 线性斜率 + 认证成功率 + 帧率下限。
 * The drill verdict (pure, driven directly by the self-test): the RSS linear slope + auth success ratio + a
 * frame-rate floor.
 *
 * @param list<array{rssTotalKb: int}> $samples 按时间升序的 RSS 采样 RSS samples in chronological order.
 * @param list<array{authOk: int, clients: int, fps: float, p99: float}> $waves stress 波次结果 Wave results from stress-map.
 * @param float $rssSlopeKbPerSample 允许的每采样 RSS 增长上限（KB） Allowed RSS growth per sample (KB).
 * @param float $minAuthRatio 认证成功率下限 The auth success ratio floor.
 * @param float $minFps 单客户端帧率下限 The per-client fps floor.
 * @return array{ok: bool, reasons: list<string>, rssSlopeKb: float, authRatio: float, minFps: float}
 */
function drillVerdict(array $samples, array $waves, float $rssSlopeKbPerSample = 16.0, float $minAuthRatio = 0.9, float $minFps = 3.0): array
{
    $reasons = [];
    $n = count($samples);
    $slope = 0.0;
    if ($n >= 3) {
        // 最小二乘斜率：x = 采样序号，y = RSS(KB)
        $sumX = $sumY = $sumXY = $sumXX = 0.0;
        foreach ($samples as $i => $s) {
            $sumX += $i;
            $sumY += $s['rssTotalKb'];
            $sumXY += $i * $s['rssTotalKb'];
            $sumXX += $i * $i;
        }
        $denom = $n * $sumXX - $sumX * $sumX;
        $slope = $denom !== 0.0 ? ($n * $sumXY - $sumX * $sumY) / $denom : 0.0;
        if ($slope > $rssSlopeKbPerSample) {
            $reasons[] = sprintf('RSS 线性增长 %.1f KB/采样 > 阈值 %.1f（疑似内存泄漏）', $slope, $rssSlopeKbPerSample);
        }
    } else {
        $reasons[] = '采样点不足 3 个，无法评估 RSS 斜率';
    }

    $authOk = array_sum(array_map(static fn (array $w): int => $w['authOk'], $waves));
    $authAll = array_sum(array_map(static fn (array $w): int => $w['clients'], $waves));
    $authRatio = $authAll > 0 ? $authOk / $authAll : 0.0;
    if ($waves !== [] && $authRatio < $minAuthRatio) {
        $reasons[] = sprintf('认证成功率 %.1f%% < %.1f%%', $authRatio * 100, $minAuthRatio * 100);
    }

    $minFpsSeen = $waves === [] ? 0.0 : min(array_map(
        static fn (array $w): float => $w['clients'] > 0 ? $w['fps'] / $w['clients'] : 0.0,
        $waves
    ));
    if ($waves !== [] && $minFpsSeen < $minFps) {
        $reasons[] = sprintf('波次内单客户端帧率最低 %.2f f/s < %.2f', $minFpsSeen, $minFps);
    }

    return ['ok' => $reasons === [], 'reasons' => $reasons, 'rssSlopeKb' => round($slope, 2), 'authRatio' => $authRatio, 'minFps' => round($minFpsSeen, 2)];
}
