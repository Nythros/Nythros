<?php

declare(strict_types=1);

// 定位：benchmarks/soak-map.php —— 长跑稳定性演练（soak）编排器。
// 编排方式：托管（或 --no-server 挂接）完整服务栈 → 循环驱动 stress-map 波次（真实 WS 客户端）→
// 每波采样 worker RSS / Redis / 日志体积 → 最小二乘评估 RSS 斜率（内存泄漏哨兵）+ 认证成功率 +
// 帧率下限，输出时间线 JSONL 与 RESULT 结论。CI 的 soak 冒烟（2 分钟）与本机小时级长跑共用本脚本，
// 只差参数（--minutes）。
// Located at: benchmarks/soak-map.php — the long-run stability (soak) drill orchestrator.
// Orchestration: hosts (or attaches to via --no-server) the full stack -> drives stress-map waves (real WS
// clients) -> samples worker RSS / Redis / log sizes per wave -> evaluates the RSS slope via least squares
// (the memory-leak sentinel) plus the auth success ratio and an fps floor, emitting a JSONL timeline and a
// RESULT verdict. The CI soak smoke (2 minutes) and a local hours-long soak share this script, differing
// only in --minutes.
//
// 用法 Usage:
//   php benchmarks/soak-map.php --minutes=2 --clients=10                 # CI 冒烟 / CI smoke
//   php benchmarks/soak-map.php --minutes=240 --clients=30 --wave-seconds=60   # 4h 长跑 / a 4h soak
//   php benchmarks/soak-map.php --minutes=120 --clients=240 --play --map-ids=map-1,map-2  # 混合玩法长跑（切图/副本/组队/聊天）/ mixed-gameplay soak
//   php benchmarks/soak-map.php --no-server --minutes=5                  # 挂接已在跑的栈 attach to a live stack
//   php benchmarks/soak-map.php --self-test
// 结果 Results: /tmp/nythros-drill/soak-timeline.jsonl + stdout 摘要（末行 RESULT: PASS|FAIL）。

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/lib/drill-harness.php';

if (in_array('--self-test', $argv, true)) {
    exit(soakSelfTest());
}

/** @return array{minutes: float, clients: int, waveSeconds: int, noServer: bool, json: bool, rssSlope: float, moveMs: int, settleMoves: int, minAuth: float, minMemMb: float, maxFrameP99Ms: float, minWorkers: int, mapIds: string, play: bool, playSilence: int} */
function soakParseArgs(array $argv): array
{
    $o = ['minutes' => 5.0, 'clients' => 10, 'waveSeconds' => 30, 'noServer' => false, 'json' => false,
        'rssSlope' => 16.0, 'moveMs' => 1000, 'settleMoves' => 0, 'minAuth' => 0.9, 'minMemMb' => 200.0,
        'maxFrameP99Ms' => 3000.0, 'minWorkers' => 4, 'mapIds' => 'map-1', 'play' => false, 'playSilence' => 5];
    foreach (array_slice($argv, 1) as $arg) {
        if (preg_match('/^--minutes=([\d.]+)$/', $arg, $m)) {
            $o['minutes'] = (float) $m[1];
        } elseif (preg_match('/^--clients=(\d+)$/', $arg, $m)) {
            $o['clients'] = (int) $m[1];
        } elseif (preg_match('/^--wave-seconds=(\d+)$/', $arg, $m)) {
            $o['waveSeconds'] = (int) $m[1];
        } elseif (preg_match('/^--rss-slope=([\d.]+)$/', $arg, $m)) {
            $o['rssSlope'] = (float) $m[1];
        } elseif (preg_match('/^--move-ms=(\d+)$/', $arg, $m)) {
            $o['moveMs'] = (int) $m[1];
        } elseif (preg_match('/^--settle-moves=(\d+)$/', $arg, $m)) {
            $o['settleMoves'] = (int) $m[1];
        } elseif (preg_match('/^--min-auth=([\d.]+)$/', $arg, $m)) {
            $o['minAuth'] = (float) $m[1];
        } elseif (preg_match('/^--min-mem-mb=([\d.]+)$/', $arg, $m)) {
            $o['minMemMb'] = (float) $m[1];
        } elseif (preg_match('/^--max-frame-p99-ms=([\d.]+)$/', $arg, $m)) {
            $o['maxFrameP99Ms'] = (float) $m[1];
        } elseif (preg_match('/^--min-workers=(\d+)$/', $arg, $m)) {
            $o['minWorkers'] = (int) $m[1];
        } elseif (preg_match('/^--map-ids=([\w,-]+)$/', $arg, $m)) {
            $o['mapIds'] = $m[1];
        } elseif ($arg === '--no-server') {
            $o['noServer'] = true;
        } elseif ($arg === '--play') {
            $o['play'] = true;
        } elseif (preg_match('/^--play-silence=(\d+)$/', $arg, $m)) {
            $o['playSilence'] = (int) $m[1];
        } elseif ($arg === '--json') {
            $o['json'] = true;
        }
    }

    return $o;
}

