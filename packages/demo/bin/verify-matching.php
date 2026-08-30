<?php

declare(strict_types=1);

// 定位：packages/demo/bin/verify-matching.php — R3 玩法批匹配服务端到端验收脚本。
// Located at: packages/demo/bin/verify-matching.php — the R3 gameplay-batch matching end-to-end acceptance script.
//
// 为什么新建而非扩展 verify-room：匹配验收的步骤矩阵（多客户端并发入队、人未满等待、取消、条件拒绝）
// 与房间生命周期验收（create/join/spawn/aoe/settle/close）正交；塞进 verify-room 会把其启动环境
// （NYTHROS_ROOMS=1）之外再叠加 NYTHROS_GAMEPLAY=1 且脚本已逾千行——独立脚本让两份验收各自聚焦，
// 失败归因互不干扰（比照 verify-economy 独立于 verify-combat 的先例）。
// Why a new script instead of extending verify-room: matching's step matrix (multi-client concurrent enqueue,
// unfilled-queue waiting, cancellation, criteria rejection) is orthogonal to the room-lifecycle acceptance
// (create/join/spawn/aoe/settle/close); folding it into verify-room would stack NYTHROS_GAMEPLAY=1 on top of its
// existing environment (NYTHROS_ROOMS=1) inside a script already past a thousand lines — separate scripts keep each
// acceptance focused with independent failure attribution (mirroring verify-economy standing apart from verify-combat).
//
// 验收链路（3 客户端 uid 1001-1003，风格对齐 verify-combat/verify-room）：
// Acceptance chain (three clients uids 1001-1003, styled after verify-combat/verify-room):
//   0 前置：gateway 18285 登录 → token → Map 18081 直连（auth_ok 后各发一帧避险 move 出世界怪巡逻域）
//     Prerequisite: gateway-18285 login → token → direct Map-18081 (each client sends one evasive move after
//     auth_ok, out of the world monsters' patrol domains)
//   1 人未满等待：1001 matching:enqueue{queueId=duo-2, level=10} → matching:ok{op=enqueue, code=ok}；
//     3s 观察窗内【无】matching:matched（凑不满不开房）
//     Unfilled waiting: 1001 enqueues → matching:ok; NO matching:matched within a 3s window (no room until full)
//   2 撮合成功 + 开房编排：1002 入队 → 双方各收 matching:matched{roomId, memberIds=[1001@*,1002@*]}
//     （FIFO 序）+ 各自 room:snapshot（MatchJoinOrchestrator 走 RoomHub transfer 全链成功的证据）
//     Match success + room orchestration: 1002 enqueues → both receive matching:matched (FIFO order) plus each
//     one's room:snapshot (evidence MatchJoinOrchestrator walked RoomHub's full transfer chain)
//   3 取消：1003 enqueue → ok → cancel → matching:ok{op=cancel, code=ok}
//     Cancellation: 1003 enqueues then cancels → matching:ok{code=ok}
//   4 条件拒绝：1003 以越界 level=99999 再入队 → matching:ok{op=enqueue, code=rejected}
//     Criteria rejection: 1003 re-enqueues with an out-of-range level=99999 → matching:ok{code=rejected}
//
// 前置环境：Redis 127.0.0.1:6379 可用；MySQL 127.0.0.1:3306（nythros 库）。服务启动（WSL 内 setsid -f 防 SIGHUP）：
//   cd /mnt/d/workspace/php/Nythros && NYTHROS_ROOMS=1 NYTHROS_GAMEPLAY=1 NYTHROS_ACCOUNTS=1001=secret,... setsid -f php bin/server start
// Prerequisites: Redis on 127.0.0.1:6379; MySQL on 127.0.0.1:3306 (the nythros DB). Boot (inside WSL use setsid -f):
//   cd /mnt/d/workspace/php/Nythros && NYTHROS_ROOMS=1 NYTHROS_GAMEPLAY=1 ... setsid -f php bin/server start
//
// 输出契约：每项一行 [verify] [PASS|FAIL]；末行 RESULT 汇总（与 verify-combat 一致）。
// Output contract: one line per item [verify] [PASS|FAIL]; a final RESULT summary line (matching verify-combat).

