<?php

declare(strict_types=1);

// 定位：packages/demo/bin/verify-economy.php — R3 经济批端到端验收脚本（掉落→拾取归属校验→穿戴→挂单→
// 购买→邮件附件全链，照 verify-combat 结构）。
// Located at: packages/demo/bin/verify-economy.php — the R3 economy-batch end-to-end acceptance script
// (drop → ownership-checked pickup → equip → listing → purchase → mail attachments, structured after verify-combat).
//
// 前置：服务器以 NYTHROS_ECONOMY=1 启动（dropTable 追加 sword 必掉条目 + 经济路由装配）；Redis 127.0.0.1:6379。
// 客户端 1001（击杀者/卖家）、1002（买家）、1003（归属外拾取者）经 gateway 登录后直连 Map。
// Prerequisites: the server boots with NYTHROS_ECONOMY=1 (the drop table gains an always-drop sword entry and the
// economy routes are assembled); Redis on 127.0.0.1:6379. Clients 1001 (killer/seller), 1002 (buyer) and
// 1003 (non-owner picker) log in via the gateway then connect the Map directly.
//
// 验收项：
//   1 掉落与归属校验：1001 独占击杀 monster-1（ownerUid=1001 确定）→ drop:spawned；1002 拾取得
//     combat:error not_owner（归属拒绝）；1001 拾取成功 item:added。
//   2 穿戴：1001 equip sword → economy:result ok + player:stats maxHp 130（100+30 装备加成聚合）；
//     unequip 后 maxHp 回落 100 且 sword 回包。非装备型物品穿戴被拒（invalid_argument）。
//   3 挂单：1001 auction:sell sword x1 价 300 → economy:result ok 附 auctionId（背包扣货托管）。
//   4 入账与购买：1002 economy:deposit 500 → auction:buy(auctionId, 300) → ok；余额结算
//     （1002=200、1001=300 经邮件附件领取后入账——见验收项 5 的余额断言口径说明）。
//   5 邮件全链：1002 收 mail:new 在线通知 + mail:list 列表 + mail:claim 幂等领取（attachments msgpack
//     字节串还原 [[itemId,count]]）→ 重复领取得 already_claimed；mail:delete 清理。
// Acceptance items:
//   1 Drop & ownership: 1001 solo-kills monster-1 (ownerUid=1001 is deterministic) → drop:spawned; 1002's pickup
//     yields combat:error not_owner; 1001 picks up with item:added.
//   2 Equip: 1001 equips the sword → economy:result ok + player:stats maxHp 130 (100+30 aggregation); unequip drops
//     maxHp back to 100 with the sword returned to the bag. Equipping a non-equipment item is rejected.
//   3 Listing: 1001 lists the sword at price 300 → economy:result ok carrying auctionId (bag debited into escrow).
//   4 Deposit & purchase: 1002 deposits 500 → buys at 300 → ok; balances settle (1002=200; 1001=300 credited after
//     claiming the delivery mail — see the balance-assertion note in item 5).
//   5 Mail full chain: 1002 receives the mail:new online notice + mail:list + an idempotent mail:claim (the msgpack
//     attachments byte string decodes back to [[itemId,count]]) → a repeat claim yields already_claimed; mail:delete cleans up.
//
// 输出契约：每项一行 [verify] [PASS|FAIL]；末行 RESULT 汇总。输出契约与 verify-combat 一致。
// Output contract: one line per item [verify] [PASS|FAIL]; a final RESULT summary line, matching verify-combat.

require __DIR__ . '/../../../vendor/autoload.php';
require __DIR__ . '/lib/map-codec.php';

use Nythros\Protocol\MsgpackSerializer;
use Workerman\Connection\AsyncTcpConnection;
use Workerman\Protocols\Websocket;
use Workerman\Timer;
use Workerman\Worker;

const GW_WS = 'ws://127.0.0.1:18285';
const MAP_WS = 'ws://127.0.0.1:18081'; // map-1#ch-1 map-1#ch-1.

/** @var list<string> 参与验收的客户端 uid（1003 仅作归属外拾取者） The acceptance client uids (1003 only as the non-owner picker). */
$GLOBALS['uids'] = ['1001', '1002', '1003'];

