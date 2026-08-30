<?php

declare(strict_types=1);

namespace Nythros\Demo\Protocol;

/**
 * 帧类型枚举：Map 频道二进制协议的「帧类型 ↔ 编码」权威字典。
 * 本枚举是客户端与服务端共享的协议契约——新增/删除帧类型必须同步修改两端，编码一经发布不得复用。
 * 每项注释说明：传输方向（客户端→服务器 = 请求 / 服务器→客户端 = 广播 / 双向）、语义与关键负载字段。
 *
 * Frame-type enum: the authoritative "type ↔ code" dictionary of the Map channel's binary protocol.
 * It is the shared client/server contract — adding or removing a type must be mirrored on both ends, and
 * a released code must never be reused. Each case documents its direction (client→server request,
 * server→client broadcast, or both) plus its key payload fields.
 */
enum FrameType: int
{
    // ── 服务器 → 客户端：视野与状态广播（帧末批量下发） ──
    // ── Server → client: view and state broadcasts (batched at frame end) ──

    /** 实体移动：状态帧，同帧同实体只保留最新位置；payload {id, position}。 */
    case ENTITY_MOVED = 1;

    /** 实体进入视野：事件帧，不可合并；payload {id, position}（掉落物附带 itemId）。 */
    case ENTITY_ENTER = 2;

    /** 实体离开视野：事件帧；payload {id, position}。 */
    case ENTITY_LEAVE = 3;

    /** 普攻命中结算：事件帧；payload {attackerId, targetId, damage, hp}。 */
    case COMBAT_HIT = 4;

    /** 技能结算广播（双向同码）：请求 {skillId, targetId}；广播 {casterId, skillId, targetId}。 */
    case SKILL_CAST = 5;

    /** 掉落物出生：事件帧；payload {dropId, itemId, x, y}。 */
    case DROP_SPAWNED = 6;

    /** 掉落物被拾取移除：事件帧；payload {dropId}。 */
    case DROP_REMOVED = 7;

    /** 背包物品入账（定向拾取者）：事件帧；payload {itemId, count}。 */
    case ITEM_ADDED = 8;

    /** 实体死亡：事件帧；payload {id}。 */
    case ENTITY_DEAD = 9;

    /** 怪物出生：事件帧；payload {id, typeId, position}。 */
    case MONSTER_SPAWNED = 10;

    /** 玩家属性同步（定向本人）：状态帧，同帧去重；payload {id, hp, maxHp}。 */
    case PLAYER_STATS = 11;

    /** 战斗操作错误回执（定向请求方）：payload {code, message}。 */
    case COMBAT_ERROR = 12;

    // ── 通用/认证 ──
    // ── Generic / auth ──

    /** 通用错误回执（认证失败、非法请求等）：payload {code, message}。 */
    case ERROR = 13;

    /** 认证成功回执（Map 直连）：payload {uid, id}。 */
    case AUTH_OK = 14;

    /** 认证失败回执：payload {code, reason}。 */
    case AUTH_FAILED = 15;

    /** 客户端认证请求（Map 直连）：payload {token}。 */
    case AUTH = 16;

    // ── 客户端 → 服务器：请求（单帧单包） ──
    // ── Client → server: requests (one frame per packet) ──

    /** 移动请求：payload {dx, dy}，帧内可多次、同帧合并为最新位置。 */
    case MOVE = 17;

    /** 普攻请求：payload {targetId}。 */
    case ATTACK = 18;

    /** 拾取请求：payload {dropId}。 */
    case PICKUP = 19;

    /** 登出请求：无负载。 */
    case LOGOUT = 20;

    // ── R2 房间与批量管线（ADR-024） ──
    // ── R2 rooms and batch pipelines (ADR-024) ──

    /** AoE 批量命中结果（事件帧，一次施法恰好一帧）：payload {casterId, skillId, targetIds[], damages[], hps[]}（并行列表对齐）。 */
    case COMBAT_AOE = 21;

    /** 掉落批量出生（事件帧，一波掉落恰好一帧）：payload {dropIds[], itemIds[], positions[{x,y}]}（并行列表对齐）。 */
    case DROP_SPAWNED_BATCH = 22;

