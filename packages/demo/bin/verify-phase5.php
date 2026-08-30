<?php

declare(strict_types=1);

// 定位：packages/demo/bin/verify-phase5.php — 阶段 5 社交层端到端验收一体化脚本（组⑦，ADR-015 §7-8 待确认点第 8 条）。
// 登录链路（ADR-021 自研单栈）：以 AsyncTcpConnection 模拟真实客户端——连 gateway 18285 完整握手
// （auth{username,password,mapId}）拿多 scope token + 三地址（map.wsAddress + endpoints.chat/team），随后按需直连：
// Map 直连走战斗协议（consume('map')），chat/team 直连发带 token 的 auth 帧（handleTokenAuth 消费各自 scope）；
// 直接调 Redis 做状态观察。覆盖阶段 5 的 8 个门禁验收项 + R3 社交批扩展的 3 个验收项：
//   1 登录链路（auth → auth_ok 五字段 uid/token/map/team/guild + endpoints 三地址；chat/team token 消费登录）
//   2 进图凭证（map:enter → map:entered；map:join → map:joined）
//   3 战斗直连铁律（auth_ok.token 直连 Map consume('map') 五态通过 + 帧同步 entity_moved 不经社交层转发）
//   4 聊天五语义（world/channel/team/guild/private + 跨频道发言 404 拦截）
//   5 组队状态机（invite/accept/reject/leave/disband 全流程 + 双队防护 409/404）
//   6 掉线重连恢复（断 gateway → 重连 auth → 恢复队伍/帮派/频道分组）
//   7 滚动更新（map-rolling.php mark-stopping 旧频道 → 新玩家不再分到旧频道 → 重连自迁移新 channelId）
//   8 token 多 scope 单向（auth token 签 [map,chat,team] 且各 scope per-scope 墓碑防重放；map:enter 续签只签 [map]）
//   9 好友全流程（申请/同意/删除/列表 + 双向一致 + 在线通知；R3 社交批）
//   10 公会正式化（建会/审批/任命/公告/踢人/解散 + 权限矩阵抽查 403；R3 社交批）
//   11 排行榜查询帧（top N 分页平行列表 + 单 uid 排名；写入口径服务端内部，脚本 ZADD 种子；R3 社交批）
// Located at: packages/demo/bin/verify-phase5.php — the phase-5 social-tier end-to-end acceptance script (group ⑦, ADR-015 §7-8 open point #8).
// Login chain (ADR-021 self-built single stack): real clients are driven via AsyncTcpConnection — they connect gateway
// 18285 with the full handshake (auth{username,password,mapId}) and receive a multi-scope token plus three addresses
// (map.wsAddress + endpoints.chat/team), then connect on demand: the Map directly over the combat protocol (consume('map')),
// each of chat/team with a token-carrying auth frame (handleTokenAuth consuming that role's scope); Redis is read directly
// for state observation. The script covers 8 acceptance gates:
//   1 login chain (auth → auth_ok five fields uid/token/map/team/guild plus the three-address endpoints; chat/team token-consume logins)
//   2 map-entry credential (map:enter → map:entered; map:join → map:joined)
//   3 combat-direct iron rule (auth_ok.token connects the Map directly, consume('map') five-state passes + frame-sync entity_moved never relays through the social tier)
//   4 five chat semantics (world/channel/team/guild/private + cross-channel 404 rejection)
//   5 team state machine (invite/accept/reject/leave/disband full flow + double-team guard 409/404)
//   6 drop-reconnect recovery (drop the gateway → re-auth → team/guild/channel groups recovered)
//   7 rolling update (map-rolling.php mark-stopping the old channel → new players skip it → reconnect self-migrates to the new channelId)
//   8 multi-scope one-way tokens (the auth token issues [map,chat,team] with per-scope tombstones preventing replay; the map:enter renewal issues ['map'] only)
//
// 前置（ADR-015 §4.1 启动铁序）：Redis 127.0.0.1:6379 可用；自研单栈经 php bin/server start 启动
// （deploy.yaml：gateway 18285 / chat 18286 / team 18287 + 地图组 18081/18082/18083；chat/team 对外地址由
// bin/server 按 deploy.yaml 注入，auth_ok.endpoints 据此下发）。脚本在真实多进程链路上验收，验收完成后请停止上述服务。
// Prerequisites (ADR-015 §4.1 boot order): Redis on 127.0.0.1:6379; the self-built single stack boots via
// php bin/server start (deploy.yaml: gateway 18285 / chat 18286 / team 18287 plus maps 18081/18082/18083; the chat/team
// public addresses are injected by bin/server from deploy.yaml and handed out via auth_ok.endpoints). The script verifies
// over the real multi-process links; stop the services afterwards.
//
// 输出契约：每项一行 [verify] [PASS|FAIL|SKIP]；末行 RESULT 汇总（PASS=… FAIL=… SKIP=…）。
// SKIP = 验收口径因环境缺口无法执行（明细见输出），不计入 FAIL。
// Output contract: one line per item [verify] [PASS|FAIL|SKIP]; a final RESULT summary line (PASS=… FAIL=… SKIP=…).
// SKIP marks acceptance criteria that cannot run due to environment gaps (details in the output); SKIP never counts as FAIL.

require __DIR__ . '/../../../vendor/autoload.php';
require __DIR__ . '/lib/map-codec.php';
require __DIR__ . '/lib/verify-framework.php';

use Nythros\Cluster\RedisServiceRegistry;
use Nythros\Security\RedisTokenStore;
use Nythros\Security\TokenManager;
use Workerman\Connection\AsyncTcpConnection;
use Workerman\Protocols\Websocket;
use Workerman\Timer;
use Workerman\Worker;

// 服务地址（ADR-021 自研单栈：gateway 登录入口 18285；chat/team 地址经 auth_ok.endpoints 动态获取；
// Map 18081/18082/18083 战斗直连）。
// Service addresses (ADR-021 self-built single stack: gateway login entry 18285; chat/team addresses come dynamically
// from auth_ok.endpoints; Maps 18081/18082/18083 are combat-direct).
const GW_WS = 'ws://127.0.0.1:18285';
const MAP_1_CH1_WS = 'ws://127.0.0.1:18081'; // map-1#ch-1（token 单向验收的 map-1 直连兜底地址） map-1#ch-1 (map-1 direct fallback for the token one-way checks)
const MAP_1_CH2_WS = 'ws://127.0.0.1:18082'; // map-1#ch-2
const MAP_2_CH1_WS = 'ws://127.0.0.1:18083'; // map-2#ch-1

// map-rolling.php 脚本路径（step 7 滚动更新用其 mark-stopping 子命令）。
// Path of map-rolling.php (its mark-stopping subcommand is used by step 7's rolling update).
const MAP_ROLLING_SCRIPT = __DIR__ . '/map-rolling.php';

// 静态账号（与 run-worker.php 装配一致：NYTHROS_ACCOUNTS 声明 `uid=password` 对，缺省 1001/1002/1003 密码 secret）。
// Static accounts (consistent with run-worker.php: NYTHROS_ACCOUNTS declares `uid=password` pairs, default 1001/1002/1003 with password secret).

/**
 * 按 uid 从 NYTHROS_ACCOUNTS 提取密码（与 run-worker.php 的账号表装配一致：`uid=password` 对逗号分隔）；
 * 未声明或 uid 缺失时缺省 'secret'（与装配缺省一致）。
 * Resolves a uid's password from NYTHROS_ACCOUNTS (consistent with run-worker.php's account-table assembly:
 * comma-separated `uid=password` pairs); defaults to 'secret' when unset or the uid is absent (matching the assembly default).
 */
function accountPassword(string $uid): string
{
    foreach (explode(',', getenv('NYTHROS_ACCOUNTS') ?: '1001=secret,1002=secret,1003=secret') as $pair) {
        $parts = explode('=', trim($pair), 2);
        if (count($parts) === 2 && $parts[0] === $uid) {
            return $parts[1];
        }
    }

    return 'secret';
}

/**
 * 验收脚本共享状态（步骤队列、断言、结果、客户端/令牌等跨步骤状态）。
 * Shared acceptance state (step queue, assertions, results, cross-step client/token state).
 *
 * @var array<string, mixed>
 */
bootVerifyGlobals([]);

/**
 * 社交客户端注册表：uid => {conn, inbox, authOk}（跨步骤持久连接）。
 * Social client registry: uid => {conn, inbox, authOk} (persistent connections across steps).
 *
 * @var array<string, array<string, mixed>>
 */
$GLOBALS['clients'] = [];

