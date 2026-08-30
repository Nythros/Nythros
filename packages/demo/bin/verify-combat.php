<?php

declare(strict_types=1);

// 定位：packages/demo/bin/verify-combat.php — 阶段 5 战斗层端到端验收一体化脚本（组⑤，ADR-017 §8）。
// 登录链路（ADR-021 自研单栈）：10 个客户端（uid 1001-1010）连 gateway 18285 完整握手
// （auth{username,password,mapId}）拿多 scope token 与三地址（map.wsAddress + endpoints.chat/team），
// 随后直连 Map（consume('map') 进图）并分别连 chat/team 地址发带 token 的 auth 帧（handleTokenAuth 消费
// 各自 scope）→ 期待 auth_ok；战斗断言全部走 Map 直连，覆盖 ADR-017 §8.7 战斗消息协议表的 8 项战斗门禁：
// Located at: packages/demo/bin/verify-combat.php — the phase-5 combat-tier end-to-end acceptance script (group ⑤, ADR-017 §8).
// Login chain (ADR-021 self-built single stack): 10 clients (uids 1001-1010) connect gateway 18285 with the full
// handshake (auth{username,password,mapId}) and receive a multi-scope token plus three addresses (map.wsAddress +
// endpoints.chat/team); they then connect the Map directly (consume('map') mounts them into the world) and each of the
// chat/team addresses with a token-carrying auth frame (handleTokenAuth consuming that role's scope), expecting auth_ok;
// all combat assertions ride the direct Map connection, covering the 8 combat gates of the ADR-017 §8.7 table:
//   1 怪物生成（monster:spawned 带 typeId + 视野内 entity_enter）
//   2 玩家攻击怪物（attack → combat:hit{attackerId,targetId,damage,hp} 视野广播）
//   3 怪物死亡（多玩家集火 → entity_dead，怪物 Actor 自清理，后续 attack 该 id 得 combat:error）
//   4 掉落生成（drop:spawned + 掉落物 entity_enter 附 itemId）
//   5 拾取（pickup → drop:removed（视野）+ item:added（定向拾取者））
//   6 技能（skill:cast → skill:cast 广播 + 伤害帧 combat:hit）
//   7 失败回执（无效目标/距离/冷却 → combat:error{code,message}，连接不断；对尸体验证在第 3 项）
//   8 持久化（拾取后背包经 ArchivePipeline 落库，观察侧读断言）
//   9 移动反作弊（NYTHROS_ANTICHEAT=1 时超速 move → error 403 overspeed 且连接不断；未启用 SKIP）
//   9 movement anti-cheat (with NYTHROS_ANTICHEAT=1 an overspeed move yields error 403 overspeed and the
//     connection stays open; SKIP when unset)
// Located at: packages/demo/bin/verify-combat.php — the phase-5 combat-tier end-to-end acceptance script (group ⑤, ADR-017 §8).
// 10 clients (uids 1001-1010) log in through gateway 18285's full handshake, then connect the map-1#ch-1 channel (18081)
// directly, covering the 8 combat gates of the ADR-017 §8.7 combat message-protocol table:
//   1 monster spawn (monster:spawned with typeId + in-view entity_enter)
//   2 player attack (attack → vision-broadcast combat:hit{attackerId,targetId,damage,hp})
//   3 monster death (multi-player focus fire → entity_dead, monster Actor self-cleanup, a later attack on that id yields combat:error)
//   4 drop spawn (drop:spawned + the drop's entity_enter carrying itemId)
//   5 pickup (pickup → drop:removed (vision) + item:added (directed to the picker))
//   6 skill (skill:cast → skill:cast broadcast + a combat:hit damage frame)
//   7 failure receipts (invalid target / out of range / cooldown → combat:error{code,message}, the connection stays open; corpse attack is covered by item 3)
//   8 persistence (post-pickup inventory archived via ArchivePipeline, asserted by side-read)
//
// 验收口径说明（与 ADR-017 §8.7 的已知产品行为对齐）：
// Acceptance-note (aligned with the known product behaviors of ADR-017 §8.7):
// - entity_enter（门禁 1/4）：spawnMonster / spawnDrops 只在 entered 非空时补发 spawn 瞬间的 entered diff
//   （entered 为空 = spawn 时视野内无旧邻居，不补发，出生通知由 spawned 帧承担），故验收走「跨格进入视野」
//   路径——客户端移出再移回，World::update 发布 enter 信封 → handleAoiVisibility 广播 entity_enter
//   （掉落物附 itemId），该路径真实可达。
// - entity_enter (gates 1/4): spawnMonster / spawnDrops back-fill the spawn-instant entered diff only when entered
//   is non-empty (an empty entered = no pre-existing neighbors at spawn, so nothing is back-filled and the spawned
//   frame carries the birth notice); acceptance thus uses the cross-cell entry path — the client moves out and back,
//   World::update publishes the enter envelope and handleAoiVisibility broadcasts entity_enter (with itemId for
//   drops); this path is genuinely reachable.
// - 怪物出生（门禁 1）：初始怪在 onWorkerStart 内出生（服务器就绪后），出生广播对出生时已在线的客户端可达；
//   验收按宽容时序——monster:spawned 或 entity_enter（1s 视野快照重同步/跨格进入）任一先到即确立可见
//   （ADR-017 §8.7：spawned 是出生事件、enter 是可见事件，信息等价）。
// - Monster birth (gate 1): the initial monsters spawn inside onWorkerStart (after the server is ready), so the
//   birth broadcast is receivable by clients already online; acceptance uses tolerant timing — monster:spawned or an
//   entity_enter (1s vision-snapshot resync / cross-cell entry), whichever arrives first, establishes visibility
//   (ADR-017 §8.7: spawned is the birth event, enter the visibility event — informationally equivalent).
// - 持久化（门禁 8）：拾取 → handlePickup 调 ArchivePipeline.markDirty（真实链路）→ 落库到 MySQL
//   （MySqlStorage 写 nythros_archive 表，collection='players'、id=uid）；侧读 SELECT data 断言 inventory。
// - persistence (gate 8): pickup → handlePickup calls ArchivePipeline.markDirty (the real chain) → archived to MySQL
//   (MySqlStorage writes the nythros_archive table with collection='players', id=uid); the side-read SELECT data
//   asserts the inventory.
//
// 前置（ADR-015 §4.1 启动铁序）：Redis 127.0.0.1:6379 可用；MySQL 127.0.0.1:3306（nythros 库）可供归档侧读
// （连接参数经 NYTHROS_MYSQL_HOST/PORT/USER/PASS/DB 环境变量覆盖，缺省同 deploy.yaml）。
// 自研单栈经 php bin/server start 启动（deploy.yaml：gateway 18285 / chat 18286 / team 18287 + 地图组；
// chat/team 对外地址由 bin/server 按 deploy.yaml 注入，auth_ok.endpoints 据此下发）；
// 账号表 1001-1010 经 NYTHROS_ACCOUNTS 注入，密码默认 secret。
// 重要：每轮验收前必须重启 Map——初始怪物只在 onWorkerStart 出生一次，本验收会击杀全部怪物
// （集火 monster-1/2），同实例二次运行会因无怪而大面积 FAIL（预期行为，非缺陷）。
// Important: restart the Map before every acceptance run — the initial monsters spawn exactly once in
// onWorkerStart, and this run kills them all; re-running against the same instance fails broadly because no
// monsters are left (expected, not a defect).
// 验收完成后请停止上述服务。
// Prerequisites (ADR-015 §4.1 boot order): Redis on 127.0.0.1:6379; MySQL on 127.0.0.1:3306 (nythros DB) ready for
// the archive side-read (connection parameters overridable via NYTHROS_MYSQL_HOST/PORT/USER/PASS/DB, defaults
// matching deploy.yaml). The self-built single stack boots via php bin/server start (deploy.yaml: gateway 18285 /
// chat 18286 / team 18287 plus the map group; the chat/team public addresses are injected by bin/server from
// deploy.yaml and handed out via auth_ok.endpoints); the 1001-1010 account table is injected via NYTHROS_ACCOUNTS,
// password secret by default). Stop the services afterwards.
//
// 输出契约：每项一行 [verify] [PASS|FAIL|SKIP]；末行 RESULT 汇总（PASS=… FAIL=… SKIP=…）。
// SKIP = 验收口径因环境缺口无法执行（明细见输出），不计入 FAIL。
// Output contract: one line per item [verify] [PASS|FAIL|SKIP]; a final RESULT summary line (PASS=… FAIL=… SKIP=…).
// SKIP marks acceptance criteria that cannot run due to environment gaps (details in the output); SKIP never counts as FAIL.

require __DIR__ . '/../../../vendor/autoload.php';
require __DIR__ . '/lib/map-codec.php';
require __DIR__ . '/lib/verify-framework.php';

use Workerman\Connection\AsyncTcpConnection;
use Workerman\Protocols\Websocket;
use Workerman\Timer;
use Workerman\Worker;

// 服务地址：社交 gateway 登录入口 18285；Map 直连 18081（map-1#ch-1）；chat/team 地址经 auth_ok.endpoints 动态获取。
// Service addresses: the social gateway login entry is 18285; Map direct is 18081 (map-1#ch-1); chat/team addresses
// come dynamically from auth_ok.endpoints.
const GW_WS = 'ws://127.0.0.1:18285';
const MAP_WS = 'ws://127.0.0.1:18081'; // map-1#ch-1（战斗直连验收目标） map-1#ch-1 (the combat-direct acceptance target)

