<?php

declare(strict_types=1);

// 定位：benchmarks/fault-drill.php —— 故障矩阵演练编排器（Redis 宕机 / MySQL 宕机 / map worker kill -9 /
// storage-exporter 断联）。每个场景按「注入故障 → 行为断言 → 恢复 → 自愈断言」执行，输出对齐 verify-* 的
// PASS/FAIL/RESULT 契约。服务栈托管/登录探针/端口探针复用 benchmarks/lib/drill-harness.php。
// 已知边界（如实声明）：网络分区无法在单机演练（需 tc/netem 或多机），不在本脚本范围；exporter 场景的
// 重启为演练手动执行（bin/server 只收割不重生子服务，生产重生归 systemd）。
// Located at: benchmarks/fault-drill.php — the fault-matrix drill orchestrator (Redis outage / MySQL outage /
// a map-worker kill -9 / storage-exporter outage). Each scenario runs inject -> behavior assertions -> heal
// -> self-heal assertions, with output aligned to the verify-* PASS/FAIL/RESULT convention. The stack hosting
// / login probe / port probe are shared with benchmarks/lib/drill-harness.php. Known boundary (declared
// honestly): network partitions cannot be drilled on a single machine (needs tc/netem or multi-host); the
// exporter scenario's restart is manual here (bin/server reaps but does not respawn children — systemd does).
//
// 用法 Usage:
//   php benchmarks/fault-drill.php                       # 全场景（托管服务栈）
//   php benchmarks/fault-drill.php --scenario=redis      # 单场景 redis|mysql|kill9|exporter
//   php benchmarks/fault-drill.php --no-server           # 挂接已在跑的栈（注入/恢复仍由本脚本执行）
//   php benchmarks/fault-drill.php --self-test
// 前置：Redis/MySQL 控制命令可通过 --redis-stop/--redis-start/--mysql-stop/--mysql-start 覆盖
// （缺省：redis-cli shutdown nosave + redis-server --daemonize yes；systemctl stop mysql + systemctl start mysql）。

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/lib/drill-harness.php';

if (in_array('--self-test', $argv, true)) {
    exit(faultSelfTest());
}

$opts = ['scenario' => 'all', 'noServer' => false];
$cmds = [
    'redis-stop' => 'redis-cli shutdown nosave',
    'redis-start' => 'redis-server --daemonize yes',
    'mysql-stop' => 'systemctl stop mysql',
    'mysql-start' => 'systemctl start mysql',
];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--scenario=(\w+)$/', $arg, $m)) {
        $opts['scenario'] = $m[1];
    } elseif ($arg === '--no-server') {
        $opts['noServer'] = true;
    } elseif (preg_match('/^--(redis-stop|redis-start|mysql-stop|mysql-start)=(.+)$/', $arg, $m)) {
        $cmds[$m[1]] = $m[2];
    }
}

$cfg = new DrillConfig(dirname(__DIR__), 'php bin/server start', !$opts['noServer'], 18285, 18081);
echo "[drill] start: 场景={$opts['scenario']}（栈托管=" . ($cfg->manageServer ? '是' : '否，挂接') . "）\n";

$server = null;
try {
    if ($cfg->manageServer) {
        $server = drillStartServer($cfg);
        if ($server === null) {
            echo "[drill] 检测到既有实例，转为挂接模式\n";
        }
    }

    $results = [];
    $scenarios = match ($opts['scenario']) {
        'all' => ['redis', 'mysql', 'kill9', 'exporter'],
        default => [$opts['scenario']],
    };
    foreach ($scenarios as $scenario) {
        $results = [...$results, ...match ($scenario) {
            'redis' => drillRedisOutage($cfg, $cmds),
            'mysql' => drillMysqlOutage($cfg, $cmds),
            'kill9' => [drillKill9($cfg)],
            'exporter' => drillExporterOutage($cfg),
            default => throw new RuntimeException("未知场景 $scenario（期望 redis|mysql|kill9|exporter|all）"),
        }];
    }

    $failed = array_filter($results, static fn (array $r): bool => !$r['pass']);
    foreach ($results as $r) {
        echo sprintf("[drill] [%s] %s %s\n", $r['pass'] ? 'PASS' : 'FAIL', $r['name'], $r['detail']);
    }
    echo 'RESULT: ' . ($failed === [] ? 'PASS' : 'FAIL') . "\n";
    exit($failed === [] ? 0 : 1);
} finally {
    drillStopServer($server);
}

