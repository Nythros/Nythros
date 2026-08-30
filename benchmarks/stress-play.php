<?php

declare(strict_types=1);

// 定位：benchmarks/stress-play.php —— 混合玩法压测客户端（真实 WS 链路，stream_select 多路复用）。
// Located at: benchmarks/stress-play.php — the mixed-gameplay stress client (real WS links, stream_select
// multiplexing).
//
// 与 stress-map 的差异：stress-map 只驱动「登录 + 走廊走位」（纯 map 二进制），验证的是帧吞吐；本脚本
// 在同一 stream_select 引擎上叠加社交层玩法——随机跨图切换（map:enter/map:entered + detach/attach +
// map:join）、副本 dungeon-A 进出（首次让 18084 worker 进负载）、组队全状态机（invite/accept/leave/disband）、
// 聊天三通道（world/channel/team，含跨频道 404）。soak `--play` 波次以本脚本为 driver，补齐长跑对玩法链路的
// 零覆盖（blueprint/33 §12 证实副本 worker 在现有拓扑永不派单）。
// Unlike stress-map (login + corridor walking only, pure map-binary throughput), this stacks social gameplay
// onto the same select engine: random cross-map switches (map:enter/entered + detach/attach + map:join), the
// dungeon-A in/out round trip (the first time 18084 ever carries load), the full team state machine
// (invite/accept/leave/disband) and chat across three scopes (world/channel/team incl. cross-channel 404).
//
// 已知正确序列照抄自 packages/demo/bin/verify-phase5.php：所有社交帧（含 map:enter/map:join/team/chat）都发在
// gateway 18285 这一条 JSON 连接上（session/分组归属以处理帧的连接所在进程为准），map 玩法帧走 map 二进制连接。
// gateway 连接全程保活——P10 踩坑（stress-hotzone/rooms）：登录即关 gw 会被网关标记离线并触发地图服 onClose，
// 组队期间会误退队，故与 stress-map 的「登录即关 gw」相反，本脚本保活 gw。
// The known-correct sequence mirrors verify-phase5.php: every social frame rides the single gateway JSON link
// (session/group membership resolves in the process handling the frame); map frames ride the map-binary link.
// The gateway link stays open the whole run (the P10 pitfall — closing it after login marks the bot offline and
// fires map onClose, spuriously leaving teams), the opposite of stress-map.
//
// 用法 Usage:
//   php benchmarks/stress-play.php --clients=12 --seconds=90 [--json]
//   php benchmarks/stress-play.php --self-test
// 前置 Precondition: the stack is running (`php bin/server start`); accounts 1001..N (soak's NYTHROS_ACCOUNTS
// already scales with --clients; team pairing reuses adjacent uids, no extra accounts needed).

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/lib/drill-harness.php';
require __DIR__ . '/../packages/demo/bin/lib/map-codec.php';

const PLAY_BUCKET_EDGES = [0, 10, 20, 40, 80, 160, 320, 640, 1280];
// 每连接节流预算（服务端 WorkermanWebSocketServer 每连接 10 tokens/s 静默丢弃、auth 后 map scope 30s TTL、
// 未认证连接 10s 超时）：客户端主动把每连接发送压在预算内，超限即丢等于自毁统计。
// Per-connection throttle budget (server drops silently past 10 msg/s; the map token lives 30s after auth;
// unauthenticated conns die at 10s): the client self-limits under the budget, since dropped sends would
// corrupt its own counters.
const PLAY_MSG_BUDGET_PER_SEC = 10.0;
const PLAY_TOKEN_TTL_SEC = 30.0;

if (in_array('--self-test', $argv, true)) {
    exit(playSelfTest());
}

$opts = [
    'clients' => 10, 'seconds' => 90, 'json' => false,
    'moveMs' => 150, 'settleMoves' => 40, 'mapIds' => 'map-1,map-2',
    'transferEvery' => 20, 'dungeonEvery' => 30, 'teamEvery' => 40, 'chatEvery' => 8,
    'dungeonMap' => 'dungeon-A',
];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--clients=(\d+)$/', $arg, $m)) {
        $opts['clients'] = max(2, (int) $m[1]); // 组队配对至少 2 人 team pairing needs ≥2 clients
    } elseif (preg_match('/^--seconds=(\d+)$/', $arg, $m)) {
        $opts['seconds'] = max(5, (int) $m[1]);
    } elseif (preg_match('/^--move-ms=(\d+)$/', $arg, $m)) {
        $opts['moveMs'] = max(50, (int) $m[1]);
    } elseif (preg_match('/^--settle-moves=(\d+)$/', $arg, $m)) {
        $opts['settleMoves'] = max(1, (int) $m[1]);
    } elseif (preg_match('/^--map-ids=([\w,-]+)$/', $arg, $m)) {
        $opts['mapIds'] = $m[1];
    } elseif (preg_match('/^--transfer-every=(\d+)$/', $arg, $m)) {
        $opts['transferEvery'] = (int) $m[1];
    } elseif (preg_match('/^--dungeon-every=(\d+)$/', $arg, $m)) {
        $opts['dungeonEvery'] = (int) $m[1];
    } elseif (preg_match('/^--team-every=(\d+)$/', $arg, $m)) {
        $opts['teamEvery'] = (int) $m[1];
    } elseif (preg_match('/^--chat-every=(\d+)$/', $arg, $m)) {
        $opts['chatEvery'] = (int) $m[1];
    } elseif (preg_match('/^--dungeon-map=([\w-]+)$/', $arg, $m)) {
        $opts['dungeonMap'] = $m[1];
    } elseif ($arg === '--json') {
        $opts['json'] = true;
    }
}

$mapIdList = array_values(array_filter(array_map('trim', explode(',', $opts['mapIds']))));
$clients = $opts['clients'];
$seconds = $opts['seconds'];