// 静态账号（与 run-worker.php 装配一致：NYTHROS_ACCOUNTS 声明 `uid=password` 对，缺省 1001-1010 密码 secret）。
// Static accounts (consistent with run-worker.php: NYTHROS_ACCOUNTS declares `uid=password` pairs, default 1001-1010 with password secret).

/**
 * 按 uid 从 NYTHROS_ACCOUNTS 提取密码（与 run-worker.php 的账号表装配一致：`uid=password` 对逗号分隔）；
 * 未声明或 uid 缺失时缺省 'secret'（与装配缺省一致）。
 * Resolves a uid's password from NYTHROS_ACCOUNTS (consistent with run-worker.php's account-table assembly:
 * comma-separated `uid=password` pairs); defaults to 'secret' when unset or the uid is absent (matching the assembly default).
 */
function accountPassword(string $uid): string
{
    foreach (explode(',', getenv('NYTHROS_ACCOUNTS') ?: '1001=secret,1002=secret,1003=secret,1004=secret,1005=secret,1006=secret,1007=secret,1008=secret,1009=secret,1010=secret') as $pair) {
        $parts = explode('=', trim($pair), 2);
        if (count($parts) === 2 && $parts[0] === $uid) {
            return $parts[1];
        }
    }

    return 'secret';
}

/** @var list<string> 10 个验收客户端 uid The 10 acceptance client uids. */
$GLOBALS['uids'] = ['1001', '1002', '1003', '1004', '1005', '1006', '1007', '1008', '1009', '1010'];

/**
 * 验收脚本共享状态（步骤队列、断言、结果、客户端/令牌等跨步骤状态）。
 * Shared acceptance state (step queue, assertions, results, cross-step client/token state).
 *
 * @var array<string, mixed>
 */
bootVerifyGlobals([]);

/**
 * 社交客户端注册表：uid => {conn, inbox, authOk}（gateway 登录用）。
 * Social client registry: uid => {conn, inbox, authOk} (gateway login).
 *
 * @var array<string, array<string, mixed>>
 */
$GLOBALS['clients'] = [];

/**
 * Map 战斗客户端注册表：uid => {conn, inbox}（战斗直连）。
 * Map combat client registry: uid => {conn, inbox} (combat direct).
 *
 * @var array<string, array<string, mixed>>
 */
$GLOBALS['mapclients'] = [];

/**
 * 令牌暂存：uid => token（gateway auth_ok 签发）。
 * Token staging: uid => token (issued by the gateway auth_ok).
 *
 * @var array<string, string>
 */
$GLOBALS['tokens'] = [];

/**
 * 实体 id 暂存：uid => entityId（Map auth_ok 返回，形如 1001@0x...）。
 * Entity-id staging: uid => entityId (from the Map auth_ok, e.g. 1001@0x...).
 *
 * @var array<string, string>
 */
$GLOBALS['entityIds'] = [];

/**
 * Map 直连地址暂存：uid => wsAddress（auth_ok.map.wsAddress）。
 * Map wsAddress staging: uid => wsAddress (auth_ok.map.wsAddress).
 *
 * @var array<string, string>
 */
$GLOBALS['mapWs'] = [];

/** @var int 请求 id 序列（requestId 相关性） Request id sequence (requestId correlation). */
$GLOBALS['reqSeq'] = 0;

/** @var array<string, mixed> 战斗暂存（dropId/itemId 等跨步骤状态） Combat staging (dropId/itemId cross-step state). */
$GLOBALS['combat'] = [
    'dropId' => '',
    'itemId' => '',
];

/**
 * 构造 JSON 协议帧（type/requestId/timestamp/payload，与 JsonSerializer 编码一致）。
 * Builds a JSON protocol frame (type/requestId/timestamp/payload, matching the JsonSerializer encoding).
 */
function frame(string $type, array $payload, ?string $requestId = null): string
{
    return json_encode([
        'type' => $type,
        'requestId' => $requestId,
        'timestamp' => microtime(true),
        'payload' => $payload,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}

/**
 * 解码协议帧；非字符串或非 JSON 对象返回 null。
 * Decodes a protocol frame; returns null for non-strings or non-JSON-objects.
 *
 * @return ?array<string, mixed> 解码后的帧 Decoded frame.
 */
function decodeFrame(mixed $data): ?array
{
    if (!is_string($data)) {
        return null;
    }

    $decoded = json_decode($data, true);

    return is_array($decoded) ? $decoded : null;
}

/**
 * Redis 单连接（脚本单进程，无 fork 共享问题）。
 * A single Redis connection (the script is one process — no fork-sharing issue).
 */
function vRedis(): \Redis
{
    static $redis = null;
    if ($redis === null) {
        $redis = new \Redis();
        if ($redis->connect('127.0.0.1', 6379, 1.0) !== true) {
            fwrite(STDERR, "[verify] fatal: 无法连接 Redis 127.0.0.1:6379\n");
            exit(1);
        }
    }

    return $redis;
}

/**
 * MySQL 侧读连接（门禁 8 落库断言用）：参数经 NYTHROS_MYSQL_HOST/PORT/USER/PASS/DB 环境变量覆盖，
 * 缺省与 deploy.yaml / run-worker 一致（127.0.0.1 / 3306 / root / 空密码 / nythros）。
 * MySQL side-read connection (used by gate-8's persistence assertion): overridable via the
 * NYTHROS_MYSQL_HOST/PORT/USER/PASS/DB env vars, defaulting to the deploy.yaml / run-worker values
 * (127.0.0.1 / 3306 / root / empty password / nythros).
 */
function vMysql(): \PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $host = getenv('NYTHROS_MYSQL_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('NYTHROS_MYSQL_PORT') ?: '3306');
        $user = getenv('NYTHROS_MYSQL_USER') ?: 'root';
        $pass = getenv('NYTHROS_MYSQL_PASS') ?: '';
        $db = getenv('NYTHROS_MYSQL_DB') ?: 'nythros';
        try {
            $pdo = new \PDO(
                sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $db),
                $user,
                $pass,
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION],
            );
        } catch (\Throwable $e) {
            fwrite(STDERR, sprintf("[verify] fatal: 无法连接 MySQL %s:%d/%s: %s\n", $host, $port, $db, $e->getMessage()));
            exit(1);
        }
    }

    return $pdo;
}

/**
 * 串行执行操作队列：每个 op 接收 next 回调，完成时调用以推进。
 * Runs an operation queue serially: each op receives the next callback and calls it when done.
 *
 * @param list<callable(callable): void> $ops 操作序列 Operation sequence.
 * @param callable $done 全部完成回调 All-done callback.
 */
function seqOps(array $ops, callable $done): void
{
    $idx = 0;
    $next = null;
    $next = function () use (&$idx, &$next, $ops, $done): void {
        if ($idx >= count($ops)) {
            $done();

            return;
        }
        $op = $ops[$idx];
        $idx++;
        $op($next);
    };
    $next();
}

/**
 * 收件箱是否含匹配帧（只读，不移除）。
 * Whether the inbox contains a matching frame (read-only, no removal).
 *
 * @param array<int, array<string, mixed>> $inbox 收件箱 Inbox.
 * @param callable(array<string, mixed>): bool|null $pred 附加谓词 Additional predicate.
 */
function inboxHas(array $inbox, string $type, ?callable $pred = null): bool
{
    foreach ($inbox as $f) {
        if (($f['type'] ?? null) !== $type) {
            continue;
        }
        if ($pred === null || $pred($f)) {
            return true;
        }
    }

    return false;
}

/**
 * 轮询等待 Redis 谓词成立（0.3s 粒度）；命中回调、超时回调失败。
 * Polls until a Redis predicate holds (0.3s granularity); the hit callback fires, the miss callback fires on timeout.
 */
function pollRedis(callable $pred, float $timeout, callable $onHit, callable $onFail): void
{
    $t0 = microtime(true);
    $scan = null;
    $scan = function () use (&$scan, $pred, $timeout, $onHit, $onFail, $t0): void {
        if ($pred()) {
            $onHit();

            return;
        }
        if (microtime(true) - $t0 >= $timeout) {
            $onFail();

            return;
        }
        verifyTimer(0.3, $scan, [], false);
    };
    $scan();
}

/**
 * 建立/重建社交连接：连 gateway 18285 完整握手 auth{username,password,mapId}，auth_ok 存入注册表并回调；
 * 后续所有帧（含通知）追加进收件箱。重复调用 = 重连（旧连接先摘除 handler 再关闭，防脏帧串入新收件箱）。
 * Opens / reopens a social connection: connect gateway 18285 with the full handshake auth{username,password,mapId};
 * auth_ok is stored and the callback fires; every later frame (notifications included) is appended to the inbox.
 * Calling again = reconnect (the old connection's handlers are detached before close, so no stale frames leak into the new inbox).
 *
 * @param callable(bool, array<string, mixed>): void $onAuth auth 结果回调（ok + auth_ok/auth_failed/error 帧） Auth result callback (ok + the auth_ok/auth_failed/error frame).
 */