$opts = soakParseArgs($argv);
$minutes = max(0.5, $opts['minutes']);
$clients = max(1, $opts['clients']);
$waveSeconds = max(5, $opts['waveSeconds']);
$deadline = microtime(true) + $minutes * 60.0;

$cfg = new DrillConfig(dirname(__DIR__), 'php bin/server start', !$opts['noServer'], 18285, 18081, '/tmp/nythros-drill', [
    // 账号表按客户端数扩展（演示账号缺省只有 1001-1003）：uid=secret 开发明文形态，装载即哈希
    // The account table scales with the client count (the demo defaults only cover 1001-1003): uid=secret
    // development plaintext, hashed on load.
    'NYTHROS_ACCOUNTS' => implode(',', array_map(
        static fn (int $uid): string => "{$uid}=secret",
        range(1001, 1000 + max(3, $clients)),
    )),
]);
echo sprintf("[soak] start: %.0f 分钟 / %d 客户端 / 波次 %ds / RSS 斜率阈值 %.0fKB/采样\n", $minutes, $clients, $waveSeconds, $opts['rssSlope']);

$samples = [];
$waves = [];
$timelinePath = $cfg->logDir . '/soak-timeline.jsonl';
$waveIndex = 0;
$verdictOk = true;
$prevDropped = null;
$server = null;

// 时间线先于服务栈打开（编排器健壮性）：目录属主不符（root 建目录后以普通用户跑，实抓）等写入失败
// 必须 fail-fast——php 的 fatal/exit 不执行 finally，若托管栈已启动会留下孤儿服务。
// Open the timeline BEFORE the managed stack: a write failure (e.g. a root-owned dir under a normal user —
// the measured cause) must fail fast; PHP fatals and exit() skip finally, which would orphan a hosted stack.
if (!is_dir($cfg->logDir)) {
    @mkdir($cfg->logDir, 0777, true);
}
$timeline = fopen($timelinePath, 'wb');
if ($timeline === false) {
    fwrite(STDERR, "[soak] fatal: 时间线不可写 {$timelinePath}（检查 /tmp/nythros-drill 属主/权限）\n");
    exit(1);
}

$server = $cfg->manageServer ? drillStartServer($cfg) : null;
if ($server === null && $cfg->manageServer) {
    echo "[soak] 检测到端口已就绪的既有实例，转为挂接模式（不托管启停）\n";
}

