<?php

declare(strict_types=1);

namespace Nythros\Demo\Protocol;

/**
 * 负载字段名枚举：Map 频道二进制协议「负载字段名 ↔ 编码」的权威字典。
 * 与 FrameType 相同——本枚举是共享协议契约，编码一经发布不得复用；新增字段必须同步客户端。
 * 字段名即协议字符串（payload 的 JSON key），编码值仅用于线上传输，业务代码仍写可读字段名。
 *
 * Payload-key enum: the authoritative "payload key ↔ code" dictionary of the Map channel binary protocol.
 * Like FrameType, it is a shared contract — released codes must never be reused and new keys must be
 * mirrored on the client. The name is the protocol string (the JSON key in payload); the code is used
 * only on the wire, business code keeps writing the readable key names.
 */
enum PayloadKey: int
{
    // ── 身份 / 定位 ──
    // ── Identity / positioning ──

    /** 实体 id（玩家 entityId / 怪物 id / 掉落物 id）。 */
    case ID = 1;

    /** 账号 uid（auth_ok 回执用）。 */
    case UID = 2;

    /** 位置对象 {x, y}（二进制 POS 定长编码，最省字节）。 */
    case POSITION = 3;

    /** 横坐标（独立字段，如 drop:spawned 的 {x, y}）。 */
    case X = 4;

    /** 纵坐标。 */
    case Y = 5;

    /** 移动增量 dx。 */
    case DX = 6;

    /** 移动增量 dy。 */
    case DY = 7;

    // ── 认证 ──
    // ── Auth ──

    /** 认证令牌（Map 直连 auth 请求）。 */
    case TOKEN = 8;

    /** 错误/回执码（error / combat:error / auth_failed）。 */
    case CODE = 9;

    /** 人类可读错误信息。 */
    case MESSAGE = 10;

    /** 账号名（社交层登录，JSON 通道；枚举保留以统一契约）。 */
    case USERNAME = 11;

    /** 密码（社交层登录，JSON 通道；枚举保留以统一契约）。 */
    case PASSWORD = 12;

    /** 目标地图 id（社交层登录）。 */
    case MAP_ID = 13;

    // ── 战斗 ──
    // ── Combat ──

    /** 攻击方实体 id（combat:hit）。 */
    case ATTACKER_ID = 14;

    /** 目标实体 id（combat:hit / attack 请求 / skill:cast）。 */
    case TARGET_ID = 15;

    /** 本次伤害值（combat:hit）。 */
    case DAMAGE = 16;

    /** 目标当前血量（combat:hit 结算后）。 */
    case HP = 17;

    /** 目标最大血量（player:stats）。 */
    case MAX_HP = 18;

    /** 施法者实体 id（skill:cast 广播）。 */
    case CASTER_ID = 19;

    /** 技能 id（skill:cast 请求与广播）。 */
    case SKILL_ID = 20;

    /** 掉落物 id（drop:spawned / drop:removed / pickup 请求）。 */
    case DROP_ID = 21;

    /** 物品 id（drop:spawned / item:added / 掉落物 entity_enter）。 */
    case ITEM_ID = 22;

    /** 物品数量（item:added）。 */
    case COUNT = 23;

    /** 怪物造型 id（monster:spawned）。 */
    case TYPE_ID = 24;

    /** 认证失败原因（auth_failed 回执，如 map_mismatch / 令牌过期）。 */
    case REASON = 25;

    // ── R2 房间与批量管线（ADR-024） ──
    // ── R2 rooms and batch pipelines (ADR-024) ──

    /** AoE 命中目标 id 列表（combat:aoe，与 damages/hps 并行对齐）。 */
    case TARGET_IDS = 26;

    /** AoE 伤害列表（combat:aoe，与 targetIds 对齐）。 */
    case DAMAGES = 27;

    /** AoE 命中后血量列表（combat:aoe，与 targetIds 对齐）。 */
    case HPS = 28;