function openSocial(string $uid, string $mapId, callable $onAuth): void
{
    $state = &$GLOBALS['clients'][$uid];

    $old = $state['conn'] ?? null;
    if ($old instanceof AsyncTcpConnection) {
        $old->onMessage = static fn (): null => null;
        $old->onClose = static fn (): null => null;
        $old->close();
    }

    $state['uid'] = $uid;
    $state['inbox'] = [];
    $state['authOk'] = null;
    $settled = false;

    $conn = new AsyncTcpConnection(GW_WS);
    $state['conn'] = $conn;
    $conn->onConnect = static function (AsyncTcpConnection $c) use ($uid, $mapId): void {
        $c->send(frame('auth', ['username' => $uid, 'password' => accountPassword($uid), 'mapId' => $mapId], 'auth:' . $uid));
    };
    $conn->onMessage = static function (AsyncTcpConnection $c, mixed $data) use (&$state, &$settled, $onAuth): void {
        $decoded = decodeFrame($data);
        if ($decoded === null) {
            return;
        }
        $state['inbox'][] = $decoded;
        $type = $decoded['type'] ?? null;
        if (!$settled && in_array($type, ['auth_ok', 'auth_failed', 'error'], true)) {
            $settled = true;
            $state['authOk'] = $type === 'auth_ok' ? $decoded : null;
            $onAuth($type === 'auth_ok', $decoded);
        }
    };
    $conn->onClose = static function () use (&$settled, $onAuth): void {
        if (!$settled) {
            $settled = true;
            $onAuth(false, ['type' => 'error', 'requestId' => null, 'payload' => ['code' => -1, 'message' => 'gateway 连接关闭']]);
        }
    };
    $conn->connect();
}

/**
 * 建立 Map 战斗直连：连 Map → auth{token}，auth_ok/auth_failed/error 回调；后续帧追加进收件箱。
 * Opens a Map combat connection: connect the Map → auth{token}; auth_ok/auth_failed/error fire the callback; later frames append to the inbox.
 *
 * @param callable(bool, array<string, mixed>): void $onAuth auth 结果回调 Auth result callback.
 */
function openMap(string $uid, string $ws, string $token, callable $onAuth): void
{
    $state = &$GLOBALS['mapclients'][$uid];
    $state['uid'] = $uid;
    $state['inbox'] = [];
    $settled = false;

    $conn = new AsyncTcpConnection($ws);
    // Map 频道二进制：出站包须以二进制 WebSocket 帧发送（BINARY opcode）
    // Map channel is binary: outbound packets must go out as binary WebSocket frames (BINARY opcode)
    $conn->websocketType = Websocket::BINARY_TYPE_ARRAYBUFFER;
    $state['conn'] = $conn;
    $conn->onConnect = static function (AsyncTcpConnection $c) use ($token): void {
        $c->send(frameMap('auth', ['token' => $token], 'map-auth:' . $token));
    };
    $conn->onMessage = static function (AsyncTcpConnection $c, mixed $data) use (&$state, &$settled, $onAuth): void {
        foreach (decodeMapFrames((string) $data) as $decoded) {
            $state['inbox'][] = $decoded;
            $type = $decoded['type'] ?? null;
            if (!$settled && in_array($type, ['auth_ok', 'auth_failed', 'error'], true)) {
                $settled = true;
                $onAuth($type === 'auth_ok', $decoded);
            }
        }
    };
    $conn->onClose = static function () use (&$settled, $onAuth): void {
        if (!$settled) {
            $settled = true;
            $onAuth(false, ['type' => 'error', 'requestId' => null, 'payload' => ['code' => -1, 'message' => 'map 连接关闭']]);
        }
    };
    $conn->connect();
}

/**
 * 一次性社交 token 认证（ADR-021 §3.2 多 scope 兑现）：新连 chat/team 地址 → auth{token} →
 * handleTokenAuth 消费该角色 scope → auth_ok/auth_failed/error 后关闭并回调。
 * One-shot social token auth (fulfilling ADR-021 §3.2's multi-scope promise): a fresh connection to a chat/team
 * address → auth{token} → handleTokenAuth consumes that role's scope → close and callback on auth_ok/auth_failed/error.
 *
 * @param callable(bool, array<string, mixed>): void $onResult 结果回调 Result callback.
 */
function socialTokenAuthOnce(string $ws, string $token, callable $onResult): void
{
    $conn = new AsyncTcpConnection($ws);
    $settled = false;

    $conn->onConnect = static function (AsyncTcpConnection $c) use ($token): void {
        $c->send(frame('auth', ['token' => $token], 'token-auth:' . $token));
    };
    $conn->onMessage = static function (AsyncTcpConnection $c, mixed $data) use ($onResult, &$settled): void {
        if ($settled) {
            return;
        }
        $decoded = decodeFrame($data);
        if ($decoded === null) {
            return;
        }
        $type = $decoded['type'] ?? null;
        if (in_array($type, ['auth_ok', 'auth_failed', 'error'], true)) {
            $settled = true;
            $c->close();
            $onResult($type === 'auth_ok', $decoded);
        }
    };
    $conn->onClose = static function () use ($onResult, &$settled): void {
        if (!$settled) {
            $settled = true;
            $onResult(false, ['type' => 'error', 'requestId' => null, 'payload' => ['code' => -1, 'message' => 'social 连接关闭']]);
        }
    };

    $conn->connect();
}

/**
 * 验收项 0（前置）：10 客户端经 gateway 18285 完整握手登录（auth_ok 拿多 scope token + map.wsAddress +
 * endpoints.chat/team），随后三服务 token 登录——直连 Map（consume('map') 拿 entityId）并分别连 chat/team
 * 地址发带 token 的 auth 帧（handleTokenAuth 消费各自 scope → auth_ok）。全部就位后 PASS。
 * Item 0 (prerequisite): 10 clients log in via gateway 18285's full handshake (auth_ok yields the multi-scope token,
 * map.wsAddress and endpoints.chat/team), then token-login all three services — the Map directly (consume('map')
 * yields the entityId) plus each chat/team address with a token-carrying auth frame (handleTokenAuth consuming that
 * role's scope → auth_ok). PASS once all are in place.
 */
function step0Login(): void
{
    $ops = [];
    foreach ($GLOBALS['uids'] as $uid) {
        $ops[] = static function (callable $next) use ($uid): void {
            openSocial($uid, 'map-1', static function (bool $ok, array $f) use ($uid, $next): void {
                if (!$ok) {
                    check(false, $uid . ' gateway 登录失败: ' . json_encode($f['payload'] ?? [], JSON_UNESCAPED_UNICODE));
                    $next();

                    return;
                }
                $p = $f['payload'] ?? [];
                $token = $p['token'] ?? null;
                $ws = $p['map']['wsAddress'] ?? null;
                $chatWs = $p['endpoints']['chat']['wsAddress'] ?? null;
                $teamWs = $p['endpoints']['team']['wsAddress'] ?? null;
                check(is_string($token) && $token !== '', $uid . ' auth_ok.token 有效');
                check(is_string($ws) && $ws !== '', $uid . ' auth_ok.map.wsAddress 有效');
                check(is_string($chatWs) && $chatWs !== '', $uid . ' auth_ok.endpoints.chat.wsAddress 有效');
                check(is_string($teamWs) && $teamWs !== '', $uid . ' auth_ok.endpoints.team.wsAddress 有效');
                if (!is_string($token) || !is_string($ws)) {
                    $next();

                    return;
                }
                $GLOBALS['tokens'][$uid] = $token;
                $GLOBALS['mapWs'][$uid] = $ws;

                // 直连 Map：consume('map') → auth_ok{uid, id}
                openMap($uid, $ws, $token, static function (bool $okMap, array $fMap) use ($uid, $chatWs, $teamWs, $next): void {
                    if (!$okMap) {
                        check(false, $uid . ' Map 直连失败: ' . json_encode($fMap['payload'] ?? [], JSON_UNESCAPED_UNICODE));
                        $next();

                        return;
                    }
                    $entityId = $fMap['payload']['id'] ?? null;
                    check(is_string($entityId) && str_starts_with($entityId, $uid . '@'), $uid . ' Map auth_ok.id 为 ' . $uid . '@ 前缀 entityId');
                    if (is_string($entityId)) {
                        $GLOBALS['entityIds'][$uid] = $entityId;
                    }

                    // 多 scope 兑现：分别连 chat/team 地址发带 token 的 auth 帧 → handleTokenAuth 各自消费 scope
                    // Multi-scope fulfillment: connect each of the chat/team addresses with a token-carrying auth frame
                    // → handleTokenAuth consumes each role's own scope
                    $scopeOps = [];
                    foreach ([['chat', $chatWs], ['team', $teamWs]] as [$role, $roleWs]) {
                        $scopeOps[] = static function (callable $next) use ($uid, $role, $roleWs): void {
                            $token = (string) ($GLOBALS['tokens'][$uid] ?? '');
                            if (!is_string($roleWs) || $roleWs === '' || $token === '') {
                                check(false, $uid . ' 缺少 ' . $role . ' 地址或 token，跳过 token 登录');
                                $next();

                                return;
                            }
                            socialTokenAuthOnce($roleWs, $token, static function (bool $okRole, array $fRole) use ($uid, $role, $next): void {
                                check($okRole, $uid . ' ' . $role . ' 角色 token 登录（consume(\'' . $role . '\')）→ auth_ok{uid}');
                                if ($okRole) {
                                    check(($fRole['payload']['uid'] ?? null) === $uid, $uid . ' ' . $role . ' auth_ok.uid == ' . $uid);
                                } else {
                                    check(false, $uid . ' ' . $role . ' token 登录失败: ' . json_encode($fRole['payload'] ?? [], JSON_UNESCAPED_UNICODE));
                                }
                                $next();
                            });
                        };
                    }
                    seqOps($scopeOps, $next);
                });
            });
        };
    }

    seqOps($ops, static function (): void {
        // 前置就位判定：任一客户端未完成 Map 直连（登录/直连失败）即中止——后续验收项全部依赖
        // 10 客户端的 Map 收件箱与连接，缺位会在深层触发空收件箱错误，掩盖真实失败面。
        // Readiness gate: any client without its Map connection aborts the run — every later item depends on all
        // 10 clients' Map inboxes/connections, and a missing one would surface as a deep empty-inbox error that
        // masks the real failure.
        $notReady = array_values(array_filter(
            $GLOBALS['uids'],
            static fn (string $uid): bool => !isset($GLOBALS['mapclients'][$uid]['conn']),
        ));
        if ($notReady !== []) {
            $GLOBALS['verify']['abort'] = true;
            closeStep('FAIL', '前置未就位（' . implode(',', $notReady) . ' 未完成三服务 token 直连），中止后续验收');

            return;
        }
        closeStep('PASS', '10 客户端登录 + Map/chat/team 三服务 token 直连就位（1001-1010）');
    });
}