$abortReason = null;
try {
    while (microtime(true) < $deadline) {
        // ① 波前采样
        $before = drillSample($cfg);
        fwrite($timeline, json_encode(['kind' => 'sample', 'phase' => 'pre-wave-' . $waveIndex] + $before, JSON_UNESCAPED_UNICODE) . "\n");
        if (!$before['alive']) {
            // 失联走 break（不是 exit）：finally 必须执行以停掉托管栈并关文件
            // Liveness loss breaks (never exit()): finally must run to stop the hosted stack and close files.
            fwrite($timeline, json_encode(['kind' => 'abort', 'reason' => 'stack-lost', 'ts' => microtime(true)], JSON_UNESCAPED_UNICODE) . "\n");
            $abortReason = '服务栈失联（gateway 无响应），提前终止';
            $verdictOk = false;

            break;
        }

        // ② stress 波次（真实 WS 客户端：登录 → move 循环 → 广播回程）
        $waveLeft = min($waveSeconds, $deadline - microtime(true));
        if ($waveLeft < 5) {
            break;
        }
        $waveIndex++;
        // --play：driver 换成混合玩法客户端（同参驱动，另含迁移/副本/组队/聊天周期），否则纯走位
        // With --play the driver becomes the mixed-gameplay client (same params plus transfer/dungeon/team/chat
        // cycles); otherwise the pure walking stress as before.
        $driver = $opts['play'] ? 'stress-play.php' : 'stress-map.php';
        $out = shell_exec(sprintf(
            'php %s/benchmarks/%s --clients=%d --seconds=%d --move-ms=%d --settle-moves=%d --map-ids=%s --json 2>&1',
            escapeshellarg($cfg->repoRoot),
            $driver,
            $clients,
            (int) ceil($waveLeft),
            $opts['moveMs'],
            $opts['settleMoves'],
            preg_replace('/[^a-zA-Z0-9,_-]/', '', $opts['mapIds']), // 白名单过滤（拼进 shell 前的纵深防御） Whitelist filtering (defense-in-depth before shell interpolation)
        )) ?? '';
        // 原始输出留存（取证用）：auth=0 的波次可直接查 stress 侧到底发生了什么
        // The raw output preserved for forensics: an auth=0 wave can be diagnosed from the stress side directly.
        file_put_contents($cfg->logDir . '/stress-last.log', $out);
        $stats = soakParseStressJson($out);
        $wave = [
            'ts' => microtime(true),
            'kind' => 'wave',
            'driver' => $opts['play'] ? 'play' : 'map',
            'clients' => $clients,
            'authOk' => $stats['authOk'] ?? 0,
            'fps' => $stats['fps'] ?? 0.0,
            'p99' => $stats['p99'] ?? 0.0,
        ];
        if ($opts['play']) {
            // 客户端侧玩法计数（成功回执为准）；服务端侧由波后 drillPlayProbe 独立佐证
            // Client-side play counters (receipt-based); the server side is corroborated by drillPlayProbe.
            $wave['play'] = [
                'transfers' => (int) ($stats['transfers'] ?? 0),
                'dungeonEnter' => (int) ($stats['dungeonEnter'] ?? 0),
                'dungeonExit' => (int) ($stats['dungeonExit'] ?? 0),
                'teamJoined' => (int) ($stats['teamJoined'] ?? 0),
                'teamLeft' => (int) ($stats['teamLeft'] ?? 0),
                'teamDisbanded' => (int) ($stats['teamDisbanded'] ?? 0),
                'chatSent' => (int) ($stats['chatSent'] ?? 0),
                'chatRecv' => (int) ($stats['chatRecv'] ?? 0),
            ];
        }
        $waves[] = $wave;
        fwrite($timeline, json_encode($wave, JSON_UNESCAPED_UNICODE) . "\n");

        // ③ 波后采样 + 崩塌熔断（恶性问题及时终止：内存泄漏斜率 / 认证崩塌 / 帧延迟崩坏 /
        //    事件总线丢弃激增 / 系统内存见底 / worker 数量下降），保留现场供取证
        // ③ The post-wave sample + collapse circuit-breaker (abort on a leak slope / an auth collapse /
        //    frame-latency blowup / an eventbus-drops surge / exhausted system memory / lost workers),
        //    preserving the scene for forensics.
        $after = drillSample($cfg);
        if ($opts['play']) {
            $after['playProbe'] = drillPlayProbe($cfg); // transfer 键计数 / dungeon playerCount / team 键族
        }
        fwrite($timeline, json_encode(['kind' => 'sample', 'phase' => 'post-wave-' . $waveIndex] + $after, JSON_UNESCAPED_UNICODE) . "\n");
        $samples[] = $after;
        $abort = soakGuard($opts, $samples, $waves, $after, $wave, $prevDropped);
        $prevDropped = $after['droppedTotal'] ?? $prevDropped;
        if ($abort !== null) {
            fwrite($timeline, json_encode(['kind' => 'abort', 'reason' => $abort, 'ts' => microtime(true)], JSON_UNESCAPED_UNICODE) . "\n");
            // 注意花括号界定：$abort 后紧跟全角括号时 PHP 会把多字节吞进变量名（\x80-\xff 属标识符字节），
            // 真实 ABORT 归因曾被此处静默成空白（240 play 验收实抓）
            fwrite(STDERR, "[soak] ABORT: {$abort}（现场已保留：{$timelinePath}）\n");
            $verdictOk = false;
            break;
        }
        echo sprintf(
            "[soak] wave#%d auth=%d/%d fps=%.1f p99=%.0fms | workers=%d rssTotal=%dKB rssPeak=%dKB redisKeys=%s memAvail=%sMB frameMean=%sms%s\n",
            $waveIndex,
            $wave['authOk'],
            $clients,
            $wave['fps'],
            $wave['p99'],
            $after['workers'],
            $after['rssTotalKb'],
            $after['rssPeakKb'],
            $after['redisDbsize'] === null ? 'n/a' : (string) $after['redisDbsize'],
            $after['memAvailMb'] === null ? 'n/a' : (string) $after['memAvailMb'],
            $after['frameMeanMs'] === null ? 'n/a' : (string) $after['frameMeanMs'],
            isset($wave['play']) ? sprintf(
                ' | play tr=%d dIn=%d dOut=%d team=%d chat=%d/%d',
                $wave['play']['transfers'],
                $wave['play']['dungeonEnter'],
                $wave['play']['dungeonExit'],
                $wave['play']['teamJoined'],
                $wave['play']['chatSent'],
                $wave['play']['chatRecv']
            ) : '',
        );
    }
} finally {
    fclose($timeline);
    drillStopServer($server);
}