$stats = [
    'authOk' => 0, 'establishFailed' => 0,
    'transfersOk' => 0, 'transfersFail' => 0,
    'dungeonEnter' => 0, 'dungeonExit' => 0,
    'teamCreated' => 0, 'teamJoined' => 0, 'teamLeft' => 0, 'teamDisbanded' => 0, 'teamError' => 0, 'teamOffline' => 0,
    'chatWorld' => 0, 'chatChannel' => 0, 'chatTeam' => 0, 'chatTeamNoop' => 0, 'chatChannelRejected' => 0,
    'chatWorldRecv' => 0, 'chatChannelRecv' => 0, 'chatTeamRecv' => 0,
    'frames' => 0, 'bytes' => 0,
];
// 玩法周期注入闭包（子进程模型下 $opts 进程局部，$GLOBALS 仅作只读广播，无跨进程泄漏面）
$GLOBALS['teamEvery'] = $opts['teamEvery'];
$GLOBALS['chatEvery'] = $opts['chatEvery'];
$GLOBALS['dungeonMap'] = $opts['dungeonMap'];
$latencyHist = [];
// 社交往返延迟直方图（map:enter → map:entered 段：注册表过滤 + minPlayerCount + 票据签发全链路）
// Social request-reply latency (the map:enter → map:entered leg: registry filter + selection + issue).
$socialLatHist = [];
$recordSocial = static function (float $ms) use (&$socialLatHist): void {
    $idx = 0;
    foreach (PLAY_BUCKET_EDGES as $i => $edge) {
        if ($ms >= $edge) {
            $idx = $i;
        }
    }
    $socialLatHist[$idx] = ($socialLatHist[$idx] ?? 0) + 1;
};
$socialPercentile = static function (float $p) use (&$socialLatHist): float {
    $total = array_sum($socialLatHist);
    if ($total === 0) {
        return 0.0;
    }
    $target = $total * $p;
    $acc = 0;
    foreach (PLAY_BUCKET_EDGES as $i => $edge) {
        $acc += $socialLatHist[$i] ?? 0;
        if ($acc >= $target) {
            return (float) $edge;
        }
    }

    return (float) end(PLAY_BUCKET_EDGES);
};

$recordLatency = static function (float $ms) use (&$latencyHist): void {
    $idx = 0;
    foreach (PLAY_BUCKET_EDGES as $i => $edge) {
        if ($ms >= $edge) {
            $idx = $i;
        }
    }
    $latencyHist[$idx] = ($latencyHist[$idx] ?? 0) + 1;
};
$percentile = static function (float $p) use (&$latencyHist): float {
    $total = array_sum($latencyHist);
    if ($total === 0) {
        return 0.0;
    }
    $target = $total * $p;
    $acc = 0;
    foreach (PLAY_BUCKET_EDGES as $i => $edge) {
        $acc += $latencyHist[$i] ?? 0;
        if ($acc >= $target) {
            $next = PLAY_BUCKET_EDGES[$i + 1] ?? $edge * 2;

            return (float) $edge + (($next - $edge) * ($target - ($acc - ($latencyHist[$i] ?? 0)))) / max(1, $latencyHist[$i] ?? 1);
        }
    }

    return (float) end(PLAY_BUCKET_EDGES) * 2;
};

$now = microtime(true);
$deadline = $now + $seconds;

// ── ① 建链：每 bot gateway JSON auth（保活）→ token + map 地址 → map 二进制 auth ──
// ── ① Establish: per-bot gateway JSON auth (kept open) -> token + map addr -> map binary auth ──
$bots = []; // streamId => bot state (map link), 每 bot 另存 gw 流
$gwBots = []; // gw streamId => bot index（反查：select 循环里 gateway 帧归属哪只 bot）
$mapStreamIds = []; // map streamId => bot index
$dirs = [[1, 1], [1, -1], [-1, 1], [-1, -1]];

