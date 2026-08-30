<?php

declare(strict_types=1);

// 定位：packages/demo/bin/verify-room.php — R2 房间与 AoE 批量管线端到端验收脚本（ADR-024 §T7）。
// Located at: packages/demo/bin/verify-room.php — the R2 room & AoE batch-pipeline end-to-end acceptance script (ADR-024 §T7).
//
// 验收链路（单客户端 uid 1001，风格对齐 verify-combat）：
// Acceptance chain (single client uid 1001, styled after verify-combat):
//   0 前置：gateway 18285 完整握手登录 → token → Map 18081 直连（consume('map')）
//     Prerequisite: full gateway-18285 handshake login → token → direct Map-18081 connection (consume('map'))
//   0.5 出生保护（R4）：auth 后驻留出生格观察窗内零掉血 player:stats 帧——怪锚点已外移出出生格 +
//     auth 激活 3s 保护窗口，登录瞬间不再被集火（R4 前实测踩坑：约 3s 打空 100 血）
//     Spawn protection (R4): zero hp-loss player:stats frames inside the post-auth dwell window — monster anchors
//     moved off the spawn cell plus the 3s auth protection window mean no more login-instant focus fire (a measured
//     pre-R4 pitfall: full 100 hp gone in about 3s)
//   1 建房：room:create → room:ok{op=create}（R4 起无需避险 move：出生保护 + 锚点外移闭环暴露窗口）
//     Room creation: room:create → room:ok{op=create} (no evasive move since R4: spawn protection plus relocated
//     anchors close the exposure window)
//   2 玩家 join（transfer 约定路径，ADR-024 §4）：room:join → room:snapshot{roomId} + room:ok{op=join}
//     Player join (the transfer convention, ADR-024 §4): room:join → room:snapshot{roomId} + room:ok{op=join}
//   2.5 join 后 move（ADR-024 §9 V6 激活）：move{dx=1,dy=0} → 观察窗内【不】收到 error{code:500}
//     且连接存活（激活前 move 绑世界 EM 查无此人必 500+断连）。
//     Post-join move (ADR-024 §9 V6 activation): move{dx=1,dy=0} → NO error{code:500} inside the observation
//     window and the connection stays alive (pre-activation move bound to the world EM missed and always died with
//     500+disconnect).
//   2.6 房内反作弊（R4 MINOR-7 债务关闭，NYTHROS_ANTICHEAT=1 时启用）：房内超速 move{dx=99999} →
//     error{403, move rejected: overspeed} 且连接存活、坐标零副作用；未启用反作弊时 SKIP。
//     In-room anti-cheat (the R4 MINOR-7 debt closed; enabled by NYTHROS_ANTICHEAT=1): an in-room overspeed
//     move{dx=99999} → error{403, move rejected: overspeed} with the connection alive and zero coordinate side
//     effects; SKIP when anti-cheat is off.
//   3 房内刷怪：room:spawn{count=200} → room:ok{op=spawn,count=200}——实体经房间 EM.add 直入
//     （不入管理器归属表、不走 join 双向通知），首帧由房间 update 的 drainMoved 进 AOI 索引；
//     In-room spawning: room:spawn{count=200} → room:ok{op=spawn,count=200} — entities enter directly via the
//     room's EM.add (never into the manager's ownership table, no join notices), indexed by the room update's
//     drainMoved on their first frame;
//   4 一次 AoE 击杀 ≥50：room:aoe{skillId=fireball,cx=0,cy=0,r=70} → 客户端断言收到【恰好一条】combat:aoe
//     合并帧（命中 ≥50、无逐目标 combat:hit 洪泛）+【恰好一条】drop:spawned_batch 合并帧
//     （dropIds 数 ≥ 击杀数——掉落正式化后多条目独立 roll，每杀至少一条掉落；连锁死亡掉落全部并入批量帧）
//     +【恰好一条】entity_dead_batch 死亡合并帧（R4 V5：ids/positions/types 并行等长列表与击杀数对齐，
//     窗口内逐条 entity_dead 为 0）；观察窗口后复核各合并帧类型仍只有一条。
//     One AoE killing ≥50: room:aoe{skillId=fireball,cx=0,cy=0,r=70} → the client asserts receiving EXACTLY ONE
//     merged combat:aoe frame (≥50 hits, no per-target combat:hit flooding) plus EXACTLY ONE drop:spawned_batch
//     merged frame (dropIds count ≥ kill count; all chained-death drops merge into the batch frame) plus EXACTLY
//     ONE entity_dead_batch death frame (R4 V5: ids/positions/types parallel equal-length lists aligned with the
//     kill count; per-target entity_dead stays 0 inside the window); after an observation window all merged-frame
//     counters are re-checked to still hold exactly one each.
//   5 结算：room:settle → room:closed{roomId} + room:ok{op=settle}（生命周期回执完整）
//     Settle: room:settle → room:closed{roomId} + room:ok{op=settle} (complete lifecycle receipts)
//   6 关闭：room:close → room:ok{op=close}（close 清空成员并销毁房间与归属表记录）
//     Close: room:close → room:ok{op=close} (close clears members and destroys the room and ownership records)
//   7 断连跨容器清理（ADR-024 §9 V3）：重建房间 → 第二客户端 B（uid 1002）登录直连 join → 第三客户端
//     C（uid 1003）登录直连 join（快照含 B）→ B 断连 → C 必须收到 room:member_leave{id=1002@*}——房内
//     幽灵成员被跨容器清理兜底（evictFromAny）摘除并广播 leave 语义的证据。（A 不参与本项断言链，
//     仅承担建房；其 re-join 能力由步骤 8 验证。）
//     Cross-container disconnect cleanup (ADR-024 §9 V3): rebuild the room → a second client B (uid 1002) logs in,
//     connects directly and joins → a third client C (uid 1003) logs in, connects directly and joins (snapshot
//     contains B) → B disconnects → C must receive room:member_leave{id=1002@*} — evidence the in-room ghost member
//     was evicted by the cross-container cleanup fallback (evictFromAny) with leave semantics broadcast. (A takes no
//     part in this item's assertion chain and only hosts the room build; its re-join capability is verified by
//     step 8 — step 6's close back-fills managed players into the world EM/AOI per the destroy-zombie disposal.)
//   8 双客户端容器路由（ADR-024 §9 V6 激活端到端 + G1 世界侧 entity_leave）：重建房间 → B（uid 1002）
//     登录直连留守世界（不 join）并瞬移与 A 同格 (36,35) → A（1001，步骤 6 close 已回填世界可再入——
//     destroy 僵尸处置的间接证据）re-join 进房 → B 必须收到 entity_leave{id=1001@*}（G1：handleJoin 摘世界
//     登记前补发的视野差分，镜像 closeConnection 时序）→ B join 同房（transfer 保留坐标，入房即与 A 同格；
//     快照含 A）→ A 收 room:member_enter{id=1002@*} → A move{dx=2,dy=3}（(36,35)→(38,38)）→ B 收
//     entity_moved{id=1001@*, position=(38,38)}（房内 AOI 广播跨容器投递）→ B 强制断连 → A 观察窗内收
//     room:member_leave{id=1002@*}。
//     Dual-client container routing (the ADR-024 §9 V6 activation e2e plus the G1 world-side entity_leave):
//     rebuild the room → B (uid 1002) logs in and stays in the host world (no join), teleporting into A's cell
//     (36,35) → A (1001, re-admissible after step 6's world back-fill — indirect evidence of the destroy-zombie
//     disposal) re-joins the room → B must receive entity_leave{id=1001@*} (G1: the vision delta back-filled by
//     handleJoin before removing the world registration, mirroring closeConnection's ordering) → B joins the same
//     room (the transfer preserves coordinates, landing B in-cell with A; snapshot contains A) → A receives
//     room:member_enter{id=1002@*} → A moves {dx=2,dy=3} ((36,35)→(38,38)) → B receives entity_moved{id=1001@*,
//     position=(38,38)} (in-room AOI broadcast delivered across containers) → B force-disconnects → A receives
//     room:member_leave{id=1002@*} within the observation window.
//
// 前置环境：Redis 127.0.0.1:6379 可用；MySQL 127.0.0.1:3306（nythros 库，归档建表幂等）。
// 服务启动（NYTHROS_ROOMS=1 开启房间装配，WSL 内 setsid -f 防 SIGHUP）：
//   cd /mnt/d/workspace/php/Nythros && NYTHROS_ROOMS=1 setsid -f php bin/server start
// Prerequisites: Redis on 127.0.0.1:6379; MySQL on 127.0.0.1:3306 (nythros DB; archive schema creation is idempotent).
// Boot (NYTHROS_ROOMS=1 enables room assembly; inside WSL use setsid -f against SIGHUP):
//   cd /mnt/d/workspace/php/Nythros && NYTHROS_ROOMS=1 setsid -f php bin/server start
//
// 输出契约：每项一行 [verify] [PASS|FAIL]；末行 RESULT 汇总。输出契约与 verify-combat 一致。
// Output contract: one line per item [verify] [PASS|FAIL]; a final RESULT summary line, matching verify-combat.