$verdict = drillVerdict($samples, $waves, $opts['rssSlope'], $opts['minAuth']);
$verdictOk = $verdictOk && $verdict['ok'];
$playReason = soakPlayVerdict($waves); // --play 波的「玩法静默」裁决（纯函数；非 play 恒 null）
if ($playReason !== null) {
    $verdict['ok'] = false;
    $verdict['reasons'][] = $playReason;
    $verdictOk = false;
}
$summary = [
    'minutes' => $minutes,
    'clients' => $clients,
    'moveMs' => $opts['moveMs'],
    'mapIds' => $opts['mapIds'],
    'waves' => count($waves),
    'timeline' => $timelinePath,
    'abort' => $abortReason,
] + $verdict;
if ($opts['play']) {
    $summary['playTotals'] = soakPlayTotals($waves);
    // 服务端累计铁证：dungeon 频道出站字节（托管栈生命周期内自 0 单调，非零即证明副本进过真实负载）
    $dBytes = null;
    for ($i = count($samples) - 1; $i >= 0; --$i) {
        if (isset($samples[$i]['playProbe']['dungeonOutBytes'])) {
            $dBytes = $samples[$i]['playProbe']['dungeonOutBytes'];
            break;
        }
    }
    $summary['dungeonOutBytes'] = $dBytes;
}

if ($opts['json']) {
    echo json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
} else {
    foreach ($summary['reasons'] as $reason) {
        echo "[soak] WARN: $reason\n";
    }
    echo sprintf(
        "[soak] 摘要：waves=%d auth=%.1f%% minFps=%.2f rssSlope=%.2fKB/采样 → %s\n",
        count($waves),
        $verdict['authRatio'] * 100,
        $verdict['minFps'],
        $verdict['rssSlopeKb'],
        $verdictOk ? 'PASS' : 'FAIL',
    );
    if ($opts['play']) {
        $pt = soakPlayTotals($waves);
        echo sprintf(
            "[soak] 玩法总量：迁移=%d 副本 进%d/出%d | 组队 加入%d/退%d/解散%d | 聊天 发%d/收%d\n",
            $pt['transfers'],
            $pt['dungeonEnter'],
            $pt['dungeonExit'],
            $pt['teamJoined'],
            $pt['teamLeft'],
            $pt['teamDisbanded'],
            $pt['chatSent'],
            $pt['chatRecv'],
        );
        echo sprintf(
            "[soak] 服务端佐证：dungeon 累计出站=%s\n",
            null === $summary['dungeonOutBytes'] ? 'n/a' : sprintf('%.2fMB', $summary['dungeonOutBytes'] / 1048576),
        );
    }
    echo 'RESULT: ' . ($verdictOk ? 'PASS' : 'FAIL') . "\n";
}

exit($verdictOk ? 0 : 1);

