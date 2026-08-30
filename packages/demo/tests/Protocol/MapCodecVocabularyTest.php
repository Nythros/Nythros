<?php

declare(strict_types=1);

namespace Nythros\Demo\Tests\Protocol;

use Nythros\Demo\Protocol\MapCodec;
use Nythros\Protocol\Message;
use PHPUnit\Framework\TestCase;

/**
 * MapCodecVocabularyTest - 用真实线上负载形状走「二进制编码 → 解码」往返，锁定词表完整性。
 * 单测 harness 走 JsonBatchSerializer 接触不到词表缺口；本测试直接以 Map 频道全部广播/请求的
 * 真实 payload 形状过二进制编解码——若某帧类型/负载字段未登记进 FrameType/PayloadKey 枚举，
 * 编码会抛 ProtocolException 使测试失败（快速失败，强制维护枚举）。
 * MapCodecVocabularyTest - round-trips the real production payload shapes through binary encode/decode to lock
 * vocabulary completeness. Unit harnesses use the JsonBatchSerializer and never touch the vocabulary; this test
 * feeds every Map-channel broadcast/request payload shape through the binary codec — an unregistered frame type
 * or payload key throws ProtocolException and fails the test (fail fast, forcing enum maintenance).
 */
final class MapCodecVocabularyTest extends TestCase
{
    private const SAMPLES = [
        // 服务器 → 客户端广播 Server → client broadcasts
        'auth_ok' => [['uid' => '1001', 'id' => '1001@p-1'], 'req-1'],
        'auth_failed' => [['code' => 403, 'message' => 'bad token', 'reason' => 'invalid'], 'req-1'],
        'entity_enter' => [['id' => 'monster-1', 'position' => ['x' => 1, 'y' => 2]], null],
        'entity_enter_with_item' => [['id' => 'drop-1', 'position' => ['x' => 1, 'y' => 2], 'itemId' => 'gold'], null],
        'entity_leave' => [['id' => 'monster-1', 'position' => ['x' => 1, 'y' => 2]], null],
        'entity_moved' => [['id' => '1001@p-1', 'position' => ['x' => 3, 'y' => 4]], null],
        'combat:hit' => [['attackerId' => '1001@p-1', 'targetId' => 'monster-1', 'damage' => 12, 'hp' => 88], null],
        'skill:cast' => [['casterId' => '1001@p-1', 'skillId' => 'fireball', 'targetId' => 'monster-1'], null],
        'drop:spawned' => [['dropId' => 'drop-1', 'itemId' => 'gold', 'x' => 5, 'y' => 6], null],
        'drop:removed' => [['dropId' => 'drop-1'], null],
        'item:added' => [['itemId' => 'gold', 'count' => 3], null],
        'entity_dead' => [['id' => 'monster-1'], null],
        // R4 死亡批量帧（ADR-024 §9 V5，V7 并行等长标量列表）R4 death batch frame (ADR-024 §9 V5, V7 parallel equal-length scalar lists)
        'entity_dead_batch' => [['ids' => ['m-1', 'm-2'], 'positions' => [['x' => 5, 'y' => 0], ['x' => 0, 'y' => 6]], 'types' => ['monster', 'monster']], null],
        'monster:spawned' => [['id' => 'monster-1', 'typeId' => 'slime', 'position' => ['x' => 0, 'y' => 0]], null],
        'player:stats' => [['id' => '1001@p-1', 'hp' => 77, 'maxHp' => 100], null],
        'combat:error' => [['code' => 404, 'message' => 'invalid target'], 'req-1'],
        'error' => [['code' => 400, 'message' => 'bad frame'], 'req-1'],
        // R2 房间与批量管线（ADR-024）R2 rooms and batch pipelines (ADR-024)
        'combat:aoe' => [['casterId' => '1001@p-1', 'skillId' => 'fireball', 'targetIds' => ['m-1', 'm-2'], 'damages' => [15, 14], 'hps' => [85, 86]], null],
        'combat:aoe_empty' => [['casterId' => '1001@p-1', 'skillId' => 'fireball', 'targetIds' => [], 'damages' => [], 'hps' => []], null],
        'drop:spawned_batch' => [['dropIds' => ['drop-m-1-1', 'drop-m-2-2'], 'itemIds' => ['bone', 'potion'], 'positions' => [['x' => 5, 'y' => 6], ['x' => -3, 'y' => 8]]], null],
        'room:snapshot' => [['roomId' => 'horde-1', 'memberIds' => ['m-1'], 'positions' => [['x' => 5, 'y' => 5]]], null],
        'room:snapshot_empty' => [['roomId' => 'horde-1', 'memberIds' => [], 'positions' => []], null],
        'room:closed' => [['roomId' => 'horde-1'], null],
        'room:member_enter' => [['id' => '1001@p-1', 'roomId' => 'horde-1', 'position' => ['x' => 0, 'y' => 0]], null],
        'room:member_leave' => [['id' => '1001@p-1', 'roomId' => 'horde-1'], null],
        'room:left' => [['roomId' => 'horde-1'], null],
        'room:ok' => [['op' => 'spawn', 'roomId' => 'horde-1', 'count' => 200], null],
        // 客户端 → 服务器请求 Client → server requests
        'auth' => [['token' => 't-1'], 'map-auth:t-1'],
        'move' => [['dx' => 30, 'dy' => -5], 'req-1'],
        'attack' => [['targetId' => 'monster-1'], 'req-1'],
        'skill:cast_request' => [['skillId' => 'fireball', 'targetId' => 'monster-1'], 'req-1'],
        'pickup' => [['dropId' => 'drop-1'], 'req-1'],
        'logout' => [[], 'req-1'],
        'room:create_request' => [['roomId' => 'horde-1'], 'req-1'],
        'room:join_request' => [['roomId' => 'horde-1'], 'req-1'],
        'room:spawn_request' => [['roomId' => 'horde-1', 'count' => 200], 'req-1'],
        'room:aoe_request' => [['roomId' => 'horde-1', 'skillId' => 'fireball', 'cx' => 0, 'cy' => 0, 'r' => 70], 'req-1'],
        'room:settle_request' => [['roomId' => 'horde-1'], 'req-1'],
        'room:close_request' => [['roomId' => 'horde-1'], 'req-1'],
        // R3 GM 最小内核 R3 GM minimal kernel
        'gm:result_ok' => [['code' => 'ok', 'message' => 'kicked 2 connection(s)'], 'req-1'],
        'gm:result_denied' => [['code' => 'permission_denied', 'message' => 'permission denied: kick'], 'req-1'],
        'gm:broadcast' => [['message' => 'server restart in 5min'], null],
        'gm:exec_status' => [['command' => 'status'], 'req-1'],
        'gm:exec_broadcast' => [['command' => 'broadcast', 'message' => 'hello all'], 'req-1'],
        'gm:exec_kick' => [['command' => 'kick', 'targetId' => '1002'], 'req-1'],
        // R3 经济批（装备/交易行/邮件）R3 economy batch (equipment / auction / mail)
        'equip_request' => [['itemId' => 'sword'], 'req-1'],
        'unequip_request' => [['slot' => 'weapon'], 'req-1'],
        'economy_result_ok' => [['op' => 'equip', 'code' => 'ok', 'message' => 'sword equipped'], 'req-1'],
        'economy_result_err' => [['op' => 'auction:buy', 'code' => 'insufficient_balance', 'message' => '余额不足'], 'req-1'],
        'economy_result_auction' => [['op' => 'auction:sell', 'code' => 'ok', 'message' => 'listed', 'auctionId' => 'auc-ab12cd34ef56gh78'], 'req-1'],
        'mail:new' => [['mailId' => 'mail-ab12cd34ef56gh78'], null],
        'mail:list_empty' => [['mailIds' => [], 'titles' => [], 'bodies' => [], 'hasAttachments' => []], 'req-1'],
        'mail:list' => [['mailIds' => ['mail-a', 'mail-b'], 'titles' => ['成交', '撤单退回'], 'bodies' => ['附件如下', '货物随附件退回'], 'hasAttachments' => [true, true]], 'req-1'],
        'mail:claimed' => [['mailId' => 'mail-a', 'attachments' => "\x91\x82\xa6itemIds\xa4bone\xa5count\x02"], 'req-1'],
        'auction:sell_request' => [['itemId' => 'sword', 'count' => 1, 'price' => 300], 'req-1'],
        'auction:buy_request' => [['auctionId' => 'auc-ab12cd34ef56gh78', 'price' => 300], 'req-1'],
        'auction:cancel_request' => [['auctionId' => 'auc-ab12cd34ef56gh78'], 'req-1'],
        'economy:deposit_request' => [['count' => 500], 'req-1'],
        'mail:claim_request' => [['mailId' => 'mail-a'], 'req-1'],
        'mail:delete_request' => [['mailId' => 'mail-a'], 'req-1'],
        // R3 社交批（好友/公会正式化/排行榜）R3 social batch (friends / guild formalization / leaderboard)
        'friend:apply_request' => [['targetUid' => '1002'], 'req-1'],
        'friend:accept_request' => [['targetUid' => '1001'], 'req-1'],
        'friend:reject_request' => [['targetUid' => '1001'], 'req-1'],
        'friend:remove_request' => [['targetUid' => '1002'], 'req-1'],
        'friend:list_request' => [[], 'req-1'],
        'friend:ok' => [['action' => 'apply'], 'req-1'],
        'friend:ok_list' => [['action' => 'list', 'uids' => ['1002', '1003']], 'req-1'],
        'friend:error' => [['code' => 409, 'message' => 'already_friends'], 'req-1'],
        'friend:notify' => [['type' => 'applied', 'fromUid' => '1001'], null],
        'guild:create_request' => [['guildId' => 'guild-r3', 'name' => '名门', 'maxMembers' => 30], 'req-1'],
        'guild:disband_request' => [['guildId' => 'guild-r3'], 'req-1'],
        'guild:kick_request' => [['guildId' => 'guild-r3', 'targetUid' => '1002'], 'req-1'],
        'guild:promote_request' => [['guildId' => 'guild-r3', 'targetUid' => '1002', 'role' => 'officer'], 'req-1'],
        'guild:notice_request' => [['guildId' => 'guild-r3', 'notice' => '今晚攻城'], 'req-1'],
        'guild:apply_request' => [['guildId' => 'guild-r3'], 'req-1'],
        'guild:approve_request' => [['guildId' => 'guild-r3', 'targetUid' => '1002', 'accept' => true], 'req-1'],
        'guild:ok' => [['action' => 'create', 'guildId' => 'guild-r3'], 'req-1'],
        'guild:error' => [['code' => 403, 'message' => 'permission_denied'], 'req-1'],
        'guild:notify_disbanded' => [['type' => 'disbanded', 'guildId' => 'guild-r3', 'fromUid' => '1001'], null],
        'guild:notify_notice' => [['type' => 'notice', 'guildId' => 'guild-r3', 'notice' => '公告', 'fromUid' => '1001'], null],
        'leaderboard:top_request' => [['boardId' => 'level', 'n' => 10, 'offset' => 0], 'req-1'],
        'leaderboard:rows' => [['boardId' => 'level', 'ranks' => [1, 2], 'uids' => ['u2', 'u1'], 'scores' => [300.0, 200.0]], 'req-1'],
        'leaderboard:rank_request' => [['boardId' => 'level'], 'req-1'],
        'leaderboard:ranked' => [['boardId' => 'level', 'uid' => '1001', 'rank' => 2, 'score' => 200.0], 'req-1'],
        // R3 玩法批（任务 quest:*；P2 补录——quest:rows 的 counts/claim 的 questId 曾漏登记，E2E 首次走线缆暴露）
        // R3 gameplay batch (quest:*; the P2 back-fill — quest:rows' counts and claim's questId were never
        // registered; the E2E's first wire pass exposed it)
        'quest:rows' => [['questIds' => ['kill_wolves', 'collect_bones', 'talk_elder'], 'counts' => [2, 2, 0], 'required' => [2, 2, 1], 'completed' => [true, true, false], 'rewarded' => [false, false, false]], null],
        'quest:rows_empty' => [['questIds' => [], 'counts' => [], 'required' => [], 'completed' => [], 'rewarded' => []], null],
        'quest:result_talk' => [['op' => 'talk', 'code' => 'ok', 'message' => '与 npc-elder 对话完成'], null],
        'quest:result_claim' => [['op' => 'claim', 'code' => 'not_ready', 'message' => '任务未完成或奖励已领取'], null],
        'quest:list_request' => [[], 'req-1'],
        'quest:claim_request' => [['questId' => 'kill_wolves'], 'req-1'],
        'quest:talk_request' => [['npcId' => 'npc-elder'], 'req-1'],
        // R4 mmorpg 试点第三批（P5：玩家复活 / AoE 施法——新帧类型走线缆前先入词表往返）
        // The R4 mmorpg pilot batch 3 (P5: player revive / AoE cast — new frame types round-trip through the
        // vocabulary before the first wire pass)
        'player:revive_request' => [[], 'req-1'],
        'player:revive_ok' => [['code' => 'ok', 'message' => '复活成功', 'position' => ['x' => 0, 'y' => 0]], null],
        'player:revive_not_ready' => [['code' => 'not_ready', 'message' => '玩家不在待复活状态'], null],
        'skill:cast_aoe_request' => [['skillId' => 'taunt_aoe', 'cx' => -6, 'cy' => -6, 'r' => 10], 'req-1'],
    ];