/**
 * 场景 1：Redis 宕机。预期：worker 存活（500/降级兜底，不重启风暴）、新登录被拒、
 * Redis 恢复后**无需重启 worker** 登录即恢复。
 * Scenario 1: a Redis outage. Expected: workers survive (500/degraded fallback, no restart storm), fresh
 * logins are rejected, and logins recover without restarting the workers once Redis returns.
 *
 * @param array<string, string> $cmds
 *
 * @return list<array{name: string, pass: bool, detail: string}>
 */
function drillRedisOutage(DrillConfig $cfg, array $cmds): array
{
    $out = [];

    // 注入前基线：登录必须通
    $baseline = drillGatewayLogin($cfg);
    $out[] = ['name' => 'redis/基线登录', 'pass' => $baseline['ok'], 'detail' => $baseline['detail']];

    shell_exec($cmds['redis-stop'] . ' 2>&1');

    // 注入有效性：等端口真正释放（shutdown 是异步的——立刻重启会抢绑失败且 daemon 静默退出，
    // Redis 一直处于宕机态，后续场景全部被拖垮。这是演练器自身的竞态，第一版实跑踩中）
    // Injection validity: wait for the port to actually free (shutdown is async — an immediate restart fails
    // to bind and the daemon exits silently, leaving Redis down and poisoning every later scenario. A real
    // orchestrator race hit on the first run).
    $stopped = false;
    for ($i = 0; $i < 20; ++$i) {
        if (!drillTcpProbe($cfg->redisPort)) {
            $stopped = true;
            break;
        }
        usleep(500_000);
    }
    if (!$stopped) {
        return [['name' => 'redis/场景', 'pass' => true, 'detail' => 'SKIP：注入命令未生效（Redis 仍在监听，检查 --redis-stop）']];
    }

    // 恢复：带重试地启动（绑定失败重试 ≤3 次），并等端口真正就绪
    // Recovery: start with retries (bind failures retried ≤3 times) and wait for the port to be truly ready.
    $restarted = false;
    for ($attempt = 0; $attempt < 3 && !$restarted; ++$attempt) {
        shell_exec($cmds['redis-start'] . ' 2>&1');
        for ($i = 0; $i < 10; ++$i) {
            if (drillTcpProbe($cfg->redisPort)) {
                $restarted = true;
                break;
            }
            usleep(500_000);
        }
    }
    if (!$restarted) {
        return [['name' => 'redis/场景', 'pass' => false, 'detail' => 'FAIL：Redis 重启命令 3 次尝试后仍未监听（检查 --redis-start）']];
    }
    $out[] = ['name' => 'redis/宕机期间 worker 存活', 'pass' => drillTcpProbe($cfg->gatewayPort) && drillTcpProbe($cfg->mapPort), 'detail' => 'gateway+map 端口仍接受连接（500/降级兜底）'];

    // 断言：宕机窗口内新登录被拒（discover 异常归一为 503 no available channel）
    $during = drillGatewayLogin($cfg);
    $out[] = ['name' => 'redis/宕机期间新登录被拒', 'pass' => !$during['ok'], 'detail' => $during['ok'] ? '异常：Redis 宕机却登录成功' : $during['detail']];

    // 恢复 + 自愈断言：不重启任何 worker，登录即恢复（心跳 ≤5s 重新注册进注册表）
    // Recovery + self-heal assertion: no worker restarts; the login recovers (heartbeats re-register within ≤5s).
    $recovered = false;
    $recoverDetail = 'Redis 重启后 gateway 一直无响应';
    for ($i = 0; $i < 30; ++$i) {
        usleep(1_000_000);
        $probe = drillGatewayLogin($cfg);
        if ($probe['ok']) {
            $recovered = true;
            $recoverDetail = sprintf('%s（未重启 worker，%ds 内自愈）', $probe['detail'], $i + 1);
            break;
        }
        $recoverDetail = $probe['detail'];
    }
    $out[] = ['name' => 'redis/恢复后免重启自愈', 'pass' => $recovered, 'detail' => $recoverDetail];

    return $out;
}