for ($i = 1; $i <= $clients; ++$i) {
    $name = (string) (1000 + $i);
    $token = null;
    $mapAddr = null;
    $gw = false;
    // 注册表真空窗自愈（实测：flushall 后 ≤5s 心跳重注册前，auth 一律 503 no available channel，
    // 且服务端 503 路径直接 closeClient）——503 视作「栈未就绪」有界重试并重握手
    // （drillGatewayLogin 就绪探针同口径），其余失败不重试。
    // Registry vacuum self-heal: the 503 path closes the server-side conn, so each retry re-handshakes.
    $authDeadline = microtime(true) + 12.0;
    for ($attempt = 0; $attempt < 20; ++$attempt) {
        $gw = drillWsHandshake('127.0.0.1', 18285);
        if ($gw === false) {
            if (microtime(true) >= $authDeadline) {
                break;
            }
            usleep(500000);
            continue;
        }
        drillWsSend($gw, drillSocialFrame('auth', "play:{$name}:" . $attempt, [
            'username' => $name, 'password' => 'secret', 'mapId' => $mapIdList[$i % count($mapIdList)], 'version' => 1,
        ]));
        $failed503 = false;
        while (microtime(true) < $authDeadline) {
            $frame = drillReadWsFrame($gw, 2.0);
            if ($frame === null || in_array($frame['opcode'], [0x8, 0x9], true)) {
                break;
            }
            $msg = json_decode($frame['payload'], true);
            if (($msg['type'] ?? '') === 'auth_ok') {
                $token = $msg['payload']['token'] ?? null;
                $mapAddr = $msg['payload']['map']['wsAddress'] ?? null;
                $failed503 = false;
                break;
            }
            if (($msg['type'] ?? '') === 'auth_failed') {
                $failed503 = (int) ($msg['payload']['code'] ?? 0) === 503;
                break;
            }
        }
        if (is_string($token)) {
            break;
        }
        if (!$failed503 || microtime(true) >= $authDeadline) {
            break;
        }
        @fclose($gw); // 503 路径服务端已 closeClient；重握手前回收本端流
        $gw = false;
        usleep(500000);
    }
    if (!is_string($token) || !is_string($mapAddr) || preg_match('#^ws://([^:]+):(\d+)$#', $mapAddr, $m) !== 1) {
        if (is_resource($gw)) {
            fclose($gw);
        }
        ++$stats['establishFailed'];
        continue;
    }

    $map = drillWsHandshake($m[1], (int) $m[2]);
    if ($map === false) {
        fclose($gw);
        ++$stats['establishFailed'];
        continue;
    }
    stream_set_blocking($map, false);
    stream_set_blocking($gw, false);
    drillWsSend($map, frameMap('auth', ['token' => $token, 'version' => 1], "map-auth:{$name}"), 0x2);

    $idx = count($bots);
    $bots[$idx] = [
        'name' => $name, 'idx' => $idx,
        'gw' => $gw, 'gwBuf' => '', 'gwAuthed' => true, 'gwAuthAt' => microtime(true),
        'map' => $map, 'mapBuf' => '', 'mapAuthed' => false, 'lastArrival' => 0.0,
        'mapId' => $mapIdList[$i % count($mapIdList)], 'homeMapId' => $mapIdList[$i % count($mapIdList)],
        'channelId' => null,
        'lastMove' => microtime(true), 'dir' => $dirs[$i % 4], 'steps' => 0, 'turnAt' => max(1, $opts['settleMoves']),
        // 玩法时钟（错峰：按 index 偏移，避免同帧集体触发挤爆 select 与 10/s 限流）
        'nextTransfer' => $now + $opts['transferEvery'] + ($i % 7),
        'nextDungeon' => $now + $opts['dungeonEvery'] + ($i % 11),
        'nextTeam' => $now + $opts['teamEvery'] + ($i % 5) * 3,
        'nextChat' => $now + $opts['chatEvery'] + ($i % 4) * 2,
        'chatScope' => 0,
        // 迁移状态机：transferPhase null|'await-entered'|'await-attach'；transferTarget 目标 mapId
        'transferPhase' => null, 'transferTarget' => null, 'transferChannel' => null, 'transferDeadline' => 0.0,
        // 组队状态机 teamPhase：0 自由 / 1 已发起或已accept / 2 in-team / 3 收尾中
        'teamPhase' => 0, 'teamId' => null, 'partnerIdx' => ($idx % 2 === 0) ? $idx + 1 : $idx - 1, 'teamDeadline' => 0.0,
    ];
    // gw 流反查表（select 循环里 gateway 文本帧要路由回 bot）
    $gwBots[(int) $gw] = $idx;
    $mapStreamIds[(int) $map] = $idx;
}

// ── ② 玩法驱动闭包（迁移/组队/聊天；全部经 gw 或 map 连接发出，各自 <10 msg/s 预算内）──
// ── ② Gameplay drivers (transfer/team/chat; every frame rides the gw or map link, each under the budget) ──

/** 断开某 bot 的 map 连接（旧地址），迁移 detach 与清理共用。 */
$dropMap = static function (int $idx) use (&$bots, &$mapStreamIds): void {
    if (!isset($bots[$idx]['map'])) {
        return;
    }
    unset($mapStreamIds[(int) $bots[$idx]['map']]);
    fclose($bots[$idx]['map']);
    $bots[$idx]['map'] = null;
    $bots[$idx]['mapBuf'] = '';
    $bots[$idx]['mapAuthed'] = false;
};

/** 向目标 map 地址发起二进制 auth（迁移第③步：连新址 attach 消费票据）。 */
$attachTo = static function (int $idx, string $wsAddr, string $token, string $mapId, ?string $channelId) use (&$bots, &$stats, $dropMap): bool {
    if (preg_match('#^ws://([^:]+):(\d+)$#', $wsAddr, $m) !== 1) {
        ++$stats['transfersFail'];

        return false;
    }
    $dropMap($idx); // 关旧 map WS → 服务端 detach 导出票据（必须在连新址 auth 之前，ADR-025 时序）
    $map = drillWsHandshake($m[1], (int) $m[2]);
    if ($map === false) {
        ++$stats['transfersFail'];

        return false;
    }
    stream_set_blocking($map, false);
    drillWsSend($map, frameMap('auth', ['token' => $token, 'version' => 1], 'att:' . $bots[$idx]['name'] . ':' . $mapId), 0x2);
    $bots[$idx]['map'] = $map;
    $bots[$idx]['mapBuf'] = '';
    $bots[$idx]['mapAuthed'] = false;
    $bots[$idx]['mapId'] = $mapId;
    $bots[$idx]['channelId'] = $channelId;
    $mapStreamIds[(int) $map] = $idx;

    return true;
};

/** 迁移第①步：gateway 发 map:enter 换取新址 token（attach 回执后在 map 侧断言，见 auth_ok 处理）。 */
$startTransfer = static function (int $idx, string $toMapId, string $phase) use (&$bots, &$stats, $attachTo): void {
    if (!isset($bots[$idx]['gw'])) {
        return;
    }
    drillWsSend($bots[$idx]['gw'], drillSocialFrame('map:enter', "tr:{$bots[$idx]['name']}:" . $idx . ':' . $toMapId, ['mapId' => $toMapId]));
    $bots[$idx]['transferSentAt'] = microtime(true);
    $bots[$idx]['prevMapId'] = (string) $bots[$idx]['mapId'];
    $bots[$idx]['transferPhase'] = $phase;
    $bots[$idx]['transferTarget'] = $toMapId;
    $bots[$idx]['transferDeadline'] = microtime(true) + 8.0;
};