/**
 * Map 战斗客户端注册表：uid => {conn, inbox}（跨步骤持久连接）。
 * Map combat client registry: uid => {conn, inbox} (persistent connections across steps).
 *
 * @var array<string, array<string, mixed>>
 */
$GLOBALS['mapclients'] = [];

/**
 * 令牌暂存：uid => token；'1001-enter' => map:enter 续签 token。
 * Token staging: uid => token; '1001-enter' => the map:enter renewal token.
 *
 * @var array<string, string>
 */
$GLOBALS['tokens'] = [];

/**
 * 频道暂存：uid => 分配到的 channelId。
 * Channel staging: uid => the assigned channelId.
 *
 * @var array<string, string>
 */
$GLOBALS['channels'] = [];

/** @var int 请求 id 序列（requestId 相关性） Request id sequence (requestId correlation). */
$GLOBALS['reqSeq'] = 0;

/** @var list<string> 脚本用过的 teamId（收尾清理） teamIds used by the script (final cleanup). */
$GLOBALS['teamIdsUsed'] = [];

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
 * 脚本侧服务注册表（直接观察/操作 Redis 注册数据，滚动更新断言用）。
 * The script-side service registry (direct Redis observation / manipulation for the rolling-update assertions).
 */
function vRegistry(): RedisServiceRegistry
{
    static $registry = null;
    if ($registry === null) {
        $registry = new RedisServiceRegistry(vRedis());
    }

    return $registry;
}

/**
 * 脚本侧 TokenManager（peek 校验 token 的 scope 只含 ['map']）。
 * The script-side token manager (peek to verify a token's scopes contain only ['map']).
 */