    /** 房间快照回执（定向进入者）：payload {roomId, memberIds[], positions[]}。 */
    case ROOM_SNAPSHOT = 23;

    /** 房间结算回执（定向存活成员）：payload {roomId}。 */
    case ROOM_CLOSED = 24;

    /** 房间成员进入（事件帧）：payload {id, roomId, position}。 */
    case ROOM_MEMBER_ENTER = 25;

    /** 房间成员离开（事件帧）：payload {id, roomId}。 */
    case ROOM_MEMBER_LEAVE = 26;

    /** 离房回执（定向离开者）：payload {roomId}。 */
    case ROOM_LEFT = 27;

    /** 房间操作成功回执（定向请求方）：payload {op, roomId, count}。 */
    case ROOM_OK = 28;

    // ── 房间操作请求（客户端 → 服务器） ──
    // ── Room operation requests (client → server) ──

    /** 建房请求：payload {roomId}。 */
    case ROOM_CREATE = 29;

    /** 进房请求（transfer 约定路径）：payload {roomId}。 */
    case ROOM_JOIN = 30;

    /** 房内刷怪请求：payload {roomId, count}。 */
    case ROOM_SPAWN = 31;

    /** AoE 施法请求：payload {roomId, skillId, cx, cy, r}。 */
    case ROOM_AOE = 32;

    /** 结算请求：payload {roomId}。 */
    case ROOM_SETTLE = 33;

    /** 关闭请求：payload {roomId}。 */
    case ROOM_CLOSE = 34;

    // ── R3 GM 最小内核 ──
    // ── R3 GM minimal kernel ──

    /** GM 命令执行请求（客户端 → 服务器）：payload {command}（broadcast 附 message、kick 附 targetId）。 */
    case GM_EXEC = 35;

    /** GM 命令结果回执（定向请求方）：payload {code, message}（code = ok|unknown_command|permission_denied|error）。 */
    case GM_RESULT = 36;

    /** GM 全服广播（事件帧，本进程全部已认证连接）：payload {message}。 */
    case GM_BROADCAST = 37;

    // ── R3 经济批（装备/交易行/邮件） ──
    // ── R3 economy batch (equipment / auction / mail) ──

    /** 穿戴请求（客户端 → 服务器）：payload {itemId}。 */
    case EQUIP = 38;

    /** 卸下请求（客户端 → 服务器）：payload {slot}。 */
    case UNEQUIP = 39;

    /** 经济操作统一回执（定向请求方）：payload {op, code, message}（auctionId 可选附带回执）。 */
    case ECONOMY_RESULT = 40;

    /** 新邮件在线通知（服务器推送）：payload {mailId}。 */
    case MAIL_NEW = 41;

    /** 收件箱列表回执（定向请求方）：payload {mailIds[], titles[], bodies[], hasAttachments[]}（并行列表对齐）。 */
    case MAIL_LIST = 42;

    /** 附件领取回执（定向请求方）：payload {mailId, attachments}——attachments 为嵌套附件列表的
     *  MessagePack 字节串（协议约束 V7：嵌套负载走 MsgpackSerializer 路径，不进二进制 LIST）。 */
    case MAIL_CLAIMED = 43;

    /** 挂单请求（客户端 → 服务器）：payload {itemId, count, price}。 */
    case AUCTION_SELL = 44;

    /** 购买请求（客户端 → 服务器）：payload {auctionId, price}。 */
    case AUCTION_BUY = 45;

    /** 撤单请求（客户端 → 服务器）：payload {auctionId}。 */
    case AUCTION_CANCEL = 46;

    /** 入账请求（客户端 → 服务器，demo 规模最小入账入口）：payload {count}。 */
    case ECONOMY_DEPOSIT = 47;

    /** 附件领取请求（客户端 → 服务器）：payload {mailId}。 */
    case MAIL_CLAIM = 48;

    /** 删除邮件请求（客户端 → 服务器）：payload {mailId}。 */
    case MAIL_DELETE = 49;