/**
 * 验收项 1：怪物生成——monster:spawned（带 typeId）或 entity_enter（1s 视野快照/跨格进入）任一先到
 * （宽容时序：客户端登录与 onWorkerStart 出怪的真实先后不确定；快照重同步在任何时序下都会把怪物
 * 的 entity_enter 补发给已认证客户端）；随后 entity_enter 走「跨格进入视野」路径验证
 * （spawnMonster 在 entered 非空时补发、entered 为空不补发——本验收场景 spawn 时视野内无旧邻居，
 * 故跨格进入的 entity_enter 由 handleAoiVisibility 真实可达）。
 * 单一等待器 + 单一 closeStep：绝不并行起多个等待器（并行会导致残留 Timer 在后续步骤误报）。
 * Item 1: monster spawn — either monster:spawned (with typeId) or an entity_enter (1s vision snapshot /
 * cross-cell entry) arrives first under the real (unknown) login-vs-spawn ordering; the snapshot resync delivers
 * the monster's entity_enter to authenticated clients under any ordering. Then entity_enter is verified via the
 * cross-cell entry path (spawnMonster back-fills a non-empty entered and skips an empty one — here the spawn has
 * no pre-existing neighbors in view, so the cross-cell entity_enter is genuinely reachable through
 * handleAoiVisibility). A single waiter and a single closeStep: never run parallel waiters (parallel waiters leave
 * stale timers that misreport later steps).
 */
function step1MonsterSpawn(): void
{
    // 第一阶段：等 monster:spawned 或 monster-1 的 entity_enter，任一先到即确立「怪物可见」（12s 窗口，覆盖 1s 快照周期）。
    // Phase 1: wait for either monster:spawned or monster-1's entity_enter, whichever arrives first, establishing
    // "the monster is visible" (12s window covers the 1s snapshot period).
    $phase1Done = false;
    // 1001 的 Map 收件箱未就位（前置失败）时直接 FAIL，避免对 null 引用触发 TypeError。
    // Fail fast when 1001's Map inbox is missing (prerequisite failure) instead of a TypeError on a null reference.
    if (!isset($GLOBALS['mapclients']['1001']['inbox']) || !is_array($GLOBALS['mapclients']['1001']['inbox'])) {
        closeStep('FAIL', '1001 Map 直连未就位（前置失败），无法感知怪物');

        return;
    }
    $inbox1001 = &$GLOBALS['mapclients']['1001']['inbox'];
    waitFrame(
        $inbox1001,
        null, // 无类型过滤：spawned 与 entity_enter 均可命中 No type filter: either spawned or entity_enter may match
        static fn (array $f): bool => (
            // spawned：验证 id/typeId/position；enter：验证 id 为 monster-1
            // spawned: assert id/typeId/position; enter: assert id is monster-1
            ($f['type'] === 'monster:spawned' && ($f['payload']['id'] ?? null) === 'monster-1')
            || ($f['type'] === 'entity_enter' && ($f['payload']['id'] ?? null) === 'monster-1')
        ),
        12.0,
        static function (array $f) use (&$phase1Done): void {
            if ($phase1Done) {
                return;
            }
            $phase1Done = true;
            if ($f['type'] === 'monster:spawned') {
                check(($f['payload']['id'] ?? null) === 'monster-1', 'monster:spawned.id == monster-1');
                check(($f['payload']['typeId'] ?? null) === 'slime', 'monster:spawned.typeId == slime');
                check(is_array($f['payload']['position'] ?? null) && isset($f['payload']['position']['x'], $f['payload']['position']['y']), 'monster:spawned.position 含 x/y');
            } else {
                check(true, 'monster-1 经 entity_enter 快照/跨格进入可感知');
            }

            // 第二阶段：跨格进入视野——1001 移出怪物 cell 再移回 → World::update 发布 enter 信封 → entity_enter。
            // Phase 2: cross-cell entry — 1001 moves out of the monster's cell and back → World::update publishes the
            // enter envelope → entity_enter.
            moveAwayAndBack('1001', static function (): void {
                waitMapFrame('1001', 'entity_enter', static fn (array $f2): bool => ($f2['payload']['id'] ?? null) === 'monster-1', 12.0, static function (): void {
                    check(true, '1001 跨格后收到 monster-1 的 entity_enter（视野进入）');
                    closeStep('PASS', 'monster-1 可感知 + 跨格 entity_enter');
                }, static function (): void {
                    closeStep('FAIL', '跨格后未收到 monster-1 的 entity_enter');
                });
            });
        },
        static function () use (&$phase1Done): void {
            if ($phase1Done) {
                return;
            }
            $phase1Done = true;
            closeStep('FAIL', '12s 内未感知 monster-1（monster:spawned 与 entity_enter 快照/跨格均未收到）');
        },
    );
}

/**
 * 让某 uid 移出出生格（跨 cell）再移回，触发 World::update 的 AOI enter 信封。
 * Moves a uid out of the spawn cell (crossing cells) and back, triggering the AOI enter envelope of World::update.
 */
function moveAwayAndBack(string $uid, callable $done): void
{
    sendMap($uid, 'move', ['dx' => 30, 'dy' => 0], reqId());
    verifyTimer(0.5, static function () use ($uid, $done): void {
        sendMap($uid, 'move', ['dx' => -30, 'dy' => 0], reqId());
        verifyTimer(0.5, $done, [], false);
    }, [], false);
}

/**
 * 验收项 2：玩家攻击怪物——1001 attack → combat:hit 视野广播（1001 与 1002 都收到）。
 * Item 2: player attack — 1001 attacks → combat:hit vision-broadcast (received by both 1001 and 1002).
 */
function step2Attack(): void
{
    sendMap('1001', 'attack', ['targetId' => 'monster-1'], reqId());

    $aPrefix = '1001@';
    waitMapFrame('1001', 'combat:hit', static fn (array $f): bool => str_starts_with((string) ($f['payload']['attackerId'] ?? ''), $aPrefix)
        && ($f['payload']['targetId'] ?? null) === 'monster-1', 8.0, static function (array $f) use ($aPrefix): void {
            $p = $f['payload'] ?? [];
            $damage = $p['damage'] ?? null;
            $hp = $p['hp'] ?? null;
            check(is_int($damage) && $damage >= 8 && $damage <= 12, 'combat:hit.damage ∈ [8,12]（普攻 10 × 80~120%）实际=' . var_export($damage, true));
            check(is_int($hp) && $hp === 100 - $damage, 'combat:hit.hp == 100 - damage（' . var_export($hp, true) . '）');

            waitMapFrame('1002', 'combat:hit', static fn (array $f2): bool => str_starts_with((string) ($f2['payload']['attackerId'] ?? ''), $aPrefix)
                && ($f2['payload']['targetId'] ?? null) === 'monster-1', 8.0, static function () use ($aPrefix): void {
                    check(true, '1002 收到同一 combat:hit（视野广播）');
                    closeStep('PASS', 'attack → combat:hit 视野广播');
                }, static function (): void {
                    closeStep('FAIL', '1002 未收到 combat:hit 视野广播');
                });
        }, static function (): void {
            closeStep('FAIL', '1001 未收到 combat:hit（攻击可能未结算）');
        });
}

/**
 * 验收项 3：怪物死亡——1002/1003/1004 三人小队磨血 + 1001 独占补刀至死 → entity_dead 广播；
 * 怪物 Actor 自清理，后续 attack 该 id 得 combat:error（不再结算伤害）。
 * 击杀归属适配（R3 掉落正式化）：掉落归属绑定最后击杀者（ownerUid），验收项 5 由 1001 拾取——
 * 故残血后切 1001 单独收尾，保证 ownerUid=1001 确定；小队磨血保留多人参与场景且无秒杀风险。
 * Item 3: monster death — the 1002/1003/1004 trio grinds monster-1 low, then 1001 solo-finishes it → entity_dead
 * broadcast; the monster Actor self-cleans, and a later attack on that id yields combat:error.
 * Kill-ownership adaptation (R3 drop formalization): drop ownership binds the last killer (ownerUid) and item 5
 * has 1001 pick up — so below the low-hp threshold only 1001 attacks, keeping ownerUid=1001 deterministic; the
 * grinding trio preserves multi-player participation with no one-shot risk.
 */