    /** 批量掉落 id 列表（drop:spawned_batch）。 */
    case DROP_IDS = 29;

    /** 批量掉落物品 id 列表（drop:spawned_batch，与 dropIds 对齐）。 */
    case ITEM_IDS = 30;

    /** 批量坐标列表（drop:spawned_batch / room:snapshot，POS 定长编码元素）。 */
    case POSITIONS = 31;

    /** 房间 id（room:* 帧）。 */
    case ROOM_ID = 32;

    /** 房间成员 id 列表（room:snapshot，与 positions 对齐）。 */
    case MEMBER_IDS = 33;

    /** 房间操作名（room:ok 回执，如 create/join/spawn/aoe/settle/close）。 */
    case OP = 34;

    /** AoE 圆心 X 坐标（room:aoe 请求）。 */
    case CX = 35;

    /** AoE 圆心 Y 坐标（room:aoe 请求）。 */
    case CY = 36;

    /** AoE 半径（room:aoe 请求，非负）。 */
    case RADIUS = 37;

    // ── R3 GM 最小内核 ──
    // ── R3 GM minimal kernel ──

    /** GM 命令名（gm:exec 请求）。 */
    case COMMAND = 38;

    // ── R3 经济批（装备/交易行/邮件） ──
    // ── R3 economy batch (equipment / auction / mail) ──

    /** 装备槽位值（unequip 请求，如 weapon/armor/accessory）。 */
    case SLOT = 39;

    /** 挂单总价（auction:sell 请求）。 */
    case PRICE = 40;

    /** 挂单 id（economy:result 回执）。 */
    case AUCTION_ID = 41;

    /** 邮件 id（mail:claim/mail:delete 请求与 mail:new/mail:claimed 回执）。 */
    case MAIL_ID = 42;

    /** 邮件标题（mail:list 回执）。 */
    case TITLE = 43;

    /** 邮件正文（mail:list 回执）。 */
    case BODY = 44;

    /** 是否含附件（mail:list 单封标记）。 */
    case HAS_ATTACHMENT = 45;

    /** 附件列表的 MessagePack 字节串（mail:claimed 回执；V7：嵌套负载走 MsgpackSerializer 路径）。 */
    case ATTACHMENTS = 46;

    /** 邮件 id 列表（mail:list 回执，与 titles/bodies/hasAttachments 并行对齐）。 */
    case MAIL_IDS = 47;

    /** 邮件标题列表（mail:list 回执）。 */
    case TITLES = 48;

    /** 邮件正文列表（mail:list 回执）。 */
    case BODIES = 49;

    /** 是否含附件列表（mail:list 回执，bool 列表）。 */
    case HAS_ATTACHMENTS = 50;

    // ── R3 社交批（好友 / 公会正式化 / 排行榜） ──
    // ── R3 social batch (friends / guild formalization / leaderboard) ──

    /** 目标账号 uid（friend:* 与 guild:kick/promote/approve 请求）。 */
    case TARGET_UID = 51;

    /** 来源账号 uid（friend:notify / guild:notify）。 */
    case FROM_UID = 52;

    /** 操作名（friend:ok / guild:ok 回执，如 apply/accept/create/disband）。 */
    case ACTION = 53;

    /** 事件类型（friend:notify / guild:notify，如 applied/disbanded/notice）。 */
    case TYPE = 54;

    /** 帮派名（guild:create 请求，可省略）。 */
    case NAME = 55;

    /** 帮派人数上限（guild:create 请求，可省略）。 */
    case MAX_MEMBERS = 56;

    /** 职位值（guild:promote 请求与回执：officer|member）。 */
    case ROLE = 57;

    /** 公告正文（guild:notice 请求与 guild:notice 通知）。 */
    case NOTICE = 58;

    /** 审批结论（guild:approve 请求 bool）。 */
    case ACCEPT = 59;

