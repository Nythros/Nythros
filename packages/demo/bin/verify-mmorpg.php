<?php

declare(strict_types=1);

// 定位：packages/demo/bin/verify-mmorpg.php — R4 mmorpg 类型模块端到端验收脚本。
// Located at: packages/demo/bin/verify-mmorpg.php — the R4 mmorpg type-module end-to-end acceptance script.
//
// 为什么新建而非扩展 verify-combat：mmorpg 验收的步骤矩阵（威胁切换/重生/任务链配置）与战斗层验收
// （攻击/死亡/掉落/拾取）正交；塞进 verify-combat 会叠加 NYTHROS_MMORPG=1 环境且脚本已逾千五百行——
// 独立脚本让两份验收各自聚焦，失败归因互不干扰（比照 verify-matching/verify-economy 独立先例）。
// Why a new script instead of extending verify-combat: mmorpg's step matrix (threat switching / respawn /
// quest-chain config) is orthogonal to the combat-tier acceptance (attack/death/drop/pickup); folding it into
// verify-combat would stack NYTHROS_MMORPG=1 on top of a script already past 1500 lines — separate scripts keep
// each acceptance focused with independent failure attribution (mirroring the verify-matching/verify-economy precedents).
//
// 验收链路（3 客户端 uid 1001-1003，风格对齐 verify-matching）：
// Acceptance chain (three clients uids 1001-1003, styled after verify-matching):
//   0 前置：gateway 18285 登录 → token → Map 18081 直连（auth_ok 后各发一帧避险 move 出世界怪巡逻域）
//     Prerequisite: gateway-18285 login → token → direct Map-18081 (each client sends one evasive move after
//     auth_ok, out of the world monsters' patrol domains)
//   1 威胁切换：1001/1002 移动到 monster-1 附近轮流攻击（1001 一次、1002 三次）→ 受击方威胁表记录
//     （1001=10、1002=30）→ 怪物 aggro 选择最高威胁者切换目标 → 断言怪物攻击 1002（combat:hit
//     attackerId=monster-1 targetId=1002 的 entityId）
//     Threat switching: 1001/1002 move near monster-1 and attack in turn (1001 once, 1002 three times) → the hit
//     side's threat table records (1001=10, 1002=30) → the monster's aggro picks the highest threat and switches
//     targets → assert the monster attacks 1002 (combat:hit attackerId=monster-1 targetId=1002's entityId)
//   2 重生：1001/1002 移动到 monster-2 附近集火击杀 → entity_dead → respawnMs（缺省 5s）后 monster:spawned
//     再现且位置回锚点 (-6,-6)
//     Respawn: 1001/1002 move near monster-2 and focus-fire it down → entity_dead → after respawnMs (default 5s)
//     monster:spawned reappears with the position back at the anchor (-6,-6)
//   3 任务链配置：MmorpgConfig questChain 解析断言（链 id 与链式任务顺序）
//     Quest-chain config: MmorpgConfig questChain parsing assertions (the chain id and the ordered quest ids)
//   4 任务链运行时（P2 收口，真实链路）：链式解锁 + 顺序推进——quest:rows 初始全零 → 锁定断言
//     （kill_wolves 未完成时 quest:talk npc-elder 不计，talk_elder 计数保持 0）→ 集火击杀 monster-2（wolf）
//     两次并拾取掉落骨 ×2 → quest:rows 断言 kill_wolves=2 完成、collect_bones=1 完成（kill1 的骨在解锁前被
//     链门忽略，kill2 的骨解锁后计入）→ talk_elder 解锁后 quest:talk 一次即完成 → 整链闭环（三任务全
//     completed，进度 [2,1,1]）。
//     Quest-chain runtime (the P2 close-out, real link): chained unlocking + sequential advancement — quest:rows
//     all-zero initially → the lock assertion (with kill_wolves incomplete, quest:talk npc-elder leaves talk_elder
//     at 0) → focus-fire monster-2 (wolf) twice and pick up the 2 bone drops → quest:rows asserts kill_wolves=2
//     complete and collect_bones=1 complete (kill1's bone was ignored by the chain gate while locked; kill2's bone
//     counted once unlocked) → with talk_elder unlocked, one quest:talk completes it → the whole chain closes
//     (all three quests completed, progress [2,1,1]).
//   5 任务领奖（P4a 收口）：kill_wolves 已完成未领奖 → quest:claim 领奖（potion×2 入包 + claim ok）→
//     rewarded 落位 → 重复领奖幂等拒绝（not_ready）→ talk_elder（无奖励表）领奖 ok 且无 item:added →
//     rewarded 全落位。
//     Quest claim (the P4a close-out): kill_wolves completed-and-unclaimed → quest:claim claims it (potion×2 into
//     the bag + claim ok) → rewarded lands → a repeated claim is idempotently rejected (not_ready) → talk_elder
//     (no reward table) claims ok without item:added → all rewarded flags land.
//   6 嘲讽技能（P4b 接入，关闭 P1 预留）：1001 施放嘲讽（tauntThreat 1000）→ 怪物威胁表写入 →
//     aggro 切换到嘲讽者 → 断言怪物攻击 1001。
//     The taunt skill (the P4b wiring, closing the P1 reservation): 1001 casts the taunt (tauntThreat 1000) →
//     the monster's threat table records it → aggro switches to the taunter → assert the monster attacks 1001.
//   7 玩家复活（P5a）：确定性探针判定待复活状态（ok=已死直接断言 / not_ready=先战死）→ 复活回执落点
//     (0,0) + player:stats 满血。
//     Player revive (the P5a): a deterministic probe judges the awaiting-revive state (ok = dead, assert directly;
//     not_ready = fight to the death first) → the revive receipt's landing (0,0) + a full-hp player:stats frame.
//   8 任务奖励落库复核（P5b）：step5 领奖的 potion×2 经归档 30s 兜底批量落库 → MySQL 复核
//     inventory.potion = 4。
//     The quest-reward persistence review (the P5b): step5's claimed potion×2 lands via the archive's 30s fallback
//     batch → MySQL confirms inventory.potion = 4.
//   9 AoE 嘲讽（P5c）：先探针复活 1003（step6 的嘲讽目标被 monster-1 磨死）→ 远点施法断言 out_of_range
//     （P6c 施法距离门）→ skill:cast_aoe 施放（castSkillAoE 消费 tauntThreat）→ monster-2 切到嘲讽者 →
//     断言 combat:hit。
//     The AoE taunt (the P5c): probe-revive 1003 first (step6's taunt target was ground down by monster-1) → a
//     far-point cast asserts out_of_range (the P6c cast-distance gate) → skill:cast_aoe (castSkillAoE consumes
//     tauntThreat) → monster-2 switches to the taunter → assert combat:hit.
//  10 时间制自动复活（P6a）：1001 攻击 monster-2 被反击至死（全程不发送 player:revive）→ playerRespawnMs
//     到期服务端主动复活：断言未请求收到 player:revive ok（落点 (0,0)）+ player:stats 满血。
//     The timed auto-revive (the P6a): 1001 attacks monster-2 and is counterattacked to death (never sending
//     player:revive) → after playerRespawnMs the server revives proactively: assert receiving an unrequested
//     player:revive ok (landing (0,0)) + a full-hp player:stats frame.
//
// 前置环境：Redis 127.0.0.1:6379 可用；MySQL 127.0.0.1:3306（nythros 库）。服务启动（WSL 内 setsid -f 防 SIGHUP）：
//   cd /mnt/d/workspace/php/Nythros && NYTHROS_MMORPG=1 NYTHROS_GAMEPLAY=1 \
//     NYTHROS_MMORPG_CHAINS='main-line=kill_wolves,collect_bones,talk_elder' \
//     NYTHROS_MMORPG_PLAYER_RESPAWN_MS=2000 NYTHROS_MMORPG_SAFE_ZONE='0,0,5' \
//     NYTHROS_ACCOUNTS=1001=secret,... setsid -f php bin/server start
// （任务链运行时需玩法批装配：NYTHROS_GAMEPLAY=1 提供 QuestService 与 quest:* 路由；任务链配置经
//   NYTHROS_MMORPG_CHAINS 注入 MmorpgConfig，玩法批消费。威胁/重生步骤不受 GAMEPLAY 影响——buff/冷却
//   表对普攻路径惰性。NYTHROS_MMORPG_PLAYER_RESPAWN_MS=2000 开启时间制自动复活（P6a，step10 消费）；
//   NYTHROS_MMORPG_SAFE_ZONE='0,0,5' 声明出生安全区（P7c）：区内（出生格）玩家对怪物 AI 不可见——复活后蹲守集火在结构上不可能。）
// Prerequisites: Redis on 127.0.0.1:6379; MySQL on 127.0.0.1:3306 (the nythros DB). Boot (inside WSL use setsid -f):
//   cd /mnt/d/workspace/php/Nythros && NYTHROS_MMORPG=1 NYTHROS_GAMEPLAY=1 \
//     NYTHROS_MMORPG_CHAINS='main-line=kill_wolves,collect_bones,talk_elder' \
//     NYTHROS_MMORPG_PLAYER_RESPAWN_MS=2000 NYTHROS_MMORPG_SAFE_ZONE='0,0,5' \
//     NYTHROS_ACCOUNTS=1001=secret,... setsid -f php bin/server start
// (The quest-chain runtime needs the gameplay batch: NYTHROS_GAMEPLAY=1 supplies QuestService and the quest:*
//   routes; the chain config rides NYTHROS_MMORPG_CHAINS into MmorpgConfig, consumed by the gameplay wiring. The
//   threat/respawn steps are unaffected by GAMEPLAY — buffs/cooldowns stay inert on the basic-attack path.
//   NYTHROS_MMORPG_PLAYER_RESPAWN_MS=2000 turns on the timed auto-revive (the P6a, consumed by step10).
//   NYTHROS_MMORPG_SAFE_ZONE='0,0,5' declares the spawn safe zone (the P7c): players inside (the spawn cell) are invisible to monster AI — post-revive camping is structurally impossible.)
//
// 输出契约：每项一行 [verify] [PASS|FAIL]；末行 RESULT 汇总（与 verify-combat 一致）。
// Output contract: one line per item [verify] [PASS|FAIL]; a final RESULT summary line (matching verify-combat).