function step3MonsterDeath(): void
{
    // 磨血阶段：1002/1003/1004 三人小队每轮各 attack 一次（单轮伤害上限 36 < 100，无秒杀风险）；
    // 从 1001 收到的 combat:hit 流读取最新 hp，hp ≤ 45 时切换 1001 补刀（伤害浮动 8~12/人，
    // 约 2 轮进入补刀窗口）。阈值 45 的归属安全余量（R3 掉落正式化 ownerUid 口径）：帧读取滞后至多
    // 一轮，最坏 45 − 36 = 9 > 0 保证切换时怪物存活；且切换后磨血组立即停手，1001 必为最后击杀者
    // （旧阈值 20 会被磨血组单轮 36 伤害穿过，人头易主 → 1001 拾取 not_owner，实测复现）。
    // Attrition phase: the 1002/1003/1004 trio attacks once per round (per-round damage cap 36 < 100, no one-shot
    // risk); the latest hp is read from 1001's combat:hit stream, switching to 1001's finisher once hp <= 45
    // (8..12 per attacker, ~2 rounds into the finisher window). The 45 threshold's ownership margin (the R3 drop
    // formalization's ownerUid convention): frame reads lag at most one round, worst case 45 - 36 = 9 > 0 keeps the
    // monster alive at the switch; and the trio stops firing from then on, so 1001 is guaranteed the last killer
    // (the old threshold of 20 could be punched through by a single 36-damage round — the kill steals to a grinder
    // and 1001's pickup answers not_owner, reproduced in the field).
    $grinders = ['1002', '1003', '1004'];
    $round = 0;
    $finish = null;
    $fire = null;
    $fire = static function () use (&$fire, &$finish, &$round, $grinders): void {
        if ($round >= 15) {
            closeStep('FAIL', '磨血 15 轮后 monster-1 血量仍未进入补刀窗口');

            return;
        }
        $round++;
        foreach ($grinders as $uid) {
            sendMap($uid, 'attack', ['targetId' => 'monster-1'], reqId());
        }
        waitMapFrame('1001', 'combat:hit', static fn (array $f): bool => ($f['payload']['targetId'] ?? null) === 'monster-1' && is_int($f['payload']['hp'] ?? null), 12.0, static function (array $f) use (&$fire, &$finish): void {
            $hp = $f['payload']['hp'] ?? 100;
            if (is_int($hp) && $hp <= 45 && $hp > 0) {
                $finish();

                return;
            }
            $fire();
        }, $fire);
    };
    // 补刀阶段：仅 1001 循环攻击直至 entity_dead（≤45 血，1~5 击内必死）。
    // Finisher phase: only 1001 keeps attacking until entity_dead (≤45 hp dies within 1..5 hits).
    $finish = static function () use (&$finish): void {
        sendMap('1001', 'attack', ['targetId' => 'monster-1'], reqId());
        waitMapFrame('1001', 'entity_dead', static fn (array $f): bool => ($f['payload']['id'] ?? null) === 'monster-1', 12.0, static function (): void {
            check(true, '集火击杀 monster-1 → 收到 entity_dead（视野广播）');

            // 尸体攻击：Actor 自清理后 attack 该 id 不再结算，定向 combat:error（连接不断）。
            // Corpse attack: after the Actor self-cleanup, attacking that id never settles — a directed combat:error (connection stays open).
            sendMap('1001', 'attack', ['targetId' => 'monster-1'], reqId());
            waitMapFrame('1001', 'combat:error', static fn (array $f): bool => ($f['payload']['code'] ?? null) === 'invalid_target', 8.0, static function (): void {
                check(true, '攻击已死 monster-1 → combat:error invalid_target（Actor 自清理）');
                closeStep('PASS', '集火死亡 + entity_dead + 尸体 attack error');
            }, static function (): void {
                closeStep('FAIL', '攻击尸体未得 combat:error invalid_target');
            });
        }, $finish);
    };
    $fire();
}

/**
 * 验收项 4：掉落生成——怪物死亡掉落 drop:spawned（视野）；掉落物 entity_enter 附 itemId 走「跨格进入」
 * 路径验证（同 spawnMonster，spawnDrops 在 entered 非空时补发、entered 为空不补发）。
 * Item 4: drop spawn — the death drop fires drop:spawned (vision); the drop's entity_enter carrying itemId is
 * verified via the cross-cell entry path (like spawnMonster, spawnDrops back-fills on a non-empty entered and
 * skips on an empty one).
 */
function step4DropSpawn(): void
{
    $hit = null;
    $hit = static function (array $f) use (&$hit): void {
        $p = $f['payload'] ?? [];
        $dropId = $p['dropId'] ?? null;
        $itemId = $p['itemId'] ?? null;
        check(is_string($dropId) && str_starts_with($dropId, 'drop-monster-1-'), 'drop:spawned.dropId 前缀 drop-monster-1（实际=' . var_export($dropId, true) . '）');
        check(in_array($itemId, ['bone', 'potion'], true), 'drop:spawned.itemId ∈ {bone, potion}（实际=' . var_export($itemId, true) . '）');
        check(is_int($p['x'] ?? null) && is_int($p['y'] ?? null), 'drop:spawned 携带 x/y 坐标');
        if (!is_string($dropId)) {
            closeStep('FAIL', 'dropId 缺失');

            return;
        }
        $GLOBALS['combat']['dropId'] = $dropId;
        $GLOBALS['combat']['itemId'] = is_string($itemId) ? $itemId : '';
        $GLOBALS['combat']['dropX'] = is_int($p['x'] ?? null) ? $p['x'] : 0;
        $GLOBALS['combat']['dropY'] = is_int($p['y'] ?? null) ? $p['y'] : 0;

        // 跨格进入视野：1001 移出掉落物 cell 再移回 → entity_enter 附 itemId。
        // Cross-cell entry: 1001 moves out of the drop's cell and back → entity_enter carrying itemId.
        moveAwayAndBack('1001', static function () use ($dropId): void {
            waitMapFrame('1001', 'entity_enter', static fn (array $f2): bool => ($f2['payload']['id'] ?? null) === $dropId, 12.0, static function (array $f2) use ($dropId): void {
                check(($f2['payload']['itemId'] ?? null) === $GLOBALS['combat']['itemId'], '掉落物 entity_enter 附 itemId（' . var_export($f2['payload']['itemId'] ?? null, true) . '）');
                closeStep('PASS', 'drop:spawned + 掉落物跨格 entity_enter 附 itemId');
            }, static function () use ($dropId): void {
                closeStep('FAIL', '跨格后未收到掉落物 ' . $dropId . ' 的 entity_enter');
            });
        });
    };

    waitMapFrame('1001', 'drop:spawned', static fn (array $f): bool => str_starts_with((string) ($f['payload']['dropId'] ?? ''), 'drop-monster-1-'), 10.0, $hit, static function (): void {
        closeStep('FAIL', '未收到 drop:spawned（怪物死亡未掉落？）');
    });
}

/**
 * 验收项 5：拾取——1001 先移动到掉落物坐标旁（掉落位置 = monster-1 巡逻域内的死亡点，随机可达
 * (15,15)——超出 1001 原点九宫格视野时 pickup 会 out_of_range，故先贴近再拾取）→ pickup →
 * drop:removed（视野广播）+ item:added（定向拾取者）；重复拾取已移除掉落得 combat:error。
 * Item 5: pickup — 1001 first moves next to the drop's coordinates (the drop lands where monster-1 died,
 * anywhere in its patrol domain up to (15,15) — beyond 1001's origin 3x3 vision the pickup would answer
 * out_of_range, so close in first) → pickup → drop:removed (vision broadcast) + item:added (directed to the
 * picker); re-picking the removed drop yields combat:error.
 */
function step5Pickup(): void
{
    $dropId = (string) ($GLOBALS['combat']['dropId'] ?? '');
    check($dropId !== '', '前置：有可拾取 dropId');
    if ($dropId === '') {
        closeStep('FAIL', '缺少 dropId');

        return;
    }

    // 贴近掉落物：step4 后 1001 已回到原点 (0,0)，move 为相对位移，直接按掉落坐标走位；
    // 等 0.6s 落位后再 pickup（比照 step7② 的落位等待口径）。1002 同步走位——drop:removed 是
    // 掉落位置的视野广播，1002 留在原点时远离掉落点会收不到。
    // Close in on the drop: after step4 1001 is back at the origin (0,0); move is a relative delta, so walk by
    // the drop's coordinates; wait 0.6s for the move to land before picking up (mirroring step7②'s settle wait).
    // 1002 walks too — drop:removed is a vision broadcast at the drop's location, which 1002 would miss from the origin.
    $dropX = (int) ($GLOBALS['combat']['dropX'] ?? 0);
    $dropY = (int) ($GLOBALS['combat']['dropY'] ?? 0);
    sendMap('1001', 'move', ['dx' => $dropX, 'dy' => $dropY], reqId());
    sendMap('1002', 'move', ['dx' => $dropX, 'dy' => $dropY], reqId());
    verifyTimer(0.6, static function () use ($dropId): void {
        sendMap('1001', 'pickup', ['dropId' => $dropId], reqId());
    }, [], false);

    waitMapFrame('1001', 'item:added', static fn (array $f): bool => ($f['payload']['itemId'] ?? null) === $GLOBALS['combat']['itemId'], 8.0, static function (array $f) use ($dropId): void {
        check(($f['payload']['itemId'] ?? null) === $GLOBALS['combat']['itemId'], '定向拾取者收到 item:added{itemId=' . $GLOBALS['combat']['itemId'] . '}');
        check(is_int($f['payload']['count'] ?? null) && $f['payload']['count'] >= 1, 'item:added.count ≥ 1');

        waitMapFrame('1002', 'drop:removed', static fn (array $f2): bool => ($f2['payload']['dropId'] ?? null) === $dropId, 8.0, static function () use ($dropId): void {
            check(true, '1002 收到 drop:removed（视野广播）');

            // 重复拾取：掉落已移除 → combat:error invalid_target（连接不断）。
            // Re-pickup: the drop is gone → combat:error invalid_target (the connection stays open).
            sendMap('1001', 'pickup', ['dropId' => $dropId], reqId());
            waitMapFrame('1001', 'combat:error', static fn (array $f3): bool => ($f3['payload']['code'] ?? null) === 'invalid_target', 8.0, static function (): void {
                check(true, '重复拾取已移除掉落 → combat:error invalid_target');
                closeStep('PASS', 'pickup → drop:removed + item:added + 重复拾取 error');
            }, static function (): void {
                closeStep('FAIL', '重复拾取未得 combat:error');
            });
        }, static function (): void {
            closeStep('FAIL', '1002 未收到 drop:removed');
        });
    }, static function (): void {
        closeStep('FAIL', '1001 未收到 item:added');
    });
}