    /** 好友 uid 列表（friend:ok list 回执）。 */
    case UIDS = 60;

    /** 榜单 id（leaderboard:* 帧）。 */
    case BOARD_ID = 61;

    /** top N 条数（leaderboard:top 请求）。 */
    case N = 62;

    /** 分页偏移（leaderboard:top 请求，可省略缺省 0）。 */
    case OFFSET = 63;

    /** 排名列列表（leaderboard:rows 回执，与 uids/scores 并行对齐）。 */
    case RANKS = 64;

    /** 分数列表（leaderboard:rows 回执，与 ranks/uids 并行对齐）。 */
    case SCORES = 65;

    /** 单 uid 排名（leaderboard:ranked 回执；未上榜为 null）。 */
    case RANK = 66;

    /** 单 uid 分数（leaderboard:ranked 回执）。 */
    case SCORE = 67;

    /** 帮派 id（guild:* 帧）。 */
    case GUILD_ID = 68;

    // ── R3 玩法批（技能Buff正式化 / 匹配 / 任务） ──
    // ── R3 gameplay batch (buff formalization / matching / quests) ──

    /** Buff 定义 id（buff:apply 请求与 buff:applied/buff:expired 回执）。 */
    case BUFF_ID = 69;

    /** Buff 当前层数（buff:applied 回执）。 */
    case STACKS = 70;

    /** Buff 持续秒数（buff:applied 回执）。 */
    case DURATION_SECONDS = 71;

    /** 匹配队列 id（matching:enqueue 请求与 matched/ok 回执）。 */
    case QUEUE_ID = 72;

    /** 候选者等级（matching:enqueue 请求，准入校验用）。 */
    case LEVEL = 73;

    /** 任务 id 列表（quest:rows 回执，与 counts/required/completed/rewarded 并行对齐）。 */
    case QUEST_IDS = 74;

    /** 进度所需数量列表（quest:rows 回执）。 */
    case REQUIRED = 75;

    /** 完成标记列表（quest:rows 回执，bool 列表）。 */
    case COMPLETED = 76;

    /** 领奖标记列表（quest:rows 回执，bool 列表）。 */
    case REWARDED = 77;

    /** NPC id（quest:talk 请求）。 */
    case NPC_ID = 78;

    // ── R4 死亡批量帧（ADR-024 §9 V5） ──
    // ── R4 death batch frame (ADR-024 §9 V5) ──

    /** 批量死亡实体 id 列表（entity_dead_batch，与 positions/types 并行对齐）。 */
    case IDS = 79;

    /** 批量死亡实体种类列表（entity_dead_batch：monster|player，与 ids 并行对齐）。 */
    case TYPES = 80;

    // ── R4 mmorpg 试点（P2 quest:rows/quest:claim 走线缆补齐） ──
    // ── R4 mmorpg pilot (the P2 close-out: quest:rows / quest:claim over the wire) ──

    /** 任务进度数量列表（quest:rows 回执，与 questIds/required/completed/rewarded 并行对齐）。 */
    case COUNTS = 81;

    /** 任务 id（quest:claim 请求）。 */
    case QUEST_ID = 82;

    /** P9b：世界 tick 分频（world:tick_rate，base tick 的分频系数）。 The P9b world tick divisor (world:tick_rate). */
    case DIVISOR = 83;

    // ── 协议版本协商（auth 载荷；编码一经发布不得复用） ──
    // ── Protocol version negotiation (auth payload; released codes are never reused) ──

    /** 客户端协议版本（gateway JSON auth 与 Map 二进制 auth 携带；服务器按最低版本守卫拒绝旧客户端）。 The client protocol version (carried by both the gateway JSON auth and the Map binary auth; the server rejects pre-minimum clients). */
    case VERSION = 84;