/**
 * 组队状态机（每 2 bot 一对，A 邀请 B）：
 *  - 偶数对（pairType=leave）：稳定期后 B 发 team:leave，双方 notify 清态；
 *  - 奇数对（pairType=disband）：A 发 team:disband，双方 notify 清态。
 * 两条收尾路径都覆盖（用户语义「组队/退队」+ 队长解散广播），且每对每周期恰好一条 → 确定性计数。
 * teamPhase: 0 自由 / 1 建队中 / 2 在队 / 3 收尾已发送（回执或超时清态）。
 * Team state machine, one cycle per pair: even pairs exercise member-leave, odd pairs leader-disband, so both
 * teardown broadcasts are deterministically covered every run.
 */
$teamStep = static function (int $idx) use (&$bots, &$stats): void {
    $b = &$bots[$idx];
    $partner = ($idx % 2 === 0) ? $idx + 1 : $idx - 1;
    if (!isset($bots[$partner]) || !isset($b['gw'])) {
        return;
    }
    $now = microtime(true);
    $pairType = intdiv(min($idx, $partner), 2) % 2 === 0 ? 'leave' : 'disband';
    switch ($b['teamPhase']) {
        case 0: // 发起方邀请；被邀方静候 team:notify(invited)（收帧处自动 accept）
            // 迁移窗口不发起（partner 正 detach/attach 时必吃 404 target_offline，纯竞态噪声）。
            // 门槛不满足时不消耗时钟——下一 tick（短退避）重试，避免与迁移撞窗后空等整个 teamEvery 周期。
            // 注意 null 语义：transferPhase 用 null 表示「静默」，判存在必须 ?? null 而非哨兵值。
            if ($idx % 2 === 0 && $b['mapAuthed'] && $b['transferPhase'] === null
                && ($bots[$partner]['mapAuthed'] ?? false) && ($bots[$partner]['transferPhase'] ?? null) === null) {
                drillWsSend($b['gw'], drillSocialFrame('team:invite', 'ti:' . $b['name'], ['targetUid' => $bots[$partner]['name']]));
                $b['teamPhase'] = 1;
                $b['teamDeadline'] = $now + 10.0;
                $b['nextTeam'] = $now + $GLOBALS['teamEvery'];
            } else {
                $b['nextTeam'] = $now + 3.0; // 短退避重试，让组队与迁移解耦（否则高频迁移下永不同时静默）
            }
            return;
        case 2: // 稳定期到 → 本对收尾：leave 路由由 B（奇）发，disband 路由由 A（偶）发
            $b['nextTeam'] = $now + $GLOBALS['teamEvery'];
            if ($pairType === 'leave') {
                if ($idx % 2 === 1 && is_string($b['teamId'])) {
                    drillWsSend($b['gw'], drillSocialFrame('team:leave', 'tl:' . $b['name'], ['teamId' => $b['teamId']]));
                    $b['teamPhase'] = 3;
                    $b['teamDeadline'] = $now + 10.0;
                } elseif ($idx % 2 === 0 && ($bots[$partner]['teamPhase'] ?? 0) === 0 && is_string($b['teamId'])) {
                    // 兜底（竞态后 B 已清而 A 滞留）：A 补发 disband 清服务端残留——防「本地清、Redis 队还在」
                    // 的 409 僵尸循环，并把 disband 覆盖补上（leadership 允许队长直接解散）
                    drillWsSend($b['gw'], drillSocialFrame('team:disband', 'tdf:' . $b['name'], ['teamId' => $b['teamId']]));
                    $b['teamPhase'] = 3;
                    $b['teamDeadline'] = $now + 10.0;
                }
            } elseif ($idx % 2 === 0 && is_string($b['teamId'])) {
                drillWsSend($b['gw'], drillSocialFrame('team:disband', 'td:' . $b['name'], ['teamId' => $b['teamId']]));
                $b['teamPhase'] = 3;
                $b['teamDeadline'] = $now + 10.0;
            } elseif ($idx % 2 === 1 && is_string($b['teamId'])) {
                // disband 对里 B 滞留（disbanded notify 丢失）→ B 补 leave 清自己
                drillWsSend($b['gw'], drillSocialFrame('team:leave', 'tlf:' . $b['name'], ['teamId' => $b['teamId']]));
                $b['teamPhase'] = 3;
                $b['teamDeadline'] = $now + 10.0;
            }
            return;
        case 1:
        case 3: // 等回执超时：仅本地清态并计 teamError（服务端残留交给 leader-disband 清理路径或 600s TTL；
            // 补发清理帧会与 notify 竞态形成双发风暴，实测后否决）
            if ($now > $b['teamDeadline']) {
                $b['teamPhase'] = 0;
                $b['teamId'] = null;
                $b['nextTeam'] = $now + 3.0;
                ++$stats['teamError'];
            }

            return;
    }
};

/** 聊天三通道轮转（world/channel/team）；成功无回执，以计数器记发送，服务端接收由 soak 探针独立佐证。 */
$chatStep = static function (int $idx) use (&$bots, &$stats): void {
    $b = &$bots[$idx];
    if (!isset($b['gw'])) {
        return;
    }
    $scope = ['world', 'channel', 'team'][$b['chatScope'] % 3];
    $payload = ['scope' => $scope, 'content' => "play:{$b['name']}:" . $b['chatScope']];
    if ($scope === 'channel') {
        $payload['mapId'] = $b['mapId'] ?? 'map-1';
        $payload['channelId'] = $b['channelId'] ?? 'ch-1';
    }
    if ($scope === 'team' && $b['teamId'] === null) {
        // 无队 team 聊天服务端回 chat:error（404 not in team）——这是「错误语义也进了负载」的正向断言点
        ++$stats['chatTeamNoop'];
    }
    drillWsSend($b['gw'], drillSocialFrame('chat:send', "cs:{$b['name']}:" . $b['chatScope'], $payload));
    if ($scope === 'world') {
        ++$stats['chatWorld'];
    } elseif ($scope === 'channel') {
        ++$stats['chatChannel'];
    } else {
        ++$stats['chatTeam'];
    }
    ++$b['chatScope'];
    $b['nextChat'] = microtime(true) + $GLOBALS['chatEvery'];
};

