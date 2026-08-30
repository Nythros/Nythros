<?php

declare(strict_types=1);

/**
 * verify-scale —— P16 动态扩缩容端到端验收：drain 生命周期 + 目录动态发现扩容实例。
 * verify-scale — the P16 dynamic scaling's end-to-end acceptance: the drain lifecycle + the directory's
 * dynamic discovery of scaled-out instances.
 *
 * 链路（单客户端 1001；boot 需 NYTHROS_GM_UIDS=1001 开 GM 白名单）：
 * Flow (a single client 1001; boot with NYTHROS_GM_UIDS=1001 to enable the GM whitelist):
 *   0 登录：gateway auth → Map(map-1) 直连 auth_ok；记录 auth_ok 下发的 channelId（记为 ch-A）
 *     Login: gateway auth -> Map (map-1) direct auth_ok; records the channelId from auth_ok (call it ch-A)
 *   1 drain：gm:exec{command:'drain'} → gm:result ok——本实例 status=draining（目录停止路由新会话）
 *     drain: gm:exec{command:'drain'} -> gm:result ok — this instance's status=draining (the directory stops routing new sessions)
 *   2 缩容路由：map:enter{mapId:'map-1'} → map:entered 落到**非** ch-A 的频道（drained 实例被过滤）→
 *     重连 auth_ok → map:join 更新会话
 *     Scale-in routing: map:enter{mapId:'map-1'} -> map:entered lands on a channel that is NOT ch-A (the drained
 *     instance filtered) -> reconnect auth_ok -> map:join updates the session
 *   3 动态扩容：shell 拉起 map-1#ch-3 worker（run-worker.php 直启，registry 自动注册）→ drain 当前频道 →
 *     map:enter → 目录发现 ch-3（0 玩家，最少在线）→ 断言 channelId == 'ch-3'
 *     Scale-out: a shell spawn of the map-1#ch-3 worker (run-worker.php directly; the registry auto-registers) ->
 *     drain the current channel -> map:enter -> the directory discovers ch-3 (0 players, least loaded) -> assert
 *     channelId == 'ch-3'
 *
 * 前置环境：Redis 可用；deploy.yaml 全拓扑（map-1 两频道）+ NYTHROS_GM_UIDS=1001：
 * Prerequisites: Redis reachable; the full deploy.yaml topology (two map-1 channels) + NYTHROS_GM_UIDS=1001:
 *   NYTHROS_MMORPG=1 NYTHROS_GM_UIDS=1001 setsid -f php bin/server start
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

/** @var array{conn: ?AsyncTcpConnection, inbox: array<int, array<string, mixed>>, authMapWs: ?string} 网关连接/收件箱与下次直连地址 The gateway connection/inbox and the next direct-connect address. */
$GLOBALS['social'] = ['conn' => null, 'inbox' => [], 'authMapWs' => null];

/** @var string|null 当前所在频道（auth_ok / map:entered 下发） The current channel (from auth_ok / map:entered). */
$GLOBALS['currentChannel'] = null;

/** @var string|null 动态扩容拉起的 worker pid（收尾清理） The scaled-out worker pid (cleaned up on finish). */
$GLOBALS['scaledPid'] = null;