require __DIR__ . '/../../../vendor/autoload.php';
require __DIR__ . '/lib/map-codec.php';
require __DIR__ . '/lib/verify-framework.php';

use Workerman\Connection\AsyncTcpConnection;
use Workerman\Timer;
use Workerman\Worker;

const GW_WS = 'ws://127.0.0.1:18285';
const MAP_WS = 'ws://127.0.0.1:18081'; // map-1#ch-1（房间验收目标频道） map-1#ch-1 (the room-acceptance target channel)
const ROOM_ID = 'horde-1';
const HORDE_COUNT = 200;
const MIN_KILLS = 50;

/**
 * 验收共享状态（步骤队列、断言、结果、客户端/令牌、合并帧计数器）。
 * Shared acceptance state (step queue, assertions, results, client/token, merged-frame counters).
 *
 * @var array<string, mixed>
 */
bootVerifyGlobals([]);

/** @var array<string, mixed> 客户端状态（conn/inbox/authOk） Client state (conn/inbox/authOk). */
$GLOBALS['client'] = [];

/** @var array<string, mixed> 第二客户端状态（V3 断连清理验收用，uid 1002） Second-client state (for the V3 disconnect-cleanup acceptance, uid 1002). */
$GLOBALS['client2'] = [];

/** @var string gateway 登录签发的多 scope token The multi-scope token issued by the gateway login. */
$GLOBALS['token'] = '';

/** @var int combat:aoe 帧接收计数（合并帧唯一性证据） combat:aoe frame receive counter (merged-frame uniqueness evidence). */
$GLOBALS['aoeCount'] = 0;

/** @var int drop:spawned_batch 帧接收计数（合并帧唯一性证据） drop:spawned_batch frame receive counter (merged-frame uniqueness evidence). */
$GLOBALS['batchCount'] = 0;

/** @var int combat:hit 帧接收计数（逐目标洪泛反证） combat:hit frame receive counter (anti-evidence of per-target flooding). */
$GLOBALS['hitCount'] = 0;

/** @var int entity_dead 帧接收计数（R4 批量化后窗口内应为 0，逐条路径反证） entity_dead frame receive counter (must stay 0 inside the R4 batch window; anti-evidence of the per-target path). */
$GLOBALS['deadCount'] = 0;

/** @var int entity_dead_batch 帧接收计数（死亡合并帧唯一性证据，ADR-024 §9 V5） entity_dead_batch frame receive counter (death merged-frame uniqueness evidence, ADR-024 §9 V5). */
$GLOBALS['deadBatchCount'] = 0;

/** @var int player:stats 掉血帧计数（出生保护断言：保护窗内恒为 0） player:stats hp-loss frame counter (spawn-protection assertion: always 0 inside the window). */
$GLOBALS['hpLossCount'] = 0;

/** @var int 请求 id 序列 Request id sequence. */
$GLOBALS['reqSeq'] = 0;

/**
 * 本地批量发送适配（P14 公共库迁移保留项）：room 脚本的客户端模型是 $GLOBALS['client']（单连接、
 * 非 uid 注册表），与库 sendMap 的 clients[uid] 口径不同——保留原发送器改名接入。
 * A local batch-send adapter (a P14 migration retention): this script's client model is $GLOBALS['client']
 * (a single connection, not the uid registry) — different from the library sendMap's clients[uid] convention,
 * so the original sender is kept under a renamed local function.
 */
function sendRoomFrame(string $type, array $payload): void
{
    $conn = $GLOBALS['client']['conn'] ?? null;
    if ($conn instanceof AsyncTcpConnection) {
        $conn->send(frameMap($type, $payload, reqId()));
    }
}

/**
 * 向第二客户端的 Map 直连发送一帧（V3 断连清理验收用）。
 * Sends one frame on the second client's direct Map connection (the V3 disconnect-cleanup acceptance).
 */
function sendSecond(string $type, array $payload): void
{
    $conn = $GLOBALS['client2']['conn'] ?? null;
    if ($conn instanceof AsyncTcpConnection) {
        $conn->send(frameMap($type, $payload, reqId()));
    }
}

/**
 * 验收项 0（前置）：gateway 登录拿 token → Map 直连 auth_ok。
 * Item 0 (prerequisite): gateway login for a token → direct-Map auth_ok.
 */