// ── ③ select 主循环：双通道读帧 + 分 kind 分发 + 玩法时钟 + map 侧 move ──
// ── ③ The select loop: read both links, dispatch by kind, drive the gameplay clocks + map moves ──
$reqSeq = 0;
$live = true;
while ($live && microtime(true) < $deadline && $bots !== []) {
    $read = [];
    $idxOf = [];
    foreach ($bots as $idx => $b) {
        if (isset($b['gw'])) {
            $read[] = $b['gw'];
            $idxOf[(int) $b['gw']] = ['idx' => $idx, 'kind' => 'gw'];
        }
        if (isset($b['map']) && $b['map'] !== null) {
            $read[] = $b['map'];
            $idxOf[(int) $b['map']] = ['idx' => $idx, 'kind' => 'map'];
        }
    }
    $write = null;
    $except = null;
    if ($read === [] || stream_select($read, $write, $except, 0, 200000) === false) {
        break;
    }
    $now = microtime(true);

    foreach ($read as $stream) {
        $sid = (int) $stream;
        if (!is_resource($stream) || !isset($idxOf[$sid]) || !isset($bots[$idxOf[$sid]['idx']])) {
            continue; // 本轮已被别的帧清理（map 迁移换流） stale after a mid-iteration swap
        }
        $idx = $idxOf[$sid]['idx'];
        $kind = $idxOf[$sid]['kind'];
        // 双保险：本批处理中该 bot 的对应流可能已被迁移替换/关闭（同批前一帧触发了 attachTo）
        // Double-check: this batch's earlier frame may have swapped/closed the stream (attachTo).
        $cur = $kind === 'gw' ? ($bots[$idx]['gw'] ?? null) : ($bots[$idx]['map'] ?? null);
        if (!is_resource($cur) || (int) $cur !== $sid) {
            continue;
        }
        $chunk = @fread($stream, 65536);
        if ($chunk === '' || $chunk === false) {
            continue;
        }
        $bufKey = $kind === 'gw' ? 'gwBuf' : 'mapBuf';
        $bots[$idx][$bufKey] .= $chunk;

        foreach (drillParseWsBuffer($bots[$idx][$bufKey]) as $frame) {
            if ($frame['opcode'] === 0x8) {
                // 对端 close：map 连接被服务端复位（如 drain/踢线）→ 标记待重连，不静默丢 bot
                if ($kind === 'map') {
                    $bots[$idx]['mapAuthed'] = false;
                }
                continue;
            }
            if ($kind === 'map') {
                if ($frame['opcode'] !== 0x2) {
                    continue;
                }
                $frames = decodeMapFrames($frame['payload']);
                $stats['frames'] += count($frames);
                $stats['bytes'] += strlen($frame['payload']);
                // 帧到达延迟（stress-map 同口径：收包间隙，同批不记；迁移换连后首帧无基准不记）
                // Frame-arrival latency (same convention as stress-map: arrival gaps; first frame after a
                // transfer re-link has no baseline and is skipped).
                if ($bots[$idx]['lastArrival'] > 0.0) {
                    $recordLatency(($now - $bots[$idx]['lastArrival']) * 1000);
                }
                $bots[$idx]['lastArrival'] = $now;
                foreach ($frames as $f) {
                    $type = (string) ($f['type'] ?? '');
                    if ($type === 'auth_ok') {
                        if (!$bots[$idx]['mapAuthed']) {
                            $bots[$idx]['mapAuthed'] = true;
                            // attach 成功：区分副本进入 vs 普通跨图，断言目的图与计数（副本回原位=落 spawnPoint，不断言坐标）
                            if ($bots[$idx]['transferTarget'] !== null) {
                                $isDungeon = $bots[$idx]['transferTarget'] === $GLOBALS['dungeonMap'];
                                $isExit = $bots[$idx]['transferTarget'] === $bots[$idx]['homeMapId']
                                    && ($bots[$idx]['prevMapId'] ?? '') === $GLOBALS['dungeonMap'];
                                if ($isDungeon) {
                                    ++$stats['dungeonEnter'];
                                } elseif ($isExit) {
                                    ++$stats['dungeonExit'];
                                } else {
                                    ++$stats['transfersOk'];
                                }
                                // attach 后补 map:join（退旧组+写快照，verify-phase5 序列第④步）
                                if (isset($bots[$idx]['gw'])) {
                                    drillWsSend($bots[$idx]['gw'], drillSocialFrame('map:join', 'mj:' . $bots[$idx]['name'] . ':' . (++$reqSeq), [
                                        'mapId' => $bots[$idx]['transferTarget'],
                                        'channelId' => $bots[$idx]['transferChannel'] ?? 'ch-1',
                                        'x' => $bots[$idx]['steps'], 'y' => 0,
                                    ]));
                                }
                                $bots[$idx]['transferPhase'] = null;
                                $bots[$idx]['transferTarget'] = null;
                            } else {
                                ++$stats['authOk'];
                            }
                        }
                    } elseif ($type === 'auth_failed') {
                        if ($bots[$idx]['transferTarget'] !== null) {
                            ++$stats['transfersFail'];
                            $bots[$idx]['transferPhase'] = null;
                            $bots[$idx]['transferTarget'] = null;
                        }
                    }
                }
                continue;
            }

            // gateway JSON 帧
            $msg = json_decode($frame['payload'], true);
            if (!is_array($msg)) {
                continue;
            }
            $type = (string) ($msg['type'] ?? '');
            $p = $msg['payload'] ?? [];
            switch ($type) {
                case 'map:entered':
                    if ($bots[$idx]['transferPhase'] === 'await-entered') {
                        if (isset($bots[$idx]['transferSentAt'])) {
                            $recordSocial((microtime(true) - $bots[$idx]['transferSentAt']) * 1000);
                        }
                        $tok = $p['token'] ?? null;
                        $mapAddr = $p['map']['wsAddress'] ?? null;
                        $chan = $p['map']['channelId'] ?? null;
                        if (is_string($tok) && is_string($mapAddr)) {
                            $bots[$idx]['transferChannel'] = $chan;
                            $bots[$idx]['transferPhase'] = 'await-attach';
                            $bots[$idx]['transferDeadline'] = microtime(true) + 8.0; // attach 段独立窗口（entered 段耗时不侵占）
                            $attachTo($idx, $mapAddr, $tok, (string) $bots[$idx]['transferTarget'], is_string($chan) ? $chan : null);
                        } else {
                            ++$stats['transfersFail'];
                            $bots[$idx]['transferPhase'] = null;
                            $bots[$idx]['transferTarget'] = null;
                        }
                    }
                    continue 2;
                case 'team:ok':
                    if (is_string($p['teamId'] ?? null) && $p['teamId'] !== '') {
                        $bots[$idx]['teamId'] = (string) $p['teamId'];
                    }
                    // 回执语义按 action 分派：invite=队已建（A）；disband=解散完成（服务端 disbanded
                    // notify 不回放发送者本人，A 侧以本回执为权威计数点）
                    $action = (string) ($p['action'] ?? '');
                    if ($action === 'disband') {
                        // leader 权威回执（服务端顺序：disbanded notify 先、team:ok 后，故不能靠 phase 门控）
                        ++$stats['teamDisbanded'];
                        $bots[$idx]['teamPhase'] = 0;
                        $bots[$idx]['teamId'] = null;
                    } elseif ($bots[$idx]['teamPhase'] === 1 && $idx % 2 === 0) {
                        ++$stats['teamCreated'];
                        $bots[$idx]['teamDeadline'] = microtime(true) + 10.0;
                    }
                    continue 2;
                case 'team:notify':
                    $nt = (string) ($p['type'] ?? '');
                    $who = (string) ($p['uid'] ?? '');
                    if ($nt === 'invited' && $bots[$idx]['teamPhase'] === 0) {
                        // 被邀方（B）自动 accept → 入队
                        $bots[$idx]['teamId'] = (string) ($p['teamId'] ?? $bots[$idx]['teamId'] ?? '');
                        drillWsSend($bots[$idx]['gw'], drillSocialFrame('team:accept', 'ta:' . $bots[$idx]['name'], ['teamId' => $bots[$idx]['teamId']]));
                        $bots[$idx]['teamPhase'] = 1;
                        $bots[$idx]['teamDeadline'] = microtime(true) + 10.0;
                    } elseif ($nt === 'joined' && $bots[$idx]['teamPhase'] === 1) {
                        $bots[$idx]['teamPhase'] = 2;
                        if ($idx % 2 === 0) {
                            ++$stats['teamJoined']; // 一侧计数，避免双计
                        }
                    } elseif ($nt === 'left') {
                        if ($who === $bots[$idx]['name'] && $bots[$idx]['teamPhase'] === 3) {
                            ++$stats['teamLeft'];
                            $bots[$idx]['teamPhase'] = 0;
                            $bots[$idx]['teamId'] = null;
                        } elseif ($who !== $bots[$idx]['name'] && $bots[$idx]['teamPhase'] === 2) {
                            // leave 对的 leader 侧：成员走了但服务端队还在（leader 是最后持有者）→ 补一发
                            // disband 清残留，并以其 team:ok{action:disband} 为 D 的**唯一**计数点
                            // （disbanded notify 不参与计数，防 ack/notify 竞态双计或两计皆空）
                            if (is_string($bots[$idx]['teamId']) && isset($bots[$idx]['gw'])) {
                                drillWsSend($bots[$idx]['gw'], drillSocialFrame('team:disband', 'tdc:' . $bots[$idx]['name'], ['teamId' => $bots[$idx]['teamId']]));
                                $bots[$idx]['teamPhase'] = 3;
                                $bots[$idx]['teamDeadline'] = microtime(true) + 10.0;
                            } else {
                                $bots[$idx]['teamPhase'] = 0;
                                $bots[$idx]['teamId'] = null;
                            }
                        }
                    } elseif ($nt === 'disbanded') {
                        // B 侧（或 ack 迟到时）只负责清态；计数点在 leader 的 team:ok{action:disband}
                        $bots[$idx]['teamPhase'] = 0;
                        $bots[$idx]['teamId'] = null;
                    }
                    continue 2;
                case 'team:error':
                    // 竞态噪声分桶，不污染真错误计数：target_offline（partner 恰在迁移，下轮静默窗口
                    // 自愈重试）、team_not_found（清理帧撞上已解散的队，目标态已达成）。其余错误码
                    //（409 双队防护等）照实计入——它们本身就是 ADR-015 §1.6 语义在负载下的覆盖。
                    $reason = (string) ($p['message'] ?? '');
                    $code = (int) ($p['code'] ?? 0);
                    if (getenv('PLAY_DEBUG')) {
                        fwrite(STDERR, sprintf("[teamerr] idx=%d %s\n", $idx, json_encode($p, JSON_UNESCAPED_UNICODE)));
                    }
                    if ($code === 404 && str_contains($reason, 'target_offline')) {
                        ++$stats['teamOffline']; // 迁移竞态可重试噪声：单列计数，下轮静默窗口自愈
                    } elseif ($code === 404 && str_contains($reason, 'team_not_found')) {
                        // 良性竞态：补发的清理帧撞上已被解散的队（服务端已达成目标态）——只清本地不计数
                    } else {
                        ++$stats['teamError'];
                    }
                    $bots[$idx]['teamPhase'] = 0;
                    $bots[$idx]['teamId'] = null;
                    continue 2;
                case 'chat:message':
                    $scope = (string) ($p['scope'] ?? '');
                    if ($scope === 'world') {
                        ++$stats['chatWorldRecv'];
                    } elseif ($scope === 'channel') {
                        ++$stats['chatChannelRecv'];
                    } elseif ($scope === 'team') {
                        ++$stats['chatTeamRecv'];
                    }
                    continue 2;
                case 'chat:error':
                    if (($p['code'] ?? null) === 404) {
                        ++$stats['chatChannelRejected']; // 跨频道/无队 404：错误语义也被负载覆盖（verify-phase5 同断言）
                    }
                    continue 2;
            }
        }
    }

    // 玩法时钟：迁移到期（在 map-2/map-1 与副本之间轮转）；组队/聊天到期
    foreach ($bots as $idx => $b) {
        if (!isset($bots[$idx])) {
            continue;
        }
        // map:enter 回执/attach 超时：拉回稳态（不卡死状态机）
        if ($b['transferPhase'] !== null && $now > $b['transferDeadline']) {
            $bots[$idx]['transferPhase'] = null;
            $bots[$idx]['transferTarget'] = null;
        }
        if ($opts['transferEvery'] > 0 && $b['transferPhase'] === null && $b['mapAuthed'] && $now >= $b['nextTransfer']) {
            // 目标图：home 与 map-2 交替；每若干轮插一次副本往返（进 → 出）
            $target = $b['mapId'] === $b['homeMapId'] ? $mapIdList[1] ?? $b['homeMapId'] : $b['homeMapId'];
            $bots[$idx]['nextTransfer'] = $now + $opts['transferEvery'];
            $startTransfer($idx, (string) $target, 'await-entered');
        }
        if ($opts['dungeonEvery'] > 0 && $b['transferPhase'] === null && $b['mapAuthed'] && $now >= $b['nextDungeon']) {
            $bots[$idx]['nextDungeon'] = $now + $opts['dungeonEvery'];
            // 进副本 → 下次 dungeon tick 出副本回 home（退副本断言落 home spawnPoint）
            $into = $b['mapId'] === $opts['dungeonMap'] ? $b['homeMapId'] : $opts['dungeonMap'];
            $startTransfer($idx, (string) $into, 'await-entered');
        }
        if ($opts['teamEvery'] > 0 && $now >= $b['nextTeam']) {
            $teamStep($idx);
        }
        if ($opts['chatEvery'] > 0 && $now >= $b['nextChat']) {
            $chatStep($idx);
        }
        // map 连接走位（与 stress-map 走廊模型一致；迁移中 map 未就绪则跳过）
        $bot = &$bots[$idx];
        if ($bot['map'] !== null && $bot['mapAuthed'] && (microtime(true) - $bot['lastMove']) * 1000 >= $opts['moveMs']) {
            $bot['lastMove'] = microtime(true);
            $bot['steps']++;
            if ($bot['steps'] % $bot['turnAt'] === 0) {
                $bot['dir'] = [-$bot['dir'][0], -$bot['dir'][1]];
            }
            drillWsSend($bot['map'], frameMap('move', ['dx' => $bot['dir'][0], 'dy' => $bot['dir'][1]], 'mv:' . $bot['name']), 0x2);
        }
        unset($bot);
    }

    // select 后清理彻底掉线的 bot（gw 断开）
    foreach ($bots as $idx => $b) {
        if (isset($b['gw']) && (feof($b['gw']))) {
            $dropMap($idx);
            fclose($b['gw']);
            unset($gwBots[(int) $b['gw']], $bots[$idx]);
        }
    }
    if ($bots === []) {
        $live = false;
    }
}