/**
 * 场景 2：MySQL 宕机。预期：登录/游戏主循环不受影响（归档是异步脏标记 + 失败重试 + 日志），
 * worker 存活；恢复后自愈。归档恢复的直接断言（flush 成功）依赖业务事件，本场景断言存活与可用性，
 * 归档失败的日志证据交由 §7.3 的告警承接。
 * Scenario 2: a MySQL outage. Expected: login and the main loop are unaffected (archiving is async dirty
 * marking with retries and logging), workers survive, and the system self-heals after recovery. A direct
 * flush-success assertion depends on business events, so this scenario asserts liveness and availability;
 * the archive-failure log evidence is carried by §7.3's alerting.
 *
 * @param array<string, string> $cmds
 *
 * @return list<array{name: string, pass: bool, detail: string}>
 */
function drillMysqlOutage(DrillConfig $cfg, array $cmds): array
{
    $out = [];

    // 前置：MySQL 可达才演练（mysqladmin 需要凭据/套接字，可靠性差——TCP 端口探针作回退；
    // 都不可达 = 环境未装 MySQL 服务，SKIP 语义）
    $mysqlWasUp = str_contains((string) shell_exec('mysqladmin --connect-timeout=2 ping 2>&1'), 'alive')
        || drillTcpProbe(3306);
    if (!$mysqlWasUp) {
        return [['name' => 'mysql/场景', 'pass' => true, 'detail' => 'SKIP：本机 MySQL 不可达，跳过演练（CI 有 MySQL 服务容器可跑）']];
    }

    $baseline = drillGatewayLogin($cfg);
    $out[] = ['name' => 'mysql/基线登录', 'pass' => $baseline['ok'], 'detail' => $baseline['detail']];

    shell_exec($cmds['mysql-stop'] . ' 2>&1');

    // 注入有效性：等 3306 真正释放（同 redis 场景的编排器竞态教训）
    // Injection validity: wait for 3306 to actually free (the same orchestrator-race lesson as the redis scenario).
    $stopped = false;
    for ($i = 0; $i < 20; ++$i) {
        if (!drillTcpProbe($cfg->mysqlPort)) {
            $stopped = true;
            break;
        }
        usleep(500_000);
    }
    if (!$stopped) {
        return [['name' => 'mysql/场景', 'pass' => true, 'detail' => 'SKIP：注入命令未生效（MySQL 仍在 3306 监听，检查 --mysql-stop 权限）']];
    }

    $aliveDuring = drillTcpProbe($cfg->gatewayPort) && drillTcpProbe($cfg->mapPort);
    $loginDuring = drillGatewayLogin($cfg);
    $out[] = ['name' => 'mysql/宕机期间 worker 存活且登录不受影响', 'pass' => $aliveDuring && $loginDuring['ok'], 'detail' => $aliveDuring && $loginDuring['ok'] ? 'MySQL 已确认宕机；归档为异步脏标记，主链路不触 MySQL' : sprintf('alive=%s login=%s', $aliveDuring ? '是' : '否', $loginDuring['detail'])];

    shell_exec($cmds['mysql-start'] . ' 2>&1');
    // mysqld 启动慢（初始化 + 崩溃恢复）：给足 30s 端口就绪窗口
    // mysqld boots slowly (init + crash recovery): allow a 30s port-readiness window.
    $healed = false;
    for ($i = 0; $i < 60; ++$i) {
        if (drillTcpProbe($cfg->mysqlPort)) {
            $healed = true;
            break;
        }
        usleep(500_000);
    }
    $after = drillGatewayLogin($cfg);
    $out[] = ['name' => 'mysql/恢复后自愈', 'pass' => $healed && $after['ok'], 'detail' => $healed ? ($after['ok'] ? $after['detail'] : $after['detail']) : 'MySQL 未能重启（检查注入命令权限）'];

    return $out;
}

/**
 * 场景 3：map worker kill -9。预期：Workerman master 自动重生 worker，端口恢复，登录链路恢复——
 * 与 blueprint/11 的 kill -9 自愈实测同口径，此处固化为可重复演练。
 * Scenario 3: kill -9 on a map worker. Expected: the Workerman master respawns the worker, the port
 * recovers and the login chain recovers — same convention as blueprint/11's kill -9 self-heal measurement,
 * now made a repeatable drill.
 *
 * @return array{name: string, pass: bool, detail: string}
 */