/**
 * 崩塌熔断（恶性问题及时终止）：任一触发即中止长跑并保留现场。返回 null = 继续。
 * The collapse circuit-breaker (abort on anything malignant, preserving the scene): non-null = the abort reason.
 *
 * @param array{minAuth: float, minMemMb: float, maxFrameP99Ms: float, minWorkers: int, play?: bool, playSilence?: int} $opts
 * @param list<array{rssTotalKb: int}> $samples
 * @param list<array{authOk: int, clients: int, fps: float, p99: float}> $waves
 * @param array{workers: int, memAvailMb: ?float, droppedTotal: ?int, rssTotalKb: int} $after
 * @param array{authOk: int, clients: int, p99: float} $wave
 * @param ?int $prevDropped 上一波次的 dropped 累计（取增量用）
 */
function soakGuard(array $opts, array $samples, array $waves, array $after, array $wave, ?int $prevDropped): ?string
{
    $wave = end($waves) ?: [];
    if (count($waves) > 0 && ($wave['clients'] ?? 0) > 0 && $wave['authOk'] / $wave['clients'] < $opts['minAuth']) {
        return sprintf('认证崩塌：%d/%d < %.0f%%', $wave['authOk'], $wave['clients'], $opts['minAuth'] * 100);
    }
    if (count($waves) > 0 && $wave['p99'] > $opts['maxFrameP99Ms']) {
        return sprintf('帧延迟崩坏：p99=%.0fms > %.0fms', $wave['p99'], $opts['maxFrameP99Ms']);
    }
    if ($after['workers'] < $opts['minWorkers']) {
        return sprintf('worker 数量下降：%d < %d（进程意外死亡且未重生）', $after['workers'], $opts['minWorkers']);
    }
    if ($after['memAvailMb'] !== null && $after['memAvailMb'] < $opts['minMemMb']) {
        return sprintf('系统内存见底：MemAvailable=%.0fMB < %.0fMB', $after['memAvailMb'], $opts['minMemMb']);
    }
    // dropped 是 P9 设计内的泄压机制（droppable 低档帧可丢，绝对坐标 + 周期快照兜底）——240 客户端
    // 实测基线 ≈7M/波，故熔断线设在 2× 基线：激增才有诊断价值，常态丢弃作为优化信号记录。
    // The drops are the P9 by-design shedding (droppable low-tier frames; absolute coords + periodic snapshots
    // backstop) — the measured baseline at 240 clients is ≈7M/wave, so the breaker sits at 2× baseline: only a
    // surge is diagnostic; the steady rate is recorded as an optimization signal.
    if ($prevDropped !== null && $after['droppedTotal'] !== null && $after['droppedTotal'] - $prevDropped > 15_000_000) {
        return sprintf('事件总线丢弃激增：单波 +%d（超过基线 2 倍，疑似雪崩）', $after['droppedTotal'] - $prevDropped);
    }
    // RSS 斜率（≥3 采样即可判）：超阈值 = 疑似内存泄漏
    // The RSS slope (judged from ≥3 samples): over the threshold = a suspected leak.
    if (count($samples) >= 3) {
        $n = count($samples);
        $sumX = $sumY = $sumXY = $sumXX = 0.0;
        foreach ($samples as $i => $s) {
            $sumX += $i;
            $sumY += $s['rssTotalKb'];
            $sumXY += $i * $s['rssTotalKb'];
            $sumXX += $i * $i;
        }
        $denom = $n * $sumXX - $sumX * $sumX;
        $slope = $denom !== 0.0 ? ($n * $sumXY - $sumX * $sumY) / $denom : 0.0;
        $slopeThreshold = 256.0; // 长跑口径：64KB/采样以上视为疑似泄漏（CI 冒烟用 256 抵消 warmup）
        if ($slope > $slopeThreshold) {
            return sprintf('RSS 线性增长 %.1f KB/采样 > %.0f（疑似内存泄漏）', $slope, $slopeThreshold);
        }
    }
    // 玩法静默熔断（24h 轮中途止损）：最近 K 个 play 波全部零活动 = driver 死亡/协议漂移，
    // 继续跑只会烧时间出不了玩法证据——立即 ABORT 留现场，把「明天才发现」变成「几小时内自动停」。
    // 与终局 soakPlayVerdict 的分工：本熔断管中途（防白跑），verdict 管收尾（全零判假绿）。
    // Play-silence circuit-breaker (mid-run stop-loss): the last K play waves with zero activity mean a dead
    // driver or a protocol drift — abort now instead of burning the remaining hours; complements the terminal
    // soakPlayVerdict (which fails an all-zero run as a false green).
    if (($opts['play'] ?? false) && ($opts['playSilence'] ?? 0) > 0) {
        $playWaves = array_values(array_filter($waves, static fn (array $w): bool => ($w['driver'] ?? 'map') === 'play'));
        if (count($playWaves) >= $opts['playSilence']) {
            $tail = array_slice($playWaves, -$opts['playSilence']);
            if (array_sum(array_map(soakPlayActivity(...), $tail)) === 0) {
                return sprintf('玩法静默：最近 %d 个 play 波零活动（driver 疑似死亡/协议漂移），中途止损', $opts['playSilence']);
            }
        }
    }

    return null;
}