$elapsed = max(0.001, microtime(true) - $deadline + $seconds);
foreach ($bots as $b) {
    if ($b['map'] !== null) {
        @fclose($b['map']);
    }
    @fclose($b['gw']);
}

$fps = round($stats['frames'] / $elapsed, 1);
$p99 = $percentile(0.99);
$playTotal = $stats['transfersOk'] + $stats['dungeonEnter'] + $stats['dungeonExit'];
$playResult = [
    'driver' => 'play',
    'clients' => $clients,
    'seconds' => round($elapsed, 1),
    'moveMs' => $opts['moveMs'],
    'authOk' => $stats['authOk'],
    'establishFailed' => $stats['establishFailed'],
    'frames' => $stats['frames'],
    'bytesKB' => round($stats['bytes'] / 1024, 1),
    'fps' => $fps,
    'p99' => round($p99, 1),
    'socialP99' => round($socialPercentile(0.99), 1), // map:enter→map:entered 社交往返（bucket 粒度）
    // 玩法发生计数（soak 玩法断言的客户端侧证据；服务端侧由 drillPlayProbe 独立佐证）
    'transfers' => $stats['transfersOk'],
    'transfersFail' => $stats['transfersFail'],
    'dungeonEnter' => $stats['dungeonEnter'],
    'dungeonExit' => $stats['dungeonExit'],
    'teamCreated' => $stats['teamCreated'],
    'teamJoined' => $stats['teamJoined'],
    'teamLeft' => $stats['teamLeft'],
    'teamDisbanded' => $stats['teamDisbanded'],
    'teamError' => $stats['teamError'],
    'teamOffline' => $stats['teamOffline'],
    'chatSent' => $stats['chatWorld'] + $stats['chatChannel'] + $stats['chatTeam'],
    'chatRecv' => ($stats['chatWorldRecv'] ?? 0) + ($stats['chatChannelRecv'] ?? 0) + ($stats['chatTeamRecv'] ?? 0),
    'chatChannelRejected' => $stats['chatChannelRejected'],
    'playActions' => $playTotal + $stats['teamJoined'] + ($stats['chatWorldRecv'] ?? 0),
];