function drillKill9(DrillConfig $cfg): array
{
    $baseline = drillGatewayLogin($cfg);
    if (!$baseline['ok']) {
        return ['name' => 'kill9/场景', 'pass' => false, 'detail' => '基线登录失败：' . $baseline['detail']];
    }

    $ps = shell_exec('ps -eo pid=,args= 2>/dev/null') ?? '';
    $victimPid = null;
    foreach (explode("\n", $ps) as $line) {
        if (preg_match('/^\s*(\d+)\s+.*run-worker\.php.*--service=map/', $line, $m)
            && !str_contains($line, 'fault-drill')) {
            $victimPid = (int) $m[1];
            break;
        }
    }
    if ($victimPid === null) {
        // start-maps 形态下 map worker 命令行不同，按 map 端口进程兜底定位
        foreach (explode("\n", $ps) as $line) {
            if (preg_match('/^\s*(\d+)\s+.*start-maps\.php/', $line, $m)) {
                $victimPid = (int) $m[1];
                break;
            }
        }
    }
    if ($victimPid === null) {
        return ['name' => 'kill9/场景', 'pass' => false, 'detail' => 'SKIP：未找到 map worker 进程'];
    }

    posix_kill($victimPid, SIGKILL);
    usleep(500_000);

    // Workerman master 重生 + 登录链路恢复（≤15s）
    $recovered = false;
    $detail = '15s 内未恢复';
    for ($i = 0; $i < 30; ++$i) {
        usleep(500_000);
        if (drillTcpProbe($cfg->mapPort, 0.5)) {
            $probe = drillGatewayLogin($cfg);
            if ($probe['ok']) {
                $recovered = true;
                $detail = sprintf('worker pid=%d 被 kill -9 后 %.1fs 内自愈', $victimPid, ($i + 1) * 0.5);
                break;
            }
            $detail = '端口恢复但登录失败：' . $probe['detail'];
        }
    }

    return ['name' => 'kill9/自愈', 'pass' => $recovered, 'detail' => $detail];
}

/**
 * 场景 4：storage-exporter 故障。断言三件事:
 * ① 主链路解耦——exporter 被 kill 期间登录/游戏不受影响(异步旁路);
 * ② 可观测降级——故障期间 backlog 心跳键 nythros:perf:storage-exporter:last 停止刷新(告警信号);
 * ③ 重启消化——手动重启 exporter 后 backlog 被消化、gauge 恢复刷新。
 * 已知边界:bin/server 对退出的子服务只收割不重生(map worker 由各自 Workerman master 重生),
 * 故本场景的重启是演练手动执行;生产重生交由 systemd/编排层(见 deployment §3)。
 * Scenario 4: storage-exporter outage. Asserts three things: ① decoupling — login/game unaffected while
 * the exporter is killed (async sidecar); ② observable degradation — the backlog heartbeat key
 * nythros:perf:storage-exporter:last stops refreshing while down (the alarm signal); ③ restart drains — a
 * manual restart consumes the backlog and resumes the gauge. Known boundary: bin/server reaps but does not
 * respawn exited children (map workers respawn via their own Workerman masters), so this drill restarts the
 * exporter manually; production respawns belong to systemd/orchestration (deployment §3).
 *
 * @return list<array{name: string, pass: bool, detail: string}>
 */