/**
 * 验收项 6：技能——1002 skill:cast{fireball, monster-2} → skill:cast 广播 + 技能伤害帧 combat:hit。
 * Item 6: skill — 1002 skill:cast{fireball, monster-2} → skill:cast broadcast + a skill damage frame combat:hit.
 */
function step6Skill(): void
{
    $cPrefix = '1002@';
    sendMap('1002', 'skill:cast', ['skillId' => 'fireball', 'targetId' => 'monster-2'], reqId());

    waitMapFrame('1001', 'skill:cast', static fn (array $f): bool => str_starts_with((string) ($f['payload']['casterId'] ?? ''), $cPrefix)
        && ($f['payload']['skillId'] ?? null) === 'fireball'
        && ($f['payload']['targetId'] ?? null) === 'monster-2', 8.0, static function () use ($cPrefix): void {
            check(true, '1001 收到 skill:cast 广播{casterId=1002@…, skillId=fireball, targetId=monster-2}');

            waitMapFrame('1001', 'combat:hit', static fn (array $f2): bool => str_starts_with((string) ($f2['payload']['attackerId'] ?? ''), $cPrefix)
                && ($f2['payload']['targetId'] ?? null) === 'monster-2', 8.0, static function (array $f2) use ($cPrefix): void {
                    $damage = $f2['payload']['damage'] ?? null;
                    check(is_int($damage) && $damage >= 12 && $damage <= 18, '技能伤害帧 combat:hit.damage ∈ [12,18]（10 × 1.5 倍率 × 80~120%）实际=' . var_export($damage, true));
                    closeStep('PASS', 'skill:cast 广播 + 技能伤害帧');
                }, static function (): void {
                    closeStep('FAIL', '未收到技能 combat:hit 伤害帧');
                });
        }, static function (): void {
            closeStep('FAIL', '未收到 skill:cast 广播');
        });
}

/**
 * 验收项 7：失败回执——无效目标 / 距离 / 冷却 各自定向 combat:error，连接不断；
 * 「对尸体攻击」的 invalid_target 由验收项 3（集火死亡后尸体攻击）覆盖。
 * Item 7: failure receipts — invalid target / out of range / cooldown each yield a directed combat:error
 * while the connection stays open; the corpse-attack invalid_target is covered by item 3 (post-focus-fire corpse attack).
 */
function step7Errors(): void
{
    $ops = [];

    // ① 无效目标：attack 不存在的 id。
    // ① Invalid target: attacking a non-existent id.
    $ops[] = static function (callable $next): void {
        sendMap('1003', 'attack', ['targetId' => 'ghost-nowhere'], reqId());
        waitMapFrame('1003', 'combat:error', static fn (array $f): bool => ($f['payload']['code'] ?? null) === 'invalid_target', 8.0, static function () use ($next): void {
            check(true, '无效目标 → combat:error invalid_target');
            $next();
        }, static function () use ($next): void {
            check(false, '无效目标未得 combat:error');
            $next();
        });
    };

    // ② 距离：1003 移出怪物九宫格后 attack → out_of_range；随后移回原格。
    // ② Out of range: 1003 moves outside the monster's 3x3 neighborhood, attacks → out_of_range; then moves back.
    $ops[] = static function (callable $next): void {
        sendMap('1003', 'move', ['dx' => 100, 'dy' => 100], reqId());
        // 移动无回执；等一帧落位后再 attack（保证九宫格已脱离）。
        // Moves have no receipt; wait a beat for the move to land before attacking (the 3x3 must be cleared).
        verifyTimer(0.6, static function () use ($next): void {
            sendMap('1003', 'attack', ['targetId' => 'monster-2'], reqId());
            waitMapFrame('1003', 'combat:error', static fn (array $f): bool => ($f['payload']['code'] ?? null) === 'out_of_range', 8.0, static function () use ($next): void {
                check(true, '远距离攻击 → combat:error out_of_range');
                sendMap('1003', 'move', ['dx' => -100, 'dy' => -100], reqId());
                $next();
            }, static function () use ($next): void {
                check(false, '远距离攻击未得 out_of_range');
                sendMap('1003', 'move', ['dx' => -100, 'dy' => -100], reqId());
                $next();
            });
        }, [], false);
    };

    // ③ 冷却：1004 连续两次 attack monster-2——首次成功（等 combat:hit），第二次被拒 cooldown。
    // ③ Cooldown: 1004 attacks monster-2 twice in a row — the first succeeds (wait for combat:hit), the second is rejected cooldown.
    $ops[] = static function (callable $next): void {
        sendMap('1004', 'attack', ['targetId' => 'monster-2'], reqId());
        waitMapFrame('1004', 'combat:hit', static fn (array $f): bool => ($f['payload']['targetId'] ?? null) === 'monster-2', 8.0, static function () use ($next): void {
            check(true, '首次攻击 monster-2 成功（combat:hit）');
            sendMap('1004', 'attack', ['targetId' => 'monster-2'], reqId());
            waitMapFrame('1004', 'combat:error', static fn (array $f): bool => ($f['payload']['code'] ?? null) === 'cooldown', 8.0, static function () use ($next): void {
                check(true, '冷却中攻击 → combat:error cooldown');
                $next();
            }, static function () use ($next): void {
                check(false, '冷却中攻击未得 combat:error cooldown');
                $next();
            });
        }, static function () use ($next): void {
            check(false, '首次攻击 monster-2 未结算（combat:hit 超时）');
            $next();
        });
    };

    seqOps($ops, static function (): void {
        closeStep('PASS', '失败回执（无效目标/距离/冷却）');
    });
}

/**
 * 主动关闭某 uid 的 Map 直连（触发服务端 onClose → 实体清理 → 断连冲刷同步点）。
 * Actively closes a uid's Map direct connection (triggering the server-side onClose → entity cleanup →
 * the disconnect-flush sync point).
 */
function closeMap(string $uid): void
{
    $conn = $GLOBALS['mapclients'][$uid]['conn'] ?? null;
    if ($conn instanceof AsyncTcpConnection) {
        $conn->onMessage = static fn (): null => null;
        $conn->onClose = static fn (): null => null;
        $conn->close();
    }
}

/**
 * 验收项 8：持久化——拾取后背包经 ArchivePipeline 落库，MySQL 侧读断言
 * （正式部署的落库目标：MySqlStorage 写 nythros_archive 表，collection='players'、id=uid、
 * data 为 JSON；侧读 SELECT data WHERE collection='players' AND id='1001'）。
 * 两段式窗口：先被动观察 20s（覆盖 markDirty 后可能已发生的兜底/同步点落库）；未命中则主动断开
 * 1001 的 Map 直连——触发服务端 onClose → onEntityCleanedUp → flushId 的断连立即冲刷（A-4 强制
 * 同步点，不受 30s 兜底门控影响）——再观察 15s。纯被动等待必须覆盖 30s 兜底间隔，两段式以真实
 * 登出链路把窗口压到 ~35s 且验证了断连冲刷语义本身。
 * Item 8: persistence — the post-pickup inventory is archived via ArchivePipeline; asserted by side-reading
 * MySQL (the production archive target: MySqlStorage writes to the nythros_archive table with
 * collection='players', id=uid and JSON data; the side-read is SELECT data WHERE collection='players' AND id='1001').
 * Two-phase window: first a passive 20s observation (covering any fallback/sync-point persistence that already
 * happened after markDirty); on a miss, 1001's Map direct connection is closed explicitly — triggering the server's
 * onClose → onEntityCleanedUp → flushId immediate disconnect flush (the A-4 forced sync point, unaffected by the
 * 30s fallback gate) — followed by 15s more observation. A purely passive wait must cover the whole 30s fallback
 * interval; the two-phase shape compresses the window to ~35s while exercising the real logout chain and its
 * disconnect-flush semantics.
 */
function step8Persistence(): void
{
    $itemId = (string) ($GLOBALS['combat']['itemId'] ?? '');
    check($itemId !== '', '前置：step5 拾取到了物品 ' . $itemId);

    $query = static function () use ($itemId): bool {
        try {
            $stmt = vMysql()->prepare('SELECT data FROM nythros_archive WHERE collection = :c AND id = :id LIMIT 1');
            $stmt->execute([':c' => 'players', ':id' => '1001']);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        } catch (\Throwable) {
            return false; // 建表/连接瞬时波动：下轮重试 Transient connect/schema issues: retried next round.
        }
        if ($row === false) {
            return false;
        }
        $data = json_decode((string) $row['data'], true);

        return is_array($data)
            && isset($data['inventory'][$itemId])
            && $data['inventory'][$itemId] >= 1;
    };

    $onHit = static function () use ($itemId): void {
        check(true, 'ArchivePipeline 已把拾取背包落库（1001 背包含 ' . $itemId . '）');
        closeStep('PASS', 'pickup → ArchivePipeline 落库，MySQL 侧读命中');
    };

    // 第一段：被动观察 20s。 Phase 1: passive observation for 20s.
    pollRedis($query, 20.0, $onHit, static function () use ($query, $onHit, $itemId): void {
        // 第二段：断开 1001 Map 直连（真实登出链路）→ 断连立即冲刷 → 再观察 15s。
        // Phase 2: close 1001's Map direct connection (the real logout chain) → immediate disconnect flush → 15s more.
        closeMap('1001');
        pollRedis($query, 15.0, $onHit, static function () use ($itemId): void {
            closeStep('FAIL', '被动 20s + 断连冲刷后 15s 均未观察到 ArchivePipeline 落库（inventory 缺 ' . $itemId . '）');
        });
    });
}