if ($opts['json']) {
    echo json_encode($playResult, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
    exit(0);
}
echo sprintf(
    "客户端=%d auth=%d 建链失败=%d fps=%.1f p99=%.0fms | 迁移=%d(失败%d) 副本进=%d 出=%d | 组队 C%d/J%d/L%d/D%d err%d | 聊天 sent=%d recv=%d 跨频道拒=%d\n",
    $clients,
    $stats['authOk'],
    $stats['establishFailed'],
    $fps,
    $p99,
    $stats['transfersOk'],
    $stats['transfersFail'],
    $stats['dungeonEnter'],
    $stats['dungeonExit'],
    $stats['teamCreated'],
    $stats['teamJoined'],
    $stats['teamLeft'],
    $stats['teamDisbanded'],
    $stats['teamError'],
    $playResult['chatSent'],
    $playResult['chatRecv'],
    $stats['chatChannelRejected'],
);

/**
 * 迁移状态机合法转移（纯函数，self-test 锚定）：给定当前 phase 与事件，返回下一 phase。
 * 事件：'entered'（map:entered 回执到达）/ 'attach_ok' / 'attach_fail' / 'timeout'。
 * 非法组合回落到 null（防状态机卡死，主循环同函数复用转移表）。
 * The transfer state machine as a pure function: (phase, event) -> next phase. Unknown combos fall back to
 * null so a stuck bot self-heals (the main loop shares this transition table conceptually).
 */
function playTransferAdvance(?string $phase, string $event): ?string
{
    return match (true) {
        $phase === 'await-entered' && $event === 'entered' => 'await-attach',
        $phase === 'await-attach' && $event === 'attach_ok' => null,
        $phase === 'await-attach' && $event === 'attach_fail' => null,
        $phase === 'await-entered' && $event === 'entered_fail' => null,
        ($phase === 'await-entered' || $phase === 'await-attach') && $event === 'timeout' => null,
        default => $phase,
    };
}

/**
 * token 消费窗口判定（纯函数）：auth 成功后 map scope 仅 PLAY_TOKEN_TTL_SEC 有效，客户端须在此之前 attach。
 * Whether an attach at $attachAt falls within the token TTL measured from $authAt.
 */
function playTokenWindowOk(float $authAt, float $attachAt): bool
{
    $elapsed = $attachAt - $authAt;

    return $elapsed >= 0 && $elapsed < PLAY_TOKEN_TTL_SEC;
}

/**
 * 每连接节流预算（纯函数）：一个 $windowSec 秒窗口内某连接最多可发 floor(PLAY_MSG_BUDGET_PER_SEC*window) 条，
 * 超过则服务端静默丢帧。客户端据此排程，超限即自毁统计，故必须在预算内。
 * Per-connection send budget: at most floor(10*window) frames fit a window; over-budget sends are silently
 * dropped by the server, so the scheduler stays under it.
 */
function playWithinBudget(int $sentInWindow, float $windowSec): bool
{
    return $sentInWindow <= (int) floor(PLAY_MSG_BUDGET_PER_SEC * $windowSec);
}

/**
 * 自测：信封 timestamp 必须数字、迁移状态机转移、token 窗口、节流预算，无网络无环境依赖。
 * Self-test: numeric-timestamp contract, transfer state machine, token window, throttle budget. No I/O.
 */
function playSelfTest(): int
{
    $failures = [];
    $assert = static function (bool $cond, string $name) use (&$failures): void {
        echo ($cond ? 'PASS' : 'FAIL') . "  {$name}\n";
        if (!$cond) {
            $failures[] = $name;
        }
    };

    // ① JSON 社交信封（blueprint/27 实测踩坑：timestamp 字符串会被网关拒）
    $env = json_decode(drillSocialFrame('map:enter', 'r1', ['mapId' => 'map-1']), true);
    $assert(is_array($env) && is_float($env['timestamp']), 'envelope timestamp 为数字（非字符串）');
    $assert(($env['type'] ?? '') === 'map:enter' && ($env['payload']['mapId'] ?? '') === 'map-1', 'envelope type/payload 结构');

    // ② 迁移状态机
    $assert(playTransferAdvance('await-entered', 'entered') === 'await-attach', 'entered → await-attach');
    $assert(playTransferAdvance('await-attach', 'attach_ok') === null, 'attach_ok → 稳态');
    $assert(playTransferAdvance('await-attach', 'timeout') === null, '超时自愈回稳态');
    $assert(playTransferAdvance('await-entered', 'attach_ok') === 'await-entered', '非法事件不改态（entered 阶段不响应 attach_ok）');
    $assert(playTransferAdvance(null, 'entered') === null, '稳态收 stray entered 无害');

    // ③ token TTL 窗口
    $assert(playTokenWindowOk(100.0, 129.0), 'auth 后 29s attach 在窗口内');
    $assert(!playTokenWindowOk(100.0, 131.0), 'auth 后 31s attach 超窗口');
    $assert(!playTokenWindowOk(100.0, 99.0), 'attach 早于 auth 非法');

    // ④ 每连接节流预算（服务端 10/s 静默丢）
    $assert(playWithinBudget(20, 2.0), '2s 窗口发 20 条达上限合法');
    $assert(!playWithinBudget(25, 2.0), '2s 窗口发 25 条超预算');
    $assert(playWithinBudget(9, 1.0), '1s 窗口 9 条 < 10 合法');

    // ⑤ 帧解析器复用 stress-map 同款（残帧保留，多帧粘包全解）
    $wire = static function (string $payload, int $opcode = 0x1): string {
        $len = strlen($payload);
        $head = chr(0x80 | $opcode);
        $head .= $len < 126 ? chr($len) : ($len < 65536 ? chr(126) . pack('n', $len) : chr(127) . pack('J', $len));

        return $head . $payload;
    };
    $buf = $wire('{"a":1}') . $wire('{"b":2}');
    $frames = drillParseWsBuffer($buf);
    $assert(count($frames) === 2 && $buf === '', 'JSON 双帧粘包全解析');

    if ($failures !== []) {
        printf("[stress-play] SELF-TEST FAIL：%d 项断言未过\n", count($failures));

        return 1;
    }
    echo '[stress-play] SELF-TEST PASS' . "\n";

    return 0;
}