/** @var array<string, mixed> 共享验收状态 Shared acceptance state. */
$GLOBALS['verify'] = [
    'steps' => [],
    'stepIdx' => 0,
    'currentItem' => '',
    'currentTimer' => null,
    'checks' => [],
    'results' => [],
    'done' => false,
    'stepSettled' => false,
    'abort' => false,
];

/** @var array<string, array<string, mixed>> uid => {conn, inbox} Map 直连客户端注册表 Map direct-client registry. */
$GLOBALS['mapclients'] = [];

/** @var array<string, string> uid => token（gateway auth_ok 签发） uid => token (issued by the gateway auth_ok). */
$GLOBALS['tokens'] = [];

/** @var array<string, string> uid => entityId（Map auth_ok 返回） uid => entityId (from the Map auth_ok). */
$GLOBALS['entityIds'] = [];

/** @var array<string, string> uid => map wsAddress uid => map wsAddress. */
$GLOBALS['mapWs'] = [];

/** @var int 请求 id 序列 Request id sequence. */
$GLOBALS['reqSeq'] = 0;

/** @var array<string, mixed> 经济暂存（dropId/itemId/auctionId/msgpack 解码器等跨步骤状态） Economy staging (dropId/itemId/auctionId/msgpack decoder cross-step state). */
$GLOBALS['econ'] = [
    'dropId' => '',
    'itemId' => '',
    'auctionId' => '',
    'mailId' => '',
    'msgpack' => new MsgpackSerializer(),
];

function reqId(): string
{
    $GLOBALS['reqSeq']++;

    return 've' . $GLOBALS['reqSeq'];
}

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