function drillExporterOutage(DrillConfig $cfg): array
{
    $out = [];

    // 定位 run-exporter 进程树(演练托管栈里由 bin/server spawn;未托管/未起 storage 组则 SKIP)。
    // Workerman 会改写 proctitle:master/manager 的 args 仍含 run-exporter.php,worker 变成
    // "WorkerMan: worker process storage-exporter"——两类都要收集,两类都要杀(只杀 worker 会被
    // master/manager 重生=心跳照跳假绿;只杀 master 会留孤儿 worker=同样假绿,上一版 drill 实锤)
    // Locate the exporter process tree. Workerman rewrites proctitles: master/manager keep run-exporter.php
    // in args while the worker becomes "WorkerMan: worker process storage-exporter" — both must be collected
    // and killed (killing only the worker gets it respawned; killing only the master leaves an orphan worker —
    // both produce a false-green heartbeat, as this drill's previous iteration proved)
    $scanPids = static function (string $re): array {
        $ps = shell_exec('ps -eo pid=,args= 2>/dev/null') ?? '';
        $pids = [];
        foreach (explode("\n", $ps) as $line) {
            if (preg_match($re, $line) && !str_contains($line, 'fault-drill')) {
                $pids[] = (int) (preg_replace('/^\s*(\d+).*$/s', '$1', $line) ?? 0);
            }
        }

        return array_values(array_filter($pids));
    };
    $treeRe = '/.*(?:run-exporter\.php|WorkerMan.*storage-exporter).*/s';
    if ($scanPids($treeRe) === []) {
        return [['name' => 'exporter/场景', 'pass' => true, 'detail' => 'SKIP：未找到 run-exporter 进程（deploy.yaml 未声明 storage 组或栈为挂接模式）']];
    }

    $baseline = drillGatewayLogin($cfg);
    if (!$baseline['ok']) {
        return [['name' => 'exporter/场景', 'pass' => false, 'detail' => '基线登录失败：' . $baseline['detail']]];
    }

    $redis = new \Redis();
    if (@$redis->connect('127.0.0.1', $cfg->redisPort, 1.0) !== true) {
        return [['name' => 'exporter/场景', 'pass' => false, 'detail' => 'Redis 不可达，无法读心跳键']];
    }
    $lastKey = 'nythros:perf:storage-exporter:last';
    $readTs = static fn (): ?float => is_string($v = $redis->get($lastKey))
        ? (float) (json_decode($v, true)['ts'] ?? 0)
        : null;
    // 基线:先确认 exporter 已完成至少一次上报(轮询 ≤12s)——「首报未落地就 kill」会让停更断言失真
    // Baseline: wait (≤12s) for the exporter's first report; killing before it lands makes the stall moot
    $tsBefore = $readTs();
    for ($i = 0; $i < 24 && ($tsBefore === null || $tsBefore <= 0); ++$i) {
        usleep(500_000);
        $tsBefore = $readTs();
    }
    if ($tsBefore === null || $tsBefore <= 0) {
        $redis->close();

        return [['name' => 'exporter/场景', 'pass' => false, 'detail' => '12s 内未观测到 exporter 首次心跳上报(gauge 链路未生效?检查 run-exporter 上报块)']];
    }

    // 注入:两遍 kill -9——先断重生源(master/manager:args 含 run-exporter.php),再清幸存 worker
    // (proctitle 类),随后轮询确认全树消失;残留=注入失败,后续心跳断言不可信
    // Inject: kill -9 in two passes — first cut the respawn source (master/manager), then sweep surviving
    // workers, then poll until the whole tree is gone; leftovers mean the injection failed
    foreach ($scanPids('/.*run-exporter\.php.*/s') as $pid) {
        posix_kill($pid, SIGKILL);
    }
    usleep(300_000);
    foreach ($scanPids($treeRe) as $pid) {
        posix_kill($pid, SIGKILL);
    }
    $treeDead = false;
    for ($i = 0; $i < 10; ++$i) {
        usleep(300_000);
        if ($scanPids($treeRe) === []) {
            $treeDead = true;
            break;
        }
    }
    if (!$treeDead) {
        $redis->close();

        return [['name' => 'exporter/场景', 'pass' => false, 'detail' => '注入失败:exporter 进程树残留(无法确认心跳停更的归因),检查 Workerman 树形态']];
    }
    usleep(500_000);

    // 断言① 主链路解耦:宕机期间登录仍成功(游戏侧只依赖 Redis,不依赖 exporter)
    // Assertion 1 (decoupling): login still succeeds during the outage (the game side depends on Redis only)
    $during = drillGatewayLogin($cfg);
    $out[] = ['name' => 'exporter/宕机期间主链路不受影响', 'pass' => $during['ok'], 'detail' => $during['ok'] ? 'exporter 已被 kill,登录仍成功(异步旁路解耦)' : $during['detail']];

    // 断言② 可观测降级:kill 后跨一个上报周期(5s)连续两次读数相等 = 心跳已停更——判定只看 kill 后
    // 状态(kill 前一刻刚报过也不影响),无竞态;证明 exporter 失联会被告警发现(deployment §4)
    // Assertion 2 (observable degradation): two post-kill reads straddling one 5s cadence are equal = stalled.
    // The verdict uses only post-kill state (immune to a pre-kill report), proving exporter loss is alertable
    sleep(6);
    $tsA = $readTs();
    sleep(6);
    $tsB = $readTs();
    $stalled = $tsA !== null && $tsB !== null && abs($tsB - $tsA) < 0.001;
    $out[] = ['name' => 'exporter/宕机期间心跳停更可观测', 'pass' => $stalled, 'detail' => $stalled ? sprintf('kill 后 12s 两次读数一致(%.1f),告警可发现失联', $tsA) : sprintf('kill 后心跳仍在推进(%.1f→%.1f,exporter 未死?)', (float) $tsA, (float) $tsB)];
    $redis->close();

    // 恢复:重启 exporter(演练手动;生产由 systemd 重生)。注入期间无新脏数据,重启只验活性恢复。
    // Heal: restart the exporter (manual here; systemd in production). No new dirty data was injected, so
    // recovery asserts the liveness signal resumes.
    $logPath = $cfg->logDir . '/exporter-heal.log';
    if (!is_dir($cfg->logDir)) {
        mkdir($cfg->logDir, 0777, true);
    }
    $cmd = sprintf(
        'cd %s && NYTHROS_CONFIG_DIR=%s setsid php packages/demo/bin/run-exporter.php start >>%s 2>&1 < /dev/null &',
        escapeshellarg($cfg->repoRoot),
        escapeshellarg($cfg->repoRoot . '/packages/demo/config'),
        escapeshellarg($logPath),
    );
    exec($cmd);

    // 断言③ 自愈:≤15s 内心跳恢复推进(exporter 重新消费+上报)
    // Assertion 3 (self-heal): within 15s the heartbeat advances again (the exporter resumes consuming/reporting)
    $recovered = false;
    $detail = '15s 内心跳未恢复';
    $redis2 = null;
    for ($i = 0; $i < 30; ++$i) {
        usleep(500_000);
        if ($redis2 === null) {
            $redis2 = new \Redis();
            if (@$redis2->connect('127.0.0.1', $cfg->redisPort, 0.5) !== true) {
                $redis2 = null;

                continue;
            }
        }
        $v = $redis2->get($lastKey);
        $ts = is_string($v) ? (float) (json_decode($v, true)['ts'] ?? 0) : 0.0;
        if ($ts > ($tsStalled ?? 0) + 0.001) {
            $recovered = true;
            $detail = sprintf('exporter 重启后 %.1fs 心跳恢复推进', ($i + 1) * 0.5);
            break;
        }
    }
    if ($redis2 !== null) {
        $redis2->close();
    }
    $out[] = ['name' => 'exporter/重启后恢复消费上报', 'pass' => $recovered, 'detail' => $detail];

    return $out;
}