/**
 * 验收项 9：移动反作弊（NYTHROS_ANTICHEAT=1 时启用）——超速 move 被 403 error 帧拒绝
 * （move rejected: overspeed，携带 requestId），连接不断（后续既有步骤继续复用同一连接即隐式验证）；
 * 既有全部移动路径（±30 / ±(100,100)）在本验收开启反作弊时照常通过 = 移动路径等价性回归。
 * 未启用反作弊时 SKIP（SKIP 不计入 FAIL）。
 * Item 9: movement anti-cheat (enabled by NYTHROS_ANTICHEAT=1) — an overspeed move is rejected with a 403
 * error frame (move rejected: overspeed, carrying the requestId) while the connection stays open (implicitly
 * verified by later existing steps reusing the same connection); every pre-existing move path (±30 / ±(100,100))
 * passing with anti-cheat enabled doubles as the move-path equivalence regression. SKIP when anti-cheat is off
 * (SKIP never counts as FAIL).
 */
function step9AntiCheat(): void
{
    if (getenv('NYTHROS_ANTICHEAT') !== '1') {
        closeStep('SKIP', 'NYTHROS_ANTICHEAT 未启用，跳过移动反作弊验收');

        return;
    }

    sendMap('1005', 'move', ['dx' => 100000, 'dy' => 0], reqId());
    waitMapFrame('1005', 'error', static fn (array $f): bool => ($f['payload']['code'] ?? null) === 403
        && str_contains((string) ($f['payload']['message'] ?? ''), 'overspeed'), 8.0, static function (): void {
            check(true, '超速 move → error 403 move rejected: overspeed');
            closeStep('PASS', '超速 move 被拒 + 连接保持（合法步等价性由既有步骤覆盖）');
        }, static function (): void {
            closeStep('FAIL', '超速 move 未被拒绝（8s 内未收到 error 403 overspeed）');
        });
}

/**
 * 验收项 10（R3 玩法批）：技能冷却表——1006 两次 skill:cast fireball（cooldownSeconds=2.0）：
 * 首次结算（combat:hit），隔 0.8s 二次施放被技能冷却表拒绝（combat:error cooldown，携带剩余秒数）。
 * 时序约束（三重）：① 必须排在集火杀怪（验收项 3）之前——目标存活是首次结算的前提，且本步骤
 * 总耗时须压缩在 ~2s 内（怪物反击 40s 即全灭玩家，长超时窗会挤压集火窗口）；② 二次施放前隔
 * 0.8s——skill:cast 复用 attack 前置（resolveCombatant 的普攻攻击冷却 5 帧=0.25s 先于技能冷却表
 * 拦截），立即连发会被「攻击冷却中」抢答，0.8s > 0.25s 攻击冷却已过而 < 2s 技能冷却未过，
 * 保证拒绝来自 per-skill 冷却表；③ 开头清空 1006 收件箱——此前步骤的视野广播
 * combat:hit{targetId=monster-2} 历史帧会被 waitFrame 全量扫描误命中，先丢弃保证外层只认本次
 * 施放的结算帧。
 * Item 10 (the R3 gameplay batch): the skill-cooldown table — 1006 casts fireball twice
 * (cooldownSeconds=2.0): the first settles (combat:hit), a re-cast 0.8s later is rejected by the cooldown
 * table (combat:error cooldown, carrying the remaining seconds). Triple timing constraints: ① it must run
 * before the focus fire (item 3) — a live target is the precondition of the first settlement, and the step's
 * total cost must stay within ~2s (monster retaliation wipes the players in ~40s; long timeout windows would
 * squeeze the focus-fire window); ② the 0.8s gap before the re-cast — skill:cast shares attack's prelude
 * (resolveCombatant's normal-attack cooldown, 5 frames = 0.25s, intercepting before the skill-cooldown table),
 * so an immediate re-cast gets answered by "attack cooldown" instead; 0.8s > 0.25s puts the attack cooldown
 * past while < 2s keeps the skill cooldown live, guaranteeing the rejection comes from the per-skill table;
 * ③ draining 1006's inbox first — earlier steps' vision-broadcast combat:hit{targetId=monster-2} history
 * would be mis-matched by waitFrame's full-history scan, so dropping them keeps the outer wait loyal to this
 * cast's own frames.
 */
function step10SkillCooldown(): void
{
    $GLOBALS['mapclients']['1006']['inbox'] = [];

    sendMap('1006', 'skill:cast', ['skillId' => 'fireball', 'targetId' => 'monster-2'], reqId());
    waitMapFrame('1006', 'combat:hit', static fn (array $f): bool => ($f['payload']['targetId'] ?? null) === 'monster-2', 8.0, static function (): void {
        check(true, '首次 fireball 结算成功（combat:hit）');
        verifyTimer(0.8, static function (): void {
            sendMap('1006', 'skill:cast', ['skillId' => 'fireball', 'targetId' => 'monster-2'], reqId());
            waitMapFrame('1006', 'combat:error', static fn (array $f): bool => ($f['payload']['code'] ?? null) === 'cooldown'
                && str_contains((string) ($f['payload']['message'] ?? ''), '技能冷却中'), 8.0, static function (): void {
                    check(true, '冷却中二次施放 → combat:error cooldown（per-skill 冷却表收编生效）');
                    closeStep('PASS', '技能冷却表：首次置冷 + 二次拒绝');
                }, static function (): void {
                    check(false, '二次施放未被技能冷却表拒绝');
                    closeStep('FAIL', '未收到 combat:error cooldown');
                });
        }, [], false);
    }, static function (): void {
        closeStep('FAIL', '首次 fireball 未结算（combat:hit 超时）');
    });
}

/**
 * 验收项 11（R3 玩法批）：Buff 状态机——1005 对自己施加 rage（maxHp+50 属性修正）与 poison
 * （DOT 5/1s）：各收 buff:applied；DOT 首拍自伤 combat:hit{attackerId=targetId=自身} 且同窗
 * player:stats.maxHp=150（属性修正如入合成上限的证据）；rage 到期（4s）与 poison 到期（6s）
 * 各收 buff:expired。到期后不再有自伤帧（过期优先于 DOT 尾拍）。
 * Item 11 (the R3 gameplay batch): the buff state machine — 1005 applies rage (maxHp+50 modifier) and
 * poison (DOT 5/1s) to itself: one buff:applied each; the first DOT beat self-damages via
 * combat:hit{attackerId=targetId=self} with player:stats.maxHp=150 in the same window (evidence the
 * modifier joins the composed ceiling); rage expires at 4s and poison at 6s, one buff:expired each.
 * No self-damage frames after expiry (expiry precedes DOT tail beats).
 */
function step11Buffs(): void
{
    $ops = [];

    // ① 施加 rage + poison → 各一条 buff:applied。
    // ① Apply rage + poison → one buff:applied each.
    $ops[] = static function (callable $next): void {
        sendMap('1005', 'buff:apply', ['buffId' => 'rage', 'targetId' => $GLOBALS['entityIds']['1005']], reqId());
        waitMapFrame('1005', 'buff:applied', static fn (array $f): bool => ($f['payload']['buffId'] ?? null) === 'rage'
            && ($f['payload']['stacks'] ?? null) === 1, 8.0, static function () use ($next): void {
                check(true, 'rage 施加回执 buff:applied{stacks=1}');
                sendMap('1005', 'buff:apply', ['buffId' => 'poison', 'targetId' => $GLOBALS['entityIds']['1005']], reqId());
                waitMapFrame('1005', 'buff:applied', static fn (array $f): bool => ($f['payload']['buffId'] ?? null) === 'poison', 8.0, static function () use ($next): void {
                    check(true, 'poison 施加回执 buff:applied');
                    $next();
                }, static function () use ($next): void {
                    check(false, 'poison 未得 buff:applied');
                    $next();
                });
            }, static function () use ($next): void {
                check(false, 'rage 未得 buff:applied');
                $next();
            });
    };

    // ② DOT 首拍：自伤 combat:hit + player:stats.maxHp=150（rage 属性修正生效证据）。
    // ② First DOT beat: the self-damage combat:hit plus player:stats.maxHp=150 (proof of rage's modifier).
    $ops[] = static function (callable $next): void {
        waitMapFrame('1005', 'combat:hit', static fn (array $f): bool => str_starts_with((string) ($f['payload']['attackerId'] ?? ''), '1005@')
            && ($f['payload']['attackerId'] ?? null) === ($f['payload']['targetId'] ?? null)
            && ($f['payload']['damage'] ?? null) === 5, 8.0, static function () use ($next): void {
                check(true, 'DOT 首拍自伤 combat:hit{attacker=target=1005@…, damage=5}');
                waitMapFrame('1005', 'player:stats', static fn (array $f): bool => ($f['payload']['maxHp'] ?? null) === 150, 8.0, static function () use ($next): void {
                    check(true, 'player:stats.maxHp=150（rage 属性修正如入合成上限）');
                    $next();
                }, static function () use ($next): void {
                    check(false, '未观察到 maxHp=150 的 player:stats');
                    $next();
                });
            }, static function () use ($next): void {
                check(false, '8s 内未观察到 DOT 自伤帧');
                $next();
            });
    };

    // ③ 到期：rage（4s）先于 poison（6s）各收 buff:expired。
    // ③ Expiry: rage (4s) before poison (6s), one buff:expired each.
    $ops[] = static function (callable $next): void {
        waitMapFrame('1005', 'buff:expired', static fn (array $f): bool => ($f['payload']['buffId'] ?? null) === 'rage', 10.0, static function () use ($next): void {
            check(true, 'rage 到期 buff:expired（4s 口径）');
            waitMapFrame('1005', 'buff:expired', static fn (array $f): bool => ($f['payload']['buffId'] ?? null) === 'poison', 10.0, static function () use ($next): void {
                check(true, 'poison 到期 buff:expired（6s 口径）');
                $next();
            }, static function () use ($next): void {
                check(false, 'poison 未在窗口内到期');
                $next();
            });
        }, static function () use ($next): void {
            check(false, 'rage 未在窗口内到期');
            $next();
        });
    };

    seqOps($ops, static function (): void {
        closeStep('PASS', 'Buff 状态机（施加/DOT 自伤/属性修正/到期）');
    });
}