require __DIR__ . '/../../../vendor/autoload.php';
require __DIR__ . '/lib/map-codec.php';
require __DIR__ . '/lib/verify-framework.php';

use Workerman\Connection\AsyncTcpConnection;
use Workerman\Timer;
use Workerman\Worker;

const GW_WS = 'ws://127.0.0.1:18285';
const MAP_WS = 'ws://127.0.0.1:18081'; // map-1#ch-1（匹配验收目标频道） map-1#ch-1 (the matching-acceptance target channel)

/** @var list<string> 验收客户端 uid 列表 The acceptance client uids. */
const UIDS = ['1001', '1002', '1003'];

/** @var array<string, mixed> 验收共享状态 Shared acceptance state. */
bootVerifyGlobals([]);

/** @var array<string, array<string, mixed>> 客户端注册表：uid => {conn, inbox, entityId} Client registry: uid => {conn, inbox, entityId}. */
$GLOBALS['clients'] = [];

/** @var int 请求 id 序列 Request id sequence. */
$GLOBALS['reqSeq'] = 0;

/**
 * 等待某 uid 收件箱【不】出现匹配帧（负向观察窗）：窗口走完回调成功，出现即回调失败。
 * Waits for a uid's inbox NOT to see a matching frame (a negative observation window): success when the window
 * elapses clean, failure the moment one appears.
 *
 * @param callable(array<string, mixed>): bool|null $pred 附加谓词 Additional predicate.
 * @param callable(): void $onClean 窗口干净回调 Clean-window callback.
 * @param callable(array<string, mixed>): void $onSeen 观察到帧回调 Frame-seen callback.
 */
function waitAbsence(string $uid, ?string $type, ?callable $pred, float $window, callable $onClean, callable $onSeen): void
{
    $inbox = &$GLOBALS['clients'][$uid]['inbox'];
    $t0 = microtime(true);
    $scan = null;
    $scan = function () use (&$scan, &$inbox, $type, $pred, $window, $onClean, $onSeen, $t0): void {
        $f = inboxTake($inbox, $type, $pred);
        if ($f !== null) {
            $onSeen($f);

            return;
        }
        if (microtime(true) - $t0 >= $window) {
            $onClean();

            return;
        }
        verifyTimer(0.2, $scan, [], false);
    };
    $scan();
}

/**
 * 验收项 0（前置）：三客户端 gateway 登录 → Map 直连（auth_ok 后各发一帧避险 move）。
 * Item 0 (prerequisite): three clients log in via the gateway → direct Map (one evasive move each after auth_ok).
 */