/**
 * 单波玩法活动度（纯函数）：回执事件的总和（迁移/副本进出/组队三态/聊天收发）。
 * 有发无收不计（与 soakPlayVerdict 同口径——回执才算「发生」，静默熔断要的是服务端真动过）。
 * Per-wave play activity (pure): sum of receipted events; sent-without-receipt does not count (same
 * convention as soakPlayVerdict — receipts are what prove server-side motion).
 *
 * @param array<string, mixed> $wave
 */
function soakPlayActivity(array $wave): int
{
    $p = $wave['play'] ?? [];

    return (int) ($p['transfers'] ?? 0) + (int) ($p['dungeonEnter'] ?? 0) + (int) ($p['dungeonExit'] ?? 0)
        + (int) ($p['teamJoined'] ?? 0) + (int) ($p['teamLeft'] ?? 0) + (int) ($p['teamDisbanded'] ?? 0)
        + (int) ($p['chatRecv'] ?? 0);
}

/**
 * 从 stress-map 的混合输出（进度行 + 末尾 JSON）中提取统计对象。
 * pretty-print JSON 含嵌套对象，不能只找最后一个 `{`——从行首定位候选起点，从后往前试解码。
 * Extracts the stats object from stress-map's mixed output (progress lines + trailing JSON).
 * The pretty-printed JSON nests objects, so the last `{` is not the document start — locate candidates at
 * line starts and decode backwards until one parses.
 *
 * @return array{authOk?: int, fps?: float, p99?: float}
 */
function soakParseStressJson(string $output): array
{
    // 首选：整段输出直接解码（--json 模式输出就是纯 JSON 文档）
    // Preferred: decode the whole output (--json mode emits a pure JSON document).
    $decoded = json_decode($output, true);
    if (is_array($decoded)) {
        return $decoded;
    }
    // 回退：从行首定位候选起点，从后往前试解码（输出前有进度行时）
    // Fallback: line-start candidates decoded backwards (when progress lines precede the JSON).
    $pos = strrpos($output, "\n{");
    while ($pos !== false) {
        $decoded = json_decode(substr($output, $pos + 1), true);
        if (is_array($decoded)) {
            return $decoded;
        }
        $pos = strrpos(substr($output, 0, $pos), "\n{");
    }

    return [];
}

/**
 * 玩法总量聚合（纯函数）：跨波累加 play 计数。非 play 波忽略。
 * Aggregate play counters across waves (pure).
 *
 * @param list<array<string, mixed>> $waves
 * @return array{transfers:int, dungeonEnter:int, dungeonExit:int, teamJoined:int, teamLeft:int, teamDisbanded:int, chatSent:int, chatRecv:int}
 */
function soakPlayTotals(array $waves): array
{
    $t = ['transfers' => 0, 'dungeonEnter' => 0, 'dungeonExit' => 0, 'teamJoined' => 0,
        'teamLeft' => 0, 'teamDisbanded' => 0, 'chatSent' => 0, 'chatRecv' => 0];
    foreach ($waves as $w) {
        foreach ($t as $k => $_v) {
            $t[$k] += (int) ($w['play'][$k] ?? 0);
        }
    }

    return $t;
}

