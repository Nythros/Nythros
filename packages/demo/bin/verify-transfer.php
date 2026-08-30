<?php

declare(strict_types=1);

/**
 * verify-transfer —— P15 跨 map 实体迁移端到端验收（ADR-025 方案 C：客户端驱动换线 + 转移票据）。
 * verify-transfer — the P15 cross-map entity migration's end-to-end acceptance (ADR-025's option C:
 * client-driven zoning + transfer tickets).
 *
 * 链路（单客户端 1001，社交连接全程保持；NYTHROS_MMORPG_PLAYER_RESPAWN_MS 不设——死亡态稳定无复活竞态）：
 * Flow (a single client 1001, the social connection stays open; NYTHROS_MMORPG_PLAYER_RESPAWN_MS unset —
 * a stable death state with no revive race):
 *   0 登录：gateway(JSON) auth → token → Map(map-1) 直连 auth_ok（二进制批量，编解码走 lib/map-codec.php）
 *     Login: gateway (JSON) auth -> token -> Map (map-1) direct auth_ok (binary batches via lib/map-codec.php)
 *   1 承伤致死：移至 monster-2 锚点 (-6,-6) 攻击 wolf，累计反击致死 → 自身 entity_dead 确认；
 *     导出快照 hp clamp = 1（ADR-025 §3.3「死亡态不迁移」）
 *     Fight to death: move to monster-2's anchor (-6,-6), attack the wolf until the accumulated counter-attacks
 *     kill -> the own entity_dead confirms; the exported snapshot clamps hp to 1 (ADR-025 §3.3's "death state
 *     never migrates")
 *   2 迁移：gateway map:enter{mapId:'map-2'} → map:entered{token, map{wsAddress}} → 断开旧连接（detach 导出
 *     票据）→ 新地址 auth → auth_ok（attach 原子消费票据重建实体）
 *     Migration: gateway map:enter{mapId:'map-2'} -> map:entered{token, map{wsAddress}} -> close the old
 *     connection (detach exports the ticket) -> auth on the new address -> auth_ok (attach atomically consumes
 *     the ticket and rebuilds the entity)
 *   3 恢复断言：跨图落点 = 目的图入场点 (0,0) → 等出生保护过期 → 迁移后首击即死（combat:hit hp ≤ 1）——
 *     hp=1 经票据恢复的唯一解释；全新入场 100hp 的首击为 88-92（区间分离，断言确定）
 *     The restore assertion: a cross-map entry lands on the destination's entry point (0,0) -> wait out the spawn
 *     protection -> the first hit after migration kills (combat:hit hp <= 1) — the only explanation is the restored
 *     hp=1 via the ticket; a fresh 100-hp entry shows 88-92 on its first hit (disjoint windows, a deterministic
 *     assertion)
 *
 * 前置环境：Redis 可用；服务按 deploy.yaml 全拓扑启动（含 map-2: 18083）：
 * Prerequisites: Redis reachable; the full deploy.yaml topology is up (including map-2: 18083):
 *   NYTHROS_MMORPG=1 setsid -f php bin/server start
 * 输出契约：逐项一行 [verify] [PASS|FAIL]，末行 summary + RESULT（与 verify-mmorpg 一致）。
 * Output contract: one [verify] [PASS|FAIL] line per item, a final summary + RESULT (matching verify-mmorpg).
 */

require __DIR__ . '/../../../vendor/autoload.php';
require __DIR__ . '/lib/map-codec.php';
require __DIR__ . '/lib/verify-framework.php';

use Workerman\Connection\AsyncTcpConnection;
use Workerman\Timer;
use Workerman\Worker;

const GW_WS = 'ws://127.0.0.1:18285';
const UID = '1001';

/** @var array{conn: ?AsyncTcpConnection, inbox: array<int, array<string, mixed>>, authMapWs: ?string} 网关连接/收件箱（全程保持）与迁移目标地址 The gateway connection/inbox (kept open) and the migration target address. */
$GLOBALS['social'] = ['conn' => null, 'inbox' => [], 'authMapWs' => null];

/** @var int 源图死亡标记（1 = map-1 上已 entity_dead，导出快照 hp clamp=1） The source-map death marker (1 = already entity_dead on map-1, the exported snapshot clamps hp to 1). */
$GLOBALS['hpDiedOnSource'] = 0;