function vTokens(): TokenManager
{
    static $tokens = null;
    if ($tokens === null) {
        $tokens = new TokenManager(new RedisTokenStore(vRedis()));
    }

    return $tokens;
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
 * 主动关闭某 uid 的社交连接（触发网关 onClose → 掉线标记）。
 * Actively closes a uid's social connection (triggering the gateway onClose → the offline marker).
 */
function closeSocial(string $uid): void
{
    $conn = $GLOBALS['clients'][$uid]['conn'] ?? null;
    if ($conn instanceof AsyncTcpConnection) {
        $conn->onMessage = static fn (): null => null;
        $conn->onClose = static fn (): null => null;
        $conn->close();
    }
}

/**
 * 向某 uid 的社交连接发送一帧。
 * Sends a frame on a uid's social connection.
 */
function sendSocial(string $uid, string $type, array $payload, string $requestId): void
{
    $conn = $GLOBALS['clients'][$uid]['conn'] ?? null;
    if ($conn instanceof AsyncTcpConnection) {
        $conn->send(frame($type, $payload, $requestId));
    }
}

/**
 * 请求-响应：向 uid 发一帧，轮询收件箱等待同 requestId 的回帧（移除该帧后回调）。
 * Request-reply: send a frame to uid, then poll the inbox for the frame carrying the same requestId (removed before the callback).
 *
 * @param callable(?array<string, mixed>): void $onReply 回帧回调（超时/失败 null） Reply callback (null on timeout/failure).
 */
function requestReply(string $uid, string $type, array $payload, string $requestId, callable $onReply, float $timeout = 15.0): void
{
    sendSocial($uid, $type, $payload, $requestId);
    waitFrame(
        $GLOBALS['clients'][$uid]['inbox'],
        null,
        static fn (array $f): bool => ($f['requestId'] ?? null) === $requestId,
        $timeout,
        static fn (array $f): mixed => $onReply($f),
        static fn (): mixed => $onReply(null),
    );
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
 * 一次性 Map 认证：新连接 → auth{token} → auth_ok/auth_failed/error 后关闭并回调。
 * One-shot Map auth: fresh connection → auth{token} → on auth_ok/auth_failed/error close and callback.
 *
 * @param callable(bool, array<string, mixed>): void $onResult 结果回调 Result callback.
 */
function mapAuthOnce(string $ws, string $token, callable $onResult): void
{
    $conn = new AsyncTcpConnection($ws);
    $conn->websocketType = Websocket::BINARY_TYPE_ARRAYBUFFER;
    $settled = false;

    $conn->onConnect = static function (AsyncTcpConnection $c) use ($token): void {
        $c->send(frameMap('auth', ['token' => $token], 'map-auth:' . $token));
    };
    $conn->onMessage = static function (AsyncTcpConnection $c, mixed $data) use ($onResult, &$settled): void {
        if ($settled) {
            return;
        }
        foreach (decodeMapFrames((string) $data) as $decoded) {
            $type = $decoded['type'] ?? null;
            if (in_array($type, ['auth_ok', 'auth_failed', 'error'], true)) {
                $settled = true;
                $c->close();
                $onResult($type === 'auth_ok', $decoded);
                break;
            }
        }
    };
    $conn->onClose = static function () use ($onResult, &$settled): void {
        if (!$settled) {
            $settled = true;
            $onResult(false, ['type' => 'error', 'requestId' => null, 'payload' => ['code' => -1, 'message' => 'map 连接关闭']]);
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
 * 组队请求-响应：发组队消息，team:ok 回 onOk(payload)，team:error 回 onErr(message, code)，超时回 onErr('timeout', 0)。
 * Team request-reply: send a team message; team:ok calls onOk(payload), team:error calls onErr(message, code), timeout calls onErr('timeout', 0).
 *
 * @param callable(array<string, mixed>): void $onOk 成功回调 Success callback.
 * @param callable(string, int): void $onErr 失败回调 Failure callback.
 */
function teamRequest(string $uid, string $type, array $payload, callable $onOk, callable $onErr): void
{
    $requestId = reqId();
    requestReply($uid, $type, $payload, $requestId, static function (?array $f) use ($onOk, $onErr): void {
        if ($f === null) {
            $onErr('timeout', 0);

            return;
        }
        $t = $f['type'] ?? null;
        if ($t === 'team:ok') {
            $onOk($f['payload'] ?? []);

            return;
        }
        if ($t === 'team:error') {
            $onErr((string) ($f['payload']['message'] ?? 'unknown'), (int) ($f['payload']['code'] ?? 0));

            return;
        }
        $onErr('unexpected:' . (string) $t, 0);
    });
}

/**
 * 断言组队成功（team:ok），并可选等待某收件箱的 team:notify 通知。
 * Asserts a successful team operation (team:ok), optionally waiting for a team:notify in an inbox.
 *
 * @param array<string, mixed>|null $notifyBox 待等通知的收件箱（引用）；null = 不等通知 Inbox awaiting a notification (by reference); null = no notification wait.
 */
function expectTeamOk(string $uid, string $type, array $payload, ?string $notifyType, ?array &$notifyBox, string $label, callable $done): void
{
    teamRequest($uid, $type, $payload, static function (array $p) use ($notifyType, &$notifyBox, $label, $done): void {
        check(true, $label);
        if ($notifyType === null || $notifyBox === null) {
            $done();

            return;
        }
        waitFrame(
            $notifyBox,
            'team:notify',
            static fn (array $f): bool => ($f['payload']['type'] ?? null) === $notifyType,
            5.0,
            static function (array $f) use ($notifyType, $done): void {
                check(true, "收到 team:notify({$notifyType}) 通知");
                $done();
            },
            static function () use ($notifyType, $done): void {
                check(false, "未收到 team:notify({$notifyType}) 通知");
                $done();
            },
        );
    }, static function (string $msg, int $code) use ($label, $done): void {
        check(false, $label . ' 失败: ' . $msg . ' code=' . $code);
        $done();
    });
}

/**
 * 断言组队失败（team:error 的 HTTP code 与 message 匹配）。
 * Asserts a failed team operation (team:error with matching HTTP code and message).
 */
function expectTeamError(string $uid, string $type, array $payload, int $httpCode, string $message, string $label, callable $done): void
{
    teamRequest($uid, $type, $payload, static function (array $p) use ($label, $httpCode, $message, $done): void {
        check(false, $label . '：预期 team:error ' . $httpCode . ' ' . $message . ' 却收到 team:ok');
        $done();
    }, static function (string $msg, int $code) use ($label, $httpCode, $message, $done): void {
        check($code === $httpCode && $msg === $message, $label . sprintf('（实际 code=%d msg=%s，预期 %d %s）', $code, $msg, $httpCode, $message));
        $done();
    });
}

/**
 * 记录 teamId 供收尾清理。
 * Records a teamId for final cleanup.
 */
function trackTeam(string $teamId): void
{
    if (!in_array($teamId, $GLOBALS['teamIdsUsed'], true)) {
        $GLOBALS['teamIdsUsed'][] = $teamId;
    }
}

/**
 * 发送聊天并断言接收方收到对应 scope 的 chat:message（成功无回执，客户端本地回显——以接收方广播为准）。
 * Sends a chat and asserts the receiver gets the matching-scope chat:message (success has no receipt — the receiver broadcast is authoritative).
 */
function expectChatFrom(string $sender, string $receiver, array $payload, string $label, callable $done): void
{
    $scope = (string) ($payload['scope'] ?? '');
    $content = (string) ($payload['content'] ?? '');
    sendSocial($sender, 'chat:send', $payload, reqId());
    waitFrame(
        $GLOBALS['clients'][$receiver]['inbox'],
        'chat:message',
        static fn (array $f): bool => ($f['payload']['scope'] ?? null) === $scope
            && ($f['payload']['content'] ?? null) === $content
            && ($f['payload']['fromUid'] ?? null) === $sender,
        5.0,
        static function () use ($label, $done): void {
            check(true, $label);
            $done();
        },
        static function () use ($label, $done): void {
            check(false, $label);
            $done();
        },
    );
}

/**
 * 运行 map-rolling.php 子命令（一次性 CLI，同步 exec）。
 * Runs a map-rolling.php subcommand (one-shot CLI, synchronous exec).
 *
 * @return array{code: int, out: string} 退出码 + 输出 Exit code + output.
 */
function runMapRolling(string $command, string $serviceId): array
{
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(MAP_ROLLING_SCRIPT) . ' ' . escapeshellarg($command) . ' ' . escapeshellarg($serviceId) . ' 2>&1';
    $out = [];
    $code = 0;
    exec($cmd, $out, $code);

    return ['code' => $code, 'out' => implode("\n", $out)];
}

/**
 * 取 map-1 的「另一」频道 id（相对给定频道）。
 * Returns map-1's "other" channel id (relative to the given channel).
 */
function otherChannel(string $channelId): string
{
    return $channelId === 'ch-2' ? 'ch-1' : 'ch-2';
}

/**
 * 验收项 1：登录链路——auth → auth_ok 五字段（uid/token/map{wsAddress,mapId,channelId}/team/guild）+ endpoints
 * 三地址；随后 chat/team 角色各以带 token 的 auth 帧直连（handleTokenAuth 消费各自 scope）→ auth_ok{uid}。
 * Item 1: login chain — auth → auth_ok five fields (uid/token/map{wsAddress,mapId,channelId}/team/guild) plus the
 * three-address endpoints; then the chat/team roles are each connected directly with a token-carrying auth frame
 * (handleTokenAuth consuming that role's scope) → auth_ok{uid}.
 */
function step1Login(): void
{
    openSocial('1001', 'map-1', static function (bool $ok, array $f): void {
        check($ok, 'A(1001) 登录成功（auth → auth_ok）');
        if (!$ok) {
            closeStep('FAIL', 'A 登录失败: ' . json_encode($f['payload'] ?? [], JSON_UNESCAPED_UNICODE));

            return;
        }
        $p = $f['payload'] ?? [];
        check(is_string($p['uid'] ?? null) && $p['uid'] === '1001', 'auth_ok.uid == 1001');
        $token = $p['token'] ?? null;
        check(is_string($token) && preg_match('/^[0-9a-f]{64}$/i', $token) === 1, 'auth_ok.token 为 64 位 hex');
        $map = $p['map'] ?? null;
        check(is_array($map) && is_string($map['wsAddress'] ?? null) && is_string($map['mapId'] ?? null) && is_string($map['channelId'] ?? null), 'auth_ok.map 含 wsAddress/mapId/channelId 三字段');
        check(($map['mapId'] ?? null) === 'map-1', 'auth_ok.map.mapId == map-1');
        check(array_key_exists('team', $p), 'auth_ok 含 team 字段（新登录为 null）');
        check(array_key_exists('guild', $p), 'auth_ok 含 guild 字段（新登录为 null）');
        // endpoints 三地址（ADR-021 §3.2）：chat/team 对外地址由部署拓扑注入，供 token 消费直连
        // The three-address endpoints (ADR-021 §3.2): chat/team public addresses injected from the deployment topology for token-consume direct connections
        $chatWs = $p['endpoints']['chat']['wsAddress'] ?? null;
        $teamWs = $p['endpoints']['team']['wsAddress'] ?? null;
        check(is_string($chatWs) && $chatWs !== '', 'auth_ok.endpoints.chat.wsAddress 有效');
        check(is_string($teamWs) && $teamWs !== '', 'auth_ok.endpoints.team.wsAddress 有效');
        $GLOBALS['tokens']['1001'] = (string) $token;
        $GLOBALS['channels']['1001'] = (string) ($map['channelId'] ?? '');

        openSocial('1002', 'map-1', static function (bool $ok2, array $f2) use ($chatWs, $teamWs, $token): void {
            check($ok2, 'B(1002) 登录成功（auth → auth_ok）');
            if ($ok2) {
                $GLOBALS['tokens']['1002'] = (string) ($f2['payload']['token'] ?? '');
                $GLOBALS['channels']['1002'] = (string) ($f2['payload']['map']['channelId'] ?? '');
            }
            check(
                ($GLOBALS['channels']['1001'] ?? '') === ($GLOBALS['channels']['1002'] ?? ''),
                'A/B 均按最少在线分配同一频道（登录即进图确定性）',
            );

            // 多 scope 兑现：A 的 token 分别连 chat/team 地址发带 token 的 auth 帧 → 各自消费 scope → auth_ok{uid}
            // Multi-scope fulfillment: A's token connects each of the chat/team addresses with a token-carrying auth frame
            // → each role consumes its own scope → auth_ok{uid}
            seqOps([
                static function (callable $next) use ($chatWs, $token): void {
                    socialTokenAuthOnce((string) $chatWs, (string) $token, static function (bool $okChat, array $fChat) use ($next): void {
                        check($okChat, 'A 拿 auth_ok.token 连 chat 角色 → handleTokenAuth consume(\'chat\') → auth_ok');
                        if ($okChat) {
                            check(($fChat['payload']['uid'] ?? null) === '1001' && !isset($fChat['payload']['token']), 'chat auth_ok 仅 uid（不重复签发 token）');
                        } else {
                            check(false, 'chat token 登录失败: ' . json_encode($fChat['payload'] ?? [], JSON_UNESCAPED_UNICODE));
                        }
                        $next();
                    });
                },
                static function (callable $next) use ($teamWs, $token): void {
                    socialTokenAuthOnce((string) $teamWs, (string) $token, static function (bool $okTeam, array $fTeam) use ($next): void {
                        check($okTeam, 'A 拿同一 token 连 team 角色 → handleTokenAuth consume(\'team\') → auth_ok');
                        if ($okTeam) {
                            check(($fTeam['payload']['uid'] ?? null) === '1001' && !isset($fTeam['payload']['token']), 'team auth_ok 仅 uid（不重复签发 token）');
                        } else {
                            check(false, 'team token 登录失败: ' . json_encode($fTeam['payload'] ?? [], JSON_UNESCAPED_UNICODE));
                        }
                        $next();
                    });
                },
            ], static function (): void {
                closeStep('PASS', 'auth_ok 五字段 + endpoints 三地址 + chat/team token 消费登录');
            });
        });
    });
}

/**
 * 验收项 2：进图凭证——map:enter → map:entered（token + map 地址）；map:join → map:joined。
 * Item 2: map-entry credential — map:enter → map:entered (token + map address); map:join → map:joined.
 */
function step2MapCredential(): void
{
    requestReply('1001', 'map:enter', ['mapId' => 'map-1'], reqId(), static function (?array $f): void {
        check($f !== null && ($f['type'] ?? null) === 'map:entered', 'map:enter → map:entered 回执');
        if ($f === null) {
            closeStep('FAIL', 'map:entered 超时');

            return;
        }
        $p = $f['payload'] ?? [];
        $token = $p['token'] ?? null;
        check(is_string($token) && preg_match('/^[0-9a-f]{64}$/i', $token) === 1, 'map:entered.token 为 64 位 hex');
        $map = $p['map'] ?? null;
        check(is_array($map) && is_string($map['wsAddress'] ?? null) && is_string($map['mapId'] ?? null) && is_string($map['channelId'] ?? null), 'map:entered.map 含 wsAddress/mapId/channelId 三字段');
        $GLOBALS['tokens']['1001-enter'] = (string) $token;
        $channelId = (string) ($map['channelId'] ?? '');

        requestReply('1001', 'map:join', ['mapId' => 'map-1', 'channelId' => $channelId, 'x' => 5, 'y' => 5], reqId(), static function (?array $f2) use ($channelId): void {
            check($f2 !== null && ($f2['type'] ?? null) === 'map:joined', 'map:join → map:joined 回执');
            if ($f2 !== null) {
                $p2 = $f2['payload'] ?? [];
                check(($p2['mapId'] ?? null) === 'map-1' && ($p2['channelId'] ?? null) === $channelId, 'map:joined 回显 mapId/channelId');
            }
            closeStep('PASS', '进图凭证续签（map:enter/map:entered + map:join/map:joined）');
        });
    });
}

/**
* 验收项 3：战斗直连铁律——auth_ok.token 直连 Map consume('map') 五态通过；帧同步 entity_moved 直接从 Map 连接收到（不经社交层转发）。
* Item 3: combat-direct iron rule — auth_ok.token connects Map directly with consume('map') passing the five-state verdict; frame-sync entity_moved arrives on the Map connection (never relayed through the social tier).
*/
function step3MapDirect(): void
{
    $aAuth = $GLOBALS['clients']['1001']['authOk'] ?? null;
    $bAuth = $GLOBALS['clients']['1002']['authOk'] ?? null;
    $aWs = $aAuth['payload']['map']['wsAddress'] ?? null;
    $bWs = $bAuth['payload']['map']['wsAddress'] ?? null;
    check(is_string($aWs), 'A 的 auth_ok.map.wsAddress 可用');
    check($aWs === $bWs, 'A/B 同频（同 Map 地址）');
    if (!is_string($aWs)) {
        closeStep('FAIL', 'A 无 map 地址');

        return;
    }

    openMap('1001', $aWs, $GLOBALS['tokens']['1001'], static function (bool $ok, array $f) use ($aWs): void {
        check($ok, 'A 拿 auth_ok.token 直连 Map，consume(' . "'map'" . ') 五态 → auth_ok（Valid）');
        if (!$ok) {
            closeStep('FAIL', 'A Map auth 失败: ' . json_encode($f['payload'] ?? [], JSON_UNESCAPED_UNICODE));

            return;
        }
        check(is_string($f['payload']['uid'] ?? null) && $f['payload']['uid'] === '1001', 'Map auth_ok.uid == 1001');

        openMap('1002', $aWs, $GLOBALS['tokens']['1002'], static function (bool $ok2, array $f2): void {
            check($ok2, 'B 拿 auth_ok.token 直连 Map → auth_ok');
            if (!$ok2) {
                closeStep('FAIL', 'B Map auth 失败: ' . json_encode($f2['payload'] ?? [], JSON_UNESCAPED_UNICODE));

                return;
            }

            // 等 A 收到 B 的 entity_enter（B 挂载进 A 视野）后让 B 移动，再断言 A 的 Map 连接收到 entity_moved。
            // Wait for A to receive B's entity_enter (B mounted into A's view), then move B and assert A's Map connection gets entity_moved.
            waitFrame(
                $GLOBALS['mapclients']['1001']['inbox'],
                'entity_enter',
                static fn (array $f): bool => str_starts_with((string) ($f['payload']['id'] ?? ''), '1002@'),
                5.0,
                static function (): void {
                    $GLOBALS['mapclients']['1002']['conn']->send(frameMap('move', ['dx' => 1, 'dy' => 0], reqId()));
                    waitFrame(
                        $GLOBALS['mapclients']['1001']['inbox'],
                        'entity_moved',
                        static fn (array $f): bool => str_starts_with((string) ($f['payload']['id'] ?? ''), '1002@'),
                        5.0,
                        static function (): void {
                            check(true, '帧同步 entity_moved 直接从 Map 连接收到');
                            check(
                                !inboxHas($GLOBALS['clients']['1001']['inbox'], 'entity_moved'),
                                'entity_moved 不经社交层转发（A 的 gateway 连接无此帧）',
                            );
                            closeStep('PASS', '战斗直连 + 帧同步不经社交层转发');
                        },
                        static function (): void {
                            closeStep('FAIL', 'A 未从 Map 连接收到 entity_moved');
                        },
                    );
                },
                static function (): void {
                    closeStep('FAIL', 'A 未收到 B 的 entity_enter（同格判定异常）');
                },
            );
        });
    });
}

/**
 * 验收项 4：聊天五语义——world/channel/team/guild/private + 跨频道发言 404 拦截。
 * Item 4: five chat semantics — world/channel/team/guild/private + cross-channel 404 rejection.
 */
function step4Chat(): void
{
    $ops = [];
    $ops[] = static function (callable $next): void {
        requestReply('1001', 'guild:join', ['guildId' => 'guild-a'], reqId(), static function (?array $f) use ($next): void {
            check($f !== null && ($f['type'] ?? null) === 'guild:joined', 'A guild:join → guild:joined');
            $next();
        });
    };
    $ops[] = static function (callable $next): void {
        requestReply('1002', 'guild:join', ['guildId' => 'guild-a'], reqId(), static function (?array $f) use ($next): void {
            check($f !== null && ($f['type'] ?? null) === 'guild:joined', 'B guild:join → guild:joined');
            $next();
        });
    };
    $ops[] = static function (callable $next): void {
        teamRequest('1001', 'team:invite', ['targetUid' => '1002'], static function (array $p) use ($next): void {
            check(is_string($p['teamId'] ?? null), 'A invite B → team:ok 携 teamId');
            $GLOBALS['teamId'] = (string) ($p['teamId'] ?? '');
            if ($GLOBALS['teamId'] !== '') {
                trackTeam($GLOBALS['teamId']);
            }
            waitFrame(
                $GLOBALS['clients']['1002']['inbox'],
                'team:notify',
                static fn (array $f): bool => ($f['payload']['type'] ?? null) === 'invited',
                5.0,
                static function () use ($next): void {
                    check(true, 'B 收到 team:notify(invited)');
                    $next();
                },
                static function () use ($next): void {
                    check(false, 'B 未收到 invited 通知');
                    $next();
                },
            );
        }, static function (string $msg, int $code) use ($next): void {
            check(false, 'A invite 失败: ' . $msg . ' code=' . $code);
            $next();
        });
    };
    $ops[] = static function (callable $next): void {
        teamRequest('1002', 'team:accept', ['teamId' => $GLOBALS['teamId'] ?? ''], static function (array $p) use ($next): void {
            check(true, 'B accept → team:ok');
            waitFrame(
                $GLOBALS['clients']['1001']['inbox'],
                'team:notify',
                static fn (array $f): bool => ($f['payload']['type'] ?? null) === 'joined',
                5.0,
                static function () use ($next): void {
                    check(true, 'A 收到 team:notify(joined)');
                    $next();
                },
                static function () use ($next): void {
                    check(false, 'A 未收到 joined 通知');
                    $next();
                },
            );
        }, static function (string $msg, int $code) use ($next): void {
            check(false, 'B accept 失败: ' . $msg . ' code=' . $code);
            $next();
        });
    };
    // 五语义：world / channel / team / guild / private（A 发 B 收，A 被排除或定向）。
    // Five semantics: world / channel / team / guild / private (A sends, B receives; A is excluded or targeted).
    $ops[] = static fn (callable $next): mixed => expectChatFrom('1001', '1002', ['scope' => 'world', 'content' => 'hello-world'], 'world 聊天：B 收到', $next);
    $ops[] = static fn (callable $next): mixed => expectChatFrom('1001', '1002', ['scope' => 'channel', 'content' => 'hello-channel'], 'channel 聊天：B 收到（同频道组）', $next);
    $ops[] = static fn (callable $next): mixed => expectChatFrom('1001', '1002', ['scope' => 'team', 'content' => 'hello-team'], 'team 聊天：B 收到（队伍组）', $next);
    $ops[] = static fn (callable $next): mixed => expectChatFrom('1001', '1002', ['scope' => 'guild', 'content' => 'hello-guild'], 'guild 聊天：B 收到（帮派组）', $next);
    $ops[] = static fn (callable $next): mixed => expectChatFrom('1001', '1002', ['scope' => 'private', 'targetUid' => '1002', 'content' => 'hello-private'], 'private 聊天：B 收到（定向）', $next);
    // 跨频道发言 404：A 带与 session loc 不符的 channelId 发 channel 发言 → chat:error 404。
    // Cross-channel 404: A sends a channel chat carrying a channelId differing from its session loc → chat:error 404.
    $ops[] = static function (callable $next): void {
        $aChannel = (string) ($GLOBALS['channels']['1001'] ?? '');
        $badChannel = otherChannel($aChannel);
        $requestId = reqId();
        requestReply('1001', 'chat:send', ['scope' => 'channel', 'content' => 'cross', 'channelId' => $badChannel], $requestId, static function (?array $f) use ($next, $badChannel): void {
            $code = $f['payload']['code'] ?? null;
            check($f !== null && ($f['type'] ?? null) === 'chat:error' && $code === 404, '跨频道发言（channelId=' . $badChannel . '）→ chat:error 404');
            $next();
        });
    };

    seqOps($ops, static function (): void {
        closeStep('PASS', '聊天五语义 + 跨频道 404');
    });
}

/**
* 验收项 5：组队状态机——invite/accept/leave/disband 全流程 + 双队防护（双邀请 accept 后 409/404），
* Redis TeamStore 跨进程一致（gateway/chat/team 三角色独立进程共享同一队伍状态）。
* Item 5: team state machine — invite/accept/leave/disband full flow + double-team guard (post-double-accept 409/404),
* cross-process consistent via the Redis TeamStore (the three gateway/chat/team role processes share one team state).
*/
function step5Team(): void
{
    openSocial('1003', 'map-1', static function (bool $ok, array $f): void {
        check($ok, 'D(1003) 登录成功（组队第三席）');
        $GLOBALS['channels']['1003'] = (string) ($f['payload']['map']['channelId'] ?? '');

        $t1 = (string) ($GLOBALS['teamId'] ?? '');
        $ops = [];

        // 全流程：A invite D → D accept → D leave → A disband（A 为队长）。
        // Full flow: A invite D → D accept → D leave → A disband (A is the leader).
        $ops[] = static fn (callable $next): mixed => expectTeamOk(
            '1001',
            'team:invite',
            ['targetUid' => '1003'],
            'invited',
            $GLOBALS['clients']['1003']['inbox'],
            'A invite D → team:ok（T1）',
            $next,
        );
        $ops[] = static fn (callable $next): mixed => expectTeamOk('1003', 'team:accept', ['teamId' => $t1], 'joined', $GLOBALS['clients']['1001']['inbox'], 'D accept T1 → team:ok', $next);
        $ops[] = static fn (callable $next): mixed => expectTeamOk('1003', 'team:leave', ['teamId' => $t1], 'left', $GLOBALS['clients']['1001']['inbox'], 'D leave T1 → team:ok（left）', $next);
        $ops[] = static fn (callable $next): mixed => expectTeamOk('1001', 'team:disband', ['teamId' => $t1], 'disbanded', $GLOBALS['clients']['1002']['inbox'], 'A disband T1 → team:ok（disbanded）', $next);

        // 双队防护：A/D 各建一队邀请 B → B 接第一队 ok → 接第二队 409 already_in_team → 邀请已在队目标 409 target_in_team → 两队解散后 accept 404 team_not_found。
        // Double-team guard: A/D each create a team inviting B → B accepts the first ok → accepts the second 409 already_in_team → inviting an in-team target 409 target_in_team → after both disband, accept 404 team_not_found.
        $ops[] = static function (callable $next): void {
            teamRequest('1001', 'team:invite', ['targetUid' => '1002'], static function (array $p) use ($next): void {
                check(is_string($p['teamId'] ?? null), 'A invite B → 建 T2');
                $GLOBALS['t2'] = (string) ($p['teamId'] ?? '');
                if ($GLOBALS['t2'] !== '') {
                    trackTeam($GLOBALS['t2']);
                }
                $next();
            }, static function (string $msg, int $code) use ($next): void {
                check(false, 'A invite B（建 T2）失败: ' . $msg . ' code=' . $code);
                $next();
            });
        };
        $ops[] = static function (callable $next): void {
            teamRequest('1003', 'team:invite', ['targetUid' => '1002'], static function (array $p) use ($next): void {
                check(is_string($p['teamId'] ?? null), 'D invite B → 建 T3（双邀请就位）');
                $GLOBALS['t3'] = (string) ($p['teamId'] ?? '');
                if ($GLOBALS['t3'] !== '') {
                    trackTeam($GLOBALS['t3']);
                }
                $next();
            }, static function (string $msg, int $code) use ($next): void {
                check(false, 'D invite B（建 T3）失败: ' . $msg . ' code=' . $code);
                $next();
            });
        };
        $ops[] = static fn (callable $next): mixed => expectTeamOk('1002', 'team:accept', ['teamId' => $GLOBALS['t2'] ?? ''], 'joined', $GLOBALS['clients']['1001']['inbox'], 'B accept T2 → team:ok', $next);
        $ops[] = static fn (callable $next): mixed => expectTeamError('1002', 'team:accept', ['teamId' => $GLOBALS['t3'] ?? ''], 409, 'already_in_team', 'B 二次 accept T3 → team:error 409 already_in_team（双队防护）', $next);
        $ops[] = static fn (callable $next): mixed => expectTeamError('1001', 'team:invite', ['targetUid' => '1002'], 409, 'target_in_team', 'A invite 已在队 B → team:error 409 target_in_team（入队拦截）', $next);
        $ops[] = static fn (callable $next): mixed => expectTeamOk('1003', 'team:disband', ['teamId' => $GLOBALS['t3'] ?? ''], null, $GLOBALS['clients']['1003']['inbox'], 'D disband T3 → team:ok', $next);
        $ops[] = static fn (callable $next): mixed => expectTeamOk('1001', 'team:disband', ['teamId' => $GLOBALS['t2'] ?? ''], null, $GLOBALS['clients']['1001']['inbox'], 'A disband T2 → team:ok', $next);
        $ops[] = static fn (callable $next): mixed => expectTeamError('1002', 'team:accept', ['teamId' => $GLOBALS['t3'] ?? ''], 404, 'team_not_found', 'B accept 已解散 T3 → team:error 404 team_not_found', $next);

        // 重建 T4（供 step 6 掉线重连恢复验收）。
        // Rebuild T4 (for step 6's drop-reconnect recovery acceptance).
        $ops[] = static function (callable $next): void {
            teamRequest('1001', 'team:invite', ['targetUid' => '1002'], static function (array $p) use ($next): void {
                $GLOBALS['teamId'] = (string) ($p['teamId'] ?? '');
                if ($GLOBALS['teamId'] !== '') {
                    trackTeam($GLOBALS['teamId']);
                }
                check(is_string($p['teamId'] ?? null), '重建 A invite B → T4');
                $next();
            }, static function (string $msg, int $code) use ($next): void {
                check(false, '重建 invite 失败: ' . $msg . ' code=' . $code);
                $next();
            });
        };
        $ops[] = static fn (callable $next): mixed => expectTeamOk('1002', 'team:accept', ['teamId' => $GLOBALS['teamId'] ?? ''], 'joined', $GLOBALS['clients']['1001']['inbox'], 'B accept T4 → team:ok（恢复准备就绪）', $next);

        seqOps($ops, static function (): void {
            closeStep('PASS', '组队全流程 + 双队防护（409/404）');
        });
    });
}

/**
 * 验收项 6：掉线重连恢复——断 gateway 连接 → 重连 auth → 恢复队伍/帮派/频道分组。
 * Item 6: drop-reconnect recovery — drop the gateway connection → re-auth → team/guild/channel groups recovered.
 */
function step6Recover(): void
{
    $t4 = (string) ($GLOBALS['teamId'] ?? '');
    $oldChannel = (string) ($GLOBALS['channels']['1001'] ?? '');
    check($t4 !== '' && $oldChannel !== '', '前置：A 有队伍 T4 与频道快照');

    // 关 A 的连接，等待掉线标记落库后重连。
    // Close A's connection, wait for the offline marker to land, then reconnect.
    closeSocial('1001');
    pollRedis(
        static fn (): bool => vRedis()->exists('nythros:gw:offline:1001') > 0,
        5.0,
        static function () use ($t4, $oldChannel): void {
            check(true, 'A 掉线标记已写（nythros:gw:offline:1001）');
            openSocial('1001', 'map-1', static function (bool $ok, array $f) use ($t4, $oldChannel): void {
                check($ok, 'A 重连 auth 成功');
                if (!$ok) {
                    closeStep('FAIL', 'A 重连失败: ' . json_encode($f['payload'] ?? [], JSON_UNESCAPED_UNICODE));

                    return;
                }
                $p = $f['payload'] ?? [];
                check(is_string($p['uid'] ?? null) && $p['uid'] === '1001', '重连 auth_ok.uid == 1001');
                $team = $p['team'] ?? null;
                check(is_array($team) && ($team['teamId'] ?? null) === $t4, 'auth_ok.team.teamId == T4（队伍恢复）');
                $members = is_array($team) ? ($team['members'] ?? []) : [];
                check(is_array($members) && in_array('1001', $members, true) && in_array('1002', $members, true), 'auth_ok.team.members 含 1001/1002（队伍成员恢复）');
                $guild = $p['guild'] ?? null;
                check(is_array($guild) && ($guild['guildId'] ?? null) === 'guild-a', 'auth_ok.guild.guildId == guild-a（帮派恢复）');
                $map = $p['map'] ?? null;
                check(is_array($map) && ($map['channelId'] ?? null) === $oldChannel, 'auth_ok.map.channelId 恢复原频道（' . $oldChannel . '）');
                $GLOBALS['channels']['1001'] = (string) ($map['channelId'] ?? '');
                // 分组恢复的最终证明：B 发 team 聊天，A 的新连接收到（A 已重新入队组）。
                // The final proof of group recovery: B sends a team chat and A's new connection receives it (A rejoined the team group).
                expectChatFrom('1002', '1001', ['scope' => 'team', 'content' => 'recover-team'], '恢复后 A 收到队伍聊天（team 组恢复）', static function (): void {
                    closeStep('PASS', '队伍/帮派/频道分组恢复');
                });
            });
        },
        static function (): void {
            closeStep('FAIL', 'A 掉线标记未在 5s 内写入');
        },
    );
}

/**
 * 验收项 7：滚动更新——map-rolling.php mark-stopping 旧频道 → 新玩家不再分到旧频道 → 重连自迁移新 channelId。
 * Item 7: rolling update — map-rolling.php mark-stopping the old channel → new players skip it → reconnect self-migrates to the new channelId.
 */
function step7Rolling(): void
{
    $oldChannel = (string) ($GLOBALS['channels']['1001'] ?? '');
    $svcId = 'map-1#' . $oldChannel;
    check($oldChannel !== '', '前置：A 有明确频道（' . $oldChannel . '）');

    // ① mark-stopping 旧频道（经 map-rolling.php，忠实于运维流程）。
    // ① mark-stopping the old channel (via map-rolling.php, faithful to the ops flow).
    $result = runMapRolling('mark-stopping', $svcId);
    check($result['code'] === 0, 'map-rolling.php mark-stopping ' . $svcId . ' 执行成功');
    $instance = vRegistry()->discover('map')[$svcId] ?? null;
    check($instance !== null && ($instance->meta['status'] ?? null) === 'stopping', $svcId . ' meta.status == stopping（旧频道标记成功）');

    // ② 新玩家（D 清空离线/位置后全新登录）不再分配到旧频道。
    // ② A new player (D with offline/location cleared = fresh login) is no longer assigned the old channel.
    closeSocial('1003');
    vRedis()->del('nythros:gw:offline:1003', 'nythros:gw:location:1003');
    openSocial('1003', 'map-1', static function (bool $ok, array $f) use ($oldChannel, $svcId): void {
        check($ok, 'D(1003) 全新登录成功');
        $dChannel = (string) ($f['payload']['map']['channelId'] ?? '');
        $GLOBALS['channels']['1003'] = $dChannel;
        check($dChannel === otherChannel($oldChannel), '新玩家 D 分配到 ' . $dChannel . '（避开停止中的 ' . $oldChannel . '）');

        // ③ A 重连自迁移：位置快照指向旧频道（stopping）→ 最少在线落到新频道。
        // ③ A reconnect self-migration: the location snapshot points to the old channel (stopping) → least-loaded falls to the new channel.
        closeSocial('1001');
        pollRedis(
            static fn (): bool => vRedis()->exists('nythros:gw:offline:1001') > 0,
            5.0,
            static function () use ($oldChannel, $svcId): void {
                openSocial('1001', 'map-1', static function (bool $ok, array $f) use ($oldChannel, $svcId): void {
                    check($ok, 'A 重连成功（滚动更新强制重连）');
                    $newChannel = (string) ($f['payload']['map']['channelId'] ?? '');
                    check($newChannel === otherChannel($oldChannel), 'A 自迁移到新频道 ' . $newChannel . '（原 ' . $oldChannel . ' 已 stopping）');
                    $GLOBALS['channels']['1001'] = $newChannel;
                    // ④ 恢复旧频道 serving（收尾，防残留）。
                    // ④ Restore the old channel to serving (cleanup, avoid residue).
                    vRegistry()->heartbeat('map', $svcId, ['status' => 'serving']);
                    closeStep('PASS', '旧频道停止分配 + 重连自迁移新频道');
                });
            },
            static function (): void {
                closeStep('FAIL', 'A 掉线标记未在 5s 内写入');
            },
        );
    });
}

/**
 * 验收项 8：token 多 scope 单向——auth token 签 [map,chat,team] 且各 scope per-scope 墓碑防重放
 * （map 直连二次 consume('map') = Replayed；chat/team 角色同 token 各消费一次 Valid、重复登录 Replayed）；
 * map:enter 续签 token 仍只签 ['map'] + 首次 Valid / 二次 Replayed。
 * Item 8: multi-scope one-way tokens — the auth token issues [map,chat,team] with per-scope tombstones preventing
 * replay (a second direct-Map consume('map') reads Replayed; the chat/team roles each consume the same token once
 * as Valid and a repeated token login reads Replayed); the map:enter renewal still issues ['map'] only, first
 * consume Valid / second Replayed.
 */
function step8TokenOneWay(): void
{
    // 全新登录取 auth token（1003 复用，单点登录会踢旧连接——step 7 后 D 已无其它用途）。
    // A fresh login grabs the auth token (reusing 1003; single sign-on kicks the old connection — D has no further role after step 7).
    openSocial('1003', 'map-1', static function (bool $ok, array $f): void {
        check($ok, 'D(1003) 登录取 auth token');
        if (!$ok) {
            closeStep('FAIL', 'D 登录失败');

            return;
        }
        $authToken = (string) ($f['payload']['token'] ?? '');
        $scopes = vTokens()->peek($authToken)?->scopes ?? [];
        check($scopes === ['map', 'chat', 'team'], 'auth token 签 scope=["map","chat","team"]（多 scope，ADR-021 §3.2）');
        $chatWs = (string) ($f['payload']['endpoints']['chat']['wsAddress'] ?? '');
        $teamWs = (string) ($f['payload']['endpoints']['team']['wsAddress'] ?? '');

        // auth token：首次 consume('map') Valid → 二次 consume Replayed（per-scope 墓碑）。
        // auth token: first consume('map') Valid → second consume Replayed (per-scope tombstone).
        mapAuthOnce(MAP_1_CH1_WS, $authToken, static function (bool $ok1, array $f1) use ($authToken, $chatWs, $teamWs): void {
            check($ok1, 'auth token 首次 consume(' . "'map'" . ') → Valid（Map auth_ok）');
            mapAuthOnce(MAP_1_CH1_WS, $authToken, static function (bool $ok2, array $f2) use ($authToken, $chatWs, $teamWs): void {
                check(!$ok2 && ($f2['payload']['reason'] ?? null) === 'replayed', 'auth token 二次 consume(' . "'map'" . ') → Replayed（auth_failed reason=replayed）');

                // chat/team 角色：同 token 各消费一次 Valid → 重复 token 登录被墓碑拒绝 Replayed。
                // The chat/team roles: each consumes the same token once as Valid → a repeated token login is rejected by the tombstone as Replayed.
                socialTokenAuthOnce($chatWs, $authToken, static function (bool $okChat1, array $fChat1) use ($authToken, $chatWs, $teamWs): void {
                    check($okChat1, 'auth token 首次 consume(' . "'chat'" . ') → Valid（chat 角色 auth_ok）');
                    socialTokenAuthOnce($chatWs, $authToken, static function (bool $okChat2, array $fChat2) use ($authToken, $teamWs): void {
                        check(!$okChat2 && ($fChat2['payload']['reason'] ?? null) === 'replayed', 'auth token 二次 consume(' . "'chat'" . ') → Replayed（chat 墓碑防重放）');
                        socialTokenAuthOnce($teamWs, $authToken, static function (bool $okTeam1, array $fTeam1) use ($authToken, $teamWs): void {
                            check($okTeam1, 'auth token 首次 consume(' . "'team'" . ') → Valid（team 角色 auth_ok）');
                            socialTokenAuthOnce($teamWs, $authToken, static function (bool $okTeam2, array $fTeam2): void {
                                check(!$okTeam2 && ($fTeam2['payload']['reason'] ?? null) === 'replayed', 'auth token 二次 consume(' . "'team'" . ') → Replayed（team 墓碑防重放）');

                                // map:enter 续签 token：仍只签 ['map'] + 首次 Valid / 二次 Replayed。
                                // map:enter renewal token: still only ['map'] + first Valid / second Replayed.
                                requestReply('1003', 'map:enter', ['mapId' => 'map-1'], reqId(), static function (?array $fe): void {
                                    check($fe !== null && ($fe['type'] ?? null) === 'map:entered', 'map:enter → map:entered（取续签 token）');
                                    if ($fe === null) {
                                        closeStep('FAIL', 'map:entered 超时');

                                        return;
                                    }
                                    $enterToken = (string) ($fe['payload']['token'] ?? '');
                                    $enterScopes = vTokens()->peek($enterToken)?->scopes ?? [];
                                    check($enterScopes === ['map'], 'map:enter token 只签 scope=["map"]');
                                    mapAuthOnce(MAP_1_CH1_WS, $enterToken, static function (bool $ok3, array $f3) use ($enterToken): void {
                                        check($ok3, 'map:enter token 首次 consume(' . "'map'" . ') → Valid');
                                        mapAuthOnce(MAP_1_CH1_WS, $enterToken, static function (bool $ok4, array $f4): void {
                                            check(!$ok4 && ($f4['payload']['reason'] ?? null) === 'replayed', 'map:enter token 二次 consume(' . "'map'" . ') → Replayed');
                                            closeStep('PASS', 'auth token 多 scope 单向 [map,chat,team]（per-scope 墓碑）+ map:enter 只签 [map]');
                                        });
                                    });
                                });
                            });
                        });
                    });
                });
            });
        });
    });
}

/**
 * 断言社交语义回执（friend:ok/guild:ok 等）或错误帧的 code/message 匹配。
 * Asserts a social-semantics receipt (friend:ok/guild:ok etc.) or an error frame's matching code/message.
 *
 * @param callable(?array<string, mixed>): void $onDone 完成回调 Done callback.
 */
function expectSocialReply(string $uid, string $type, array $payload, string $okType, ?int $errCode, ?string $errMsg, string $label, callable $onDone): void
{
    $requestId = reqId();
    requestReply($uid, $type, $payload, $requestId, static function (?array $f) use ($label, $okType, $errCode, $errMsg, $onDone): void {
        if ($f === null) {
            check(false, $label . '：超时无回执');
            $onDone();

            return;
        }
        $t = $f['type'] ?? null;
        if ($t === $okType) {
            check(true, $label);
            $onDone();

            return;
        }
        $code = $f['payload']['code'] ?? null;
        $message = $f['payload']['message'] ?? null;
        $match = $errCode !== null && $code === $errCode && $message === $errMsg;
        check($match, $label . sprintf('（实际 %s code=%s msg=%s，预期 %s 或 %d %s）', (string) $t, (string) $code, (string) $message, $okType, (int) $errCode, (string) $errMsg));
        $onDone();
    });
}

/**
 * 等待某收件箱出现指定类型的通知帧（5s 粒度 0.2s）。
 * Waits for a notification frame of the given type in an inbox (5s at 0.2s granularity).
 *
 * @param array<int, array<string, mixed>> $inbox 收件箱（引用） Inbox (by reference).
 */
function expectNotify(array &$inbox, string $notifyType, string $label, callable $done): void
{
    waitFrame(
        $inbox,
        null,
        static fn (array $f): bool => str_ends_with((string) ($f['type'] ?? ''), ':notify')
            && ($f['payload']['type'] ?? null) === $notifyType,
        5.0,
        static function () use ($label, $done): void {
            check(true, $label);
            $done();
        },
        static function () use ($label, $done): void {
            check(false, $label . '：未收到通知');
            $done();
        },
    );
}

/**
 * 验收项 9：好友全流程——申请（在线通知）/重复申请 409/自邀 400/同意（双向一致）/列表/删除（双向清空 + 通知）。
 * Item 9: the full friend flow — apply (online notification) / duplicate 409 / self 400 / accept (bidirectional
 * consistency) / list / remove (both sides cleared + notification).
 */
function step9Friends(): void
{
    $ops = [];
    // 申请 + 在线通知 Online application notification
    $ops[] = static function (callable $next): void {
        expectSocialReply('1001', 'friend:apply', ['targetUid' => '1002'], 'friend:ok', null, null, 'A apply B → friend:ok', static function () use ($next): void {
            expectNotify($GLOBALS['clients']['1002']['inbox'], 'applied', 'B 收到 friend:notify(applied)', $next);
        });
    };
    // 重复申请 409 Duplicate 409
    $ops[] = static fn (callable $next): mixed => expectSocialReply('1001', 'friend:apply', ['targetUid' => '1002'], 'friend:error', 409, 'request_exists', 'A 重复申请 → friend:error 409 request_exists', $next);
    // 自邀 400 Self 400
    $ops[] = static fn (callable $next): mixed => expectSocialReply('1001', 'friend:apply', ['targetUid' => '1001'], 'friend:error', 400, 'self_not_allowed', 'A 自邀 → friend:error 400 self_not_allowed', $next);
    // 同意 + 通知 Accept + notification
    $ops[] = static function (callable $next): void {
        expectSocialReply('1002', 'friend:accept', ['targetUid' => '1001'], 'friend:ok', null, null, 'B accept A → friend:ok', static function () use ($next): void {
            expectNotify($GLOBALS['clients']['1001']['inbox'], 'accepted', 'A 收到 friend:notify(accepted)', $next);
        });
    };
    // 双向一致性 Bidirectional consistency
    $ops[] = static function (callable $next): void {
        requestReply('1001', 'friend:list', [], reqId(), static function (?array $fa) use ($next): void {
            $aHas = is_array($fa['payload']['uids'] ?? null) && in_array('1002', $fa['payload']['uids'], true);
            requestReply('1002', 'friend:list', [], reqId(), static function (?array $fb) use ($aHas, $next): void {
                $bHas = is_array($fb['payload']['uids'] ?? null) && in_array('1001', $fb['payload']['uids'], true);
                check($aHas && $bHas, '双向一致：A 列表含 B 且 B 列表含 A');
                $next();
            });
        });
    };
    // 删除（双向清空 + 通知）Remove (both sides + notification)
    $ops[] = static function (callable $next): void {
        expectSocialReply('1002', 'friend:remove', ['targetUid' => '1001'], 'friend:ok', null, null, 'B remove A → friend:ok', static function () use ($next): void {
            expectNotify($GLOBALS['clients']['1001']['inbox'], 'removed', 'A 收到 friend:notify(removed)', static function () use ($next): void {
                requestReply('1001', 'friend:list', [], reqId(), static function (?array $fa) use ($next): void {
                    check(is_array($fa['payload']['uids'] ?? null) && $fa['payload']['uids'] === [], '删除后 A 列表为空（双向清空）');
                    $next();
                });
            });
        });
    };

    seqOps($ops, static function (): void {
        closeStep('PASS', '好友申请/同意/删除/列表 + 双向一致 + 在线通知');
    });
}

/**
 * 验收项 10：公会正式化——建会/入会申请/审批/任命/公告/权限矩阵抽查（成员解散 403、官员踢人）/踢人/解散清场。
 * Item 10: guild formalization — create / applications / approval / promotion / notice / permission-matrix spot
 * checks (member disband 403, officer kick) / kick / disband cleanup.
 */
function step10Guild(): void
{
    $ops = [];
    // 退出 step4 的 legacy guild-a，避免换帮拦截 Leave step4's legacy guild-a to avoid the guild-switch guard
    $ops[] = static fn (callable $next): mixed => expectSocialReply('1001', 'guild:leave', ['guildId' => 'guild-a'], 'guild:left', null, null, 'A 退出 legacy guild-a', $next);
    $ops[] = static fn (callable $next): mixed => expectSocialReply('1002', 'guild:leave', ['guildId' => 'guild-a'], 'guild:left', null, null, 'B 退出 legacy guild-a', $next);

    // 建会 Create
    $ops[] = static fn (callable $next): mixed => expectSocialReply('1001', 'guild:create', ['guildId' => 'guild-r3', 'name' => 'R3公会', 'maxMembers' => 3], 'guild:ok', null, null, 'A create guild-r3 → guild:ok', $next);
    // 入会申请 Apply
    $ops[] = static fn (callable $next): mixed => expectSocialReply('1002', 'guild:apply', ['guildId' => 'guild-r3'], 'guild:ok', null, null, 'B apply guild-r3 → guild:ok', $next);
    $ops[] = static fn (callable $next): mixed => expectSocialReply('1003', 'guild:apply', ['guildId' => 'guild-r3'], 'guild:ok', null, null, 'D apply guild-r3 → guild:ok', $next);
    // 非成员公告 403（权限矩阵抽查：非成员 × 公告）Non-member notice 403 (matrix spot check)
    $ops[] = static fn (callable $next): mixed => expectSocialReply('1002', 'guild:notice', ['guildId' => 'guild-r3', 'notice' => 'x'], 'guild:error', 403, 'not_member', 'B(非成员) 公告 → guild:error 403 not_member', $next);
    // 审批收编 Approval admits
    $ops[] = static function (callable $next): void {
        expectSocialReply('1001', 'guild:approve', ['guildId' => 'guild-r3', 'targetUid' => '1002', 'accept' => true], 'guild:ok', null, null, 'A approve B → guild:ok', static function () use ($next): void {
            expectNotify($GLOBALS['clients']['1002']['inbox'], 'approved', 'B 收到 guild:notify(approved)', $next);
        });
    };
    $ops[] = static fn (callable $next): mixed => expectSocialReply('1001', 'guild:approve', ['guildId' => 'guild-r3', 'targetUid' => '1003', 'accept' => true], 'guild:ok', null, null, 'A approve D → guild:ok', $next);
    // 任命官员 Promote officer
    $ops[] = static function (callable $next): void {
        expectSocialReply('1001', 'guild:promote', ['guildId' => 'guild-r3', 'targetUid' => '1002', 'role' => 'officer'], 'guild:ok', null, null, 'A promote B officer → guild:ok', static function () use ($next): void {
            expectNotify($GLOBALS['clients']['1002']['inbox'], 'promoted', 'B 收到 guild:notify(promoted)', $next);
        });
    };
    // 公告广播 Notice broadcast
    $ops[] = static function (callable $next): void {
        expectSocialReply('1001', 'guild:notice', ['guildId' => 'guild-r3', 'notice' => '今晚攻城'], 'guild:ok', null, null, 'A 公告 → guild:ok', static function () use ($next): void {
            expectNotify($GLOBALS['clients']['1002']['inbox'], 'notice', 'B 收到 guild:notify(notice)', $next);
        });
    };
    // 成员解散 403（权限矩阵抽查：成员 × 解散）Member disband 403 (matrix spot check)
    $ops[] = static fn (callable $next): mixed => expectSocialReply('1003', 'guild:disband', ['guildId' => 'guild-r3'], 'guild:error', 403, 'permission_denied', 'D(成员) 解散 → guild:error 403 permission_denied', $next);
    // 官员踢成员（低阶位）Officer kicks member (lower rank)
    $ops[] = static function (callable $next): void {
        expectSocialReply('1002', 'guild:kick', ['guildId' => 'guild-r3', 'targetUid' => '1003'], 'guild:ok', null, null, 'B(官员) kick D → guild:ok', static function () use ($next): void {
            expectNotify($GLOBALS['clients']['1003']['inbox'], 'kicked', 'D 收到 guild:notify(kicked)', $next);
        });
    };
    // 解散清场 Disband cleanup
    $ops[] = static function (callable $next): void {
        expectSocialReply('1001', 'guild:disband', ['guildId' => 'guild-r3'], 'guild:ok', null, null, 'A disband guild-r3 → guild:ok', static function () use ($next): void {
            expectNotify($GLOBALS['clients']['1002']['inbox'], 'disbanded', 'B 收到 guild:notify(disbanded)', $next);
        });
    };

    seqOps($ops, static function (): void {
        closeStep('PASS', '建会/审批/任命/公告/踢人/解散 + 权限矩阵抽查（403）');
    });
}

/**
 * 验收项 11：排行榜查询帧——top N 分页（平行列表 ranks/uids/scores）+ 单 uid 排名。
 * 写入口径为服务端内部（业务上报/定时聚合），脚本直接以 Redis ZADD 种子后走真实查询链路。
 * Item 11: leaderboard query frames — top-N pagination (parallel ranks/uids/scores lists) plus a single uid's rank.
 * Writes are server-internal (business reporting / scheduled aggregation); the script seeds via Redis ZADD directly
 * then rides the real query path.
 */
function step11Leaderboard(): void
{
    vRedis()->zAdd('nythros:lb:board:r3-level', 300, '1002', 200, '1001', 250, '1003');

    $ops = [];
    $ops[] = static function (callable $next): void {
        requestReply('1001', 'leaderboard:top', ['boardId' => 'r3-level', 'n' => 2], reqId(), static function (?array $f) use ($next): void {
            $p = $f['payload'] ?? [];
            $rowsOk = ($p['ranks'] ?? null) === [1, 2]
                && ($p['uids'] ?? null) === ['1002', '1003']
                && ($p['scores'] ?? null) == [300, 250];
            check($f !== null && ($f['type'] ?? null) === 'leaderboard:rows' && $rowsOk, 'leaderboard:top n=2 → rows 平行列表 [1,2]/[1002,1003]/[300,250]');
            $next();
        });
    };
    $ops[] = static function (callable $next): void {
        requestReply('1001', 'leaderboard:rank', ['boardId' => 'r3-level'], reqId(), static function (?array $f) use ($next): void {
            $p = $f['payload'] ?? [];
            $rankOk = ($p['uid'] ?? null) === '1001'
                && ($p['rank'] ?? null) === 3
                && (float) ($p['score'] ?? 0) === 200.0;
            check($f !== null && ($f['type'] ?? null) === 'leaderboard:ranked' && $rankOk, 'leaderboard:rank → ranked uid=1001 rank=3 score=200');
            $next();
        });
    };

    seqOps($ops, static function (): void {
        closeStep('PASS', '榜单 top N 分页 + 单 uid 排名');
    });
}

// 验收步骤注册表（顺序执行；每步独立超时）。
// The acceptance step registry (sequential execution; per-step timeout).
$GLOBALS['verify']['steps'] = [
    ['1. 登录链路（auth_ok 五字段 + endpoints + chat/team token 消费登录）', 'step1Login', 40.0],
    ['2. 进图凭证（map:enter → map:entered；map:join → map:joined）', 'step2MapCredential', 30.0],
    ['3. 战斗直连铁律（consume(map) 五态通过 + 帧同步不经社交层转发）', 'step3MapDirect', 30.0],
    ['4. 聊天五语义（world/channel/team/guild/private + 跨频道 404）', 'step4Chat', 60.0],
    ['5. 组队状态机（invite/accept/leave/disband + 双队防护 409/404）', 'step5Team', 90.0],
    ['6. 掉线重连恢复（队伍/帮派/频道分组恢复）', 'step6Recover', 40.0],
    ['7. 滚动更新（mark-stopping 旧频道 → 新玩家避开 → 重连自迁移）', 'step7Rolling', 60.0],
    ['8. token 多 scope 单向（auth=[map,chat,team] per-scope 墓碑 + map:enter=[map]）', 'step8TokenOneWay', 60.0],
    ['9. 好友全流程（申请/同意/删除/列表 + 双向一致 + 在线通知）', 'step9Friends', 60.0],
    ['10. 公会正式化（建会/审批/任命/公告/踢人/解散 + 权限矩阵抽查）', 'step10Guild', 90.0],
    ['11. 排行榜查询帧（top N 分页 + 单 uid 排名）', 'step11Leaderboard', 30.0],
];

$worker = new Worker();
$worker->onWorkerStart = static function (): void {
    echo "[verify] 阶段 5 社交层端到端验收启动（组⑦，ADR-015 §7-8 + R3 社交批扩展）\n";
    // 全局看门狗：420s 未完成强制收尾。
    // Global watchdog: force the summary after 420s.
    Timer::add(420.0, static function (): void {
        echo "[verify] WATCHDOG: 全局超时\n";
        finishAll();
    }, [], false);
    nextStep();
};

// Workerman 5.2 要求 argv 中显式含自身命令（start/stop/...）：本脚本无自定义参数，直接注入 start（前台 DEBUG 模式）。
// Workerman 5.2 requires an explicit own command (start/stop/...) in argv: this script takes no custom args, so inject start (foreground DEBUG mode).
$GLOBALS['argv'] = [$argv[0], 'start'];

Worker::runAll();