/**
 * 玩法静默裁决（纯函数）：--play 波次若「三类玩法全为 0」（迁移 0 且 副本 0 且 组队加入 0 且 聊天收 0）
 * 判 FAIL——那说明新增覆盖根本没被触发（协议漂移/路由回归），长跑绿灯就成了假绿灯。
 * 单类缺失（如副本池满进不去）只可能出现在个别波，聚合判定容忍之，但要求跨波至少各发生一次：
 * 迁移/副本进/副本出/组队(加入或退或解散任一)/聊天收 五类全 0 才判静默。
 * The silent-gameplay verdict (pure): a play run where all play families are zero FAILS — it means the new
 * coverage never fired (a protocol/routing regression), making a green light a false one. Any single event in
 * any family across waves clears that family; only an all-zero across every family is "silent".
 *
 * @param list<array<string, mixed>> $waves
 * @return null|string null = 无 play 波或玩法确有发生；string = 静默归因
 */
function soakPlayVerdict(array $waves): ?string
{
    $playWaves = array_filter($waves, static fn (array $w): bool => ($w['driver'] ?? 'map') === 'play');
    if ($playWaves === []) {
        return null; // 非 play 模式不裁决 Not a play run: no play verdict.
    }
    $t = soakPlayTotals(array_values($playWaves));
    $fired = $t['transfers'] + $t['dungeonEnter'] + $t['dungeonExit']
        + $t['teamJoined'] + $t['teamLeft'] + $t['teamDisbanded'] + $t['chatRecv'];
    if ($fired === 0) {
        return sprintf('玩法静默：%d 个 play 波无任何迁移/副本/组队/聊天事件（疑似协议漂移）', count($playWaves));
    }

    return null;
}

/**
 * 自测：drillVerdict 纯函数正负向用例（RSS 斜率/认证率/帧率），无环境依赖。
 * Self-test: drillVerdict positive/negative cases (RSS slope / auth ratio / fps), no environment needed.
 */