    // ── R3 社交批（好友 / 公会正式化 / 排行榜） ──
    // ── R3 social batch (friends / guild formalization / leaderboard) ──

    /** 好友申请请求（客户端 → 服务器）：payload {targetUid}。 */
    case FRIEND_APPLY = 50;

    /** 好友同意请求（客户端 → 服务器）：payload {targetUid}（targetUid 为原申请方）。 */
    case FRIEND_ACCEPT = 51;

    /** 好友拒绝请求（客户端 → 服务器）：payload {targetUid}（targetUid 为原申请方）。 */
    case FRIEND_REJECT = 52;

    /** 好友删除请求（客户端 → 服务器）：payload {targetUid}（双向一致移除）。 */
    case FRIEND_REMOVE = 53;

    /** 好友列表请求（客户端 → 服务器）：无负载。 */
    case FRIEND_LIST = 54;

    /** 好友操作回执（定向请求方）：payload {action}（list 附 uids[]）。 */
    case FRIEND_OK = 55;

    /** 好友操作错误回执（定向请求方）：payload {code, message}。 */
    case FRIEND_ERROR = 56;

    /** 好友事件通知（服务器推送，离线静默丢弃）：payload {type, fromUid}（type = applied|accepted|rejected|removed）。 */
    case FRIEND_NOTIFY = 57;

    /** 建会请求（客户端 → 服务器）：payload {guildId}（name/maxMembers 可选）。 */
    case GUILD_CREATE = 58;

    /** 解散请求（客户端 → 服务器，仅会长）：payload {guildId}。 */
    case GUILD_DISBAND = 59;

    /** 踢人请求（客户端 → 服务器，会长/官员踢低阶位）：payload {guildId, targetUid}。 */
    case GUILD_KICK = 60;

    /** 任命请求（客户端 → 服务器，仅会长）：payload {guildId, targetUid, role}（role = officer|member）。 */
    case GUILD_PROMOTE = 61;

    /** 公告请求（客户端 → 服务器，会长/官员）：payload {guildId, notice}。 */
    case GUILD_NOTICE = 62;

    /** 入会申请请求（客户端 → 服务器）：payload {guildId}。 */
    case GUILD_APPLY = 63;

    /** 审批请求（客户端 → 服务器，会长/官员）：payload {guildId, targetUid, accept}。 */
    case GUILD_APPROVE = 64;

    /** 公会操作回执（定向请求方）：payload {action, guildId}（promote 附 role）。 */
    case GUILD_OK = 65;

    /** 公会操作错误回执（定向请求方）：payload {code, message}。 */
    case GUILD_ERROR = 66;

    /** 公会事件通知（服务器推送）：payload {type, guildId}（notice 附 notice、promote 附 role、fromUid 可选）。 */
    case GUILD_NOTIFY = 67;

    /** 榜单 top N 查询（客户端 → 服务器）：payload {boardId, n}（offset 可选）。 */
    case LEADERBOARD_TOP = 68;

    /** 榜单行回执（定向请求方）：payload {boardId, ranks[], uids[], scores[]}（并行列表对齐）。 */
    case LEADERBOARD_ROWS = 69;

    /** 单 uid 排名查询（客户端 → 服务器）：payload {boardId}。 */
    case LEADERBOARD_RANK = 70;

    /** 单 uid 排名回执（定向请求方）：payload {boardId, uid, rank, score}（未上榜 rank 为 null）。 */
    case LEADERBOARD_RANKED = 71;

    // ── R3 玩法批（技能Buff正式化 / 匹配 / 任务） ──
    // ── R3 gameplay batch (buff formalization / matching / quests) ──

    /** Buff 施加请求（客户端 → 服务器）：payload {buffId, targetId}。 */
    case BUFF_APPLY = 72;

    /** Buff 移除请求（客户端 → 服务器，主动驱散）：payload {buffId}。 */
    case BUFF_REMOVE = 73;

    /** Buff 施加/刷新回执（定向宿主）：payload {targetId, buffId, stacks, durationSeconds}。 */
    case BUFF_APPLIED = 74;

    /** Buff 到期/驱散通知（定向宿主）：payload {targetId, buffId}。 */
    case BUFF_EXPIRED = 75;