    /** @var array<string, string> 样本标签 → 协议帧类型（含双用途帧的请求/广播区分）。 Sample label → wire frame type (dual-use frames distinguish request vs broadcast). */
    private const LABEL_TO_TYPE = [
        'auth_ok' => 'auth_ok',
        'auth_failed' => 'auth_failed',
        'entity_enter' => 'entity_enter',
        'entity_enter_with_item' => 'entity_enter',
        'entity_leave' => 'entity_leave',
        'entity_moved' => 'entity_moved',
        'combat:hit' => 'combat:hit',
        'skill:cast' => 'skill:cast',
        'skill:cast_request' => 'skill:cast',
        'drop:spawned' => 'drop:spawned',
        'drop:removed' => 'drop:removed',
        'item:added' => 'item:added',
        'entity_dead' => 'entity_dead',
        'entity_dead_batch' => 'entity_dead_batch',
        'monster:spawned' => 'monster:spawned',
        'player:stats' => 'player:stats',
        'combat:error' => 'combat:error',
        'error' => 'error',
        'combat:aoe' => 'combat:aoe',
        'combat:aoe_empty' => 'combat:aoe',
        'drop:spawned_batch' => 'drop:spawned_batch',
        'room:snapshot' => 'room:snapshot',
        'room:snapshot_empty' => 'room:snapshot',
        'room:closed' => 'room:closed',
        'room:member_enter' => 'room:member_enter',
        'room:member_leave' => 'room:member_leave',
        'room:left' => 'room:left',
        'room:ok' => 'room:ok',
        'room:create_request' => 'room:create',
        'room:join_request' => 'room:join',
        'room:spawn_request' => 'room:spawn',
        'room:aoe_request' => 'room:aoe',
        'room:settle_request' => 'room:settle',
        'room:close_request' => 'room:close',
        'gm:result_ok' => 'gm:result',
        'gm:result_denied' => 'gm:result',
        'gm:broadcast' => 'gm:broadcast',
        'gm:exec_status' => 'gm:exec',
        'gm:exec_broadcast' => 'gm:exec',
        'gm:exec_kick' => 'gm:exec',
        'equip_request' => 'equip',
        'unequip_request' => 'unequip',
        'economy_result_ok' => 'economy:result',
        'economy_result_err' => 'economy:result',
        'economy_result_auction' => 'economy:result',
        'mail:new' => 'mail:new',
        'mail:list_empty' => 'mail:list',
        'mail:list' => 'mail:list',
        'mail:claimed' => 'mail:claimed',
        'auction:sell_request' => 'auction:sell',
        'auction:buy_request' => 'auction:buy',
        'auction:cancel_request' => 'auction:cancel',
        'economy:deposit_request' => 'economy:deposit',
        'mail:claim_request' => 'mail:claim',
        'mail:delete_request' => 'mail:delete',
        'friend:apply_request' => 'friend:apply',
        'friend:accept_request' => 'friend:accept',
        'friend:reject_request' => 'friend:reject',
        'friend:remove_request' => 'friend:remove',
        'friend:list_request' => 'friend:list',
        'friend:ok' => 'friend:ok',
        'friend:ok_list' => 'friend:ok',
        'friend:error' => 'friend:error',
        'friend:notify' => 'friend:notify',
        'guild:create_request' => 'guild:create',
        'guild:disband_request' => 'guild:disband',
        'guild:kick_request' => 'guild:kick',
        'guild:promote_request' => 'guild:promote',
        'guild:notice_request' => 'guild:notice',
        'guild:apply_request' => 'guild:apply',
        'guild:approve_request' => 'guild:approve',
        'guild:ok' => 'guild:ok',
        'guild:error' => 'guild:error',
        'guild:notify_disbanded' => 'guild:notify',
        'guild:notify_notice' => 'guild:notify',
        'leaderboard:top_request' => 'leaderboard:top',
        'leaderboard:rows' => 'leaderboard:rows',
        'leaderboard:rank_request' => 'leaderboard:rank',
        'leaderboard:ranked' => 'leaderboard:ranked',
        'quest:rows' => 'quest:rows',
        'quest:rows_empty' => 'quest:rows',
        'quest:result_talk' => 'quest:result',
        'quest:result_claim' => 'quest:result',
        'quest:list_request' => 'quest:list',
        'quest:claim_request' => 'quest:claim',
        'quest:talk_request' => 'quest:talk',
        'player:revive_request' => 'player:revive',
        'player:revive_ok' => 'player:revive',
        'player:revive_not_ready' => 'player:revive',
        'skill:cast_aoe_request' => 'skill:cast_aoe',
        'auth' => 'auth',
        'move' => 'move',
        'attack' => 'attack',
        'pickup' => 'pickup',
        'logout' => 'logout',
    ];

    public function testEveryProductionFrameShapeRoundTripsThroughBinaryCodec(): void
    {
        $codec = MapCodec::create();
        foreach (self::SAMPLES as $label => [$payload, $requestId]) {
            $type = self::LABEL_TO_TYPE[$label];

            $batch = $codec->encodeBatch([Message::create($type, $payload, $requestId)]);
            $decoded = $codec->decodeBatch($batch);
            self::assertCount(1, $decoded, "批量必须恰含 1 帧: $label. Batch must hold exactly one frame: $label.");
            self::assertSame($type, $decoded[0]->type, "帧类型往返: $label. Frame type round-trip: $label.");
            self::assertSame($requestId, $decoded[0]->requestId, "requestId 往返: $label. requestId round-trip: $label.");
            self::assertSame($payload, $decoded[0]->payload, "负载往返: $label. Payload round-trip: $label.");
        }
    }
}