function step0Login(): void
{
    $state = &$GLOBALS['client'];
    $state['inbox'] = [];
    // 连接存活标志：onClose 置位，V6 join 后 move 步骤断言「连接存活」用
    // Connection-alive flag: set by onClose, asserted by the V6 post-join move step
    $state['closed'] = false;
    // 双连接各自独立的完成标志：社交握手与 Map 握手分别判定（共用标志会让 Map auth_ok 的
    // PASS 永久被抑制，步骤 0 空转到超时——期间玩家滞留大世界会被世界怪击杀）
    // Independent per-connection settle flags: the social handshake and the Map handshake are judged separately
    // (a shared flag permanently suppresses the Map auth_ok PASS, idling step 0 into timeout — during which the
    // player lingers in the host world and gets killed by world monsters)
    $socialDone = false;
    $mapDone = false;

    $social = new AsyncTcpConnection(GW_WS);
    $social->onConnect = static function (AsyncTcpConnection $c): void {
        $c->send(json_encode([
            'type' => 'auth',
            'requestId' => 'login:1001',
            'timestamp' => microtime(true),
            'payload' => ['username' => '1001', 'password' => 'secret', 'mapId' => 'map-1'],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    };
    $social->onMessage = static function (AsyncTcpConnection $c, mixed $data) use (&$state, &$socialDone): void {
        $decoded = json_decode((string) $data, true);
        if (!is_array($decoded) || ($decoded['type'] ?? null) !== 'auth_ok') {
            return;
        }
        // 先置位再关闭：防止主动 close 触发的 onClose 走失败分支（迟到回调串步骤）
        // Settle first, then close: prevents the deliberate close's onClose from taking the failure branch (a late callback bleeding into the next step)
        $socialDone = true;
        $token = $decoded['payload']['token'] ?? null;
        $ws = $decoded['payload']['map']['wsAddress'] ?? null;
        if (!is_string($token) || !is_string($ws)) {
            check(false, 'gateway auth_ok 缺少 token 或 map.wsAddress');
            closeStep('FAIL', 'auth_ok 负载不完整');

            return;
        }
        $c->close();
        $GLOBALS['token'] = $token;

        // Map 直连（二进制协议）：auth{token} → auth_ok
        // Direct Map connection (binary protocol): auth{token} → auth_ok
        $map = new AsyncTcpConnection(MAP_WS);
        $map->websocketType = \Workerman\Protocols\Websocket::BINARY_TYPE_ARRAYBUFFER;
        $state['conn'] = $map;
        $map->onConnect = static function (AsyncTcpConnection $m) use ($token): void {
            $m->send(frameMap('auth', ['token' => $token], 'map-auth:' . $token));
        };
        $map->onMessage = static function (AsyncTcpConnection $m, mixed $data) use (&$state, &$mapDone): void {
            foreach (decodeMapFrames((string) $data) as $decoded) {
                // 合并帧唯一性计数：全连接生命周期内累计（不随步骤清零）
                // Merged-frame uniqueness counters: accumulated over the whole connection lifetime (never reset per step)
                match ($decoded['type'] ?? null) {
                    'combat:aoe' => $GLOBALS['aoeCount']++,
                    'drop:spawned_batch' => $GLOBALS['batchCount']++,
                    'combat:hit' => $GLOBALS['hitCount']++,
                    'entity_dead' => $GLOBALS['deadCount']++,
                    'entity_dead_batch' => $GLOBALS['deadBatchCount']++,
                    default => null,
                };
                // 出生保护断言计数：player:stats 掉血帧（hp < maxHp）即受到伤害
                // Spawn-protection assertion counter: a player:stats frame with hp < maxHp means damage taken
                if (($decoded['type'] ?? null) === 'player:stats'
                    && is_int($decoded['payload']['hp'] ?? null)
                    && is_int($decoded['payload']['maxHp'] ?? null)
                    && $decoded['payload']['hp'] < $decoded['payload']['maxHp']) {
                    $GLOBALS['hpLossCount']++;
                }
                $state['inbox'][] = $decoded;
                if (($decoded['type'] ?? null) === 'error') {
                    // 错误帧透传打印：路由失败/状态机拒绝的归因证据
                    // Error frames are printed through: attribution evidence for routing failures / state-machine rejections
                    echo sprintf("[verify] error frame: %s\n", json_encode($decoded['payload'] ?? [], JSON_UNESCAPED_UNICODE));
                }
                if (!$mapDone && ($decoded['type'] ?? null) === 'auth_ok') {
                    $mapDone = true;
                    $entityId = $decoded['payload']['id'] ?? '';
                    check(is_string($entityId) && str_starts_with($entityId, '1001@'), 'Map auth_ok.id 为 1001@ 前缀 entityId');
                    closeStep('PASS', 'gateway 登录 + Map 直连就位');
                }
            }
        };
        $map->onClose = static function () use (&$mapDone, &$state): void {
            $state['closed'] = true;
            if (!$mapDone) {
                $mapDone = true;
                check(false, 'Map 连接在认证前关闭');
                closeStep('FAIL', 'Map 连接关闭');
            }
        };
        $map->connect();
    };
    $social->onClose = static function () use (&$socialDone): void {
        // 登录成功路径已置位；仅在未完成登录时判失败
        // The success path settles first; only an unfinished login counts as a failure here
        if (!$socialDone && !isset($GLOBALS['client']['conn'])) {
            $socialDone = true;
            check(false, 'gateway 连接在登录完成前关闭');
            closeStep('FAIL', 'gateway 连接关闭');
        }
    };
    $social->connect();
}

/**
 * 验收项 0.5（R4 出生保护）：auth 后驻留出生格 2s 观察窗——窗内零掉血 player:stats 帧
 * （怪锚点已外移出出生格 + auth 激活 3s 保护窗口，登录瞬间不再被集火）。
 * Item 0.5 (R4 spawn protection): dwell at the spawn cell for a 2s observation window after auth — zero
 * hp-loss player:stats frames inside it (monster anchors moved off the spawn cell plus the 3s auth protection
 * window mean no more login-instant focus fire).
 */
function step05SpawnProtection(): void
{
    $baseline = $GLOBALS['hpLossCount'];
    verifyTimer(2.0, static function () use ($baseline): void {
        check($GLOBALS['hpLossCount'] === $baseline, 'auth 后 2s 驻留窗内零掉血帧（出生保护 + 锚点外移）');
        check(empty($GLOBALS['client']['closed']), '驻留观察窗内连接存活');
        closeStep('PASS', '出生保护窗口闭环（R4）：登录即驻留不被集火');
    }, [], false);
}

/**
 * 验收项 1：建房——room:create → room:ok{op=create}。
 * 幂等前置采用快速路径：直接 create（零等待）；仅当撞上上一轮残留房（error: 已存在）才走 settle+close
 * 清理后重试。R4 起无需避险 move：出生格不再被怪锚点覆盖（锚点外移至对角邻格 cell(±1,±1)）且 auth
 * 激活 3s 出生保护窗口，create/join 的暴露窗口双保险闭环。
 * Item 1: room creation — room:create → room:ok{op=create}.
 * The idempotent pre-step takes the fast path: create directly; only a collision with a previous run's leftover
 * room (the "already exists" error) falls back to a settle+close cleanup and retry. No evasive move since R4:
 * the spawn cell is no longer covered by monster anchors (relocated to the diagonal neighbor cells) and the 3s
 * auth spawn-protection window closes the create/join exposure doubly.
 */
function step1CreateRoom(): void
{
    sendRoomFrame('room:create', ['roomId' => ROOM_ID]);
    waitFrame(
        $GLOBALS['client']['inbox'],
        'room:ok',
        static fn (array $f): bool => ($f['payload']['op'] ?? null) === 'create',
        8.0,
        static function (array $f): void {
            check(($f['payload']['roomId'] ?? null) === ROOM_ID, 'room:ok.roomId == ' . ROOM_ID);
            closeStep('PASS', '建房回执 room:ok{create}');
        },
        static function (): void {
            // 残留房清理重试：handleClose 已按 manager->destroy 口径容错（Created/Running 内部补 settle），
            // 直接 close 即可；事件驱动等 close 回执后再重试 create（滞留窗口压到毫秒级——世界怪
            // 巡逻域覆盖出生点，长滞留会被击杀）。
            // Leftover-room cleanup retry: handleClose already tolerates any state per manager->destroy
            // (Created/Running settle internally), so a single close suffices; the create retry is event-driven on
            // the close receipt (the exposure window shrinks to milliseconds — world monsters' patrol domains cover
            // the spawn cell, and long lingering means death).
            sendRoomFrame('room:close', ['roomId' => ROOM_ID]);
            waitFrame(
                $GLOBALS['client']['inbox'],
                'room:ok',
                static fn (array $f): bool => ($f['payload']['op'] ?? null) === 'close',
                8.0,
                static function (): void {
                    sendRoomFrame('room:create', ['roomId' => ROOM_ID]);
                    waitFrame(
                        $GLOBALS['client']['inbox'],
                        'room:ok',
                        static fn (array $f): bool => ($f['payload']['op'] ?? null) === 'create',
                        8.0,
                        static function (array $f): void {
                            check(($f['payload']['roomId'] ?? null) === ROOM_ID, 'room:ok.roomId == ' . ROOM_ID);
                            closeStep('PASS', '残留房清理后重建 room:ok{create}');
                        },
                        static function (): void {
                            closeStep('FAIL', '未收到 room:ok{create}（含残留房清理重试）');
                        },
                    );
                },
                static function (): void {
                    closeStep('FAIL', '残留房清理未收到 room:ok{close}');
                },
            );
        },
    );
}

/**
 * 验收项 2：玩家 join（transfer 约定路径）——room:snapshot 快照回执 + room:ok{join}。
 * Item 2: player join (the transfer convention) — the room:snapshot receipt plus room:ok{join}.
 */
function step2JoinRoom(): void
{
    sendRoomFrame('room:join', ['roomId' => ROOM_ID]);
    waitFrame(
        $GLOBALS['client']['inbox'],
        'room:snapshot',
        static fn (array $f): bool => ($f['payload']['roomId'] ?? null) === ROOM_ID,
        8.0,
        static function (array $f): void {
            // 空房首个成员：快照成员清单为空（不含自己）
            // First member of an empty room: the snapshot member list is empty (self excluded)
            check(($f['payload']['memberIds'] ?? []) === [], 'room:snapshot.memberIds 为空（首成员，不含自身）');
            waitFrame(
                $GLOBALS['client']['inbox'],
                'room:ok',
                static fn (array $f2): bool => ($f2['payload']['op'] ?? null) === 'join',
                8.0,
                static function (): void {
                    check(true, 'transfer 入房回执 room:ok{join}');
                    closeStep('PASS', 'join 快照 + 回执完整');
                },
                static function (): void {
                    closeStep('FAIL', '未收到 room:ok{join}');
                },
            );
        },
        static function (): void {
            closeStep('FAIL', '未收到 room:snapshot（transfer 入房路径未生效？）');
        },
    );
}

/**
 * 验收项 2.5：join 后 move（ADR-024 §9 V6 激活）——move 在房间上下文结算：
 * 观察窗内【不】收到 error{code:500}（激活前 move 绑世界 EM 查无此人必 500+断连）且连接存活。
 * 位移 {dx=1,dy=0}：(0,0)→(1,0)，本就远离原点世界怪巡逻域与房内 horde 刷怪网格
 * （x∈[22,64], y∈[-26,-6]），后续步骤安全。
 * Item 2.5: post-join move (ADR-024 §9 V6 activation) — move settles in the room context: NO error{code:500}
 * inside the observation window (pre-activation move bound to the world EM missed and always died with
 * 500+disconnect) and the connection stays alive. The displacement {dx=1,dy=0}: (0,0)→(1,0), already clear of
 * both the origin world-monster patrol domains and the in-room horde spawn grid (x∈[22,64], y∈[-26,-6]),
 * keeping later steps safe.
 */
function step25MoveInRoom(): void
{
    sendRoomFrame('move', ['dx' => 1, 'dy' => 0]);
    verifyTimer(1.5, static function (): void {
        $errors500 = array_filter(
            $GLOBALS['client']['inbox'],
            static fn (array $f): bool => ($f['type'] ?? null) === 'error' && ($f['payload']['code'] ?? null) === 500,
        );
        check($errors500 === [], 'join 后 move 未收到 error{500}（世界路由失效标志帧）');
        check(empty($GLOBALS['client']['closed']), 'move 后连接存活');
        closeStep('PASS', 'join 后 move 容器上下文结算（无 500、连接存活）');
    }, [], false);
}

/**
 * 验收项 2.6（R4 MINOR-7 债务关闭，NYTHROS_ANTICHEAT=1 时启用）：房内超速 move 被定向
 * error{403, move rejected: overspeed} 拒绝且连接存活——房内分支与世界模板复用同一 MovementValidator
 * 实例；坐标零副作用由后续步骤的合法位移间接验证。未启用反作弊时 SKIP（SKIP 不计入 FAIL，
 * 与 verify-combat 验收项 9 同口径）。
 * Item 2.6 (the R4 MINOR-7 debt closed; enabled by NYTHROS_ANTICHEAT=1): an in-room overspeed move is rejected
 * with a directed error{403, move rejected: overspeed} while the connection stays alive — the in-room branch
 * reuses the same MovementValidator instance as the world template; zero coordinate side effects are verified
 * indirectly by later steps' legal displacements. SKIP when anti-cheat is off (SKIP never counts as FAIL,
 * matching verify-combat item 9).
 */
function step26InRoomAntiCheat(): void
{
    if (getenv('NYTHROS_ANTICHEAT') !== '1') {
        closeStep('SKIP', 'NYTHROS_ANTICHEAT 未启用，跳过房内反作弊验收');

        return;
    }

    sendRoomFrame('move', ['dx' => 99999, 'dy' => 0]);
    waitFrame(
        $GLOBALS['client']['inbox'],
        'error',
        static fn (array $f): bool => ($f['payload']['code'] ?? null) === 403 && str_contains((string) ($f['payload']['message'] ?? ''), 'overspeed'),
        8.0,
        static function (): void {
            check(true, '房内超速 move → error 403 move rejected: overspeed');
            check(empty($GLOBALS['client']['closed']), '拒绝后连接存活');
            closeStep('PASS', '房内反作弊闭环（MINOR-7 债务关闭）：同一 validator 实例覆盖房内路径');
        },
        static function (): void {
            closeStep('FAIL', '8s 内未收到 error 403 overspeed（房内反作弊未生效？）');
        },
    );
}

/**
 * 验收项 3：房内直入刷怪——room:spawn{200} → room:ok{spawn,count=200}，随后等待若干房间 tick
 * （drainMoved 将直入实体索引进 AOI）再进入 AoE 步骤。
 * Item 3: direct in-room spawning — room:spawn{200} → room:ok{spawn,count=200}, then waits several room ticks
 * (drainMoved indexes the directly-added entities into the AOI) before entering the AoE step.
 */
function step3SpawnHorde(): void
{
    sendRoomFrame('room:spawn', ['roomId' => ROOM_ID, 'count' => HORDE_COUNT]);
    waitFrame(
        $GLOBALS['client']['inbox'],
        'room:ok',
        static fn (array $f): bool => ($f['payload']['op'] ?? null) === 'spawn',
        8.0,
        static function (array $f): void {
            check(($f['payload']['count'] ?? null) === HORDE_COUNT, 'room:ok{spawn}.count == ' . HORDE_COUNT);
            verifyTimer(0.8, static function (): void {
                closeStep('PASS', HORDE_COUNT . ' 怪直入登记完成（等待索引 tick 后进入 AoE）');
            }, [], false);
        },
        static function (): void {
            closeStep('FAIL', '未收到 room:ok{spawn}');
        },
    );
}

/**
 * 验收项 4：一次 AoE 击杀 ≥50——【恰好一条】combat:aoe 合并帧 + 【恰好一条】drop:spawned_batch 合并帧
 * + 【恰好一条】entity_dead_batch 死亡合并帧（R4 V5），无逐目标 combat:hit/entity_dead 洪泛；
 * 观察窗后复核计数仍为 1/1/1（合并帧唯一性证据）。
 * Item 4: one AoE killing ≥50 — EXACTLY ONE merged combat:aoe frame plus EXACTLY ONE merged drop:spawned_batch
 * frame plus EXACTLY ONE entity_dead_batch death frame (R4 V5), with no per-target combat:hit/entity_dead
 * flooding; after an observation window the counters are re-checked to still hold 1/1/1 (merged-frame uniqueness evidence).
 */
function step4AoeKill(): void
{
    // 计数器归零：只统计 AoE 窗口内的帧（进房前大世界阶段的杂散帧不属于本验收口径）
    // Reset the counters: only frames inside the AoE window count (stray pre-join frames from the host-world phase are outside this acceptance's scope)
    $GLOBALS['aoeCount'] = 0;
    $GLOBALS['batchCount'] = 0;
    $GLOBALS['hitCount'] = 0;
    $GLOBALS['deadCount'] = 0;
    $GLOBALS['deadBatchCount'] = 0;

    sendRoomFrame('room:aoe', ['roomId' => ROOM_ID, 'skillId' => 'fireball', 'cx' => 0, 'cy' => 0, 'r' => 70]);

    waitFrame(
        $GLOBALS['client']['inbox'],
        'combat:aoe',
        null,
        10.0,
        static function (array $f): void {
            $p = $f['payload'];
            $targets = $p['targetIds'] ?? [];
            $damages = $p['damages'] ?? [];
            $hps = $p['hps'] ?? [];
            check(is_array($targets) && count($targets) >= MIN_KILLS, 'combat:aoe 命中数 ≥ ' . MIN_KILLS . '（实际=' . count(is_array($targets) ? $targets : []) . '）');
            check(is_array($damages) && count($damages) === count($targets), 'damages 与 targetIds 等长对齐');
            check(is_array($hps) && count($hps) === count($targets), 'hps 与 targetIds 等长对齐');
            $inRange = is_array($damages) && array_reduce($damages, static fn (bool $ok, mixed $d): bool => $ok && is_int($d) && $d >= 12 && $d <= 18, true);
            check($inRange, '每点伤害 ∈ [12,18]（fireball 10×1.5×[80%,120%]）');

            // 全部命中即全部击杀（horde 怪 maxHp=12 ≤ 最小伤害 12）。掉落断言按正式化多条目 roll 口径：
            // 每条目独立 roll 是否掉落（当前 horde 表 bone:potion 双条目、无不掉落段 → 每杀恒产 2 条），
            // 故只断言「每杀至少一条掉落」（dropIds ≥ 击杀数），不锁定逐杀掉落条数——掉落表条目数变化
            // （如经济批追加 sword）不破坏本验收。
            // Every hit kills (horde monsters maxHp=12 ≤ minimum damage 12). The drop assertion follows the
            // formalized multi-entry roll semantics: entries roll independently (the current horde table holds the
            // bone:potion pair with no no-drop segment → exactly 2 drops per kill), so we only assert "at least one
            // drop per kill" (dropIds ≥ kill count) and never pin the per-kill drop count — table entry changes
            // (e.g. the economy batch appending sword) must not break this acceptance.
            waitFrame(
                $GLOBALS['client']['inbox'],
                'entity_dead_batch',
                null,
                10.0,
                static function (array $fDeath) use ($targets): void {
                    // 死亡合并帧内容（R4 V5）：并行等长标量列表 ids/positions/types，与击杀数对齐，
                    // 种类全为 monster；真实数据锁帧格式（200 杀 → 恰好 1 条 entity_dead_batch）
                    // Death-batch content (R4 V5): parallel equal-length scalar lists ids/positions/types aligned
                    // with the kill count, all kinds monster; the wire format is locked with real data
                    // (200 kills → exactly one entity_dead_batch)
                    $pd = $fDeath['payload'];
                    $ids = $pd['ids'] ?? [];
                    $positions = $pd['positions'] ?? [];
                    $types = $pd['types'] ?? [];
                    $killCount = is_array($targets) ? count($targets) : 0;
                    check(is_array($ids) && count($ids) === $killCount, 'entity_dead_batch.ids 数 == 击杀数（kills=' . $killCount . ' deaths=' . count(is_array($ids) ? $ids : []) . '）');
                    check(is_array($positions) && count($positions) === count($ids), 'positions 与 ids 等长对齐');
                    check(is_array($types) && count($types) === count($ids), 'types 与 ids 等长对齐');
                    $allMonsters = is_array($types) && array_reduce($types, static fn (bool $ok, mixed $t): bool => $ok && $t === 'monster', true);
                    check($allMonsters, 'types 全为 monster（horde 怪）');

                    waitFrame(
                        $GLOBALS['client']['inbox'],
                        'drop:spawned_batch',
                        null,
                        10.0,
                        static function (array $f2) use ($targets): void {
                            $p2 = $f2['payload'];
                            $dropIds = $p2['dropIds'] ?? [];
                            $itemIds = $p2['itemIds'] ?? [];
                            $positions = $p2['positions'] ?? [];
                            check(is_array($dropIds) && count($dropIds) >= count($targets), 'drop:spawned_batch.dropIds 数 ≥ 击杀数（drops=' . count(is_array($dropIds) ? $dropIds : []) . ' kills=' . count(is_array($targets) ? $targets : []) . '）');
                            check(is_array($itemIds) && count($itemIds) === count($dropIds), 'itemIds 与 dropIds 对齐');
                            check(is_array($positions) && count($positions) === count($dropIds), 'positions 与 dropIds 对齐');
                            $itemsValid = is_array($itemIds) && array_reduce($itemIds, static fn (bool $ok, mixed $i): bool => $ok && in_array($i, ['bone', 'potion', 'sword'], true), true);
                            check($itemsValid, 'itemIds ⊆ {bone, potion, sword}（共享掉落表；NYTHROS_ECONOMY=1 追加 sword 条目）');

                            // 观察窗：等 1.5s 复核合并帧唯一性（若存在逐目标洪泛，此处计数必然爆炸）
                            // Observation window: re-check merged-frame uniqueness after 1.5s (per-target flooding would blow the counters up here)
                            $killCount = is_array($targets) ? count($targets) : 0;
                            $dropCount = is_array($dropIds) ? count($dropIds) : 0;
                            verifyTimer(1.5, static function () use ($killCount, $dropCount): void {
                                check($GLOBALS['aoeCount'] === 1, sprintf('全程 combat:aoe 恰好 1 帧（实际=%d）', $GLOBALS['aoeCount']));
                                check($GLOBALS['batchCount'] === 1, sprintf('全程 drop:spawned_batch 恰好 1 帧（实际=%d）', $GLOBALS['batchCount']));
                                check($GLOBALS['deadBatchCount'] === 1, sprintf('全程 entity_dead_batch 恰好 1 帧（实际=%d）', $GLOBALS['deadBatchCount']));
                                check($GLOBALS['hitCount'] === 0, sprintf('无逐目标 combat:hit 洪泛（实际=%d）', $GLOBALS['hitCount']));
                                check($GLOBALS['deadCount'] === 0, sprintf('窗口内无逐条 entity_dead（V5 批量化，实际=%d）', $GLOBALS['deadCount']));
                                closeStep('PASS', sprintf('AoE 单帧命中 %d 杀 + 掉落单帧 %d 条 + 死亡合并帧 1 条（合并帧 1/1/1）', $killCount, $dropCount));
                            }, [], false);
                        },
                        static function (): void {
                            closeStep('FAIL', '未收到 drop:spawned_batch 合并帧');
                        },
                    );
                },
                static function (): void {
                    closeStep('FAIL', '未收到 entity_dead_batch 死亡合并帧（R4 V5 攒批未生效？）');
                },
            );
        },
        static function (): void {
            closeStep('FAIL', '未收到 combat:aoe 合并帧');
        },
    );
}

/**
 * 验收项 5：结算——room:settle → room:closed{roomId}（存活成员回执）+ room:ok{settle}。
 * Item 5: settle — room:settle → room:closed{roomId} (surviving-member receipt) plus room:ok{settle}.
 */
function step5Settle(): void
{
    sendRoomFrame('room:settle', ['roomId' => ROOM_ID]);
    waitFrame(
        $GLOBALS['client']['inbox'],
        'room:closed',
        static fn (array $f): bool => ($f['payload']['roomId'] ?? null) === ROOM_ID,
        8.0,
        static function (): void {
            check(true, '存活成员收到 room:closed 回执');
            waitFrame(
                $GLOBALS['client']['inbox'],
                'room:ok',
                static fn (array $f2): bool => ($f2['payload']['op'] ?? null) === 'settle',
                8.0,
                static function (): void {
                    closeStep('PASS', 'settle 生命周期回执完整');
                },
                static function (): void {
                    closeStep('FAIL', '未收到 room:ok{settle}');
                },
            );
        },
        static function (): void {
            closeStep('FAIL', '未收到 room:closed');
        },
    );
}

/**
 * 验收项 6：关闭——room:close → room:ok{close}（终态清理 + 归属表销毁）。
 * Item 6: close — room:close → room:ok{close} (terminal cleanup plus ownership-table destruction).
 */
function step6Close(): void
{
    sendRoomFrame('room:close', ['roomId' => ROOM_ID]);
    waitFrame(
        $GLOBALS['client']['inbox'],
        'room:ok',
        static fn (array $f): bool => ($f['payload']['op'] ?? null) === 'close',
        8.0,
        static function (): void {
            closeStep('PASS', 'close 终态回执完整');
        },
        static function (): void {
            closeStep('FAIL', '未收到 room:ok{close}');
        },
    );
}

/**
 * 验收项 7：断连跨容器清理（ADR-024 §9 V3）——重建房间 → B（uid 1002）登录直连 join →
 * C（uid 1003）登录直连 join（快照含 B）→ B 断连 → C 必须收到 room:member_leave{id=1002@*}。
 * A 不参与本项断言链，仅承担建房；其 re-join 能力由步骤 8 验证（步骤 6 close 已按 destroy 僵尸处置
 * 把受管玩家回填世界 EM/AOI）。
 * Item 7: cross-container disconnect cleanup (ADR-024 §9 V3) — rebuild the room → B (uid 1002) logs in, connects
 * directly and joins → C (uid 1003) logs in, connects directly and joins (snapshot contains B) → B disconnects →
 * C must receive room:member_leave{id=1002@*}. A takes no part in this item's assertion chain and only hosts the
 * room build; its re-join capability is verified by step 8 (step 6's close back-fills managed players into the
 * world EM/AOI per the destroy-zombie disposal).
 */
function step7DisconnectCleanup(): void
{
    // 1) 重建房间（step6 已 destroy；直接 create 的快速路径与 step1 同口径，撞残留房才清理重试）
    // 1) Rebuild the room (destroyed in step 6); the direct-create fast path matches step 1, cleaning up and retrying only on a leftover collision
    sendRoomFrame('room:create', ['roomId' => ROOM_ID]);
    waitFrame(
        $GLOBALS['client']['inbox'],
        'room:ok',
        static fn (array $f): bool => ($f['payload']['op'] ?? null) === 'create',
        8.0,
        static function (array $f): void {
            check(($f['payload']['roomId'] ?? null) === ROOM_ID, '重建房间 room:ok{create}');
            runDisconnectAssertion();
        },
        static function (): void {
            // 残留房清理重试：直接 close（handleClose 状态容错）后重建，事件驱动零滞留
            // Leftover-room cleanup retry: a direct close (handleClose's state tolerance) then rebuild, event-driven with zero lingering
            sendRoomFrame('room:close', ['roomId' => ROOM_ID]);
            waitFrame(
                $GLOBALS['client']['inbox'],
                'room:ok',
                static fn (array $f): bool => ($f['payload']['op'] ?? null) === 'close',
                8.0,
                static function (): void {
                    sendRoomFrame('room:create', ['roomId' => ROOM_ID]);
                    waitFrame(
                        $GLOBALS['client']['inbox'],
                        'room:ok',
                        static fn (array $f): bool => ($f['payload']['op'] ?? null) === 'create',
                        8.0,
                        static function (array $f): void {
                            check(($f['payload']['roomId'] ?? null) === ROOM_ID, '重建房间 room:ok{create}（残留房清理后）');
                            runDisconnectAssertion();
                        },
                        static function (): void {
                            closeStep('FAIL', '重建房间未收到 room:ok{create}');
                        },
                    );
                },
                static function (): void {
                    closeStep('FAIL', '残留房清理未收到 room:ok{close}');
                },
            );
        },
    );
}

/**
 * 断连清理断言主体：B（1002）与 C（1003）先后登录入房 → 触发 B 断连 → C 必须收到
 * room:member_leave{id=1002@*}（跨容器清理兜底摘除幽灵成员并广播 leave 语义的证据）。
 * The disconnect-cleanup assertion body: B (1002) and C (1003) log in and join in turn → B's disconnect is
 * triggered → C must receive room:member_leave{id=1002@*} (evidence the cross-container cleanup fallback evicted
 * the ghost member with leave semantics broadcast).
 */
function runDisconnectAssertion(): void
{
    loginRoomClient('client2', '1002', 'b', null, static function (): void {
        // C 登录入房并断言快照含 B（B 在房内的成员事实），随后触发 B 断连
        // C logs in and asserts its snapshot contains B (the in-room membership fact), then triggers B's disconnect
        loginRoomClient('client3', '1003', 'c', '1002@', static function (): void {
            $connB = $GLOBALS['client2']['conn'] ?? null;
            if (!$connB instanceof AsyncTcpConnection) {
                closeStep('FAIL', 'B 连接缺失，无法触发断连');

                return;
            }
            $connB->close();
            waitFrame(
                $GLOBALS['client3']['inbox'],
                'room:member_leave',
                static fn (array $f): bool => is_string($f['payload']['id'] ?? null) && str_starts_with($f['payload']['id'], '1002@') && (($f['payload']['roomId'] ?? null) === ROOM_ID),
                8.0,
                static function (array $f): void {
                    check(true, 'C 收到 room:member_leave{id=' . ($f['payload']['id'] ?? '') . '}（幽灵成员已被摘除）');
                    // 收尾销毁重建的房间：不留残留房给下一轮（close 幂等容错，回执不等待）
                    // Tear down the rebuilt room: no leftover for the next run (close is idempotent-tolerant; the receipt is not awaited)
                    sendRoomFrame('room:close', ['roomId' => ROOM_ID]);
                    closeStep('PASS', '断连跨容器清理闭环（V3）：房内成员摘除 + leave 语义广播');
                },
                static function (): void {
                    closeStep('FAIL', '8s 内未收到 room:member_leave（跨容器清理兜底未生效？）');
                },
            );
        });
    });
}

/**
 * 房间客户端上线（V3 断连清理验收用）：gateway 登录（指定 uid）→ Map 直连 auth_ok → join 房间。
 * $autoJoin=false 时 auth_ok 即视为就位并直接续接 $onJoined（留守大世界、不发 join）——G1 世界侧
 * entity_leave 断言要求 B 以旁观者身份留在世界见证 A join。
 * Brings a room client online (the V3 disconnect-cleanup acceptance): gateway login (the given uid) → direct-Map
 * auth_ok → join the room. With $autoJoin=false the auth_ok alone counts as ready and $onJoined runs immediately
 * (staying in the host world, no join sent) — the G1 world-side entity_leave assertion needs B as a bystander
 * inside the world witnessing A's join.
 *
 * @param string $stateKey 客户端状态槽位（client2/client3） Client state slot (client2/client3).
 * @param string $uid 登录账号 uid The login account uid.
 * @param string $tag requestId/断言文案标签 Label for requestIds and assertion texts.
 * @param null|string $expectPeerPrefix 非空时断言 room:snapshot.memberIds 含该前缀成员（在房成员事实链） When non-null, asserts room:snapshot.memberIds holds a member with this prefix (the in-room membership fact).
 * @param callable(): void $onJoined join 完成（room:ok{join} 已收到）后的续接回调；$autoJoin=false 时为 auth_ok 就位回调 Continuation invoked once the join receipt arrives; with $autoJoin=false, invoked once auth_ok settles.
 * @param bool $autoJoin auth_ok 后是否自动 join（false = 留守大世界） Whether to auto-join after auth_ok (false = stay in the host world).
 */
function loginRoomClient(string $stateKey, string $uid, string $tag, ?string $expectPeerPrefix, callable $onJoined, bool $autoJoin = true): void
{
    $state = &$GLOBALS[$stateKey];
    $state = ['inbox' => [], 'entityId' => '', 'authDone' => false, 'joinOk' => false];

    $social = new AsyncTcpConnection(GW_WS);
    $social->onConnect = static function (AsyncTcpConnection $c) use ($uid): void {
        $c->send(json_encode([
            'type' => 'auth',
            'requestId' => "login:{$uid}",
            'timestamp' => microtime(true),
            'payload' => ['username' => $uid, 'password' => 'secret', 'mapId' => 'map-1'],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    };
    // 注意：PHP 嵌套闭包不继承祖父作用域——内层 $map->onMessage 需要的变量必须先由本层 use 导入，
    // 否则内层 use 到的是未定义变量（null），运行时 TypeError（实测踩坑）。
    // Note: PHP nested closures never inherit the grandparent scope — variables the inner $map->onMessage needs
    // must first be imported by this layer's use clause, otherwise the inner use binds an undefined variable (null)
    // and blows up as a runtime TypeError (a measured pitfall).
    $social->onMessage = static function (AsyncTcpConnection $c, mixed $data) use (&$state, $uid, $tag, $stateKey, $expectPeerPrefix, $onJoined, $autoJoin): void {
        $decoded = json_decode((string) $data, true);
        if (!is_array($decoded) || ($decoded['type'] ?? null) !== 'auth_ok') {
            return;
        }
        $token = $decoded['payload']['token'] ?? null;
        $ws = $decoded['payload']['map']['wsAddress'] ?? null;
        if (!is_string($token) || !is_string($ws)) {
            check(false, "{$tag} 的 auth_ok 缺少 token 或 map.wsAddress");
            closeStep('FAIL', "{$tag} 登录负载不完整");

            return;
        }
        $c->close();

        // 直连 Map（二进制协议）：auth → auth_ok → join；快照与回执断言在主循环内完成
        // Direct Map connection (binary protocol): auth → auth_ok → join; snapshot/receipt assertions live in the main loop
        $map = new AsyncTcpConnection(MAP_WS);
        $map->websocketType = \Workerman\Protocols\Websocket::BINARY_TYPE_ARRAYBUFFER;
        $state['conn'] = $map;
        $map->onConnect = static function (AsyncTcpConnection $m) use ($token, $tag): void {
            $m->send(frameMap('auth', ['token' => $token], "map-auth-{$tag}:" . $token));
        };
        $map->onMessage = static function (AsyncTcpConnection $m, mixed $data) use (&$state, $stateKey, $uid, $tag, $expectPeerPrefix, $onJoined, $autoJoin): void {
            foreach (decodeMapFrames((string) $data) as $decoded) {
                $state['inbox'][] = $decoded;
                $type = $decoded['type'] ?? null;

                if (!$state['authDone'] && $type === 'auth_ok') {
                    $state['authDone'] = true;
                    $state['entityId'] = $decoded['payload']['id'] ?? '';
                    check(is_string($state['entityId']) && str_starts_with($state['entityId'], "{$uid}@"), "{$tag} 的 auth_ok.id 为 {$uid}@ 前缀 entityId");
                    // 留守模式：auth_ok 即就位，不发 join（G1 世界侧旁观断言用）
                    // Bystander mode: auth_ok alone settles; no join is sent (for the G1 world-side bystander assertion)
                    if (!$autoJoin) {
                        $onJoined();

                        continue;
                    }
                    sendRoomClient($stateKey, 'room:join', ['roomId' => ROOM_ID]);

                    continue;
                }

                // 顺序无关：room:ok{join} 直入 outbox 而 room:snapshot 经帧末总线 flush 转发，
                // ok 恒先于 snapshot 到达——故收到 ok 后反向等待 snapshot（与主客户端步骤 2 同口径）。
                // 留守模式（autoJoin=false）禁用本自动分支：join 由外部编排手动触发，snapshot 的等待与
                // 断言归外部回调所有——若此处抢先消费 snapshot 并重放 $onJoined 会串步（实测踩坑）。
                // Order-independent: room:ok{join} enters the outbox directly while room:snapshot is forwarded by the
                // frame-end bus flush, so ok always arrives BEFORE the snapshot — hence after ok, wait for the
                // snapshot backwards (matching the main client's step 2). The bystander mode (autoJoin=false)
                // disables this automatic branch: joins are triggered manually by external orchestration, which also
                // owns the snapshot wait and assertions — consuming the snapshot here first and replaying $onJoined
                // would cross-step the flow (a measured pitfall).
                if (!$state['joinOk'] && $autoJoin && $type === 'room:ok' && ($decoded['payload']['op'] ?? null) === 'join') {
                    $state['joinOk'] = true;
                    waitFrame(
                        $state['inbox'],
                        'room:snapshot',
                        static function (array $f) use ($expectPeerPrefix): bool {
                            if ($expectPeerPrefix === null) {
                                return true;
                            }
                            $memberIds = $f['payload']['memberIds'] ?? [];

                            return is_array($memberIds) && (bool) array_filter($memberIds, static fn (mixed $id): bool => is_string($id) && str_starts_with($id, $expectPeerPrefix));
                        },
                        8.0,
                        static function (array $f) use ($expectPeerPrefix, $tag, $onJoined): void {
                            if ($expectPeerPrefix !== null) {
                                // 快照含既有成员：证明对方确实在房内（断连前的成员事实）
                                // The snapshot holds the existing member: proof the peer was genuinely in the room (the pre-disconnect membership fact)
                                $memberIds = $f['payload']['memberIds'] ?? [];
                                $hasPeer = is_array($memberIds) && (bool) array_filter($memberIds, static fn (mixed $id): bool => is_string($id) && str_starts_with($id, $expectPeerPrefix));
                                check($hasPeer, "{$tag} 的 room:snapshot.memberIds 含 {$expectPeerPrefix} 前缀成员");
                            }
                            check(true, "{$tag} 收到 room:snapshot（transfer 路径完整）");
                            $onJoined();
                        },
                        static function () use ($tag): void {
                            closeStep('FAIL', "{$tag} 未收到 room:snapshot");
                        },
                    );

                    continue;
                }
            }
        };
        $map->onClose = static function () use (&$state, $tag): void {
            if (!$state['authDone']) {
                $state['authDone'] = true;
                check(false, "{$tag} 的 Map 连接在认证前关闭");
                closeStep('FAIL', "{$tag} 的 Map 连接关闭");
            }
        };
        $map->connect();
    };
    $social->connect();
}

/**
 * 向指定房间客户端的 Map 直连发送一帧（V3 断连清理验收用）。
 * Sends one frame on the given room client's direct Map connection (the V3 disconnect-cleanup acceptance).
 */
function sendRoomClient(string $stateKey, string $type, array $payload): void
{
    $conn = $GLOBALS[$stateKey]['conn'] ?? null;
    if ($conn instanceof AsyncTcpConnection) {
        $conn->send(frameMap($type, $payload, reqId()));
    }
}

/**
 * 验收项 8：双客户端容器路由（ADR-024 §9 V6 激活端到端）——重建房间后进入三段投递链断言。
 * Item 8: dual-client container routing (the ADR-024 §9 V6 activation e2e) — rebuilds the room, then enters the
 * three-leg delivery-chain assertion.
 */
function step8DualClientRouting(): void
{
    // 重建房间（step7 收尾已 close；快速路径与 step1/step7 同口径，撞残留房才清理重试）
    // Rebuild the room (torn down at the end of step 7); the fast path matches steps 1/7, cleaning up and retrying only on a leftover collision
    sendRoomFrame('room:create', ['roomId' => ROOM_ID]);
    waitFrame(
        $GLOBALS['client']['inbox'],
        'room:ok',
        static fn (array $f): bool => ($f['payload']['op'] ?? null) === 'create',
        8.0,
        static function (array $f): void {
            check(($f['payload']['roomId'] ?? null) === ROOM_ID, '重建房间 room:ok{create}');
            runDualClientAssertion();
        },
        static function (): void {
            sendRoomFrame('room:close', ['roomId' => ROOM_ID]);
            waitFrame(
                $GLOBALS['client']['inbox'],
                'room:ok',
                static fn (array $f): bool => ($f['payload']['op'] ?? null) === 'close',
                8.0,
                static function (): void {
                    sendRoomFrame('room:create', ['roomId' => ROOM_ID]);
                    waitFrame(
                        $GLOBALS['client']['inbox'],
                        'room:ok',
                        static fn (array $f): bool => ($f['payload']['op'] ?? null) === 'create',
                        8.0,
                        static function (array $f): void {
                            check(($f['payload']['roomId'] ?? null) === ROOM_ID, '重建房间 room:ok{create}（残留房清理后）');
                            runDualClientAssertion();
                        },
                        static function (): void {
                            closeStep('FAIL', '重建房间未收到 room:ok{create}');
                        },
                    );
                },
                static function (): void {
                    closeStep('FAIL', '残留房清理未收到 room:ok{close}');
                },
            );
        },
    );
}

/**
 * 双客户端断言主体第一段（G1 世界侧 entity_leave + destroy 处置证据）：B 先登录留守大世界（不 join）
 * 并瞬移 {dx=1,dy=0} 与 A 同格 (1,0)（A 位于步骤 2.5 后的 (1,0)；出生点 (0,0) 已因锚点外移 + 出生保护
 * 不再需要长距避险）；随后 A re-join——步骤 6 close 已把 A 回填世界（destroy 僵尸处置），再次入册成功
 * 本身即是处置生效的间接证据；A 进房时 handleJoin 摘世界登记前补发视野差分，B 必须收到
 * entity_leave{id=1001@*}。
 * Dual-client assertion leg 1 (the G1 world-side entity_leave plus destroy-disposal evidence): B logs in first and
 * stays in the host world (no join), teleporting {dx=1,dy=0} into A's cell (1,0) (A sits at (1,0) after step 2.5;
 * the spawn cell needs no long-range evasion anymore thanks to the relocated anchors and spawn protection). Then A
 * re-joins — step 6's close back-filled A into the world (the destroy-zombie disposal), so this successful
 * re-admission is itself indirect evidence the disposal works; as A enters the room, handleJoin back-fills the
 * vision delta before removing the world registration, so B must receive entity_leave{id=1001@*}.
 */
function runDualClientAssertion(): void
{
    loginRoomClient('client2', '1002', 'b', null, static function (): void {
        // B 世界侧瞬移与 A 同格（(0,0)→(1,0)）。
        // move 只改坐标、AOI 索引由下一帧 world update 全量刷新——延迟 1s 待 B 重进 cell(0,0) 索引后
        // 再触发 A join，否则 A join 的 leave 广播按旧索引查不到 B（实测踩坑）。
        // B teleports into A's cell on the world side ((0,0)→(1,0)). The move only mutates coordinates while the
        // AOI index refreshes via the next world-update sweep — delay 1s so B re-enters the cell(0,0) index before
        // A's join fires; otherwise A's join-leave broadcast misses B via the stale index (a measured pitfall).
        sendRoomClient('client2', 'move', ['dx' => 1, 'dy' => 0]);
        verifyTimer(1.0, static function (): void {
            sendRoomFrame('room:join', ['roomId' => ROOM_ID]);
            waitFrame(
                $GLOBALS['client']['inbox'],
                'room:ok',
                static fn (array $f): bool => ($f['payload']['op'] ?? null) === 'join',
                8.0,
                static function (): void {
                    check(true, 'A 回填世界后 re-join 成功（destroy 僵尸处置证据）');
                    // G1：A join 摘世界登记前补发 entity_leave，留守世界的 B 必须收到
                    // G1: A's join back-fills entity_leave before removing the world registration; the world-staying B must receive it
                    waitFrame(
                        $GLOBALS['client2']['inbox'],
                        'entity_leave',
                        static fn (array $f): bool => is_string($f['payload']['id'] ?? null) && str_starts_with($f['payload']['id'], '1001@'),
                        8.0,
                        static function (array $f): void {
                            check(true, sprintf('B 留守世界收到 A join 的 entity_leave{id=%s}（G1）', $f['payload']['id'] ?? ''));
                            runPostJoinWorldLeaveLeg();
                        },
                        static function (): void {
                            closeStep('FAIL', 'B 未收到 A join 的 entity_leave（G1 摘世界登记前补发缺失？）');
                        },
                    );
                },
                static function (): void {
                    closeStep('FAIL', 'A re-join 未收到 room:ok{join}（回填世界失败？）');
                },
            );
        }, [], false);
    }, autoJoin: false);
}

/**
 * 双客户端断言主体第二段（G1 后续接）：A 留守房内原位 (1,0)（B 自世界 (1,0) join 进房——transfer
 * 保留实体坐标，入房即与 A 同格，无需回原点调度）；随后 B join 同房并断言快照含 A（在房成员事实链），
 * 进入 enter/moved/leave 三段投递链。
 * Dual-client assertion leg 2 (post-G1 continuation): A stays put at its in-room position (1,0) — B joins from
 * the world at (1,0) and the transfer preserves entity coordinates, so B lands in-cell with A, no origin shuttle
 * needed; then B joins the same room with its snapshot asserted to contain A (the in-room membership fact chain),
 * moving on to the enter/moved/leave delivery-chain legs.
 */
function runPostJoinWorldLeaveLeg(): void
{
    sendRoomClient('client2', 'room:join', ['roomId' => ROOM_ID]);
    waitFrame(
        $GLOBALS['client2']['inbox'],
        'room:snapshot',
        static function (array $f): bool {
            $memberIds = $f['payload']['memberIds'] ?? [];

            return is_array($memberIds) && (bool) array_filter($memberIds, static fn (mixed $id): bool => is_string($id) && str_starts_with($id, '1001@'));
        },
        8.0,
        static function (array $f): void {
            // 快照含既有成员：证明对方确实在房内（断连前的成员事实）
            // The snapshot holds the existing member: proof the peer was genuinely in the room (the pre-disconnect membership fact)
            $memberIds = $f['payload']['memberIds'] ?? [];
            $hasPeer = is_array($memberIds) && (bool) array_filter($memberIds, static fn (mixed $id): bool => is_string($id) && str_starts_with($id, '1001@'));
            check($hasPeer, 'B 的 room:snapshot.memberIds 含 1001@ 前缀成员');
            check(true, 'B 收到 room:snapshot（transfer 路径完整）');
            runMemberEnterAssertion();
        },
        static function (): void {
            closeStep('FAIL', 'B 未收到 room:snapshot');
        },
    );
}

/**
 * 双客户端断言主体第三段：A 收 room:member_enter{id=1002@*}（B join 的既有成员通知跨容器到达），
 * 随后进入移动投递段。
 * Dual-client assertion leg 3: A receives room:member_enter{id=1002@*} (B's join notice reaching the existing
 * member across containers), then moves on to the move-delivery leg.
 */
function runMemberEnterAssertion(): void
{
    waitFrame(
        $GLOBALS['client']['inbox'],
        'room:member_enter',
        static fn (array $f): bool => is_string($f['payload']['id'] ?? null) && str_starts_with($f['payload']['id'], '1002@'),
        8.0,
        static function (array $f): void {
            check(true, 'A 收到 room:member_enter{id=' . ($f['payload']['id'] ?? '') . '}');
            runMoveDeliveryAssertion();
        },
        static function (): void {
            closeStep('FAIL', 'A 未收到 room:member_enter');
        },
    );
}

/**
 * 双客户端断言主体第四段：A move{dx=2,dy=3}（(1,0)→(3,3)，同 cell(0,0) 内位移，坐标可精确计算），
 * B 必须收到 entity_moved{id=1001@*, position=(3,3)}——房内 AOI 广播经容器维度路由投递到同房连接。
 * Dual-client assertion leg 4: A moves {dx=2,dy=3} ((1,0)→(3,3), an in-cell displacement inside cell (0,0),
 * exactly computable coordinates) and B must receive entity_moved{id=1001@*, position=(3,3)} — the in-room AOI
 * broadcast routed to the same-room connection via the container dimension.
 */
function runMoveDeliveryAssertion(): void
{
    sendRoomFrame('move', ['dx' => 2, 'dy' => 3]);
    waitFrame(
        $GLOBALS['client2']['inbox'],
        'entity_moved',
        static fn (array $f): bool => is_string($f['payload']['id'] ?? null) && str_starts_with($f['payload']['id'], '1001@')
            && (($f['payload']['position']['x'] ?? null) === 3)
            && (($f['payload']['position']['y'] ?? null) === 3),
        8.0,
        static function (array $f): void {
            check(true, 'B 收到 entity_moved{id=' . ($f['payload']['id'] ?? '') . ', position=(3,3)}（房内移动跨容器投递）');
            runMemberLeaveAssertion();
        },
        static function (): void {
            closeStep('FAIL', 'B 未收到 A 的 entity_moved（房内 AOI 广播未达？）');
        },
    );
}

/**
 * 双客户端断言主体第五段：B 强制断连 → A 观察窗内收 room:member_leave{id=1002@*}
 * （V3 跨容器清理在 V6 容器维度标记下的复验）；收尾销毁重建的房间。
 * Dual-client assertion leg 5: B force-disconnects → A receives room:member_leave{id=1002@*} within the observation
 * window (the V3 cross-container cleanup re-verified under V6's container marking); the rebuilt room is torn down.
 */
function runMemberLeaveAssertion(): void
{
    $connB = $GLOBALS['client2']['conn'] ?? null;
    if (!$connB instanceof AsyncTcpConnection) {
        closeStep('FAIL', 'B 连接缺失，无法触发断连');

        return;
    }
    $connB->close();
    waitFrame(
        $GLOBALS['client']['inbox'],
        'room:member_leave',
        static fn (array $f): bool => is_string($f['payload']['id'] ?? null) && str_starts_with($f['payload']['id'], '1002@'),
        8.0,
        static function (array $f): void {
            check(true, 'B 断连后 A 收到 room:member_leave{id=' . ($f['payload']['id'] ?? '') . '}');
            // 收尾销毁重建的房间：不留残留房给下一轮（close 幂等容错，回执不等待）
            // Tear down the rebuilt room: no leftover for the next run (close is idempotent-tolerant; the receipt is not awaited)
            sendRoomFrame('room:close', ['roomId' => ROOM_ID]);
            closeStep('PASS', '双客户端容器路由闭环（V6）：enter/moved/leave 三段投递完整');
        },
        static function (): void {
            closeStep('FAIL', 'B 断连后 A 未收到 room:member_leave');
        },
    );
}

// ── 步骤装配与全局看门狗 ──
// ── Step assembly and the global watchdog ──

$GLOBALS['verify']['steps'] = [
    ['0 前置登录 login', 'step0Login', 20.0],
    ['0.5 出生保护窗（R4）', 'step05SpawnProtection', 10.0],
    ['1 建房 room:create', 'step1CreateRoom', 10.0],
    ['2 玩家 join（transfer 路径）', 'step2JoinRoom', 20.0],
    ['2.5 join 后 move（V6）', 'step25MoveInRoom', 10.0],
    ['2.6 房内反作弊（MINOR-7）', 'step26InRoomAntiCheat', 15.0],
    [sprintf('3 房内刷怪 room:spawn{%d}', HORDE_COUNT), 'step3SpawnHorde', 15.0],
    [sprintf('4 一次 AoE 击杀 ≥%d（合并帧 1/1/1）', MIN_KILLS), 'step4AoeKill', 30.0],
    ['5 settle 生命周期回执', 'step5Settle', 20.0],
    ['6 close 终态', 'step6Close', 10.0],
    ['7 断连跨容器清理（V3）', 'step7DisconnectCleanup', 30.0],
    ['8 双客户端容器路由（V6）', 'step8DualClientRouting', 40.0],
];

$worker = new Worker();
$worker->onWorkerStart = static function (): void {
    echo "[verify] verify-room：R2 房间与 AoE 批量管线端到端验收（ADR-024）\n";

    // 全局看门狗：任一步骤挂死也能汇总退出（380s 与 verify-combat 同口径）
    // Global watchdog: even a hung step summarizes and exits (380s, matching verify-combat)
    Timer::add(380.0, static function (): void {
        echo "[verify] WATCHDOG: 全局超时\n";
        finishAll();
    }, [], false);
    nextStep();
};

// Workerman 5.2 要求 argv 中显式含自身命令：注入 start（前台 DEBUG 模式）。
// Workerman 5.2 requires an explicit own command in argv: inject start (foreground DEBUG mode).
$GLOBALS['argv'] = [$argv[0], 'start'];

// 未捕获 Throwable 兜底：打印后通知父 monitor 退出再 exit（防 worker 重启死循环，同 verify-combat）。
// Uncaught-Throwable backstop: report, ask the parent monitor to quit, then exit (prevents a worker-restart loop, same as verify-combat).
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