/**
 * 验收项 12（R3 玩法批，NYTHROS_ROOMS=1 时启用）：房间 AoE 合并帧——建房刷怪 60 只
 * （RoomHub 网格布局），room:aoe r=70 全簇命中：恰好一条 combat:aoe 合并帧（targetIds ≥50，
 * 无逐目标 combat:hit 洪泛）+ 恰好一条 drop:spawned_batch（dropIds ≥ 击杀数，多条目 roll 口径）。
 * 未启用房间装配时 SKIP（SKIP 不计入 FAIL）。
 * Item 12 (the R3 gameplay batch, enabled by NYTHROS_ROOMS=1): merged room-AoE frames — build a room,
 * spawn 60 monsters (RoomHub's grid layout) and room:aoe r=70 hits the whole cluster: EXACTLY ONE merged
 * combat:aoe frame (targetIds >= 50, no per-target combat:hit flooding) plus EXACTLY ONE drop:spawned_batch
 * (dropIds >= kills, the multi-entry roll convention). SKIP without room assembly (SKIP never counts as FAIL).
 */
function step12AoeRoom(): void
{
    if (getenv('NYTHROS_ROOMS') !== '1') {
        closeStep('SKIP', 'NYTHROS_ROOMS 未启用，跳过房间 AoE 验收');

        return;
    }

    $ops = [];
    $ops[] = static function (callable $next): void {
        sendMap('1007', 'room:create', ['roomId' => 'verify-combat-aoe'], reqId());
        waitMapFrame('1007', 'room:ok', static fn (array $f): bool => ($f['payload']['op'] ?? null) === 'create', 8.0, static function () use ($next): void {
            sendMap('1007', 'room:join', ['roomId' => 'verify-combat-aoe'], reqId());
            waitMapFrame('1007', 'room:ok', static fn (array $f): bool => ($f['payload']['op'] ?? null) === 'join', 8.0, static function () use ($next): void {
                sendMap('1007', 'room:spawn', ['roomId' => 'verify-combat-aoe', 'count' => 60], reqId());
                waitMapFrame('1007', 'room:ok', static fn (array $f): bool => ($f['payload']['op'] ?? null) === 'spawn', 8.0, static function () use ($next): void {
                    check(true, '建房 + join + 刷怪 60 完成');
                    $next();
                }, static function () use ($next): void {
                    check(false, 'room:spawn 未确认');
                    $next();
                });
            }, static function () use ($next): void {
                check(false, 'room:join 未确认');
                $next();
            });
        }, static function () use ($next): void {
            check(false, 'room:create 未确认');
            $next();
        });
    };

    $ops[] = static function (callable $next): void {
        // 网格簇 x∈[24,62], y∈[-24,-20]：圆心 (43,-22) r=70 覆盖全簇。
        // The grid cluster spans x∈[24,62], y∈[-24,-20]: center (43,-22) r=70 covers it all.
        sendMap('1007', 'room:aoe', ['roomId' => 'verify-combat-aoe', 'skillId' => 'fireball', 'cx' => 43, 'cy' => -22, 'r' => 70], reqId());
        waitMapFrame('1007', 'combat:aoe', static fn (array $f): bool => ($f['payload']['casterId'] ?? null) === $GLOBALS['entityIds']['1007'], 8.0, static function (array $f) use ($next): void {
            $targets = is_array($f['payload']['targetIds'] ?? null) ? $f['payload']['targetIds'] : [];
            check(count($targets) >= 50, 'combat:aoe 合并帧命中 ≥50（实际 ' . count($targets) . '）');

            waitMapFrame('1007', 'drop:spawned_batch', static fn (array $f2): bool => true, 8.0, static function (array $f2) use ($targets, $next): void {
                $dropIds = is_array($f2['payload']['dropIds'] ?? null) ? $f2['payload']['dropIds'] : [];
                check(count($dropIds) >= count($targets), 'drop:spawned_batch.dropIds ≥ 击杀数（drops=' . count($dropIds) . ' kills=' . count($targets) . '）');
                $next();
            }, static function () use ($next): void {
                check(false, '未收到 drop:spawned_batch 合并帧');
                $next();
            });
        }, static function () use ($next): void {
            check(false, '未收到 combat:aoe 合并帧');
            $next();
        });
    };

    seqOps($ops, static function (): void {
        closeStep('PASS', '房间 AoE 合并帧（combat:aoe ×1 + drop:spawned_batch ×1）');
    });
}

// 验收步骤注册表（顺序执行；每步独立超时）。
// 执行顺序说明：怪物 spawn 后会立即反击玩家（玩家 hp 100，两个怪物 400ms/击，全灭约 40s），
// 玩家全灭后怪物 PATROL 随机移动会走远——因此所有「活怪物」操作（攻击/技能/失败回执）提前在
// 集火前完成，再击杀怪物、处理掉落与拾取。
// The acceptance step registry (sequential execution; per-step timeout).
// Ordering note: monsters retaliate immediately after spawn (players have 100 hp, both monsters hit every
// 400ms, total wipe in ~40s); once every player is dead the monsters PATROL-randomly and drift away — so every
// live-monster operation (attack / skill / failure receipts) runs before the focus fire, then monsters die and drops/pickup follow.
$GLOBALS['verify']['steps'] = [
    ['0. 前置：10 客户端登录 + Map/chat/team 三服务 token 直连（1001-1010）', 'step0Login', 120.0],
    ['1. 怪物生成（monster:spawned typeId + 跨格 entity_enter）', 'step1MonsterSpawn', 80.0],
    ['2. 玩家攻击（attack → combat:hit 视野广播）', 'step2Attack', 20.0],
    ['6. 技能（skill:cast 广播 + 技能伤害帧）', 'step6Skill', 20.0],
    ['7. 失败回执（无效目标/距离/冷却 → combat:error）', 'step7Errors', 40.0],
    ['10. 技能冷却表（per-skill 冷却：首次置冷 + 二次拒绝；须在集火前）', 'step10SkillCooldown', 25.0],
    ['3. 怪物死亡（多玩家集火 → entity_dead + 尸体 attack error）', 'step3MonsterDeath', 60.0],
    ['4. 掉落生成（drop:spawned + 跨格 entity_enter 附 itemId）', 'step4DropSpawn', 20.0],
    ['5. 拾取（pickup → drop:removed + item:added + 重复拾取 error）', 'step5Pickup', 25.0],
    ['8. 持久化（pickup 后背包经 ArchivePipeline 落库，MySQL 侧读）', 'step8Persistence', 60.0],
    // 门禁 8 两段式窗口 ~35s+：被动段 20s 覆盖 markDirty 后可能已发生的落库；主动段断开 1001 的
    // Map 直连触发断连立即冲刷（A-4 强制同步点）后再观察 15s。步骤超时 60s 留足余量。
    // Gate-8's two-phase window is ~35s+: the passive 20s phase covers persistence that already happened after
    // markDirty; the active phase closes 1001's Map direct connection to trigger the immediate disconnect flush
    // (the A-4 forced sync point) and observes 15s more. The 60s step timeout leaves ample headroom.
    ['9. 移动反作弊（NYTHROS_ANTICHEAT=1 时超速 move 被拒；未启用 SKIP）', 'step9AntiCheat', 20.0],
    ['11. Buff 状态机（施加/DOT 自伤/属性修正/到期）', 'step11Buffs', 45.0],
    ['12. 房间 AoE 合并帧（NYTHROS_ROOMS=1 时 combat:aoe ×1 + drop 批帧；未启用 SKIP）', 'step12AoeRoom', 40.0],
];

$worker = new Worker();
$worker->onWorkerStart = static function (): void {
    echo "[verify] 阶段 5 战斗层端到端验收启动（组⑤，ADR-017 §8）\n";
    // 全局看门狗：380s 未完成强制收尾。
    // Global watchdog: force the summary after 380s.
    Timer::add(380.0, static function (): void {
        echo "[verify] WATCHDOG: 全局超时\n";
        finishAll();
    }, [], false);
    nextStep();
};

// Workerman 5.2 要求 argv 中显式含自身命令（start/stop/...）：本脚本无自定义参数，直接注入 start（前台 DEBUG 模式）。
// Workerman 5.2 requires an explicit own command (start/stop/...) in argv: this script takes no custom args, so inject start (foreground DEBUG mode).
$GLOBALS['argv'] = [$argv[0], 'start'];

// 未捕获 Throwable 兜底：打印后通知父 monitor 退出再 exit——否则 Workerman 的 monitor 循环会把崩溃的
// worker 进程当作意外退出自动重启，验收脚本陷入「崩溃→重启→再崩溃」死循环直到外部 timeout。
// Uncaught-Throwable backstop: report, ask the parent monitor to quit, then exit — otherwise Workerman's monitor
// loop treats the crashed worker as an unexpected death and restarts it, trapping the acceptance script in a
// crash→restart→crash loop until an external timeout.
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