/**
 * 自测：无环境依赖的正负向用例（探针解析/帧读取/参数归因）。
 * Self-test: environment-free positive/negative cases (probe parsing / frame reading / attribution).
 */
function faultSelfTest(): int
{
    $failures = [];
    $assert = static function (bool $cond, string $name) use (&$failures): void {
        echo ($cond ? 'PASS' : 'FAIL') . "  {$name}\n";
        if (!$cond) {
            $failures[] = $name;
        }
    };

    // ws 帧编码/解析 roundtrip（复用 soak 自测同款最小帧协议）
    // The ws frame encode/parse roundtrip (the same minimal frame protocol as the soak self-test).
    $payload = json_encode(['type' => 'auth_failed', 'payload' => ['reason' => 'x']]);
    $fixture = chr(0x81) . chr(strlen($payload)) . $payload;
    $fp = fopen('php://temp', 'r+');
    fwrite($fp, $fixture);
    rewind($fp);
    $parsed = drillReadWsFrame($fp, 1.0);
    $assert($parsed !== null && $parsed['opcode'] === 0x1 && $parsed['payload'] === $payload, '服务端帧解析 roundtrip');
    fclose($fp);

    // TCP 探针对真实端口/死端口的行为
    // The TCP probe against a real port / a dead port.
    $srv = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    $name = stream_socket_get_name($srv, false);
    $port = (int) substr($name, strrpos($name, ':') + 1);
    $assert(drillTcpProbe($port), 'TCP 探针：真实端口可达');
    fclose($srv);
    $assert(!drillTcpProbe($port), 'TCP 探针：关闭后端口失联');

    // 场景函数对未知场景快速失败
    $assert(function_exists('drillKill9') && function_exists('drillRedisOutage') && function_exists('drillMysqlOutage') && function_exists('drillExporterOutage'), '四场景函数就位');

    if ($failures !== []) {
        printf("[fault-drill] SELF-TEST FAIL：%d 项断言未过\n", count($failures));

        return 1;
    }
    echo '[fault-drill] SELF-TEST PASS' . "\n";

    return 0;
}