function sendSocial(string $type, array $payload): void
{
    $conn = $GLOBALS['social']['conn'] ?? null;
    if ($conn instanceof AsyncTcpConnection) {
        $conn->send(json_encode([
            'type' => $type,
            'requestId' => reqId(),
            // 网关对全部消息校验 timestamp 必须为数字（auth 同口径，实测踩坑）
            // The gateway validates a numeric timestamp on every message (the auth convention, a measured pitfall).
            'timestamp' => microtime(true),
            'payload' => $payload,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}

/** 自身受击/死亡帧谓词（combat:hit targetId=own 且 hp≤0，或 entity_dead id=own）。 The own hit/death-frame predicate (combat:hit with targetId=own AND hp<=0, or entity_dead with id=own). */
function ownDamageFrame(): callable
{
    return static function (array $f): bool {
        $own = $GLOBALS['entityIds'][UID] ?? null;
        if ($own === null) {
            return false;
        }
        if (($f['type'] ?? null) === 'entity_dead') {
            return ($f['payload']['id'] ?? null) === $own;
        }

        return ($f['type'] ?? null) === 'combat:hit'
            && ($f['payload']['targetId'] ?? null) === $own
            && is_int($f['payload']['hp'] ?? null)
            && $f['payload']['hp'] <= 0;
    };
}

/**
 * 验收项 0：登录链路（网关 + Map 直连；社交连接不关——map:enter 需要活会话）。
 * Item 0: the login chain (gateway + Map direct; the social connection stays — map:enter needs a live session).
 */
function step0Login(): void
{
    $GLOBALS['clients'][UID] = ['inbox' => [], 'conn' => null];

    $social = new AsyncTcpConnection(GW_WS);
    $GLOBALS['social']['conn'] = $social;
    $social->onConnect = static function (AsyncTcpConnection $c): void {
        $c->send(json_encode([
            'type' => 'auth',
            'requestId' => 'login:' . UID,
            'timestamp' => microtime(true),
            'payload' => ['username' => UID, 'password' => 'secret', 'mapId' => 'map-1'],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    };
    $social->onMessage = static function (AsyncTcpConnection $c, mixed $data): void {
        $decoded = json_decode((string) $data, true);
        if (is_array($decoded)) {
            $GLOBALS['social']['inbox'][] = $decoded;
        }
    };

    waitFrame($GLOBALS['social']['inbox'], 'auth_ok', null, 15.0, static function (array $f): void {
        $token = $f['payload']['token'] ?? null;
        if (!is_string($token)) {
            closeStep('FAIL', 'gateway auth_ok 缺少 token');

            return;
        }
        connectMap($token, static function (): void {
            check(isset($GLOBALS['entityIds'][UID]) && str_starts_with((string) $GLOBALS['entityIds'][UID], UID . '@'), 'Map auth_ok 就位（entityId = uid@conn）');
            // 避险 move：远离两怪巡逻域，随后进承伤致死
            // The evasive move: far outside both patrol domains, then into the fight-to-death.
            sendMap(UID, 'move', ['dx' => 100, 'dy' => 100]);
            closeStep('PASS', '登录链路就位（社交连接保持）');
        }, static function (string $reason): void {
            closeStep('FAIL', $reason);
        });
    }, static function (): void {
        closeStep('FAIL', 'gateway auth_ok 超时');
    });
    $social->connect();
}

/**
 * Map 直连装配（收件箱并入 clients[UID] 供 waitMapFrame；auth_ok 即 ready）。
 * The Map direct-connect assembly (the inbox merges into clients[UID] for waitMapFrame; auth_ok means ready).
 */
function connectMap(string $token, callable $onReady, callable $onFail): void
{
    $wsAddress = $GLOBALS['social']['authMapWs'] ?? 'ws://127.0.0.1:18081';
    $map = new AsyncTcpConnection($wsAddress);
    $map->websocketType = \Workerman\Protocols\Websocket::BINARY_TYPE_ARRAYBUFFER;
    $GLOBALS['clients'][UID]['conn'] = $map;
    $GLOBALS['clients'][UID]['inbox'] = [];
    $map->onConnect = static function (AsyncTcpConnection $m) use ($token): void {
        $m->send(frameMap('auth', ['token' => $token], reqId()));
    };
    $map->onMessage = static function (AsyncTcpConnection $m, mixed $data) use ($onReady, $onFail): void {
        try {
            foreach (decodeMapFrames((string) $data) as $frame) {
                $GLOBALS['clients'][UID]['inbox'][] = $frame;
            }
        } catch (\Throwable $e) {
            $onFail('帧解码失败: ' . $e->getMessage());

            return;
        }
        $authOk = inboxTake($GLOBALS['clients'][UID]['inbox'], 'auth_ok');
        if ($authOk !== null) {
            $entityId = $authOk['payload']['id'] ?? '';
            check(is_string($entityId) && str_starts_with($entityId, UID . '@'), 'entityId 前缀 = uid@');
            $GLOBALS['entityIds'][UID] = $entityId;
            $onReady();
        }
    };
    $map->onError = static function () use ($onFail): void {
        $onFail('Map 连接错误');
    };
    $map->connect();
}

/**
 * 验收项 1：承伤致死（auto-revive 关闭 → 死亡态稳定，无复活竞态）——wolf 反击累计致死，
 * 自身 entity_dead 确认；导出快照 hp clamp = 1（ADR-025 §3.3「死亡态不迁移」）。
 * Item 1: fight to death (auto-revive off -> a stable death state, no revive race) — the wolf's counter-attacks
 * accumulate lethally; the own entity_dead confirms; the exported snapshot clamps hp to 1 (ADR-025 §3.3's
 * "death state never migrates").
 */
function step1TakeDamage(): void
{
    sendMap(UID, 'move', ['dx' => -106, 'dy' => -106]);
    verifyTimer(0.8, static function (): void {
        attackUntilDeath(40, static function (): void {
            $GLOBALS['hpDiedOnSource'] = 1;
            closeStep('PASS', 'map-1 死亡确认（entity_dead）');
        }, static function (string $reason): void {
            closeStep('FAIL', $reason);
        });
    });
}

/**
 * 攻击循环：每 0.9s 一次 attack，直到自身死亡（entity_dead 自身帧或 combat:hit hp=0 自身帧）。
 * The attack loop: one attack per 0.9s until the own death (an own entity_dead frame or an own combat:hit with hp=0).
 */
function attackUntilDeath(int $maxAttempts, callable $onDead, callable $onFail): void
{
    $attempt = 0;
    $try = null;
    $try = static function () use (&$try, &$attempt, $maxAttempts, $onDead, $onFail): void {
        $dead = inboxTake($GLOBALS['clients'][UID]['inbox'], null, ownDamageFrame());
        if ($dead !== null) {
            echo '  [E2E] death-match: ' . json_encode($dead, JSON_UNESCAPED_UNICODE) . "\n";
            $onDead();

            return;
        }
        if ($attempt >= $maxAttempts) {
            $onFail('攻击 ' . $maxAttempts . ' 次未死亡（承伤节奏异常）');

            return;
        }
        ++$attempt;
        sendMap(UID, 'attack', ['targetId' => 'monster-2']);
        verifyTimer(0.9, $try);
    };
    $try();
}

/**
 * 验收项 2：迁移——gateway map:enter{mapId:'map-2'} → map:entered → 断旧连（detach 导出票据）→
 * 新地址 auth（attach 原子消费票据重建实体；entityId 的 conn 段不跨进程比对——各 worker 连接计数
 * 独立可能撞号，auth_ok 本身即 attach 成功信号）。
 * Item 2: the migration — gateway map:enter{mapId:'map-2'} -> map:entered -> close the old connection (detach
 * exports the ticket) -> auth on the new address (attach atomically consumes the ticket and rebuilds; the
 * entityId's conn segment is never compared across processes — per-worker counters may collide; auth_ok itself
 * is the attach-success signal).
 */
function step2Migrate(): void
{
    sendSocial('map:enter', ['mapId' => 'map-2']);
    waitFrame($GLOBALS['social']['inbox'], 'map:entered', null, 15.0, static function (array $f): void {
        $token = $f['payload']['token'] ?? null;
        $mapInfo = $f['payload']['map'] ?? null;
        if (!is_string($token) || !is_array($mapInfo) || !is_string($mapInfo['wsAddress'] ?? null)) {
            closeStep('FAIL', 'map:entered 负载不完整');

            return;
        }
        check(str_contains((string) $mapInfo['wsAddress'], '18083'), '目录选中 map-2（wsAddress=18083）');

        $conn = $GLOBALS['clients'][UID]['conn'] ?? null;
        if ($conn instanceof AsyncTcpConnection) {
            $conn->close(); // detach：closeConnection 清理路径导出转移票据（ADR-025 §3.2） detach: the closeConnection cleanup path exports the ticket (ADR-025 §3.2).
        }
        verifyTimer(0.8, static function () use ($token, $mapInfo): void {
            $GLOBALS['social']['authMapWs'] = (string) $mapInfo['wsAddress'];
            connectMap($token, static function (): void {
                closeStep('PASS', '跨 map 迁移就位（map-2 auth_ok）');
            }, static function (string $reason): void {
                closeStep('FAIL', $reason);
            });
        });
    }, static function (): void {
        // 超时诊断：倾倒社交收件箱内的 error/map:error 帧（timestamp 校验拒绝/无可用频道等）
        // Timeout diagnostics: dump error/map:error frames left in the social inbox (timestamp rejections,
        // no-available-channel, etc.).
        foreach ($GLOBALS['social']['inbox'] as $f) {
            if (in_array($f['type'] ?? null, ['error', 'map:error'], true)) {
                echo '  [verify] social ' . $f['type'] . ': ' . json_encode($f['payload'] ?? [], JSON_UNESCAPED_UNICODE) . "\n";
            }
        }
        closeStep('FAIL', 'map:entered 超时');
    });
}

/**
 * 验收项 3：恢复断言——迁移后首击即死（combat:hit hp ≤ 1）是 hp=1 经票据恢复的唯一解释；
 * 全新入场 100hp 的首击为 88-92（区间分离，断言确定）。从入场点 (0,0) 移向 monster-2 锚点，
 * 0.1s 粒度扫描自身受击帧。
 * Item 3: the restore assertion — the first hit after migration kills (combat:hit hp <= 1), the only
 * explanation being the restored hp=1 via the ticket; a fresh 100-hp entry shows 88-92 on its first hit
 * (disjoint windows, a deterministic assertion). Move from the entry point (0,0) to monster-2's anchor and
 * scan the own hit frames at 0.1s granularity.
 */
function step3AssertRestoredHp(): void
{
    if ($GLOBALS['hpDiedOnSource'] !== 1) {
        closeStep('FAIL', '缺少源图死亡标记');

        return;
    }
    sendMap(UID, 'move', ['dx' => -6, 'dy' => -6]);
    $try = null;
    $elapsed = 0.0;
    $try = static function () use (&$try, &$elapsed): void {
        $hit = inboxTake($GLOBALS['clients'][UID]['inbox'], 'combat:hit', static function (array $f): bool {
            return ($f['payload']['targetId'] ?? null) === ($GLOBALS['entityIds'][UID] ?? null)
                && is_int($f['payload']['hp'] ?? null);
        });
        if ($hit !== null) {
            $hp = (int) $hit['payload']['hp'];
            check($hp <= 1, sprintf('迁移后首击 hp=%d ≤ 1（票据恢复生效；未恢复应为 88-92）', $hp));
            closeStep($hp <= 1 ? 'PASS' : 'FAIL', sprintf('迁移后首击 hp=%d（死亡态迁移 clamp=1）', $hp));

            return;
        }
        $elapsed += 0.1;
        if ($elapsed >= 40.0) {
            closeStep('FAIL', '迁移后 40s 未承伤（怪物漂移或反击未发生）');

            return;
        }
        sendMap(UID, 'attack', ['targetId' => 'monster-2']);
        verifyTimer(0.1, $try);
    };
    $try();
}

// 验收步骤注册表（顺序执行；每步独立超时）。
// The acceptance step registry (sequential execution; per-step timeout).
bootVerifyGlobals([
    ['0. 登录（网关 + Map 直连，社交连接保持）', 'step0Login', 40.0],
    ['1. 承伤致死（wolf 反击累计 → entity_dead）', 'step1TakeDamage', 90.0],
    ['2. 迁移（map:enter map-2 → 换连 → auth 重建）', 'step2Migrate', 40.0],
    ['3. 恢复断言（迁移后首击即死：hp=1 经票据恢复）', 'step3AssertRestoredHp', 60.0],
]);

$worker = new Worker();
$worker->onWorkerStart = static function (): void {
    echo "[verify] P15 跨 map 实体迁移端到端验收启动（ADR-025 方案 C）\n";
    Timer::add(240.0, static function (): void {
        echo "[verify] WATCHDOG: 全局超时\n";
        finishAll();
    }, [], false);
    nextStep();
};

$GLOBALS['argv'] = [$argv[0], 'start'];

set_exception_handler(static function (\Throwable $e): void {
    fwrite(STDERR, sprintf("[verify] fatal: %s in %s:%d\n", $e->getMessage(), $e->getFile(), $e->getLine()));
    if (function_exists('posix_kill')) {
        posix_kill(posix_getppid(), SIGINT);
    }
    exit(1);
});

Worker::runAll();