    /** 匹配入队请求（客户端 → 服务器）：payload {queueId, level}。 */
    case MATCHING_ENQUEUE = 76;

    /** 匹配取消请求（客户端 → 服务器）：无负载。 */
    case MATCHING_CANCEL = 77;

    /** 撮合成功通知（定向各成员）：payload {roomId, memberIds[]}。 */
    case MATCHING_MATCHED = 78;

    /** 匹配操作回执（定向请求方）：payload {op, code, message}。 */
    case MATCHING_OK = 79;

    /** 任务列表请求（客户端 → 服务器）：无负载。 */
    case QUEST_LIST = 80;

    /** 任务进度列表回执（定向请求方）：payload {questIds[], counts[], required[], completed[], rewarded[]}（并行列表对齐）。 */
    case QUEST_ROWS = 81;

    /** 任务领奖请求（客户端 → 服务器）：payload {questId}。 */
    case QUEST_CLAIM = 82;

    /** 任务对话请求（客户端 → 服务器，对话进度源）：payload {npcId}。 */
    case QUEST_TALK = 83;

    /** 任务操作回执（定向请求方）：payload {op, code, message}。 */
    case QUEST_RESULT = 84;

    // ── R4 死亡批量帧（ADR-024 §9 V5） ──
    // ── R4 death batch frame (ADR-024 §9 V5) ──

    /** 实体批量死亡（事件帧，一次 AoE 攒批恰好一帧）：payload {ids[], positions[{x,y}], types[]}（并行列表对齐，V7 列式编码）。 */
    case ENTITY_DEAD_BATCH = 85;

    // ── R4 mmorpg 试点第三批（P5：玩家复活 / AoE 施法） ──
    // ── The R4 mmorpg pilot batch 3 (P5: player revive / AoE cast) ──

    /** 玩家复活请求（客户端 → 服务器，待复活状态消费）：无负载；回执（定向请求方）payload {code, message, position}（code = ok|not_ready）。 */
    case PLAYER_REVIVE = 86;

    /** AoE 施法请求（客户端 → 服务器）：payload {skillId, cx, cy, r}——形状为世界绝对坐标，施法距离由形状裁决。 */
    case SKILL_CAST_AOE = 87;

    /** P9b：世界 tick 分频速率帧（负载降档时通知客户端调整插值窗口）。 The P9b world tick-rate frame (notifies the client to adjust its interpolation window on a downgrade). */
    case WORLD_TICK_RATE = 88;