function soakSelfTest(): int
{
    $failures = [];
    $assert = static function (bool $cond, string $name) use (&$failures): void {
        echo ($cond ? 'PASS' : 'FAIL') . "  {$name}\n";
        if (!$cond) {
            $failures[] = $name;
        }
    };
    $flat = array_map(static fn (int $i): array => ['rssTotalKb' => 100000], range(1, 6));
    $wavesOk = [['authOk' => 10, 'clients' => 10, 'fps' => 500.0, 'p99' => 30.0]];
    $v = drillVerdict($flat, $wavesOk);
    $assert($v['ok'], '平稳 RSS + 满认证 + 高帧率 → PASS');
    $assert($v['rssSlopeKb'] === 0.0, '平稳 RSS 斜率为 0');

    $leaky = array_map(static fn (int $i): array => ['rssTotalKb' => 100000 + $i * 4096], range(0, 5));
    $v = drillVerdict($leaky, $wavesOk, rssSlopeKbPerSample: 16.0);
    $assert(!$v['ok'] && $v['rssSlopeKb'] > 4000, '线性增长 RSS（4MB/采样）→ FAIL 且斜率命中');

    $short = [['authOk' => 8, 'clients' => 10, 'fps' => 500.0, 'p99' => 30.0]];
    $v = drillVerdict($flat, $short, minAuthRatio: 0.9);
    $assert(!$v['ok'] && (bool) array_filter($v['reasons'], static fn (string $r): bool => str_contains($r, '认证成功率')), '认证成功率 80% → FAIL');

    $slow = [['authOk' => 10, 'clients' => 10, 'fps' => 10.0, 'p99' => 900.0]];
    $v = drillVerdict($flat, $slow, minFps: 3.0);
    $assert(!$v['ok'] && (bool) array_filter($v['reasons'], static fn (string $r): bool => str_contains($r, '帧率')), '单客户端 1 f/s → FAIL');

    $v = drillVerdict([['rssTotalKb' => 1]], $wavesOk);
    $assert(!$v['ok'] && str_contains($v['reasons'][0], '采样点不足'), '采样不足 3 个 → FAIL 并归因');

    // 玩法裁决（--play）：全静默 FAIL、任一类有事件放行、非 play 波不裁决
    $playWaves = [
        ['authOk' => 10, 'clients' => 10, 'fps' => 500.0, 'p99' => 30.0, 'driver' => 'play',
            'play' => ['transfers' => 0, 'dungeonEnter' => 0, 'dungeonExit' => 0, 'teamJoined' => 0,
                'teamLeft' => 0, 'teamDisbanded' => 0, 'chatSent' => 0, 'chatRecv' => 0]],
        ['authOk' => 10, 'clients' => 10, 'fps' => 500.0, 'p99' => 30.0, 'driver' => 'play',
            'play' => ['transfers' => 0, 'dungeonEnter' => 0, 'dungeonExit' => 0, 'teamJoined' => 0,
                'teamLeft' => 0, 'teamDisbanded' => 0, 'chatSent' => 5, 'chatRecv' => 0]],
    ];
    $r = soakPlayVerdict($playWaves);
    $assert(is_string($r) && str_contains($r, '玩法静默'), 'play 波全零事件 → 静默 FAIL（chatSent 有发无收不算发生）');
    $playWaves[1]['play']['dungeonEnter'] = 2;
    $assert(soakPlayVerdict($playWaves) === null, '副本进入 ≥1 → 放行（任一类发生即非静默）');
    $assert(soakPlayVerdict([['authOk' => 10, 'clients' => 10, 'fps' => 500.0, 'p99' => 30.0]]) === null, '非 play 波不裁决（向后兼容）');
    $totals = soakPlayTotals($playWaves);
    $assert($totals['dungeonEnter'] === 2 && $totals['chatSent'] === 5, '跨波总量聚合');

    // 玩法静默熔断（中途止损）：连续 K 个 play 波零活动才扳机；不足 K 或有活动都放行
    $gOpts = ['minAuth' => 0.9, 'minMemMb' => 200.0, 'maxFrameP99Ms' => 3000.0, 'minWorkers' => 4, 'play' => true, 'playSilence' => 3];
    $gSamples = [['rssTotalKb' => 100000], ['rssTotalKb' => 100000], ['rssTotalKb' => 100000]];
    $gAfter = ['workers' => 4, 'memAvailMb' => 2500.0, 'droppedTotal' => 0, 'rssTotalKb' => 100000];
    $zeroWave = static fn (): array => ['authOk' => 10, 'clients' => 10, 'fps' => 500.0, 'p99' => 30.0, 'driver' => 'play',
        'play' => ['transfers' => 0, 'dungeonEnter' => 0, 'dungeonExit' => 0, 'teamJoined' => 0, 'teamLeft' => 0, 'teamDisbanded' => 0, 'chatSent' => 9, 'chatRecv' => 0]];
    $liveWave = static fn (): array => ['authOk' => 10, 'clients' => 10, 'fps' => 500.0, 'p99' => 30.0, 'driver' => 'play',
        'play' => ['transfers' => 3, 'dungeonEnter' => 0, 'dungeonExit' => 0, 'teamJoined' => 0, 'teamLeft' => 0, 'teamDisbanded' => 0, 'chatSent' => 9, 'chatRecv' => 0]];
    $r = soakGuard($gOpts, $gSamples, [$zeroWave(), $zeroWave(), $zeroWave()], $gAfter, $zeroWave(), null);
    $assert(is_string($r) && str_contains($r, '玩法静默'), '连续 3 零活动 play 波 → 熔断（有发无收不算活动）');
    $r = soakGuard($gOpts, $gSamples, [$zeroWave(), $zeroWave(), $liveWave(), $zeroWave(), $zeroWave()], $gAfter, $zeroWave(), null);
    $assert($r === null, '尾部 2 零 + 波内有活动 → 放行');
    $r = soakGuard($gOpts, $gSamples, [$zeroWave(), $zeroWave()], $gAfter, $zeroWave(), null);
    $assert($r === null, 'play 波不足 K → 放行');
    $r = soakGuard(['play' => false] + $gOpts, $gSamples, [$zeroWave(), $zeroWave(), $zeroWave()], $gAfter, $zeroWave(), null);
    $assert($r === null, '非 play 模式不触发静默熔断');

    if ($failures !== []) {
        printf("[soak] SELF-TEST FAIL：%d 项断言未过\n", count($failures));

        return 1;
    }
    echo "[soak] SELF-TEST PASS\n";

    return 0;
}