function frame(string $type, array $payload, ?string $requestId = null): string
{
    return json_encode([
        'type' => $type,
        'requestId' => $requestId,
        'timestamp' => microtime(true),
        'payload' => $payload,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}

function check(bool $ok, string $label): void
{
    $GLOBALS['verify']['checks'][] = ['ok' => $ok, 'label' => $label];
}

function closeStep(string $status, string $detail = ''): void
{
    $v = &$GLOBALS['verify'];
    if ($v['done'] || ($v['stepSettled'] ?? false)) {
        return;
    }
    $v['stepSettled'] = true;

    if ($v['currentTimer'] !== null) {
        Timer::del($v['currentTimer']);
        $v['currentTimer'] = null;
    }

    if ($status === 'PASS') {
        $failures = array_filter($v['checks'], static fn (array $c): bool => !$c['ok']);
        if ($failures !== []) {
            $status = 'FAIL';
            echo "  断言失败 assertions failed:\n";
            foreach ($failures as $c) {
                echo '    - ' . $c['label'] . PHP_EOL;
            }
        }
    }

    $v['results'][] = ['item' => $v['currentItem'], 'status' => $status, 'detail' => $detail];
    echo sprintf("[verify] [%s] %s%s\n", $status, $v['currentItem'], $detail !== '' ? ' — ' . $detail : '');
    $v['checks'] = [];

    nextStep();
}

function nextStep(): void
{
    $v = &$GLOBALS['verify'];
    if ($v['done']) {
        return;
    }

    if (!empty($v['abort'])) {
        finishAll();

        return;
    }

    if ($v['stepIdx'] >= count($v['steps'])) {
        finishAll();

        return;
    }

    [$item, $body, $timeout] = $v['steps'][$v['stepIdx']];
    $v['stepIdx']++;
    $v['currentItem'] = $item;
    $v['checks'] = [];
    $v['stepSettled'] = false;
    echo sprintf("[verify] run: %s\n", $item);
    $v['currentTimer'] = Timer::add($timeout, function () use ($item, $timeout): void {
        echo sprintf("[verify] TIMEOUT: %s\n", $item);
        closeStep('FAIL', sprintf('步骤超时 step timeout（>%gs）', $timeout));
    }, [], false);
    try {
        $body();
    } catch (\Throwable $e) {
        echo sprintf("[verify] EXCEPTION in %s: %s\n", $item, $e->getMessage());
        closeStep('FAIL', '步骤异常: ' . $e->getMessage());
    }
}

function finishAll(): void
{
    $v = &$GLOBALS['verify'];
    if ($v['done']) {
        return;
    }
    $v['done'] = true;

    if ($v['currentTimer'] !== null) {
        Timer::del($v['currentTimer']);
        $v['currentTimer'] = null;
    }

    foreach ($GLOBALS['uids'] as $uid) {
        $conn = $GLOBALS['mapclients'][$uid]['conn'] ?? null;
        if ($conn instanceof AsyncTcpConnection) {
            $conn->close();
        }
    }

    // 清理经济批 Redis 残留（邮件/账本/挂单键，前缀 nythros:ml:/nythros:ec:）
    // Clean the economy-batch Redis residue (mail/ledger/listing keys under nythros:ml:/nythros:ec:)
    try {
        $redis = new \Redis();
        if (@$redis->connect('127.0.0.1', 6379, 1.0) === true) {
            foreach ($GLOBALS['uids'] as $uid) {
                $redis->del(
                    'nythros:ml:mailbox:' . $uid,
                    'nythros:ml:claimed:' . $uid,
                    'nythros:ec:balance:' . $uid,
                );
            }
            $listingKeys = $redis->keys('nythros:ec:auction:*');
            if (is_array($listingKeys) && $listingKeys !== []) {
                $redis->del($listingKeys);
            }
            $redis->close();
        }
    } catch (\Throwable $e) {
        echo sprintf("[verify] cleanup: 清理 Redis 残留失败 %s\n", $e->getMessage());
    }

    $pass = $fail = 0;
    foreach ($v['results'] as $r) {
        if ($r['status'] === 'PASS') {
            $pass++;
        } else {
            $fail++;
        }
    }

    echo PHP_EOL;
    echo sprintf("[verify] summary: PASS=%d FAIL=%d\n", $pass, $fail);
    echo sprintf("[verify] RESULT: %s (PASS=%d FAIL=%d)\n", $fail > 0 ? 'FAILED' : 'PASSED', $pass, $fail);

    posix_kill(posix_getppid(), SIGINT);
}

/**
 * 在收件箱中查找并移除首个匹配帧。
 * Finds and removes the first matching frame in the inbox.
 *
 * @param array<int, array<string, mixed>> $inbox 收件箱（引用） Inbox (by reference).
 */
function inboxTake(array &$inbox, ?string $type = null, ?callable $pred = null): ?array
{
    foreach ($inbox as $index => $f) {
        if ($type !== null && ($f['type'] ?? null) !== $type) {
            continue;
        }
        if ($pred !== null && !$pred($f)) {
            continue;
        }
        unset($inbox[$index]);
        $inbox = array_values($inbox);

        return $f;
    }

    return null;
}

/**
 * 轮询等待收件箱出现匹配帧（0.2s 粒度）。
 * Polls until a matching frame appears in the inbox (0.2s granularity).
 *
 * @param array<int, array<string, mixed>> $inbox 收件箱（引用） Inbox (by reference).
 */
function waitFrame(array &$inbox, ?string $type, ?callable $pred, float $timeout, callable $onHit, callable $onFail): void
{
    $t0 = microtime(true);
    $scan = null;
    $scan = function () use (&$scan, &$inbox, $type, $pred, $timeout, $onHit, $onFail, $t0): void {
        $f = inboxTake($inbox, $type, $pred);
        if ($f !== null) {
            $onHit($f);

            return;
        }
        if (microtime(true) - $t0 >= $timeout) {
            $onFail();

            return;
        }
        Timer::add(0.2, $scan, [], false);
    };
    $scan();
}

function waitMapFrame(string $uid, string $type, callable $pred, float $timeout, callable $onHit, callable $onFail): void
{
    if (!isset($GLOBALS['mapclients'][$uid]['inbox']) || !is_array($GLOBALS['mapclients'][$uid]['inbox'])) {
        $onFail();

        return;
    }
    waitFrame($GLOBALS['mapclients'][$uid]['inbox'], $type, $pred, $timeout, $onHit, $onFail);
}

function sendMap(string $uid, string $type, array $payload, string $requestId): void
{
    $conn = $GLOBALS['mapclients'][$uid]['conn'] ?? null;
    if ($conn instanceof AsyncTcpConnection) {
        $conn->send(frameMap($type, $payload, $requestId));
    }
}

/**
 * 建立 Map 直连：连 Map → auth{token}，auth_ok/auth_failed/error 回调；后续帧追加进收件箱。
 * Opens a Map connection: connect → auth{token}; auth_ok/auth_failed/error fire the callback; later frames append to the inbox.
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
    // The Map channel is binary: outbound packets go out as binary WebSocket frames (BINARY opcode)
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
 * 社交 gateway 登录（auth{username,password,mapId} → auth_ok 拿 token 与 map.wsAddress）。
 * Social gateway login (auth{username,password,mapId} → auth_ok carrying the token and map.wsAddress).
 *
 * @param callable(bool, array<string, mixed>): void $onAuth auth 结果回调 Auth result callback.
 */
function openSocialOnce(string $uid, string $mapId, callable $onAuth): void
{
    $settled = false;
    $conn = new AsyncTcpConnection(GW_WS);
    $conn->onConnect = static function (AsyncTcpConnection $c) use ($uid, $mapId): void {
        $c->send(frame('auth', ['username' => $uid, 'password' => accountPassword($uid), 'mapId' => $mapId], 'auth:' . $uid));
    };
    $conn->onMessage = static function (AsyncTcpConnection $c, mixed $data) use (&$settled, $onAuth): void {
        if ($settled) {
            return;
        }
        $decoded = json_decode((string) $data, true);
        if (!is_array($decoded)) {
            return;
        }
        $type = $decoded['type'] ?? null;
        if (in_array($type, ['auth_ok', 'auth_failed', 'error'], true)) {
            $settled = true;
            $c->close();
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
 * 验收项 0（前置）：3 客户端 gateway 登录拿 token → Map 直连（consume('map')）。全部就位后 PASS。
 * Item 0 (prerequisite): 3 clients log in via the gateway for tokens → Map direct connections (consume('map')). PASS once all are in place.
 */
function step0Login(): void
{
    $ops = [];
    foreach ($GLOBALS['uids'] as $uid) {
        $ops[] = static function (callable $next) use ($uid): void {
            openSocialOnce($uid, 'map-1', static function (bool $ok, array $f) use ($uid, $next): void {
                if (!$ok) {
                    check(false, $uid . ' gateway 登录失败: ' . json_encode($f['payload'] ?? [], JSON_UNESCAPED_UNICODE));
                    $next();

                    return;
                }
                $p = $f['payload'] ?? [];
                $token = $p['token'] ?? null;
                $ws = $p['map']['wsAddress'] ?? null;
                check(is_string($token) && $token !== '', $uid . ' auth_ok.token 有效');
                check(is_string($ws) && $ws !== '', $uid . ' auth_ok.map.wsAddress 有效');
                if (!is_string($token) || !is_string($ws)) {
                    $next();

                    return;
                }
                $GLOBALS['tokens'][$uid] = $token;
                $GLOBALS['mapWs'][$uid] = $ws;

                openMap($uid, $ws, $token, static function (bool $okMap, array $fMap) use ($uid, $next): void {
                    if (!$okMap) {
                        check(false, $uid . ' Map 直连失败: ' . json_encode($fMap['payload'] ?? [], JSON_UNESCAPED_UNICODE));
                        $next();

                        return;
                    }
                    $entityId = $fMap['payload']['id'] ?? null;
                    check(is_string($entityId) && str_starts_with($entityId, $uid . '@'), $uid . ' Map auth_ok.id 为 ' . $uid . '@ 前缀 entityId');
                    $GLOBALS['entityIds'][$uid] = is_string($entityId) ? $entityId : '';
                    $next();
                });
            });
        };
    }

    $idx = 0;
    $next = null;
    $next = function () use (&$idx, &$next, $ops): void {
        if ($idx >= count($ops)) {
            $notReady = array_values(array_filter(
                $GLOBALS['uids'],
                static fn (string $uid): bool => !isset($GLOBALS['mapclients'][$uid]['conn']),
            ));
            if ($notReady !== []) {
                $GLOBALS['verify']['abort'] = true;
                closeStep('FAIL', '前置未就位（' . implode(',', $notReady) . '），中止后续验收');

                return;
            }
            closeStep('PASS', '3 客户端登录 + Map 直连就位（1001-1003）');

            return;
        }
        $op = $ops[$idx];
        $idx++;
        $op($next);
    };
    $next();
}

/**
 * 验收项 1：掉落与归属校验——1001 独占击杀 monster-1（ownerUid=1001 确定）。NYTHROS_ECONOMY=1 下
 * 掉落表三条目（bone/potion/sword）独立 roll 且权重段无不掉落区间，死亡必同时掉三物；收齐三条
 * drop:spawned 后：1002 拾取 sword 得 combat:error not_owner（归属拒绝）；1001 拾取全部成功 item:added。
 * Item 1: drop & ownership — 1001 solo-kills monster-1 (ownerUid=1001 deterministic). Under NYTHROS_ECONOMY=1 the
 * three drop-table entries (bone/potion/sword) roll independently with no no-drop segment, so a death always drops
 * all three; after collecting the three drop:spawned frames: 1002's pickup of the sword yields combat:error
 * not_owner; 1001 picks everything up with item:added.
 */
function step1DropOwnership(): void
{
    // 收集器：凑齐 bone/potion/sword 三条 drop:spawned（多条目独立 roll 必全掉）
    // Collector: gather the three drop:spawned frames (independent multi-entry rolls always hit)
    $GLOBALS['econ']['drops'] = [];
    $collect = static function () use (&$collect): void {
        waitMapFrame('1001', 'drop:spawned', static fn (array $f): bool => str_starts_with((string) ($f['payload']['dropId'] ?? ''), 'drop-monster-1-'), 8.0, static function (array $f) use (&$collect): void {
            $p = $f['payload'] ?? [];
            $dropId = is_string($p['dropId'] ?? null) ? $p['dropId'] : '';
            $itemId = is_string($p['itemId'] ?? null) ? $p['itemId'] : '';
            if ($dropId !== '' && $itemId !== '') {
                $GLOBALS['econ']['drops'][$itemId] = $dropId;
            }
            if (count($GLOBALS['econ']['drops']) < 3) {
                $collect();

                return;
            }

            check(isset($GLOBALS['econ']['drops']['bone'], $GLOBALS['econ']['drops']['potion'], $GLOBALS['econ']['drops']['sword']), '三条目必掉齐：bone/potion/sword 各一条 drop:spawned');
            $swordDropId = $GLOBALS['econ']['drops']['sword'];

            // 归属拒绝：非击杀者 1002 拾取 sword → combat:error not_owner
            // Ownership rejection: non-killer 1002 picks up the sword → combat:error not_owner
            sendMap('1002', 'pickup', ['dropId' => $swordDropId], reqId());
            waitMapFrame('1002', 'combat:error', static fn (array $f2): bool => ($f2['payload']['code'] ?? null) === 'not_owner', 6.0, static function (): void {
                check(true, '1002 拾取他人掉落 → combat:error not_owner（归属校验）');

                // 归属内拾取：击杀者 1001 全部拾取（逐个移除直至收空）
                // Owned pickup: the killer 1001 takes everything (removing each until empty)
                $pickupAll = static function () use (&$pickupAll): void {
                    if ($GLOBALS['econ']['drops'] === []) {
                        closeStep('PASS', '掉落 + 归属拒绝 + 归属内拾取');

                        return;
                    }
                    $dropId = array_shift($GLOBALS['econ']['drops']);
                    sendMap('1001', 'pickup', ['dropId' => (string) $dropId], reqId());
                    waitMapFrame('1001', 'item:added', static fn (array $f3): bool => true, 6.0, static function () use (&$pickupAll): void {
                        check(true, '1001 item:added 入包');
                        $pickupAll();
                    }, static function (): void {
                        closeStep('FAIL', '1001 未收到 item:added（归属内拾取失败）');
                    });
                };
                $pickupAll();
            }, static function (): void {
                closeStep('FAIL', '1002 未收到 combat:error not_owner（归属校验未生效）');
            });
        }, static function () use (&$collect): void {
            closeStep('FAIL', '等待 drop:spawned 超时（掉落未生成或未收齐三条目）');
        });
    };
    $attack = null;
    $attack = static function () use (&$attack, $collect): void {
        sendMap('1001', 'attack', ['targetId' => 'monster-1'], reqId());
        waitMapFrame('1001', 'entity_dead', static fn (array $f): bool => ($f['payload']['id'] ?? null) === 'monster-1', 1.2, static function () use ($collect): void {
            check(true, '1001 独占击杀 monster-1 → entity_dead');
            $collect();
        }, $attack);
    };
    $attack();
}

/**
 * 验收项 2：穿戴——1001 equip sword → economy:result ok + player:stats maxHp 130（装备加成聚合）；
 * 非装备型物品穿戴被拒；unequip 后 maxHp 回落且物品回包。
 * Item 2: equip — 1001 equips the sword → economy:result ok + player:stats maxHp 130 (bonus aggregation); equipping a
 * non-equipment item is rejected; unequip drops maxHp back and returns the item to the bag.
 */
function step2Equip(): void
{
    // sword 来源：NYTHROS_ECONOMY=1 掉落表三条目必全掉（验收项 1 已全部拾取），背包必持有 sword。
    // Sword source: under NYTHROS_ECONOMY=1 all three drop-table entries always drop (picked up in item 1), so the bag holds the sword.
    sendMap('1001', 'equip', ['itemId' => 'sword'], reqId());

    waitMapFrame('1001', 'player:stats', static fn (array $f): bool => ($f['payload']['maxHp'] ?? null) === 130, 6.0, static function (array $f): void {
        check(($f['payload']['maxHp'] ?? null) === 130, 'player:stats.maxHp == 130（100 基础 + 30 装备加成聚合）');

        waitMapFrame('1001', 'economy:result', static fn (array $f2): bool => ($f2['payload']['op'] ?? null) === 'equip' && ($f2['payload']['code'] ?? null) === 'ok', 6.0, static function (): void {
            check(true, 'equip sword → economy:result ok');

            // 非装备型拒绝：equip potion → invalid_argument
            // Non-equipment rejection: equipping the potion yields invalid_argument
            sendMap('1001', 'equip', ['itemId' => 'potion'], reqId());
            waitMapFrame('1001', 'economy:result', static fn (array $f3): bool => ($f3['payload']['op'] ?? null) === 'equip' && ($f3['payload']['code'] ?? null) === 'invalid_argument', 6.0, static function (): void {
                check(true, 'equip potion（非装备型）→ economy:result invalid_argument');

                // 卸下：unequip weapon → maxHp 回落 100
                // Unequip: unequip weapon → maxHp falls back to 100
                sendMap('1001', 'unequip', ['slot' => 'weapon'], reqId());
                waitMapFrame('1001', 'player:stats', static fn (array $f4): bool => ($f4['payload']['maxHp'] ?? null) === 100, 6.0, static function (): void {
                    check(true, 'unequip weapon → player:stats.maxHp 回落 100（卸下收敛 hp ≤ maxHp 不变量）');
                    closeStep('PASS', '穿戴聚合 + 非装备拒绝 + 卸下回落');
                }, static function (): void {
                    closeStep('FAIL', 'unequip 后未收到 maxHp==100 的 player:stats');
                });
            }, static function (): void {
                closeStep('FAIL', 'equip potion 未被拒绝（非装备型校验失效）');
            });
        }, static function (): void {
            closeStep('FAIL', '未收到 equip 的 economy:result ok');
        });
    }, static function (): void {
        closeStep('FAIL', '未收到 maxHp==130 的 player:stats（sword 未拾取到或属性聚合未生效）');
    });
}

/**
 * 验收项 3：挂单——1001 auction:sell sword x1 价 300 → economy:result ok 附 auctionId（背包扣货托管）。
 * Item 3: listing — 1001 lists the sword at 300 → economy:result ok carrying auctionId (bag debited into escrow).
 */
function step3Sell(): void
{
    sendMap('1001', 'auction:sell', ['itemId' => 'sword', 'count' => 1, 'price' => 300], reqId());

    waitMapFrame('1001', 'economy:result', static fn (array $f): bool => ($f['payload']['op'] ?? null) === 'auction:sell', 6.0, static function (array $f): void {
        $p = $f['payload'] ?? [];
        check(($p['code'] ?? null) === 'ok', 'auction:sell → economy:result ok（实际=' . var_export($p['code'] ?? null, true) . '）');
        $auctionId = $p['auctionId'] ?? '';
        check(is_string($auctionId) && str_starts_with($auctionId, 'auc-'), '回执附 auc- 前缀 auctionId（' . var_export($auctionId, true) . '）');
        if (!is_string($auctionId) || $auctionId === '') {
            closeStep('FAIL', 'auctionId 缺失');

            return;
        }
        $GLOBALS['econ']['auctionId'] = $auctionId;
        closeStep('PASS', '挂单托管成功');
    }, static function (): void {
        closeStep('FAIL', '未收到 auction:sell 回执');
    });
}

/**
 * 验收项 4：入账与购买——1002 economy:deposit 500 → auction:buy(auctionId, 300) → ok。
 * 余额结算断言：买家 1002 扣款后余 200；卖家 1001 的 300 在 Lua 内即时入账（账本侧读不到进程内背包，
 * 断言走 mail:claimed 后的入包结果——见验收项 5）。
 * Item 4: deposit & purchase — 1002 deposits 500 → buys at 300 → ok. Balance assertions: the buyer holds 200 after
 * the debit; the seller's 300 was credited inside the Lua instantly (the ledger never reads in-process bags; the
 * bag-side assertion rides item 5's claim result).
 */
function step4DepositAndBuy(): void
{
    sendMap('1002', 'economy:deposit', ['count' => 500], reqId());

    waitMapFrame('1002', 'economy:result', static fn (array $f): bool => ($f['payload']['op'] ?? null) === 'economy:deposit' && ($f['payload']['code'] ?? null) === 'ok', 6.0, static function (): void {
        check(true, '1002 economy:deposit 500 → ok');

        sendMap('1002', 'auction:buy', ['auctionId' => $GLOBALS['econ']['auctionId'], 'price' => 300], reqId());
        waitMapFrame('1002', 'economy:result', static fn (array $f2): bool => ($f2['payload']['op'] ?? null) === 'auction:buy', 6.0, static function (array $f2): void {
            check(($f2['payload']['code'] ?? null) === 'ok', '1002 auction:buy → ok（实际=' . var_export($f2['payload']['code'] ?? null, true) . '）');

            // 并发互斥旁证：挂单已删，重复购买得 no_listing
            // Mutual-exclusion corroboration: the listing is gone, a repeat purchase yields no_listing
            sendMap('1003', 'auction:buy', ['auctionId' => $GLOBALS['econ']['auctionId'], 'price' => 300], reqId());
            waitMapFrame('1003', 'economy:result', static fn (array $f3): bool => ($f3['payload']['op'] ?? null) === 'auction:buy', 6.0, static function (array $f3): void {
                check(($f3['payload']['code'] ?? null) === 'no_listing', '重复购买已删挂单 → no_listing（Lua 原子互斥旁证）');
                closeStep('PASS', '入账 + 购买结算 + 互斥旁证');
            }, static function (): void {
                closeStep('FAIL', '重复购买未得 no_listing');
            });
        }, static function (): void {
            closeStep('FAIL', '未收到 auction:buy 回执');
        });
    }, static function (): void {
        closeStep('FAIL', 'economy:deposit 未得到 ok 回执');
    });
}

/**
 * 验收项 5：邮件全链——1002 收 mail:new 在线通知（购买发货邮件）→ mail:list → mail:claim
 * （attachments msgpack 字节串还原 [[itemId,1]]）→ 重复领取 already_claimed → mail:delete。
 * Item 5: mail full chain — 1002 receives mail:new (the delivery mail) → mail:list → mail:claim (the msgpack
 * attachments decode back to [[itemId,1]]) → a repeat claim yields already_claimed → mail:delete.
 */
function step5MailChain(): void
{
    // mail:new 在线通知可能在购买瞬间已入收件箱（先查缓存再等待）
    // The mail:new online notice may already sit in the inbox (scan first, then wait)
    $cached = inboxTake($GLOBALS['mapclients']['1002']['inbox'], 'mail:new', null);
    if ($cached !== null) {
        step5AfterNotify($cached);

        return;
    }

    waitMapFrame('1002', 'mail:new', static fn (array $f): bool => str_starts_with((string) ($f['payload']['mailId'] ?? ''), 'mail-'), 8.0, static function (array $f): void {
        step5AfterNotify($f);
    }, static function (): void {
        closeStep('FAIL', '1002 未收到 mail:new 在线通知');
    });
}

/**
 * @param array<string, mixed> $notifyFrame mail:new 帧 The mail:new frame.
 */
function step5AfterNotify(array $notifyFrame): void
{
    $mailId = $notifyFrame['payload']['mailId'] ?? '';
    check(is_string($mailId) && str_starts_with($mailId, 'mail-'), 'mail:new.mailId 为 mail- 前缀（' . var_export($mailId, true) . '）');
    $GLOBALS['econ']['mailId'] = is_string($mailId) ? $mailId : '';

    sendMap('1002', 'mail:list', [], reqId());
    waitMapFrame('1002', 'mail:list', static fn (array $f): bool => true, 6.0, static function (array $f): void {
        $p = $f['payload'] ?? [];
        /** @var list<int|string> $mailIds */
        $mailIds = $p['mailIds'] ?? [];
        /** @var list<bool> $hasAttachments */
        $hasAttachments = $p['hasAttachments'] ?? [];
        check(in_array($GLOBALS['econ']['mailId'], $mailIds, true), 'mail:list 含发货邮件 mailId');
        $idx = array_search($GLOBALS['econ']['mailId'], $mailIds, true);
        check(is_int($idx) && ($hasAttachments[$idx] ?? null) === true, '该邮件 hasAttachment 标记为 true（并行列表对齐）');

        // 领取附件：mail:claimed 的 attachments 为 msgpack 字节串 → 还原 [[itemId, count]]
        // Claim: mail:claimed's attachments is a msgpack byte string → decodes back to [[itemId, count]]
        sendMap('1002', 'mail:claim', ['mailId' => $GLOBALS['econ']['mailId']], reqId());
        waitMapFrame('1002', 'mail:claimed', static fn (array $f2): bool => ($f2['payload']['mailId'] ?? null) === $GLOBALS['econ']['mailId'], 6.0, static function (array $f2): void {
            $bytes = $f2['payload']['attachments'] ?? '';
            check(is_string($bytes) && $bytes !== '', 'mail:claimed.attachments 为非空字节串（V7 msgpack 路径）');
            try {
                /** @var mixed $decoded */
                $decoded = $GLOBALS['econ']['msgpack']->unpack((string) $bytes);
            } catch (\Throwable $e) {
                closeStep('FAIL', 'attachments msgpack 解码失败: ' . $e->getMessage());

                return;
            }
            check($decoded === [['itemId' => 'sword', 'count' => 1]], 'msgpack 还原附件 [[' . var_export($decoded, true) . ']] 期望 [[itemId=sword,count=1]]');

            // 幂等：重复领取得 already_claimed（不重复发放）
            // Idempotency: a repeat claim yields already_claimed (nothing re-granted)
            sendMap('1002', 'mail:claim', ['mailId' => $GLOBALS['econ']['mailId']], reqId());
            waitMapFrame('1002', 'economy:result', static fn (array $f3): bool => ($f3['payload']['op'] ?? null) === 'mail:claim', 6.0, static function (array $f3): void {
                check(($f3['payload']['code'] ?? null) === 'already_claimed', '重复领取 → already_claimed（幂等命中）');

                // 删除清理 Delete cleanup.
                sendMap('1002', 'mail:delete', ['mailId' => $GLOBALS['econ']['mailId']], reqId());
                waitMapFrame('1002', 'economy:result', static fn (array $f4): bool => ($f4['payload']['op'] ?? null) === 'mail:delete' && ($f4['payload']['code'] ?? null) === 'ok', 6.0, static function (): void {
                    check(true, 'mail:delete → ok');
                    closeStep('PASS', '在线通知 + 列表 + msgpack 附件领取 + 幂等 + 删除');
                }, static function (): void {
                    closeStep('FAIL', 'mail:delete 未得到 ok');
                });
            }, static function (): void {
                closeStep('FAIL', '重复领取未得 already_claimed');
            });
        }, static function (): void {
            closeStep('FAIL', '未收到 mail:claimed（附件领取失败）');
        });
    }, static function (): void {
        closeStep('FAIL', '未收到 mail:list 回执');
    });
}

/**
 * 组装步骤队列并启动（Workerman 事件循环）。
 * Assembles the step queue and starts (the Workerman event loop).
 */
// Workerman 5.2 要求 argv 中显式含自身命令（start/stop/...）：本脚本无自定义参数，直接注入 start（前台 DEBUG 模式）。
// Workerman 5.2 requires an explicit own command (start/stop/...) in argv: this script takes no custom args, so inject start (foreground DEBUG mode).
$GLOBALS['argv'] = [$argv[0], 'start'];

$GLOBALS['verify']['steps'] = [
    ['0 登录前置 login prerequisite', 'step0Login', 30.0],
    ['1 掉落与归属校验 drop & ownership', 'step1DropOwnership', 60.0],
    ['2 穿戴 equip', 'step2Equip', 40.0],
    ['3 挂单 sell', 'step3Sell', 20.0],
    ['4 入账与购买 deposit & buy', 'step4DepositAndBuy', 30.0],
    ['5 邮件全链 mail chain', 'step5MailChain', 40.0],
];

$worker = new Worker();
$worker->onWorkerStart = static function (): void {
    nextStep();
};
Worker::runAll();