function step0Login(): void
{
    $GLOBALS['loginPending'] = count(UIDS);
    foreach (UIDS as $i => $uid) {
        $state = &$GLOBALS['clients'][$uid];
        $state['inbox'] = [];
        $socialDone = false;
        $mapDone = false;

        $social = new AsyncTcpConnection(GW_WS);
        $social->onConnect = static function (AsyncTcpConnection $c) use ($uid): void {
            $c->send(json_encode([
                'type' => 'auth',
                'requestId' => 'login:' . $uid,
                'timestamp' => microtime(true),
                'payload' => ['username' => $uid, 'password' => 'secret', 'mapId' => 'map-1'],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        };
        $social->onMessage = static function (AsyncTcpConnection $c, mixed $data) use (&$state, &$socialDone, $uid): void {
            $decoded = json_decode((string) $data, true);
            if (!is_array($decoded) || ($decoded['type'] ?? null) !== 'auth_ok') {
                return;
            }
            $socialDone = true;
            $token = $decoded['payload']['token'] ?? null;
            if (!is_string($token)) {
                check(false, $uid . ' gateway auth_ok 缺少 token');
                closeStep('FAIL', 'auth_ok 负载不完整');

                return;
            }
            $c->close();

            $map = new AsyncTcpConnection(MAP_WS);
            $map->websocketType = \Workerman\Protocols\Websocket::BINARY_TYPE_ARRAYBUFFER;
            $state['conn'] = $map;
            $map->onConnect = static function (AsyncTcpConnection $m) use ($token): void {
                $m->send(frameMap('auth', ['token' => $token], 'map-auth:' . $token));
            };
            $map->onMessage = static function (AsyncTcpConnection $m, mixed $data) use (&$state, &$mapDone, $uid, &$pending): void {
                foreach (decodeMapFrames((string) $data) as $decodedFrame) {
                    $state['inbox'][] = $decodedFrame;
                    if (($decodedFrame['type'] ?? null) === 'error') {
                        echo sprintf("[verify] error frame: %s\n", json_encode($decodedFrame['payload'] ?? [], JSON_UNESCAPED_UNICODE));
                    }
                    if (!$mapDone && ($decodedFrame['type'] ?? null) === 'auth_ok') {
                        $mapDone = true;
                        $entityId = $decodedFrame['payload']['id'] ?? '';
                        check(is_string($entityId) && str_starts_with($entityId, $uid . '@'), $uid . ' Map auth_ok 就位');
                        // 避险 move：一步跳出世界怪的感知/攻击范围（R4 起锚点外移至对角邻格 cell(±1,±1)，
                        // (100,100) 落 cell(10,10) 远在巡逻域与九宫格视野之外）。位移取 {100,100} 而非更远的
                        // 旧值 {300,300}：E2E 现以 NYTHROS_ANTICHEAT=1 启动（verify-room 房内反作弊断言依赖），
                        // 单步须落在反作弊阈值内（轴 ≤128、欧氏 ≤200）——(100,100) 欧氏 ≈141 合法。
                        // The evasive move: one hop beyond the world monsters' perception/attack range. Since R4 the
                        // anchors sit in the diagonal neighbor cells, and (100,100) lands in cell(10,10), far outside
                        // both the patrol domains and any 3x3 view. The displacement is {100,100} rather than the old
                        // {300,300}: E2E now boots with NYTHROS_ANTICHEAT=1 (the verify-room in-room anti-cheat
                        // assertion depends on it), so a single step must stay inside the anti-cheat thresholds
                        // (axis ≤128, Euclidean ≤200) — (100,100) is ≈141 and legal.
                        sendMap($uid, 'move', ['dx' => 100, 'dy' => 100]);
                        $GLOBALS['loginPending']--;
                        if ($GLOBALS['loginPending'] === 0) {
                            closeStep('PASS', '三客户端 gateway 登录 + Map 直连就位');
                        }
                    }
                }
            };
            $map->onClose = static function () use (&$mapDone): void {
                if (!$mapDone) {
                    $mapDone = true;
                    check(false, 'Map 连接在认证前关闭');
                    closeStep('FAIL', 'Map 连接关闭');
                }
            };
            $map->connect();
        };
        $social->onClose = static function () use (&$socialDone, $uid): void {
            if (!$socialDone && !isset($GLOBALS['clients'][$uid]['conn'])) {
                $socialDone = true;
                check(false, $uid . ' gateway 连接在登录完成前关闭');
                closeStep('FAIL', 'gateway 连接关闭');
            }
        };
        $social->connect();
    }
}

/**
 * 验收项 1：人未满等待——1001 入队后 3s 观察窗内无 matching:matched。
 * Item 1: unfilled waiting — after 1001 enqueues, no matching:matched within a 3s observation window.
 */
function step1WaitUnfilled(): void
{
    sendMap('1001', 'matching:enqueue', ['queueId' => 'duo-2', 'level' => 10]);
    waitMapFrame('1001', 'matching:ok', static fn (array $f): bool => ($f['payload']['op'] ?? null) === 'enqueue'
        && ($f['payload']['code'] ?? null) === 'ok', 8.0, static function (): void {
            check(true, '1001 入队回执 matching:ok{code=ok}');
            waitAbsence('1001', 'matching:matched', null, 3.0, static function (): void {
                check(true, '3s 观察窗内无 matching:matched（人未满保持等待）');
                closeStep('PASS', '人未满等待');
            }, static function (array $f): void {
                check(false, '人未满却收到 matching:matched');
                closeStep('FAIL', '提前撮合');
            });
        }, static function (): void {
            check(false, '1001 未得入队回执');
            closeStep('FAIL', 'matching:enqueue 未确认');
        });
}

/**
 * 验收项 2：撮合成功 + 开房编排——1002 入队即凑满：双方收 matching:matched（memberIds FIFO 序）
 * 与各自的 room:snapshot（transfer 全链编排成功证据）。
 * Item 2: match success + room orchestration — 1002's enqueue fills the queue: both sides receive matching:matched
 * (memberIds in FIFO order) plus each one's room:snapshot (evidence the full transfer chain orchestrated).
 */
function step2MatchAndBuild(): void
{
    sendMap('1002', 'matching:enqueue', ['queueId' => 'duo-2', 'level' => 12]);

    // matched.memberIds 为 uid 口径（撮合是社交语义帧；MatchingService built 结构中 uids/entityIds
    // 并行对齐，投递侧选 uids），断言按 uid 精确匹配并校验 FIFO 序（先入队者排前）。
    // matched.memberIds carry uids (matching is a social-semantics frame; MatchingService's built structure
    // aligns uids/entityIds in parallel and the delivery side picks uids), so assert exact uids with the FIFO
    // order (the earlier enqueuer ranks first).
    $checkSide = static function (string $uid, callable $next): void {
        waitMapFrame($uid, 'matching:matched', static function (array $f): bool {
            $members = is_array($f['payload']['memberIds'] ?? null) ? $f['payload']['memberIds'] : [];

            return count($members) === 2
                && ($members[0] ?? null) === '1001'
                && ($members[1] ?? null) === '1002';
        }, 8.0, static function (array $f) use ($uid, $next): void {
            $members = is_array($f['payload']['memberIds'] ?? null) ? $f['payload']['memberIds'] : [];
            check(true, $uid . ' 收到 matching:matched{memberIds=[1001, 1002]}（uid 口径 FIFO 序）');
            $roomId = (string) ($f['payload']['roomId'] ?? '');
            check(str_starts_with($roomId, 'match-duo-2-'), 'roomId 为 match-duo-2-* 口径（实际 ' . $roomId . '）');

            waitMapFrame($uid, 'room:snapshot', static fn (array $f2): bool => ($f2['payload']['roomId'] ?? null) === $roomId, 8.0, static function () use ($uid, $next): void {
                check(true, $uid . ' 收到 room:snapshot（transfer 编排成功）');
                $next();
            }, static function () use ($uid, $next): void {
                check(false, $uid . ' 未收到 room:snapshot');
                $next();
            });
        }, static function () use ($uid, $next): void {
            check(false, $uid . ' 未收到 matching:matched');
            $next();
        });
    };

    $bothDone = static function (): void {
        closeStep('PASS', '撮合成功 + 开房编排（matched ×2 + snapshot ×2）');
    };

    $seq = null;
    $seq = static function () use (&$seq, $checkSide, $bothDone): void {
        static $stage = 0;
        if ($stage === 0) {
            $stage++;
            $checkSide('1001', $seq);

            return;
        }
        if ($stage === 1) {
            $stage++;
            $checkSide('1002', $seq);

            return;
        }
        $bothDone();
    };
    $seq();
}

/**
 * 验收项 3：取消——1003 入队后取消 → matching:ok{op=cancel, code=ok}。
 * Item 3: cancellation — 1003 enqueues then cancels → matching:ok{op=cancel, code=ok}.
 */
function step3Cancel(): void
{
    sendMap('1003', 'matching:enqueue', ['queueId' => 'duo-2', 'level' => 15]);
    waitMapFrame('1003', 'matching:ok', static fn (array $f): bool => ($f['payload']['op'] ?? null) === 'enqueue'
        && ($f['payload']['code'] ?? null) === 'ok', 8.0, static function () {
            check(true, '1003 入队回执 ok');
            sendMap('1003', 'matching:cancel', []);
            waitMapFrame('1003', 'matching:ok', static fn (array $f): bool => ($f['payload']['op'] ?? null) === 'cancel'
                && ($f['payload']['code'] ?? null) === 'ok', 8.0, static function (): void {
                    check(true, '取消回执 matching:ok{op=cancel, code=ok}');
                    closeStep('PASS', '取消排队');
                }, static function (): void {
                    check(false, '未收到取消回执');
                    closeStep('FAIL', 'matching:cancel 未确认');
                });
        }, static function (): void {
            check(false, '1003 未得入队回执');
            closeStep('FAIL', 'matching:enqueue 未确认');
        });
}

/**
 * 验收项 4：条件拒绝——1003 以越界 level=99999 入队（duo-2 准入区间 [1,999]）→ code=rejected。
 * Item 4: criteria rejection — 1003 re-enqueues with an out-of-range level=99999 (duo-2 admits [1,999]) → rejected.
 */
function step4CriteriaRejection(): void
{
    sendMap('1003', 'matching:enqueue', ['queueId' => 'duo-2', 'level' => 99999]);
    waitMapFrame('1003', 'matching:ok', static fn (array $f): bool => ($f['payload']['op'] ?? null) === 'enqueue'
        && ($f['payload']['code'] ?? null) === 'rejected', 8.0, static function (): void {
            check(true, '越界 level 入队被拒 matching:ok{code=rejected}');
            closeStep('PASS', '准入条件拒绝');
        }, static function (): void {
            check(false, '越界 level 未被拒绝');
            closeStep('FAIL', '准入校验失效');
        });
}

// 验收步骤注册表（顺序执行；每步独立超时）。
// The acceptance step registry (sequential execution; per-step timeout).
$GLOBALS['verify']['steps'] = [
    ['0. 前置：三客户端登录 + Map 直连（1001-1003）', 'step0Login', 60.0],
    ['1. 人未满等待（入队回执 + 3s 无 matched）', 'step1WaitUnfilled', 25.0],
    ['2. 撮合成功 + 开房编排（matched ×2 + snapshot ×2）', 'step2MatchAndBuild', 30.0],
    ['3. 取消排队（cancel → ok）', 'step3Cancel', 25.0],
    ['4. 准入条件拒绝（越界 level → rejected）', 'step4CriteriaRejection', 25.0],
];

$worker = new Worker();
$worker->onWorkerStart = static function (): void {
    echo "[verify] R3 玩法批匹配端到端验收启动\n";
    // 全局看门狗：180s 未完成强制收尾。
    // Global watchdog: force the summary after 180s.
    Timer::add(180.0, static function (): void {
        echo "[verify] WATCHDOG: 全局超时\n";
        finishAll();
    }, [], false);
    nextStep();
};

$GLOBALS['argv'] = [$argv[0], 'start'];

set_exception_handler(static function (\Throwable $e): void {
    fwrite(STDERR, sprintf(
        "[verify] fatal: %s in %s:%d\n",
        $e->getMessage(),
        $e->getFile(),
        $e->getLine(),
    ));
    if (function_exists('posix_kill')) {
        posix_kill(posix_getppid(), SIGINT);
    }
    exit(1);
});

Worker::runAll();