    /**
     * 字段名（协议字符串，如 'position'）→ 编码。
     * Payload-key name (wire string, e.g. 'position') → code.
     *
     * @return array<string, int>
     */    public static function codeMap(): array
    {
        return [
            'id' => self::ID->value,
            'uid' => self::UID->value,
            'position' => self::POSITION->value,
            'x' => self::X->value,
            'y' => self::Y->value,
            'dx' => self::DX->value,
            'dy' => self::DY->value,
            'token' => self::TOKEN->value,
            'code' => self::CODE->value,
            'message' => self::MESSAGE->value,
            'username' => self::USERNAME->value,
            'password' => self::PASSWORD->value,
            'mapId' => self::MAP_ID->value,
            'attackerId' => self::ATTACKER_ID->value,
            'targetId' => self::TARGET_ID->value,
            'damage' => self::DAMAGE->value,
            'hp' => self::HP->value,
            'maxHp' => self::MAX_HP->value,
            'casterId' => self::CASTER_ID->value,
            'skillId' => self::SKILL_ID->value,
            'dropId' => self::DROP_ID->value,
            'itemId' => self::ITEM_ID->value,
            'count' => self::COUNT->value,
            'typeId' => self::TYPE_ID->value,
            'reason' => self::REASON->value,
            'targetIds' => self::TARGET_IDS->value,
            'damages' => self::DAMAGES->value,
            'hps' => self::HPS->value,
            'dropIds' => self::DROP_IDS->value,
            'itemIds' => self::ITEM_IDS->value,
            'positions' => self::POSITIONS->value,
            'roomId' => self::ROOM_ID->value,
            'memberIds' => self::MEMBER_IDS->value,
            'op' => self::OP->value,
            'cx' => self::CX->value,
            'cy' => self::CY->value,
            'r' => self::RADIUS->value,
            'command' => self::COMMAND->value,
            'slot' => self::SLOT->value,
            'price' => self::PRICE->value,
            'auctionId' => self::AUCTION_ID->value,
            'mailId' => self::MAIL_ID->value,
            'title' => self::TITLE->value,
            'body' => self::BODY->value,
            'hasAttachment' => self::HAS_ATTACHMENT->value,
            'attachments' => self::ATTACHMENTS->value,
            'mailIds' => self::MAIL_IDS->value,
            'titles' => self::TITLES->value,
            'bodies' => self::BODIES->value,
            'hasAttachments' => self::HAS_ATTACHMENTS->value,
            'targetUid' => self::TARGET_UID->value,
            'fromUid' => self::FROM_UID->value,
            'action' => self::ACTION->value,
            'type' => self::TYPE->value,
            'name' => self::NAME->value,
            'maxMembers' => self::MAX_MEMBERS->value,
            'role' => self::ROLE->value,
            'notice' => self::NOTICE->value,
            'accept' => self::ACCEPT->value,
            'uids' => self::UIDS->value,
            'boardId' => self::BOARD_ID->value,
            'n' => self::N->value,
            'offset' => self::OFFSET->value,
            'ranks' => self::RANKS->value,
            'scores' => self::SCORES->value,
            'rank' => self::RANK->value,
            'score' => self::SCORE->value,
            'guildId' => self::GUILD_ID->value,
            'buffId' => self::BUFF_ID->value,
            'stacks' => self::STACKS->value,
            'durationSeconds' => self::DURATION_SECONDS->value,
            'queueId' => self::QUEUE_ID->value,
            'level' => self::LEVEL->value,
            'questIds' => self::QUEST_IDS->value,
            'required' => self::REQUIRED->value,
            'completed' => self::COMPLETED->value,
            'rewarded' => self::REWARDED->value,
            'npcId' => self::NPC_ID->value,
            'ids' => self::IDS->value,
            'types' => self::TYPES->value,
            'counts' => self::COUNTS->value,
            'questId' => self::QUEST_ID->value,
            'divisor' => self::DIVISOR->value,
            'version' => self::VERSION->value,
        ];
    }
}