require __DIR__ . '/../../../vendor/autoload.php';
require __DIR__ . '/lib/map-codec.php';
require __DIR__ . '/lib/verify-framework.php';

use Nythros\Framework\Deploy\DeployConfig;
use Nythros\Framework\Game\Mmorpg\MmorpgConfig;
use Nythros\Framework\Quest\QuestChain;
use Workerman\Connection\AsyncTcpConnection;
use Workerman\Timer;
use Workerman\Worker;

const GW_WS = 'ws://127.0.0.1:18285';
const MAP_WS = 'ws://127.0.0.1:18081'; // map-1#ch-1（mmorpg 验收目标频道） map-1#ch-1 (the mmorpg-acceptance target channel)

/** @var list<string> 验收客户端 uid 列表 The acceptance client uids. */
const UIDS = ['1001', '1002', '1003'];

// P14 公共库接管骨架全局状态（steps 表在脚本尾部注册；stepTimers 为定时器登记表——失败清理核心）。
// The P14 common library owns the skeleton global state (the steps table registers at the script's tail;
// stepTimers is the timer registry — the failure-cleanup core).
bootVerifyGlobals([]);


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
            $map->onMessage = static function (AsyncTcpConnection $m, mixed $data) use (&$state, &$mapDone, $uid): void {
                foreach (decodeMapFrames((string) $data) as $decodedFrame) {
                    $state['inbox'][] = $decodedFrame;
                    if (($decodedFrame['type'] ?? null) === 'error') {
                        echo sprintf("[verify] error frame: %s\n", json_encode($decodedFrame['payload'] ?? [], JSON_UNESCAPED_UNICODE));
                    }
                    if (!$mapDone && ($decodedFrame['type'] ?? null) === 'auth_ok') {
                        $mapDone = true;
                        $entityId = $decodedFrame['payload']['id'] ?? '';
                        check(is_string($entityId) && str_starts_with($entityId, $uid . '@'), $uid . ' Map auth_ok 就位');
                        if (is_string($entityId)) {
                            $GLOBALS['entityIds'][$uid] = $entityId;
                        }
                        // 避险 move：一步跳出世界怪的感知/攻击范围（(100,100) 落 cell(10,10)，
                        // 远在 monster-1/2 巡逻域与九宫格视野之外）。
                        // The evasive move: one hop beyond the world monsters' perception/attack range
                        // ((100,100) lands in cell(10,10), far outside both patrol domains and any 3x3 view).
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
 * 验收项 1：威胁切换——1001/1002 移动到 monster-1 附近轮流攻击（1001 一次、1002 三次），
 * 受击方威胁表记录（1001=10、1002=30），怪物 aggro 选择最高威胁者切换目标，
 * 断言怪物攻击 1002（combat:hit attackerId=monster-1 targetId=1002 的 entityId）。
 * Item 1: threat switching — 1001/1002 move near monster-1 and attack in turn (1001 once, 1002 three times);
 * the hit side's threat table records (1001=10, 1002=30); the monster's aggro picks the highest threat and
 * switches targets; assert the monster attacks 1002 (combat:hit attackerId=monster-1 targetId=1002's entityId).
 */
function step1ThreatSwitch(): void
{
    // 移动到 monster-1 锚点 (15,15)（从避险位 (100,100) 一步到位）
    // Move to monster-1's anchor (15,15) (one hop from the evasive position (100,100))
    sendMap('1001', 'move', ['dx' => -85, 'dy' => -85]);
    sendMap('1002', 'move', ['dx' => -85, 'dy' => -85]);

    // 轮流攻击：1001 一次（威胁 10）、1002 三次（威胁 30），间隔 0.3s 覆盖玩家攻击冷却（5 帧 = 0.25s）
    // Attack in turn: 1001 once (threat 10), 1002 three times (threat 30), 0.3s apart covering the player attack
    // cooldown (5 frames = 0.25s)
    verifyTimer(0.5, static function (): void {
        sendMap('1001', 'attack', ['targetId' => 'monster-1']);
        verifyTimer(0.3, static function (): void {
            sendMap('1002', 'attack', ['targetId' => 'monster-1']);
            verifyTimer(0.3, static function (): void {
                sendMap('1002', 'attack', ['targetId' => 'monster-1']);
                verifyTimer(0.3, static function (): void {
                    sendMap('1002', 'attack', ['targetId' => 'monster-1']);
                    // 等待怪物攻击高威胁者 1002（aggro 切换证据）
                    // Wait for the monster to attack the highest threat 1002 (the aggro-switch evidence)
                    waitMapFrame('1002', 'combat:hit', static fn (array $f): bool => ($f['payload']['attackerId'] ?? null) === 'monster-1'
                        && ($f['payload']['targetId'] ?? null) === ($GLOBALS['entityIds']['1002'] ?? ''), 15.0, static function (): void {
                            // P6a 适配：1001 撤回避险位 (100,100)——留在 monster-1 巡逻域会被游荡怪物反击致死，
                            // 自动复活又把他传回 (0,0)，step4 的位移基准随之失效（E2E 实测踩坑）。
                            // The P6a adaptation: 1001 falls back to the evasive (100,100) — lingering in monster-1's
                            // patrol domain invites a roaming counterattack death, and the auto-revive then teleports
                            // him back to (0,0), invalidating step4's move base (surfaced by the E2E).
                            sendMap('1001', 'move', ['dx' => 85, 'dy' => 85]);
                            check(true, '怪物 aggro 切换到高威胁者 1002（combat:hit attackerId=monster-1 targetId=1002）');
                            closeStep('PASS', '威胁切换（受击方记威胁 + aggro 选最高者）');
                        }, static function (): void {
                            check(false, '怪物未攻击高威胁者 1002（aggro 切换未生效）');
                            closeStep('FAIL', '威胁切换未生效');
                        });
                }, [], false);
            }, [], false);
        }, [], false);
    }, [], false);
}

/**
 * 验收项 2：重生——1001/1002 移动到 monster-2 附近集火击杀 → entity_dead →
 * respawnMs（缺省 5s）后 monster:spawned 再现且位置回锚点 (-6,-6)。
 * Item 2: respawn — 1001/1002 move near monster-2 and focus-fire it down → entity_dead → after respawnMs
 * (default 5s) monster:spawned reappears with the position back at the anchor (-6,-6).
 */
function step2Respawn(): void
{
    // 移动到 monster-2 锚点 (-6,-6)（从 monster-1 位 (15,15) 一步到位）。
    // P4c 隔离修正：改用 1002/1003 攻击——step2 击杀会给最后一击者记 kill_wolves 进度，若落在 1001
    // 上则 step4 的「初始进度全零」断言失效（真实随机伤害 8-12 让最后一击归属抖动，P2 潜在抖动本次暴露）。
    // 位移按各客户端实际位置计算：1002 从 (15,15) → (-6,-6)（dx=-21）；1003 从 step0 避险位 (100,100)
    // → (-6,-6)（dx=-106）——此前按 (-6,-6) 推算 dx=-21 把 1003 挪到 (79,79)，其攻击全部 out_of_range、
    // step6 的位移亦基于错误起点（E2E 实测暴露）。
    // The P4c isolation fix: 1002/1003 attack instead — step2's kill credits kill_wolves progress to whoever
    // lands the final blow, and a 1001 credit would break step4's all-zero-initial assertion (real random damage
    // 8-12 makes the last-blow attribution flaky — a latent P2 flake this batch surfaced). Displacements follow
    // each client's actual position: 1002 from (15,15) → (-6,-6) (dx=-21); 1003 from step0's evasive (100,100)
    // → (-6,-6) (dx=-106) — assuming (-6,-6) and sending dx=-21 had parked 1003 at (79,79), its attacks all
    // out_of_range and step6's displacement built on the wrong origin (surfaced by the E2E).
    sendMap('1002', 'move', ['dx' => -21, 'dy' => -21]);
    sendMap('1003', 'move', ['dx' => -106, 'dy' => -106]);

    // 轮流集火 monster-2（maxHp 150：两玩家每 0.3s 一轮两击 = 20 伤害，约 8 轮 = 2.4s 击杀）
    // Focus-fire monster-2 in turn (maxHp 150: two players, one round of two hits every 0.3s = 20 damage,
    // ~8 rounds = 2.4s to kill)
    $stop = false;
    $attack = null;
    $attack = static function () use (&$attack, &$stop): void {
        if ($stop) {
            return;
        }
        sendMap('1002', 'attack', ['targetId' => 'monster-2']);
        verifyTimer(0.3, static function () use (&$attack, &$stop): void {
            if ($stop) {
                return;
            }
            sendMap('1003', 'attack', ['targetId' => 'monster-2']);
            verifyTimer(0.3, $attack, [], false);
        }, [], false);
    };
    $attack();

    waitMapFrame('1002', 'entity_dead', static fn (array $f): bool => ($f['payload']['id'] ?? null) === 'monster-2', 25.0, static function () use (&$stop): void {
        $stop = true;
        check(true, 'monster-2 死亡 entity_dead');
        // 等待重生：respawnMs 缺省 5000ms，15s 窗口覆盖
        // Wait for the respawn: respawnMs defaults to 5000ms, a 15s window covers it
        waitMapFrame('1002', 'monster:spawned', static fn (array $f): bool => ($f['payload']['id'] ?? null) === 'monster-2', 15.0, static function (array $f): void {
            $position = $f['payload']['position'] ?? null;
            check(is_array($position) && ($position['x'] ?? null) === -6 && ($position['y'] ?? null) === -6, 'monster-2 重生回锚点 (-6,-6)');
            closeStep('PASS', '重生（死亡登记 → respawnMs 后回锚点）');
        }, static function (): void {
            check(false, 'monster-2 未在重生窗口内再现');
            closeStep('FAIL', '重生未生效');
        });
    }, static function () use (&$stop): void {
        $stop = true;
        check(false, 'monster-2 未被击杀');
        closeStep('FAIL', '集火击杀失败');
    });
}

/**
 * 验收项 3：任务链配置——MmorpgConfig questChain 解析断言（链 id 与链式任务顺序）。
 * Item 3: quest-chain config — MmorpgConfig questChain parsing assertions (the chain id and the ordered quest ids).
 */
function step3QuestChain(): void
{
    $config = new MmorpgConfig(questChains: [new QuestChain('main-line', ['kill_wolves', 'collect_bones', 'talk_elder'])]);

    check(count($config->questChains) === 1, 'questChains 解析出 1 条链');
    check($config->questChains[0]->id === 'main-line', '链 id 解析为 main-line');
    check($config->questChains[0]->questIds === ['kill_wolves', 'collect_bones', 'talk_elder'], '链式任务顺序解析（kill → collect → talk）');

    closeStep('PASS', '任务链配置解析');
}

/**
 * 验收项 4：任务链运行时（P2 收口）——链式解锁 + 顺序推进的真实链路：
 * 初始 quest:rows 全零 → 锁定断言（kill_wolves 未完成时 quest:talk 不计，talk_elder 计数保持 0）→
 * 集火击杀 monster-2（wolf）两次并拾取掉落骨 ×2 → kill_wolves/collect_bones 完成（count=2）→
 * talk_elder 解锁后一次对话完成 → 整链三任务全 completed 闭环。
 * 前置：服务器需 NYTHROS_GAMEPLAY=1（QuestService 与 quest:* 路由）+ NYTHROS_MMORPG_CHAINS 注入任务链。
 * Item 4: the quest-chain runtime (the P2 close-out) — the real chained-unlock + sequential-advancement link:
 * quest:rows all-zero initially → the lock assertion (with kill_wolves incomplete, quest:talk leaves talk_elder at
 * 0) → focus-fire monster-2 (wolf) twice and pick up the 2 bone drops → kill_wolves/collect_bones complete
 * (count=2) → one talk completes the now-unlocked talk_elder → all three quests completed, the chain closes.
 * Prerequisite: the server runs with NYTHROS_GAMEPLAY=1 (QuestService and the quest:* routes) plus the
 * NYTHROS_MMORPG_CHAINS-injected chain.
 */
function step4QuestChainRuntime(): void
{
    // 清空 1001 收件箱（P2 归属修正）：step2 击杀残留的 drop:spawned 旧帧（掉落归属 1002）会污染本步的
    // 拾取收集器——旧帧被先取走、拾取被 not_owner 拒绝，导致 item:added 确认超时。本步只关心自己的新帧。
    // Clear 1001's inbox (the P2 ownership fix): step2's kill left stale drop:spawned frames (drops owned by 1002)
    // that would pollute this step's pickup collector — the stale frames get taken first, their pickups rejected
    // not_owner, and the item:added confirmation times out. This step only cares about its own fresh frames.
    // P6a 适配：位移基准「复活探针」确定性判定——复活帧观察存在「死亡发生在基准检查后、复活帧到达前」
    // 的竞态（实测踩坑），改为先发 player:revive 读回执：ok = 1001 死过且已被复活/即被复活到 (0,0)；
    // not_ready = 存活且自 step1 撤离后未死（恒在避险位 (100,100)）。两分支均无歧义，随后 kill-loop 内
    // 的复活观察覆盖战中复活。
    // The P6a adaptation: the move base is judged deterministically by a revive probe — frame-watching had the
    // race "death lands after the base check, before the revive frame arrives" (a measured pitfall); send
    // player:revive first and read the receipt: ok = 1001 was dead and is/will be revived to (0,0); not_ready =
    // alive and never died since step1's fallback (always at the evasive (100,100)). Both branches are
    // unambiguous, and the kill-loop's revive watch covers a mid-fight revival.
    sendMap('1001', 'player:revive', []);
    waitMapFrame('1001', 'player:revive', null, 10.0, static function (array $f): void {
        $reviveBase = ($f['payload']['code'] ?? null) === 'ok' ? ['x' => 0, 'y' => 0] : ['x' => 100, 'y' => 100];
        // 清空 1001 收件箱（P2 归属修正）：step2 击杀残留的 drop:spawned 旧帧会污染本步的拾取收集器。
        // Clear 1001's inbox (the P2 ownership fix): step2's stale drop:spawned frames would pollute this step's
        // pickup collector.
        $GLOBALS['clients']['1001']['inbox'] = [];
        // P4c 隔离修正：step2 起 1001 不再参战（击杀进度隔离），本步开始把 1001 移到 monster-2 锚点 (-6,-6)；
        // 首攻前留 0.6s 让移动落地。
        // The P4c isolation fix: since step2 1001 no longer fights (kill-progress isolation); this step moves 1001
        // to monster-2's anchor (-6,-6), leaving 0.6s before the first attack.
        sendMap('1001', 'move', ['dx' => -6 - $reviveBase['x'], 'dy' => -6 - $reviveBase['y']]);
        $GLOBALS['verify']['quest'] = ['phase' => 0, 'kills' => 0];
        questTick();
    }, static function (): void {
        closeStep('FAIL', 'step4 复活基准探针超时');
    });
}

/** 任务链运行时状态机 tick：每阶段注册 waitMapFrame，回调推进相位并再次 tick（事件驱动、无阻塞等待）。
 *  The quest-chain runtime state machine tick: each phase registers a waitMapFrame whose callback advances the
 *  phase and ticks again (event-driven, never blocking). */
function questTick(): void
{
    $s = &$GLOBALS['verify']['quest'];
    switch ($s['phase']) {
        case 0: // 初始 rows：三任务全零（链首解锁、后两环锁定）
            sendMap('1001', 'quest:list', []);
            waitMapFrame('1001', 'quest:rows', null, 10.0, static function (array $f) use (&$s): void {
                $p = $f['payload'];
                $actual = json_encode(['counts' => $p['counts'] ?? null, 'completed' => $p['completed'] ?? null, 'rewarded' => $p['rewarded'] ?? null], JSON_UNESCAPED_UNICODE);
                check(($p['questIds'] ?? []) === ['kill_wolves', 'collect_bones', 'talk_elder'], 'quest:rows 三任务按定义全集列出');
                check(($p['counts'] ?? []) === [0, 0, 0] && ($p['completed'] ?? []) === [false, false, false], "初始进度全零（实际 {$actual}）");
                $s['phase'] = 1;
                questTick();
            }, static function (): void {
                closeStep('FAIL', '初始 quest:rows 超时');
            });
            break;
        case 1: // 锁定断言：talk_elder 未解锁时 quest:talk npc-elder（对话被链门忽略）
            sendMap('1001', 'quest:talk', ['npcId' => 'npc-elder']);
            waitMapFrame('1001', 'quest:result', static fn (array $f): bool => ($f['payload']['op'] ?? null) === 'talk', 10.0, static function () use (&$s): void {
                $s['phase'] = 2;
                questTick();
            }, static function (): void {
                closeStep('FAIL', 'quest:talk 回执超时');
            });
            break;
        case 2: // 复查 rows：talk_elder 计数仍 0（链式解锁门生效）
            sendMap('1001', 'quest:list', []);
            waitMapFrame('1001', 'quest:rows', null, 10.0, static function (array $f) use (&$s): void {
                $counts = $f['payload']['counts'] ?? [];
                check(($counts[2] ?? null) === 0, '锁定中的 talk_elder 对话不计（链式解锁门生效）');
                $s['phase'] = 3;
                questTick();
            }, static function (): void {
                closeStep('FAIL', '锁定断言 quest:rows 超时');
            });
            break;
        case 3: // 第一次杀狼 cycle
            questKillCycle();
            break;
        case 4: // 拾取第一杀掉落（bone + potion）
            questPickupDrops();
            break;
        case 5: // 第二次杀狼 cycle（先等重生）
            questKillCycle();
            break;
        case 6: // 拾取第二杀掉落
            questPickupDrops();
            break;
        case 7: // 推进断言：kill_wolves/collect_bones 完成、talk_elder 仍 0
            sendMap('1001', 'quest:list', []);
            waitMapFrame('1001', 'quest:rows', null, 10.0, static function (array $f) use (&$s): void {
                $p = $f['payload'];
                // 链门语义（P2）：kill1 的骨在 kill_wolves 完成前被忽略（拾取成功但进度不计），
                // kill2 的骨在解锁后计入——collect_bones count=1 而非 2。
                // The chain-gate semantics (the P2): kill1's bone was ignored while kill_wolves was incomplete
                // (the pickup succeeded but the progress never counted); kill2's bone counted once unlocked —
                // collect_bones sits at 1, not 2.
                $actual = json_encode(['counts' => $p['counts'] ?? null, 'completed' => $p['completed'] ?? null], JSON_UNESCAPED_UNICODE);
                check(($p['counts'] ?? []) === [2, 1, 0], "两杀两拾后实际 {$actual}（期望 counts=[2,1,0]：kill1 的骨解锁前被链门忽略）");
                check(($p['completed'] ?? []) === [true, true, false], "实际 completed={$actual}（期望 [true,true,false]）");
                $s['phase'] = 8;
                questTick();
            }, static function (): void {
                closeStep('FAIL', '推进断言 quest:rows 超时');
            });
            break;
        case 8: // talk_elder 已解锁：一次对话完成
            sendMap('1001', 'quest:talk', ['npcId' => 'npc-elder']);
            waitMapFrame('1001', 'quest:result', static fn (array $f): bool => ($f['payload']['op'] ?? null) === 'talk', 10.0, static function () use (&$s): void {
                $s['phase'] = 9;
                questTick();
            }, static function (): void {
                closeStep('FAIL', '解锁后 quest:talk 回执超时');
            });
            break;
        case 9: // 终态 rows：整链闭环
            sendMap('1001', 'quest:list', []);
            waitMapFrame('1001', 'quest:rows', null, 10.0, static function (array $f): void {
                $p = $f['payload'];
                $actual = json_encode(['counts' => $p['counts'] ?? null, 'completed' => $p['completed'] ?? null], JSON_UNESCAPED_UNICODE);
                check(($p['counts'] ?? []) === [2, 1, 1], "整链进度实际 {$actual}（期望 counts=[2,1,1]）");
                check(($p['completed'] ?? []) === [true, true, true], "实际 completed={$actual}（期望 [true,true,true]）");
                closeStep('PASS', '任务链运行时（锁定忽略 → 杀怪推进 → 解锁 → 对话完成 → 整链闭环）');
            }, static function (): void {
                closeStep('FAIL', '终态 quest:rows 超时');
            });
            break;
        default:
            closeStep('FAIL', '任务链状态机非法相位');
    }
}

/**
 * 集火击杀 monster-2 一轮（P2 归属修正：1001 单攻——掉落 ownerUid = 最后伤害来源，交替攻击会让 1002
 * 拿最后一击、掉落归 1002，1001 拾取被 not_owner 拒绝；单攻保证击杀者=拾取者=任务 uid 三者一致）：
 * 等 entity_dead 确认后 kills++ 并进拾取相位；第二杀前先等重生（monster:spawned）。
 * One focus-fire kill round of monster-2 (the P2 ownership fix: 1001 attacks alone — the drop ownerUid is the
 * last damage source, so alternating would let 1002 land the final blow and own the drops, and 1001's pickups
 * would be rejected not_owner; solo attacking keeps killer = picker = the quest uid aligned): after entity_dead
 * confirms, kills++ and the pickup phase begins; the second round first waits for the respawn (monster:spawned).
 */
function questKillCycle(): void
{
    $s = &$GLOBALS['verify']['quest'];
    $kill = static function () use (&$s): void {
        $stop = false;
        // P6a 适配：1001 中途战死会被时间制自动复活传回出生点 (0,0)——攻击循环观察 player:revive ok 帧，
        // 命中即重新归位 monster-2 巡逻域中心 (-6,-6)（移动落地后继续攻击），消除「复活后远离巡逻域 →
        // 攻击静默失效 → 击杀超时」的抖动路径。
        // The P6a adaptation: a mid-fight death auto-revives 1001 back at the spawn (0,0) — the attack loop watches
        // for the player:revive ok frame, and on hit re-approaches the monster-2 patrol-domain center (-6,-6)
        // (attacking resumes once the move lands), removing the flaky "revived far from the patrol domain → silent
        // attack failures → kill timeout" path.
        $attack = null;
        $attack = static function () use (&$attack, &$stop, &$s): void {
            if ($stop) {
                return;
            }
            // 只选择性取出 player:revive ok 帧（不得整箱清空——entity_dead 的 waitMapFrame 轮询同一收件箱）；
            // 命中即 1001 已被自动复活传回 (0,0)，重新归位巡逻域中心后继续攻击。
            // Selectively take only the player:revive ok frames (never wipe the inbox — the entity_dead
            // waitMapFrame polls the same inbox); on hit 1001 was auto-revived back to (0,0) — re-approach the
            // domain center and resume attacking.
            $revived = inboxTake($GLOBALS['clients']['1001']['inbox'], 'player:revive', static fn (array $f): bool => ($f['payload']['code'] ?? null) === 'ok');
            if ($revived !== null) {
                sendMap('1001', 'move', ['dx' => -6, 'dy' => -6]);
            }
            sendMap('1001', 'attack', ['targetId' => 'monster-2']);
            verifyTimer(0.3, $attack, [], false);
        };
        // 首攻延迟 0.6s：让 step4 开场移动落地（1001 进入 monster-2 攻击范围）
        // The first attack waits 0.6s: letting step4's opening move land (1001 inside monster-2's attack range).
        verifyTimer(0.6, $attack, [], false);
        waitMapFrame('1001', 'entity_dead', static fn (array $f): bool => ($f['payload']['id'] ?? null) === 'monster-2', 40.0, static function () use (&$stop, &$s): void {
            $stop = true;
            $s['kills']++;
            $s['phase']++;
            questTick();
        }, static function () use (&$stop): void {
            $stop = true;
            closeStep('FAIL', 'monster-2 未被击杀（链任务杀怪）');
        });
    };
    if ($s['kills'] === 1) {
        // 第二杀前置：等 monster-2 重生（respawnMs 5s 窗口内）
        // Before the second kill: wait for monster-2's respawn (within the respawnMs 5s window).
        waitMapFrame('1001', 'monster:spawned', static fn (array $f): bool => ($f['payload']['id'] ?? null) === 'monster-2', 15.0, $kill, static function (): void {
            closeStep('FAIL', '第二杀前 monster-2 未重生');
        });
    } else {
        $kill();
    }
}

/**
 * 拾取本杀的两只掉落（bone + potion）：收齐两条 drop:spawned 后逐条 pickup，两条 item:added 确认入包后
 * 进下一相位。bone 驱动 collect_bones 进度源；potion 无任务匹配，拾取无害。
 * Collects this kill's two drops (bone + potion): after both drop:spawned frames arrive, one pickup per dropId,
 * and the next phase starts once both item:added confirmations land. bone drives the collect_bones progress
 * source; potion matches no quest and the pickup is harmless.
 */
function questPickupDrops(): void
{
    $s = &$GLOBALS['verify']['quest'];
    $dropIds = [];
    $take = null;
    $take = static function () use (&$take, &$dropIds, &$s): void {
        if (count($dropIds) >= 2) {
            foreach ($dropIds as $dropId) {
                sendMap('1001', 'pickup', ['dropId' => $dropId]);
            }
            $got = 0;
            $confirm = null;
            $confirm = static function () use (&$confirm, &$got, &$s): void {
                if ($got >= 2) {
                    $s['phase']++;
                    questTick();

                    return;
                }
                waitMapFrame('1001', 'item:added', null, 10.0, static function () use (&$confirm, &$got): void {
                    $got++;
                    $confirm();
                }, static function (): void {
                    closeStep('FAIL', '拾取 item:added 确认超时');
                });
            };
            $confirm();

            return;
        }
        waitMapFrame('1001', 'drop:spawned', static fn (array $f): bool => str_starts_with((string) ($f['payload']['dropId'] ?? ''), 'drop-monster-2-'), 15.0, static function (array $f) use (&$dropIds, &$take): void {
            $dropIds[] = (string) $f['payload']['dropId'];
            $take();
        }, static function (): void {
            closeStep('FAIL', '掉落 drop:spawned 超时');
        });
    };
    $take();
}

/**
 * 验收项 5：任务领奖闭环（P4a 收口）——kill_wolves 已完成未领奖 → quest:claim 领奖（奖励 potion×2 入包 +
 * quest:result claim ok）→ quest:rows rewarded 落位 → 重复领奖幂等拒绝（not_ready）→ talk_elder（无奖励表）
 * 领奖 ok 且不产生 item:added → rewarded 全落位。
 * Item 5: the quest-claim close-out (the P4a wiring) — kill_wolves completed-and-unclaimed → quest:claim claims
 * it (the potion×2 reward into the bag + quest:result claim ok) → quest:rows shows rewarded landed → a repeated
 * claim is idempotently rejected (not_ready) → talk_elder (empty reward table) claims ok without any item:added →
 * all rewarded flags land.
 */
function step5QuestClaim(): void
{
    // 清空 1001 收件箱：step4 遗留帧（quest:result/item:added 等）会污染本步的领奖帧收集
    // Clear 1001's inbox: step4 leftovers (quest:result/item:added etc.) would pollute this step's claim-frame collection.
    $GLOBALS['clients']['1001']['inbox'] = [];
    $s = ['phase' => 0];
    $tick = null;
    $tick = static function () use (&$s, &$tick): void {
        switch ($s['phase']) {
            case 0: // 领 kill_wolves（已完成未领奖）：item:added potion×2 + quest:result claim ok
                sendMap('1001', 'quest:claim', ['questId' => 'kill_wolves']);
                waitMapFrame('1001', 'quest:result', static fn (array $f): bool => ($f['payload']['op'] ?? null) === 'claim' && ($f['payload']['code'] ?? null) === 'ok', 10.0, static function () use (&$s, &$tick): void {
                    // item:added 先于 quest:result 入箱（路由先发奖励帧再回执）；result ok 确认后立即断言
                    // potion×2 帧存在（inboxTake 同时消费，保持收件箱干净）
                    // item:added lands before quest:result (the route sends reward frames first, then the receipt);
                    // right after the ok receipt assert the potion×2 frame (inboxTake also consumes it, keeping the inbox clean).
                    $potion = inboxTake($GLOBALS['clients']['1001']['inbox'], 'item:added', static fn (array $f): bool => ($f['payload']['itemId'] ?? null) === 'potion' && ($f['payload']['count'] ?? null) === 2);
                    check($potion !== null, '领奖入包：item:added potion×2');
                    $s['phase'] = 1;
                    $tick();
                }, static function (): void {
                    closeStep('FAIL', 'kill_wolves 领奖回执超时');
                });
                break;
            case 1: // rewarded 落位：kill_wolves 已领、其余未领
                sendMap('1001', 'quest:list', []);
                waitMapFrame('1001', 'quest:rows', null, 10.0, static function (array $f) use (&$s, &$tick): void {
                    check(($f['payload']['rewarded'] ?? []) === [true, false, false], '领奖后 rewarded=[true,false,false]');
                    $s['phase'] = 2;
                    $tick();
                }, static function (): void {
                    closeStep('FAIL', '领奖后 quest:rows 超时');
                });
                break;
            case 2: // 重复领奖幂等拒绝（not_ready）
                sendMap('1001', 'quest:claim', ['questId' => 'kill_wolves']);
                waitMapFrame('1001', 'quest:result', static fn (array $f): bool => ($f['payload']['op'] ?? null) === 'claim' && ($f['payload']['code'] ?? null) === 'not_ready', 10.0, static function () use (&$s, &$tick): void {
                    check(true, '重复领奖幂等拒绝（quest:result claim not_ready）');
                    $s['phase'] = 3;
                    $tick();
                }, static function (): void {
                    closeStep('FAIL', '重复领奖未被拒绝（幂等失效）');
                });
                break;
            case 3: // talk_elder（无奖励表）领奖 ok 且不产生 item:added
                sendMap('1001', 'quest:claim', ['questId' => 'talk_elder']);
                waitMapFrame('1001', 'quest:result', static fn (array $f): bool => ($f['payload']['op'] ?? null) === 'claim' && ($f['payload']['code'] ?? null) === 'ok', 10.0, static function () use (&$s, &$tick): void {
                    $leftover = inboxTake($GLOBALS['clients']['1001']['inbox'], 'item:added', null);
                    check($leftover === null, '无奖励表任务领奖不产生 item:added');
                    $s['phase'] = 4;
                    $tick();
                }, static function (): void {
                    closeStep('FAIL', 'talk_elder 领奖回执超时');
                });
                break;
            case 4: // rewarded 全落位 → PASS
                sendMap('1001', 'quest:list', []);
                waitMapFrame('1001', 'quest:rows', null, 10.0, static function (array $f): void {
                    check(($f['payload']['rewarded'] ?? []) === [true, false, true], '终态 rewarded=[true,false,true]');
                    check(($f['payload']['completed'] ?? []) === [true, true, true], '三任务 completed 保持');
                    closeStep('PASS', '任务领奖（claim 入包 + 幂等三态 + rewarded 落位）');
                }, static function (): void {
                    closeStep('FAIL', '终态 quest:rows 超时');
                });
                break;
            default:
                closeStep('FAIL', '领奖状态机非法相位');
        }
    };
    $tick();
}

/**
 * 验收项 6：嘲讽技能（P4b 接入，关闭 P1 预留）——1003 移到 monster-1 当前所在位置后施放嘲讽
 * （tauntThreat 1000 × tauntMultiplier 1.0 = 1000，远超 step1 遗留的 1001=10/1002=30 伤害威胁）→
 * 怪物威胁表写入 → 下一个攻击 tick 的 aggro 切换目标到 1003 → 断言怪物攻击 1003（combat:hit
 * attackerId=monster-1 targetId=1003 的 entityId）。
 * 位置联动（E2E 实测暴露）：monster-1 在巡逻域内持续漂移，固定移到锚点 (15,15) 会在施法时大概率
 * out_of_range（resolveCombatant 的 isNeighborIn 防线）；改为每轮重试前从收件箱读怪物最新位置、1003 移
 * 到该处再施法，out_of_range（combat:error 帧）即清箱重试（最多 4 轮）。
 * 嘲讽者选 1003 而非 1001：step4 的战斗让 1001 死亡（awaitingRevive 标记，实体/Actor 仍在）——aggro
 * 切换会先把已死威胁者从仇恨列表剔除（不残留尸体），死者的 1000 威胁也会被清掉，切换无从发生；
 * 1003 全程未参战、存活且无历史威胁，嘲讽切换确定性成立。
 * Item 6: the taunt skill (the P4b wiring, closing the P1 reservation) — 1003 moves to monster-1's current
 * position and casts the taunt (tauntThreat 1000 × tauntMultiplier 1.0 = 1000, far above the step1 leftover
 * damage threats of 1001=10 / 1002=30) → the monster's threat table records it → the next attack tick's aggro
 * switch targets 1003 → assert the monster attacks 1003 (combat:hit attackerId=monster-1 with 1003's entityId).
 * Position linkage (surfaced by the E2E): monster-1 keeps drifting inside its patrol domain, so a fixed move to
 * the anchor (15,15) mostly ends in out_of_range at cast time (resolveCombatant's isNeighborIn line); instead each
 * retry round reads the monster's latest position from the inboxes, moves 1003 there, then casts — an
 * out_of_range (a combat:error frame) clears the inbox and retries (up to 4 rounds).
 * The taunter is 1003, not 1001: step4's fighting leaves 1001 dead (the awaitingRevive marker; the entity/actor
 * persist) — the aggro switch first purges dead threat sources from the hate list (no corpses linger), so a dead
 * taunter's 1000 threat would be purged too and the switch could never happen; 1003 never fought, stays alive and
 * carries no history threat — the taunt switch is deterministic.
 */
function step6Taunt(): void
{
    // 1003 当前位置（起点 (-6,-6)，每轮移动后更新）+ 重试计数
    // 1003's current position (starts at (-6,-6), updated after every move) + the retry counter.
    $s = ['x' => -6, 'y' => -6, 'attempts' => 0];
    $GLOBALS['clients']['1003']['inbox'] = [];

    // 位置联动（E2E 实测暴露）：monster-1 在巡逻域（锚 (15,15) ±10 = [5,25]²）内持续漂移，且 step4 后
    // 1001/1002 已死——它们收件箱里的怪物位置帧是陈旧的（怪物早已离开其视野），固定移动到锚点必然
    // out_of_range。1003 先移到巡逻域中心 (15,15)：其 3×3 视野（cellSize 10 → ±10 单位）恰好覆盖整个
    // 巡逻域，之后从 1003 自己收件箱读到的 monster-1 位置始终新鲜（怪物不离开该域）；再移到怪物所在格
    // 施放嘲讽，out_of_range（combat:error 帧）即清箱重试（最多 5 轮）。
    // Position linkage (surfaced by the E2E): monster-1 keeps drifting inside its patrol domain (anchor (15,15)
    // ±10 = [5,25]²), and after step4 both 1001/1002 are dead — their inboxes carry stale monster frames (the
    // monster left their view long ago), so a fixed move to the anchor always ends out_of_range. 1003 first moves
    // to the domain center (15,15): its 3x3 view (cellSize 10 → ±10 units) exactly covers the whole patrol domain,
    // so monster-1's position read from 1003's own inbox is always fresh (the monster never leaves that domain);
    // then 1003 moves onto the monster's cell and casts the taunt; an out_of_range (a combat:error frame) clears
    // the inbox and retries (up to 5 rounds).
    // P6a 适配：位移基准「复活帧感知」——1003 若在步骤间隙被自动复活传回 (0,0)，基准即 (0,0)，否则 (-6,-6)。
    // The P6a adaptation: the move's base is revive-aware — if 1003 was auto-revived back to (0,0) during the step
    // gap, the base is (0,0), otherwise (-6,-6).
    $reviveBase = inboxTake($GLOBALS['clients']['1003']['inbox'], 'player:revive', static fn (array $f): bool => ($f['payload']['code'] ?? null) === 'ok') !== null
        ? ['x' => 0, 'y' => 0]
        : ['x' => -6, 'y' => -6];
    sendMap('1003', 'move', ['dx' => 15 - $reviveBase['x'], 'dy' => 15 - $reviveBase['y']]);
    $s['x'] = 15;
    $s['y'] = 15;

    $tryCast = null;
    $tryCast = static function () use (&$s, &$tryCast): void {
        // 从 1003 自己收件箱读 monster-1 最新位置（视野覆盖整个巡逻域 → 帧恒新鲜）
        // Read monster-1's latest position from 1003's own inbox (the view covers the whole patrol domain → frames are always fresh).
        $monsterPos = null;
        foreach (($GLOBALS['clients']['1003']['inbox'] ?? []) as $f) {
            $payload = $f['payload'] ?? [];
            if (($payload['id'] ?? null) === 'monster-1' && is_array($payload['position'] ?? null)) {
                $monsterPos = $payload['position'];
            }
        }
        if ($monsterPos === null) {
            $types = array_map(static fn (array $f): string => (string) ($f['type'] ?? '?') . ':' . (string) json_encode($f['payload'] ?? [], JSON_UNESCAPED_UNICODE), $GLOBALS['clients']['1003']['inbox'] ?? []);
            error_log('[verify] step6 pos-unknown inbox-1003=' . json_encode($types, JSON_UNESCAPED_UNICODE));
            check(false, 'monster-1 位置未知（1003 收件箱无位置帧）');
            closeStep('FAIL', '嘲讽切换未生效（怪物位置未知）');

            return;
        }
        $dx = (int) ($monsterPos['x'] ?? 0) - $s['x'];
        $dy = (int) ($monsterPos['y'] ?? 0) - $s['y'];
        $s['x'] = (int) ($monsterPos['x'] ?? 0);
        $s['y'] = (int) ($monsterPos['y'] ?? 0);
        sendMap('1003', 'move', ['dx' => $dx, 'dy' => $dy]);

        // 0.15s 后施放嘲讽（移动落地 + 怪物漂移的最小窗口；巡逻步频下漂移 ≤1 格，仍在 isNeighborIn 邻格内）
        // Cast 0.15s later (the move lands; the smallest window for monster drift — at patrol step frequency the
        // drift stays ≤1 cell, inside isNeighborIn's neighbor test).
        verifyTimer(0.15, static function () use (&$s, &$tryCast): void {
            sendMap('1003', 'skill:cast', ['skillId' => 'taunt', 'targetId' => 'monster-1']);
            // 断言怪物下一个攻击 tick 的 aggro 切到 1003：combat:hit attackerId=monster-1 targetId=1003 的 entityId
            // Assert the monster's next attack-tick aggro lands on 1003: combat:hit attackerId=monster-1 with 1003's entityId.
            waitMapFrame('1003', 'combat:hit', static fn (array $f): bool => ($f['payload']['attackerId'] ?? null) === 'monster-1'
                && ($f['payload']['targetId'] ?? null) === ($GLOBALS['entityIds']['1003'] ?? ''), 5.0, static function (): void {
                    check(true, '嘲讽后怪物 aggro 切换到嘲讽者 1003（combat:hit attackerId=monster-1 targetId=1003）');
                    closeStep('PASS', '嘲讽技能（tauntThreat 写入威胁表 → aggro 切换到嘲讽者）');
                }, static function () use (&$s, &$tryCast): void {
                    if ($s['attempts']++ >= 5) {
                        $types = array_map(static fn (array $f): string => (string) ($f['type'] ?? '?') . ':' . (string) json_encode($f['payload'] ?? [], JSON_UNESCAPED_UNICODE), $GLOBALS['clients']['1003']['inbox'] ?? []);
                        error_log('[verify] step6 exhausted inbox-1003=' . json_encode($types, JSON_UNESCAPED_UNICODE));
                        check(false, '嘲讽后怪物未攻击 1003（aggro 切换未生效）');
                        closeStep('FAIL', '嘲讽切换未生效');

                        return;
                    }
                    // 本轮未命中：大概率 out_of_range（怪物漂移）——清掉 combat:error 帧后按新位置重试
                    // This round missed: most likely out_of_range (monster drift) — clear the combat:error frames
                    // and retry with the fresh position.
                    while (inboxTake($GLOBALS['clients']['1003']['inbox'], 'combat:error', null) !== null) {
                    }
                    $tryCast();
                });
        }, [], false);
    };

    // 先等 0.8s：让 1003 落到 (15,15) 且怪物的巡逻移动开始向 1003 广播位置帧
    // First wait 0.8s: 1003 lands at (15,15) and the monster's patrol moves start broadcasting position frames to it.
    verifyTimer(0.8, $tryCast, [], false);
}

/**
 * 验收项 7：玩家复活（P5a 接入，消费 awaitingRevive 标记）——状态判定用确定性探针而非收件箱扫描：
 * 先发 player:revive，回执 ok = 已处待复活（step4 战死，直接断言复活结果）；回执 not_ready = 存活
 * （step5 领奖步骤开场清空 1001 收件箱，把 step4 战死的 player:stats（hp=0）证据冲掉了——扫描判定
 * 不可靠）。存活路径先集火挨打到死（entity_dead）再复活。断言：回执 ok + 落点出生点 (0,0) +
 * player:stats 满血帧（hp = maxHp）。
 * Item 7: the player revive (the P5a wiring, consuming the awaitingRevive marker) — the state is judged with a
 * deterministic probe instead of an inbox scan: a player:revive first — an ok receipt means already awaiting
 * (died in step4; assert the revive result directly); a not_ready receipt means alive (step5's claim step clears
 * 1001's inbox at its start, wiping step4's death-evidence player:stats (hp=0) — the scan is unreliable). The
 * alive path fights to the death first (entity_dead) then revives. Assertions: an ok receipt + the spawn landing
 * (0,0) + a full-hp player:stats frame (hp = maxHp).
 */
function step7PlayerRevive(): void
{
    $s = ['phase' => 0];
    $tick = null;
    $tick = static function () use (&$s, &$tick): void {
        switch ($s['phase']) {
            case 0: // 状态探针：player:revive 回执 ok（已死）→ 复活已消费，直接断言收尾；not_ready（存活）→ 战死前置
                sendMap('1001', 'player:revive', []);
                waitMapFrame('1001', 'player:revive', null, 10.0, static function (array $f) use (&$s, &$tick): void {
                    if (($f['payload']['code'] ?? null) === 'ok') {
                        // 探针即复活：本次回执已消费待复活标记——断言落点与满血帧后直接 PASS（不再二次发送）
                        // The probe IS the revival: this receipt already consumed the marker — assert the landing and
                        // the full-hp frame, then PASS directly (no second send).
                        check(true, '1001 已处待复活状态（step4 战死）——探针直接消费复活');
                        $position = $f['payload']['position'] ?? null;
                        check(is_array($position) && ($position['x'] ?? null) === 0 && ($position['y'] ?? null) === 0, '复活回执落点出生点 (0,0)');
                        $stats = inboxTake($GLOBALS['clients']['1001']['inbox'], 'player:stats', static fn (array $f2): bool => ($f2['payload']['id'] ?? null) === ($GLOBALS['entityIds']['1001'] ?? '') && ($f2['payload']['hp'] ?? null) === ($f2['payload']['maxHp'] ?? null));
                        check($stats !== null, '复活后 player:stats 满血（hp = maxHp）');
                        closeStep('PASS', '玩家复活（awaitingRevive 消费：满血回生 + 传送出生点）');

                        return;
                    }
                    check(($f['payload']['code'] ?? null) === 'not_ready', '1001 存活（探针 not_ready）——先战死再复活');
                    $s['phase'] = 1;
                    $tick();
                }, static function (): void {
                    closeStep('FAIL', '复活探针回执超时');
                });
                break;
            case 1: // 战死前置：1001 单体攻击 monster-2（被反击至死）→ entity_dead（1001 的 entityId）。
                // P7c 适配：先移出安全区（区内玩家的攻击不记威胁，怪物永远不会反击致死）——基准复活帧感知
                // （探针 ok 路径已直接收尾；not_ready 时 1001 只可能在 (0,0)（步骤间隙自动复活）或 (-6,-6)
                // （step4 后未死），收件箱有复活 ok 帧即为前者），统一归位 monster-2 巡逻域中心 (-6,-6)
                // （距圆心 8.5 > 半径 5，且全巡逻域在其视野内）。
                // The P7c adaptation: move out of the safe zone first (a zone player's attacks record no threat,
                // so the monster never counterattacks to death) — the base is revive-aware (the probe's ok path
                // closes the step directly; on not_ready 1001 sits at either (0,0), auto-revived during a step
                // gap, or (-6,-6), alive since step4 — a revive ok frame in the inbox means the former), then
                // re-anchor at monster-2's patrol-domain center (-6,-6) (distance 8.5 > radius 5, and the whole
                // patrol domain stays in view).
                $reviveBase = inboxTake($GLOBALS['clients']['1001']['inbox'], 'player:revive', static fn (array $f): bool => ($f['payload']['code'] ?? null) === 'ok') !== null
                    ? ['x' => 0, 'y' => 0]
                    : ['x' => -6, 'y' => -6];
                sendMap('1001', 'move', ['dx' => -6 - $reviveBase['x'], 'dy' => -6 - $reviveBase['y']]);
                $stop = false;
                $attack = null;
                $attack = static function () use (&$attack, &$stop): void {
                    if ($stop) {
                        return;
                    }
                    sendMap('1001', 'attack', ['targetId' => 'monster-2']);
                    verifyTimer(0.3, $attack, [], false);
                };
                $attack();
                waitMapFrame('1001', 'entity_dead', static fn (array $f): bool => ($f['payload']['id'] ?? null) === ($GLOBALS['entityIds']['1001'] ?? ''), 25.0, static function () use (&$s, &$stop, &$tick): void {
                    $stop = true;
                    check(true, '1001 被 monster-2 反击至死（entity_dead）');
                    $s['phase'] = 2;
                    $tick();
                }, static function () use (&$stop): void {
                    $stop = true;
                    closeStep('FAIL', '1001 未被击杀（复活前置）');
                });
                break;
            case 2: // 复活：回执 ok + 落点 (0,0)；player:stats 满血帧先于回执入箱（同批）
                sendMap('1001', 'player:revive', []);
                waitMapFrame('1001', 'player:revive', static fn (array $f): bool => ($f['payload']['code'] ?? null) === 'ok', 10.0, static function (array $f) use (&$s, &$tick): void {
                    $position = $f['payload']['position'] ?? null;
                    check(is_array($position) && ($position['x'] ?? null) === 0 && ($position['y'] ?? null) === 0, '复活回执落点出生点 (0,0)');
                    $stats = inboxTake($GLOBALS['clients']['1001']['inbox'], 'player:stats', static fn (array $f2): bool => ($f2['payload']['id'] ?? null) === ($GLOBALS['entityIds']['1001'] ?? '') && ($f2['payload']['hp'] ?? null) === ($f2['payload']['maxHp'] ?? null));
                    check($stats !== null, '复活后 player:stats 满血（hp = maxHp）');
                    $s['phase'] = 3;
                    $tick();
                }, static function (): void {
                    closeStep('FAIL', '复活回执超时');
                });
                break;
            case 3:
                closeStep('PASS', '玩家复活（awaitingRevive 消费：满血回生 + 传送出生点）');
                break;
            default:
                closeStep('FAIL', '复活状态机非法相位');
        }
    };
    $tick();
}

/**
 * 验收项 8：任务奖励落库复核（P5b）——step5 领奖的 potion×2 经标脏 → 归档 30s 兜底批量落库（P5b 落实的
 * 兜底定时器）→ 从 MySQL 归档表（nythros_archive, collection=players）复核 1001 的 inventory.potion = 4
 * （step4 拾取 2 + step5 领奖 2）。连接口径与服务器同源（deploy.yaml）；2s 轮询、40s 窗口自终止。
 * Item 8: the quest-reward persistence review (the P5b) — step5's claimed potion×2 rides markDirty → the archive's
 * 30s periodic batch (the P5b-wired fallback timer) → verify from the MySQL archive table (nythros_archive,
 * collection=players) that 1001's inventory.potion = 4 (2 picked up in step4 + 2 claimed in step5). The connection
 * matches the server's (deploy.yaml); a 2s poll, self-terminating after 40s.
 */
function step8QuestRewardPersisted(): void
{
    $config = DeployConfig::parseYaml((string) file_get_contents(__DIR__ . '/../config/deploy.yaml'));
    $mysqlInfo = $config->mysql();
    $pdo = new \PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $mysqlInfo['host'], $mysqlInfo['port'], $mysqlInfo['dbname']),
        $mysqlInfo['user'],
        $mysqlInfo['password'],
        [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION],
    );

    $t0 = microtime(true);
    $poll = null;
    $poll = static function () use (&$poll, $pdo, $t0): void {
        $stmt = $pdo->prepare('SELECT data FROM nythros_archive WHERE collection = :collection AND id = :uid');
        $stmt->execute(['collection' => 'players', 'uid' => '1001']);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $data = $row === false ? [] : (json_decode((string) $row['data'], true) ?? []);
        $potion = (int) (($data['inventory']['potion'] ?? null) ?? 0);
        if ($potion === 4) {
            check(true, '任务奖励落库：归档 inventory.potion = 4（step4 拾取 2 + step5 领奖 2）');
            closeStep('PASS', '任务奖励 MySQL 落库复核（归档 30s 兜底批量）');

            return;
        }
        if (microtime(true) - $t0 >= 40.0) {
            check(false, '归档 40s 内未出现领奖 potion（实际=' . $potion . '）');
            closeStep('FAIL', '任务奖励未落库');

            return;
        }
        verifyTimer(2.0, $poll, [], false);
    };
    $poll();
}

/**
 * 验收项 9：AoE 嘲讽（P5c 接入）——1003 移到 monster-2 当前所在位置（位置联动，同 step6 口径）→
 * skill:cast_aoe {taunt_aoe, cx/cy = monster-2 位置, r=10}（castSkillAoE 消费 tauntThreat，多目标嘲讽语义）
 * → monster-2 威胁表写入 1000 → aggro 切换到嘲讽者 → 断言怪物攻击 1003（combat:hit attackerId=monster-2
 * targetId=1003 的 entityId）。out_of_range/冷却等 combat:error 清箱重试（≤5 轮）。
 * Item 9: the AoE taunt (the P5c wiring) — 1003 moves to monster-2's current position (position-linked, same
 * convention as step6) → skill:cast_aoe {taunt_aoe, cx/cy = monster-2's position, r=10} (castSkillAoE consumes
 * tauntThreat, the multi-target taunt semantics) → monster-2's threat table records 1000 → aggro switches to the
 * taunter → assert the monster attacks 1003 (combat:hit attackerId=monster-2 with 1003's entityId). combat:error
 * frames (out_of_range / cooldown) clear and retry (up to 5 rounds).
 */
function step9AoETaunt(): void
{
    // 先探针复活 1003（E2E 实测暴露）：step6 的嘲讽让 monster-1 永久锁定 1003，steps 7-8（~45s）里
    // monster-1 持续攻击把 1003 磨死（awaitingRevive）——死者会被 aggro 切换的「清死尸」从仇恨列表剔除，
    // 其 1000 威胁随之消失，切换无从发生。复活回执 ok → 1003 满血回生且落点 (0,0)（起点确定）；
    // not_ready（存活）→ 以 monster-1 最新位置近似起点。1003 在 (0,0) 时视野（cell(0,0) ± 1）恰好覆盖
    // monster-2 的整巡逻域（全在 cell(-1,-1)）——位置帧恒新鲜。
    // First probe-revive 1003 (surfaced by the E2E): step6's taunt leaves monster-1 permanently locked on 1003,
    // whose persistent attacks grind 1003 to death during steps 7-8 (~45s, the awaitingRevive marker) — the aggro
    // switch's corpse-purge then removes the dead taunter's 1000 threat and the switch can never happen. An ok
    // receipt revives 1003 full-hp at the spawn (0,0) (a deterministic start); a not_ready (alive) approximates the
    // start with monster-1's latest position. At (0,0) 1003's view (cell(0,0) ± 1) exactly covers monster-2's whole
    // patrol domain (all inside cell(-1,-1)) — position frames are always fresh.
    sendMap('1003', 'player:revive', []);
    waitMapFrame('1003', 'player:revive', null, 10.0, static function (array $f): void {
        $s = ['x' => null, 'y' => null, 'attempts' => 0];
        if (($f['payload']['code'] ?? null) === 'ok') {
            check(true, '1003 战死（monster-1 持续攻击）——先行复活（满血回生 + 落点 (0,0)）');
            $s['x'] = 0;
            $s['y'] = 0;
        } else {
            check(($f['payload']['code'] ?? null) === 'not_ready', '1003 存活——直接进入 AoE 嘲讽');
            foreach (($GLOBALS['clients']['1003']['inbox'] ?? []) as $frame) {
                $payload = $frame['payload'] ?? [];
                if (($payload['id'] ?? null) === 'monster-1' && is_array($payload['position'] ?? null)) {
                    $s['x'] = (int) ($payload['position']['x'] ?? 0);
                    $s['y'] = (int) ($payload['position']['y'] ?? 0);
                }
            }
            $s['x'] ??= 15;
            $s['y'] ??= 15;
        }

        // 首次位移：monster-2 最新位置从 1002 收件箱读（1002 驻 (-6,-6) 且连接存活、视野覆盖 monster-2 整
        // 巡逻域 → 帧恒新鲜；1003 无论复活落点 (0,0) 还是 monster-1 领域，都可能读不到 monster-2 帧）。
        // 先移过去，此后 1003 自己的视野覆盖 monster-2 域，重试循环的位置读取恒新鲜。
        // The first move: monster-2's latest position comes from 1002's inbox (1002 sits at (-6,-6) with a live
        // connection and a view covering monster-2's whole patrol domain → frames are always fresh; 1003 — whether
        // revived to (0,0) or parked in monster-1's domain — may not see monster-2 at all). Once moved over, 1003's
        // own view covers monster-2's domain and the retry loop's position reads stay fresh.
        $monster2 = null;
        foreach (($GLOBALS['clients']['1002']['inbox'] ?? []) as $f) {
            $payload = $f['payload'] ?? [];
            if (($payload['id'] ?? null) === 'monster-2' && is_array($payload['position'] ?? null)) {
                $monster2 = $payload['position'];
            }
        }
        if ($monster2 !== null) {
            $dx = (int) ($monster2['x'] ?? 0) - $s['x'];
            $dy = (int) ($monster2['y'] ?? 0) - $s['y'];
            $s['x'] = (int) ($monster2['x'] ?? 0);
            $s['y'] = (int) ($monster2['y'] ?? 0);
            sendMap('1003', 'move', ['dx' => $dx, 'dy' => $dy]);
        }
        $GLOBALS['clients']['1003']['inbox'] = [];

        $tryCast = null;
        $tryCast = static function () use (&$s, &$tryCast): void {
            // 从 1003 收件箱读 monster-2 最新位置（1003 在其视野覆盖范围内 → 帧恒新鲜）
            // Read monster-2's latest position from 1003's inbox (1003's view covers it → frames are always fresh).
            $monsterPos = null;
            foreach (($GLOBALS['clients']['1003']['inbox'] ?? []) as $f) {
                $payload = $f['payload'] ?? [];
                if (($payload['id'] ?? null) === 'monster-2' && is_array($payload['position'] ?? null)) {
                    $monsterPos = $payload['position'];
                }
            }
            if ($monsterPos === null) {
                // 视野重同步 1s 周期：位置帧可能尚未入箱——重试等待而非立即失败
                // The view resync fires on a 1s cycle: the position frame may not have landed yet — retry instead of failing at once.
                if ($s['attempts']++ >= 5) {
                    $types = array_map(static fn (array $f): string => (string) ($f['type'] ?? '?') . ':' . (string) json_encode($f['payload'] ?? [], JSON_UNESCAPED_UNICODE), $GLOBALS['clients']['1003']['inbox'] ?? []);
                    error_log('[verify] step9 pos-unknown inbox-1003=' . json_encode($types, JSON_UNESCAPED_UNICODE));
                    check(false, 'monster-2 位置未知（1003 收件箱无位置帧）');
                    closeStep('FAIL', 'AoE 嘲讽未生效（怪物位置未知）');

                    return;
                }
                verifyTimer(0.5, $tryCast, [], false);

                return;
            }
            $dx = (int) ($monsterPos['x'] ?? 0) - $s['x'];
            $dy = (int) ($monsterPos['y'] ?? 0) - $s['y'];
            $s['x'] = (int) ($monsterPos['x'] ?? 0);
            $s['y'] = (int) ($monsterPos['y'] ?? 0);
            sendMap('1003', 'move', ['dx' => $dx, 'dy' => $dy]);

            // 0.15s 后施放 AoE 嘲讽：形状覆盖 monster-2（cx/cy = 其位置，r=10）
            // Cast the AoE taunt 0.15s later: the shape covers monster-2 (cx/cy = its position, r=10).
            verifyTimer(0.15, static function () use (&$s, &$tryCast, $monsterPos): void {
                sendMap('1003', 'skill:cast_aoe', ['skillId' => 'taunt_aoe', 'cx' => (int) ($monsterPos['x'] ?? 0), 'cy' => (int) ($monsterPos['y'] ?? 0), 'r' => 10]);
                waitMapFrame('1003', 'combat:hit', static fn (array $f): bool => ($f['payload']['attackerId'] ?? null) === 'monster-2'
                    && ($f['payload']['targetId'] ?? null) === ($GLOBALS['entityIds']['1003'] ?? ''), 6.0, static function (): void {
                        check(true, 'AoE 嘲讽后怪物 aggro 切换到嘲讽者 1003（combat:hit attackerId=monster-2 targetId=1003）');
                        closeStep('PASS', 'AoE 嘲讽（castSkillAoE 路径消费 tauntThreat → 多目标拉取）');
                    }, static function () use (&$s, &$tryCast): void {
                        if ($s['attempts']++ >= 5) {
                            $types = array_map(static fn (array $f): string => (string) ($f['type'] ?? '?') . ':' . (string) json_encode($f['payload'] ?? [], JSON_UNESCAPED_UNICODE), $GLOBALS['clients']['1003']['inbox'] ?? []);
                            error_log('[verify] step9 exhausted inbox-1003=' . json_encode($types, JSON_UNESCAPED_UNICODE));
                            check(false, 'AoE 嘲讽后怪物未攻击 1003（aggro 切换未生效）');
                            closeStep('FAIL', 'AoE 嘲讽切换未生效');

                            return;
                        }
                        while (inboxTake($GLOBALS['clients']['1003']['inbox'], 'combat:error', null) !== null) {
                        }
                        $tryCast();
                    });
            }, [], false);
        };

        // 先等 0.8s：让首次位移落地且 monster-2 的巡逻移动开始向 1003 广播位置帧
        // First wait 0.8s: the first move lands and monster-2's patrol moves start broadcasting position frames to 1003.
        // P6c 施法距离门前置断言：远点施法（cx = 1003 当前位置 +100，远超 taunt_aoe range 10）必须被
        // out_of_range 拒绝——range 字段消费的直接线缆证据；通过后再进入合法施法重试循环。
        // The P6c cast-distance-gate precondition assert: a far-point cast (cx = 1003's position +100, far beyond
        // taunt_aoe's range 10) must be rejected with out_of_range — the direct wire evidence of the range field's
        // consumption; only then does the legit-cast retry loop begin.
        verifyTimer(0.8, static function () use (&$s, &$tryCast): void {
            sendMap('1003', 'skill:cast_aoe', ['skillId' => 'taunt_aoe', 'cx' => $s['x'] + 100, 'cy' => $s['y'], 'r' => 10]);
            waitMapFrame('1003', 'combat:error', static fn (array $f): bool => ($f['payload']['code'] ?? null) === 'out_of_range', 5.0, static function () use (&$s, &$tryCast): void {
                check(true, 'AoE 施法距离门：远点施法被 out_of_range 拒绝（P6c definition->range 消费）');
                $tryCast();
            }, static function (): void {
                closeStep('FAIL', '远点施法未被 out_of_range 拒绝（施法距离门未生效）');
            });
        }, [], false);
    }, static function (): void {
        closeStep('FAIL', '复活探针回执超时');
    });
}

/**
 * 验收项 10：时间制自动复活（P6a）——1001 攻击 monster-2 被反击至死（全程不发送 player:revive）→
 * playerRespawnMs（env 2000ms）到期后世界 tick 服务端主动复活：断言未请求的情况下收到 player:revive ok
 * （落点 (0,0)）+ player:stats 满血。死亡确定性：monster-2 威胁表 1003 的嘲讽威胁（1000）在 1003 死亡时
 * 被 aggro「清死尸」剔除且不衰减的 1001 累计伤害威胁终将登顶——两路任一先到即切换到 1001，反击致死。
 * Item 10: the timed auto-revive (the P6a) — 1001 attacks monster-2 and is counterattacked to death (never sending
 * player:revive) → after playerRespawnMs (env 2000ms) the world tick revives server-side: assert receiving an
 * unrequested player:revive ok (landing (0,0)) + a full-hp player:stats frame. Death determinism: monster-2's 1003
 * taunt threat (1000) is purged by the aggro corpse-cleanup the moment 1003 dies, and 1001's accumulated
 * undecaying damage threat eventually tops the table — whichever lands first switches aggro to 1001, and the
 * counterattack kills it.
 */
function step10AutoRevive(): void
{
    $s = ['phase' => 0];
    $tick = null;
    $tick = static function () use (&$s, &$tick): void {
        switch ($s['phase']) {
            case 0: // 战死前置：先移出安全区——1001 经 step7 复活恒在 (0,0)（区内），区内攻击不记威胁且
                // 域边缘距出生点可达 14 > aggroRange 10；统一移到 monster-2 巡逻域中心 (-6,-6)（距安全区
                // 圆心 8.5 > 半径 5、全巡逻域在其视野内），再进入攻击循环直到 1001 自身 entity_dead。
                // Precondition: move out of the safe zone first — 1001 always sits at (0,0) (inside the zone)
                // after step7's revive, where attacks record no threat and the domain edge can sit 14 >
                // aggroRange 10 from the spawn; re-anchor at monster-2's patrol-domain center (-6,-6) (distance
                // 8.5 > radius 5 from the zone center, with the whole patrol domain in view), then the attack
                // loop runs until 1001's own entity_dead.
                sendMap('1001', 'move', ['dx' => -6, 'dy' => -6]);
                $stop = false;
                $attack = null;
                $attack = static function () use (&$attack, &$stop): void {
                    if ($stop) {
                        return;
                    }
                    sendMap('1001', 'attack', ['targetId' => 'monster-2']);
                    verifyTimer(0.3, $attack, [], false);
                };
                verifyTimer(0.6, $attack, [], false);
                waitMapFrame('1001', 'entity_dead', static fn (array $f): bool => ($f['payload']['id'] ?? null) === ($GLOBALS['entityIds']['1001'] ?? ''), 50.0, static function () use (&$s, &$stop, &$tick): void {
                    $stop = true;
                    check(true, '1001 被 monster-2 反击至死（entity_dead）——等待时间制自动复活');
                    $s['phase'] = 1;
                    $tick();
                }, static function () use (&$stop): void {
                    $stop = true;
                    closeStep('FAIL', '1001 未被击杀（自动复活前置）');
                });
                break;
            case 1: // 自动复活：客户端不发送 player:revive——等世界 tick 到期服务端主动下发 ok 回执
                waitMapFrame('1001', 'player:revive', static fn (array $f): bool => ($f['payload']['code'] ?? null) === 'ok', 15.0, static function (array $f) use (&$s, &$tick): void {
                    check(true, '未发送 player:revive 仍收到 ok 回执——服务端时间制自动复活');
                    $position = $f['payload']['position'] ?? null;
                    check(is_array($position) && ($position['x'] ?? null) === 0 && ($position['y'] ?? null) === 0, '自动复活落点出生点 (0,0)');
                    $stats = inboxTake($GLOBALS['clients']['1001']['inbox'], 'player:stats', static fn (array $f2): bool => ($f2['payload']['id'] ?? null) === ($GLOBALS['entityIds']['1001'] ?? '') && ($f2['payload']['hp'] ?? null) === ($f2['payload']['maxHp'] ?? null));
                    check($stats !== null, '自动复活后 player:stats 满血（hp = maxHp）');
                    $s['phase'] = 2;
                    $tick();
                }, static function (): void {
                    closeStep('FAIL', 'playerRespawnMs 到期后未收到服务端自动复活回执');
                });
                break;
            case 2:
                closeStep('PASS', '时间制自动复活（playerRespawnMs 到期 → 服务端主动复活）');
                break;
            default:
                closeStep('FAIL', '自动复活状态机非法相位');
        }
    };
    $tick();
}

// 验收步骤注册表（顺序执行；每步独立超时）。
// The acceptance step registry (sequential execution; per-step timeout).
$GLOBALS['verify']['steps'] = [
    ['0. 前置：三客户端登录 + Map 直连（1001-1003）', 'step0Login', 60.0],
    ['1. 威胁切换（受击方记威胁 + aggro 选最高者切换目标）', 'step1ThreatSwitch', 30.0],
    ['2. 重生（击杀 → respawnMs 后回锚点）', 'step2Respawn', 50.0],
    ['3. 任务链配置（MmorpgConfig questChain 解析）', 'step3QuestChain', 10.0],
    ['4. 任务链运行时（锁定忽略 → 杀怪推进 → 解锁 → 对话完成 → 整链闭环）', 'step4QuestChainRuntime', 90.0],
    ['5. 任务领奖（claim 入包 + 幂等三态 + rewarded 落位）', 'step5QuestClaim', 30.0],
    ['6. 嘲讽技能（tauntThreat 写入威胁表 → aggro 切换到嘲讽者）', 'step6Taunt', 60.0],
    ['7. 玩家复活（awaitingRevive 消费：满血回生 + 传送出生点）', 'step7PlayerRevive', 50.0],
    ['8. 任务奖励落库复核（归档 30s 兜底批量 → MySQL potion=4）', 'step8QuestRewardPersisted', 50.0],
    ['9. AoE 嘲讽（castSkillAoE 路径消费 tauntThreat → 多目标拉取）', 'step9AoETaunt', 60.0],
    ['10. 时间制自动复活（playerRespawnMs 到期 → 服务端主动复活）', 'step10AutoRevive', 75.0],
];

$worker = new Worker();
$worker->onWorkerStart = static function (): void {
    echo "[verify] R4 mmorpg 类型模块端到端验收启动\n";
    // 全局看门狗：420s 未完成强制收尾（P6 批新增 step10 后总时长含 step8 的 30s 落库等待与 step10 的
    // 反击致死攻击循环窗口）。
    // Global watchdog: force the summary after 420s (the P6 batch's step10 extends the total with step8's 30s
    // persistence wait and the retry windows).
    Timer::add(420.0, static function (): void {
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