    /**
     * 帧类型名（协议字符串，如 'entity_moved'）→ 编码。
     * Frame-type name (wire string, e.g. 'entity_moved') → code.
     *
     * @return array<string, int>
     */
    public static function codeMap(): array
    {
        return [
            'entity_moved' => self::ENTITY_MOVED->value,
            'entity_enter' => self::ENTITY_ENTER->value,
            'entity_leave' => self::ENTITY_LEAVE->value,
            'combat:hit' => self::COMBAT_HIT->value,
            'skill:cast' => self::SKILL_CAST->value,
            'drop:spawned' => self::DROP_SPAWNED->value,
            'drop:removed' => self::DROP_REMOVED->value,
            'item:added' => self::ITEM_ADDED->value,
            'entity_dead' => self::ENTITY_DEAD->value,
            'monster:spawned' => self::MONSTER_SPAWNED->value,
            'player:stats' => self::PLAYER_STATS->value,
            'combat:error' => self::COMBAT_ERROR->value,
            'error' => self::ERROR->value,
            'auth_ok' => self::AUTH_OK->value,
            'auth_failed' => self::AUTH_FAILED->value,
            'auth' => self::AUTH->value,
            'move' => self::MOVE->value,
            'attack' => self::ATTACK->value,
            'pickup' => self::PICKUP->value,
            'logout' => self::LOGOUT->value,
            'combat:aoe' => self::COMBAT_AOE->value,
            'drop:spawned_batch' => self::DROP_SPAWNED_BATCH->value,
            'room:snapshot' => self::ROOM_SNAPSHOT->value,
            'room:closed' => self::ROOM_CLOSED->value,
            'room:member_enter' => self::ROOM_MEMBER_ENTER->value,
            'room:member_leave' => self::ROOM_MEMBER_LEAVE->value,
            'room:left' => self::ROOM_LEFT->value,
            'room:ok' => self::ROOM_OK->value,
            'room:create' => self::ROOM_CREATE->value,
            'room:join' => self::ROOM_JOIN->value,
            'room:spawn' => self::ROOM_SPAWN->value,
            'room:aoe' => self::ROOM_AOE->value,
            'room:settle' => self::ROOM_SETTLE->value,
            'room:close' => self::ROOM_CLOSE->value,
            'gm:exec' => self::GM_EXEC->value,
            'gm:result' => self::GM_RESULT->value,
            'gm:broadcast' => self::GM_BROADCAST->value,
            'equip' => self::EQUIP->value,
            'unequip' => self::UNEQUIP->value,
            'economy:result' => self::ECONOMY_RESULT->value,
            'mail:new' => self::MAIL_NEW->value,
            'mail:list' => self::MAIL_LIST->value,
            'mail:claimed' => self::MAIL_CLAIMED->value,
            'auction:sell' => self::AUCTION_SELL->value,
            'auction:buy' => self::AUCTION_BUY->value,
            'auction:cancel' => self::AUCTION_CANCEL->value,
            'economy:deposit' => self::ECONOMY_DEPOSIT->value,
            'mail:claim' => self::MAIL_CLAIM->value,
            'mail:delete' => self::MAIL_DELETE->value,
            'friend:apply' => self::FRIEND_APPLY->value,
            'friend:accept' => self::FRIEND_ACCEPT->value,
            'friend:reject' => self::FRIEND_REJECT->value,
            'friend:remove' => self::FRIEND_REMOVE->value,
            'friend:list' => self::FRIEND_LIST->value,
            'friend:ok' => self::FRIEND_OK->value,
            'friend:error' => self::FRIEND_ERROR->value,
            'friend:notify' => self::FRIEND_NOTIFY->value,
            'guild:create' => self::GUILD_CREATE->value,
            'guild:disband' => self::GUILD_DISBAND->value,
            'guild:kick' => self::GUILD_KICK->value,
            'guild:promote' => self::GUILD_PROMOTE->value,
            'guild:notice' => self::GUILD_NOTICE->value,
            'guild:apply' => self::GUILD_APPLY->value,
            'guild:approve' => self::GUILD_APPROVE->value,
            'guild:ok' => self::GUILD_OK->value,
            'guild:error' => self::GUILD_ERROR->value,
            'guild:notify' => self::GUILD_NOTIFY->value,
            'leaderboard:top' => self::LEADERBOARD_TOP->value,
            'leaderboard:rows' => self::LEADERBOARD_ROWS->value,
            'leaderboard:rank' => self::LEADERBOARD_RANK->value,
            'leaderboard:ranked' => self::LEADERBOARD_RANKED->value,
            'buff:apply' => self::BUFF_APPLY->value,
            'buff:remove' => self::BUFF_REMOVE->value,
            'buff:applied' => self::BUFF_APPLIED->value,
            'buff:expired' => self::BUFF_EXPIRED->value,
            'matching:enqueue' => self::MATCHING_ENQUEUE->value,
            'matching:cancel' => self::MATCHING_CANCEL->value,
            'matching:matched' => self::MATCHING_MATCHED->value,
            'matching:ok' => self::MATCHING_OK->value,
            'quest:list' => self::QUEST_LIST->value,
            'quest:rows' => self::QUEST_ROWS->value,
            'quest:claim' => self::QUEST_CLAIM->value,
            'quest:talk' => self::QUEST_TALK->value,
            'quest:result' => self::QUEST_RESULT->value,
            'entity_dead_batch' => self::ENTITY_DEAD_BATCH->value,
            'player:revive' => self::PLAYER_REVIVE->value,
            'skill:cast_aoe' => self::SKILL_CAST_AOE->value,
            'world:tick_rate' => self::WORLD_TICK_RATE->value,
        ];
    }
}