function sendSocial(string $type, array $payload): void
{
    $conn = $GLOBALS['social']['conn'] ?? null;
    if ($conn instanceof AsyncTcpConnection) {
        $conn->send(json_encode([
            'type' => $type,
            'requestId' => reqId(),
            // 网关对全部消息校验 timestamp 必须为数字（P15 实测踩坑） The gateway validates a numeric timestamp on every message (the P15 measured pitfall).
            'timestamp' => microtime(true),
            'payload' => $payload,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}

/**
 * 验收项 0：登录（网关 + Map 直连，社交连接保持）。
 * Item 0: login (gateway + Map direct, the social connection stays).
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
        $mapInfo = $f['payload']['map'] ?? [];
        if (!is_string($token) || !is_array($mapInfo) || !is_string($mapInfo['channelId'] ?? null)) {
            closeStep('FAIL', 'gateway auth_ok 负载不完整');

            return;
        }
        $GLOBALS['currentChannel'] = (string) $mapInfo['channelId'];
        $GLOBALS['social']['authMapWs'] = (string) ($mapInfo['wsAddress'] ?? 'ws://127.0.0.1:18081');
        connectMap($token, static function (): void {
            closeStep('PASS', sprintf('登录就位（频道 %s）', $GLOBALS['currentChannel']));
        }, static function (string $reason): void {
            closeStep('FAIL', $reason);
        });
    }, static function (): void {
        closeStep('FAIL', 'gateway auth_ok 超时');
    });
    $social->connect();
}

/**
 * Map 直连装配（收件箱并入 clients[UID]；auth_ok 即 ready）。
 * The Map direct-connect assembly (the inbox merges into clients[UID]; auth_ok means ready).
 */
function connectMap(string $token, callable $onReady, callable $onFail): void
{
    $map = new AsyncTcpConnection($GLOBALS['social']['authMapWs'] ?? 'ws://127.0.0.1:18081');
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
            $GLOBALS['entityIds'][UID] = $authOk['payload']['id'] ?? '';
            $onReady();
        }
    };
    $map->onError = static function () use ($onFail): void {
        $onFail('Map 连接错误');
    };
    $map->connect();
}

/**
 * drain 当前频道（gm:exec → gm:result ok）——步骤 1 的主断言，也是步骤 3 换频道的前置。
 * Drains the current channel (gm:exec -> gm:result ok) — step 1's main assertion and also step 3's prerequisite
 * for switching channels.
 */
function drainCurrent(callable $onDrained, callable $onFail): void
{
    sendMap(UID, 'gm:exec', ['command' => 'drain']);
    waitMapFrame(UID, 'gm:result', null, 10.0, static function (array $f) use ($onDrained, $onFail): void {
        if (($f['payload']['code'] ?? null) !== 'ok') {
            $onFail('gm:result ' . json_encode($f['payload'] ?? [], JSON_UNESCAPED_UNICODE));

            return;
        }
        $onDrained();
    }, static function () use ($onFail): void {
        $onFail('gm:result 超时');
    });
}

/**
 * 验收项 1：drain 当前频道（gm:exec → status=draining）。
 * Item 1: drain the current channel (gm:exec -> status=draining).
 */
function step1DrainCurrent(): void
{
    drainCurrent(static function (): void {
        closeStep('PASS', '当前频道已 drain（status=draining）');
    }, static function (string $reason): void {
        closeStep('FAIL', $reason);
    });
}

/**
 * 换连编排：map:enter → map:entered（记新频道）→ 断旧连 → 新地址 auth → auth_ok → onReady。
 * The reconnect choreography: map:enter -> map:entered (records the new channel) -> close the old connection ->
 * auth on the new address -> auth_ok -> onReady.
 */
function migrateWithinMap(callable $onReady, callable $onFail): void
{
    sendSocial('map:enter', ['mapId' => 'map-1']);
    waitFrame($GLOBALS['social']['inbox'], 'map:entered', null, 15.0, static function (array $f) use ($onReady, $onFail): void {
        $token = $f['payload']['token'] ?? null;
        $mapInfo = $f['payload']['map'] ?? null;
        if (!is_string($token) || !is_array($mapInfo) || !is_string($mapInfo['wsAddress'] ?? null) || !is_string($mapInfo['channelId'] ?? null)) {
            $onFail('map:entered 负载不完整');

            return;
        }
        $GLOBALS['currentChannel'] = (string) $mapInfo['channelId'];
        $conn = $GLOBALS['clients'][UID]['conn'] ?? null;
        if ($conn instanceof AsyncTcpConnection) {
            $conn->close();
        }
        verifyTimer(0.6, static function () use ($token, $mapInfo, $onReady, $onFail): void {
            $GLOBALS['social']['authMapWs'] = (string) $mapInfo['wsAddress'];
            connectMap($token, $onReady, $onFail);
        });
    }, static function () use ($onFail): void {
        $onFail('map:entered 超时');
    });
}

/**
 * 验收项 2：缩容路由——drained 频道被目录过滤，重入落到另一频道。
 * Item 2: the scale-in routing — the drained channel is filtered by the directory and the re-entry lands elsewhere.
 */
function step2ReroutedAfterDrain(): void
{
    $drainedChannel = (string) $GLOBALS['currentChannel'];
    migrateWithinMap(static function () use ($drainedChannel): void {
        check($GLOBALS['currentChannel'] !== $drainedChannel, sprintf('重入落点 %s ≠ drained %s（目录过滤生效）', $GLOBALS['currentChannel'], $drainedChannel));
        // map:join 更新会话频道（下一步 drain 的对象）
        // map:join updates the session channel (the next step's drain target).
        sendSocial('map:join', ['mapId' => 'map-1', 'channelId' => $GLOBALS['currentChannel']]);
        closeStep('PASS', sprintf('缩容路由生效（%s → %s）', $drainedChannel, $GLOBALS['currentChannel']));
    }, static function (string $reason): void {
        closeStep('FAIL', $reason);
    });
}

/**
 * 验收项 3：动态扩容——shell 拉起 ch-3 worker（registry 自动注册）→ drain 当前频道 →
 * map:enter 必落 ch-3（唯一存活 map-1 频道）。
 * Item 3: the dynamic scale-out — a shell-spawned ch-3 worker (auto-registered in the registry) -> drain the
 * current channel -> map:enter must land on ch-3 (the only live map-1 channel).
 */
function step3ScaleOutDiscovery(): void
{
    $root = dirname(__DIR__, 3);
    $cmd = sprintf(
        'cd %s && NYTHROS_MMORPG=${NYTHROS_MMORPG:-} nohup php packages/demo/bin/run-worker.php --service=map --mapId=map-1 --channelId=ch-3 --port=18089 > /tmp/nythros-ch3.log 2>&1 & echo $!',
        escapeshellarg($root),
    );
    $GLOBALS['scaledPid'] = trim((string) shell_exec($cmd));
    check($GLOBALS['scaledPid'] !== '', 'ch-3 worker 已拉起（pid ' . $GLOBALS['scaledPid'] . '）');

    // registry 注册即时可见（heartbeat 水位 15s 才回收，注册即 discover 可见）；留 2s 启动余量。
    // **必须先 drain 当前频道**：同图重入的「会话频道优先」会直接命中原频道（实测踩坑——
    // 不 drain 则扩容实例永不被选中，会话偏好优先于最少在线）。
    // The registry registration is visible immediately (the 15s heartbeat watermark only reclaims); leave a 2s
    // startup margin. **The current channel must be drained first**: the same-map re-entry's "session-channel
    // preference" would hit the original channel directly (a measured pitfall — without draining, the scaled-out
    // instance is never picked because session preference outranks least-loaded).
    verifyTimer(2.0, static function (): void {
        $drainedChannel = (string) $GLOBALS['currentChannel'];
        drainCurrent(static function () use ($drainedChannel): void {
            migrateWithinMap(static function () use ($drainedChannel): void {
                check($GLOBALS['currentChannel'] === 'ch-3', sprintf('扩容实例被目录发现（落点 %s == ch-3）', $GLOBALS['currentChannel']));
                closeStep('PASS', sprintf('动态扩容生效（%s drained → ch-3 接管）', $drainedChannel));
            }, static function (string $reason): void {
                closeStep('FAIL', $reason);
            });
        }, static function (string $reason): void {
            closeStep('FAIL', $reason);
        });
    });
}

// 验收步骤注册表（顺序执行；每步独立超时）。
// The acceptance step registry (sequential execution; per-step timeout).
bootVerifyGlobals([
    ['0. 登录（网关 + Map 直连）', 'step0Login', 40.0],
    ['1. drain 当前频道（gm:exec → status=draining）', 'step1DrainCurrent', 20.0],
    ['2. 缩容路由（重入避开 drained 频道）', 'step2ReroutedAfterDrain', 40.0],
    ['3. 动态扩容（ch-3 worker 直启 → 目录发现接管）', 'step3ScaleOutDiscovery', 60.0],
]);

$worker = new Worker();
$worker->onWorkerStart = static function (): void {
    echo "[verify] P16 动态扩缩容端到端验收启动\n";
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

register_shutdown_function(static function (): void {
    // ch-3 worker 收尾清理（scale-in 的物理摘除演示）
    // The ch-3 worker cleanup (the scale-in's physical removal demo).
    $pid = $GLOBALS['scaledPid'] ?? null;
    if (is_string($pid) && $pid !== '' && ctype_digit($pid)) {
        @shell_exec('kill ' . $pid . ' 2>/dev/null');
    }
});

Worker::runAll();
