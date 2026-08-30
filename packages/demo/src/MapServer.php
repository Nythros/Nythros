<?php

declare(strict_types=1);

namespace Nythros\Demo;

use Nythros\Cluster\ServiceRegistryInterface;
use Nythros\Contracts\ActorInterface;
use Nythros\Contracts\AOIProviderInterface;
use Nythros\Contracts\ClockInterface;
use Nythros\Contracts\EntityInterface;
use Nythros\Contracts\RoomInstanceInterface;
use Nythros\Contracts\TimerInterface;
use Nythros\Contracts\WorldInterface;
use Nythros\Demo\Gameplay\GameplayConfig;
use Nythros\Entity\BaseEntity;
use Nythros\Entity\CircleShape;
use Nythros\Entity\Position;
use Nythros\Entity\RectangleShape;
use Nythros\Framework\Actor\PlayerActor;
use Nythros\Framework\Auction\AuctionService;
use Nythros\Framework\Auction\CurrencyLedger;
use Nythros\Framework\BasePlayer;
use Nythros\Framework\Cluster\PlayerTransferStoreInterface;
use Nythros\Framework\Combat\ActorLookupInterface;
use Nythros\Framework\Combat\BuffService;
use Nythros\Framework\Combat\CombatService;
use Nythros\Framework\Combat\DropEntity;
use Nythros\Framework\Combat\DropTable;
use Nythros\Framework\Combat\EntityTypeIndex;
use Nythros\Framework\Combat\MonsterActor;
use Nythros\Framework\Combat\RandomSourceInterface;
use Nythros\Framework\Combat\SkillCooldownTable;
use Nythros\Framework\Combat\SystemRandomSource;
use Nythros\Framework\Combat\VisionBroadcasterInterface;
use Nythros\Framework\Damageable;
use Nythros\Framework\Event\EventDispatcherInterface;
use Nythros\Framework\Game\Mmorpg\CellDensityGovernor;
use Nythros\Framework\Game\Mmorpg\MmorpgConfig;
use Nythros\Framework\Game\Mmorpg\Respawner;
use Nythros\Framework\Game\Mmorpg\ThreatRules;
use Nythros\Framework\Game\Mmorpg\ThreatTable;
use Nythros\Framework\Gm\GmBroadcasterInterface;
use Nythros\Framework\Gm\GmCommandBus;
use Nythros\Framework\Gm\GmDrainHandlerInterface;
use Nythros\Framework\Gm\GmKickerInterface;
use Nythros\Framework\Gm\GmStatusProviderInterface;
use Nythros\Framework\Inventory;
use Nythros\Framework\Inventory\Equipment\Equipment;
use Nythros\Framework\Mail\MailNotifierInterface;
use Nythros\Framework\Mail\MailService;
use Nythros\Framework\Matching\MatchingService;
use Nythros\Framework\Persistence\ArchivePipeline;
use Nythros\Framework\Plugin\Item\ItemDefinition;
use Nythros\Framework\Plugin\Item\ItemRepository;
use Nythros\Framework\Plugin\Skill\SkillDefinition;
use Nythros\Framework\Plugin\Skill\SkillRepository;
use Nythros\Framework\Quest\QuestService;
use Nythros\Framework\Server\ConnectionRegistry;
use Nythros\Framework\Server\RealtimeServer;
use Nythros\Network\ConnectionInterface;
use Nythros\Network\ServerInterface;
use Nythros\Protocol\BatchSerializerInterface;
use Nythros\Protocol\Message;
use Nythros\Protocol\MsgpackSerializer;
use Nythros\Security\TokenManagerInterface;
use Nythros\Security\TokenStatus;

/**
 * Map 服：认证、移动与 AOI 视野广播的入口，负责连接生命周期内的实体与 Actor 挂载/清理；
 * 同时作为战斗层接口的组装实现（VisionBroadcaster/ActorLookup，供 CombatService 与 MonsterActor 依赖倒置）。
 * 服务器运行时骨架（连接生命周期/消息分发/慢客户端与帧末批量发送/视野统一广播路径/清理模板）继承自
 * framework 的 RealtimeServer——本类只写游戏逻辑（认证握手、战斗路由、怪物出生）。
 * Map server: entry point for auth, movement and AOI view broadcast; owns entity/actor attach and cleanup over a
 * connection's lifecycle; it also implements the combat-tier interfaces (VisionBroadcaster/ActorLookup) so CombatService
 * and MonsterActor can depend on them by inversion. The server runtime skeleton (connection lifecycle / message dispatch /
 * slow-client + frame-end batching / the unified view-broadcast path / the cleanup template) is inherited from the
 * framework's RealtimeServer — this class only writes game logic (auth handshake, combat routing, monster spawning).
 */
final class MapServer extends RealtimeServer implements VisionBroadcasterInterface, ActorLookupInterface, GmBroadcasterInterface, GmKickerInterface, GmStatusProviderInterface, GmDrainHandlerInterface, MailNotifierInterface
{
    /** 世界 tick 间隔（秒）：50ms 一帧，驱动时钟与 world 更新。World tick interval in seconds: 50ms per frame, driving the clock and world update. */
    private const TICK_INTERVAL_SECONDS = 0.05;

    /** 注册表心跳间隔（秒）：5s 周期携 playerCount 调 registry->heartbeat（TTL 15s，心跳新鲜度 ≤5s）。Registry heartbeat interval in seconds: heartbeat every 5s carrying playerCount (15s TTL, heartbeat freshness ≤5s). */
    private const HEARTBEAT_INTERVAL_SECONDS = 5.0;

    /** AOI 格子尺寸（P9a 热区密度统计与 GridAOI 同源；装配层 GridAOI(10) —— 改格子尺寸须两处同步）。 The AOI cell size (the P9a hot-cell density statistics share the GridAOI source; the assembly uses GridAOI(10) — changing it requires updating both sites). */
    public const AOI_CELL_SIZE = 10;

    /** 服务类型编码：地图频道在注册表/心跳/注销中的 serviceType。Service type code: the map channel's serviceType in register/heartbeat/unregister. */
    private const SERVICE_TYPE_MAP = 'map';

    /** @var array<string, ActorInterface> entityId => Actor（玩家与怪物都登记） entityId => actor (players and monsters are both registered). */
    private array $actors = [];

    /** 已认证连接计数：auth 成功 +1、已认证连接清理 -1，随注册 meta 与心跳上报（本频道在线数口径）。Authenticated connection count: +1 on auth success, -1 on cleanup of an authenticated connection, reported via register meta and heartbeat (this channel's online-player metric). */
    private int $playerCount = 0;

    /** 战斗服务：与 CombatService 构成依赖循环（它依赖本 MapServer 做广播），由组装层在构造后回填。Combat service: circular with this MapServer (it needs this instance for broadcasting), back-filled by the assembly layer after construction. */
    private ?CombatService $combatService = null;

    /** GM 命令总线：与命令能力实现构成依赖循环（status/broadcast/kick 以本 MapServer 为门面），由组装层构造后回填（比照 attachCombat）。GM command bus: circular with the command capabilities (status/broadcast/kick use this MapServer as their facade), back-filled by the assembly layer (mirroring attachCombat). */
    private ?GmCommandBus $gm = null;

    /** 最低客户端协议版本（null = 版本守卫不启用）。装配期经 setMinClientVersion 注入。 The minimum client protocol version (null = the version guard is off), injected via setMinClientVersion at assembly. */
    private ?int $minClientVersion = null;

    /** @var array<string, Inventory> entityId => 玩家背包（auth 初始化、pickup 消费） entityId => player inventory (initialized on auth, consumed on pickup). */
    private array $inventories;

    /** @var array<string, Equipment> entityId => 玩家装备栏（auth 初始化、equip/unequip 消费；BasePlayer 属性聚合挂载） entityId => player equipment set (initialized on auth, consumed by equip/unequip; mounted for BasePlayer attribute aggregation). */
    private array $equipments = [];

    /** 经济批服务组（R3 starter-kit 接线，attachEconomy 回填；缺省 null = equip/unequip/auction/mail 路由 404）。 The economy-batch service group (the R3 starter-kit wiring, back-filled by attachEconomy; default null = equip/unequip/auction/mail routes 404). */
    private ?MailService $mail = null;

    private ?AuctionService $auction = null;

    private ?CurrencyLedger $ledger = null;

    private ?ItemRepository $economyItems = null;

    /** 附件嵌套负载编码器（V7：mail:claimed 的 attachments 字段走 MsgpackSerializer 路径）。 The nested-attachment payload encoder (V7: mail:claimed's attachments field rides the MsgpackSerializer path). */
    private ?MsgpackSerializer $msgpack = null;

    /**
     * 玩法批服务组（R3 starter-kit 接线，attachGameplay 回填；缺省 null = buff:* / matching:* / quest:* 路由 404，
     * 技能冷却表不启用）。 The gameplay-batch service group (the R3 starter-kit wiring, back-filled by attachGameplay;
     * default null = buff:* / matching:* / quest:* routes 404 and the skill-cooldown table stays off).
     */
    private ?BuffService $buffs = null;

    private ?SkillCooldownTable $cooldowns = null;

    private ?MatchingService $matching = null;

    private ?QuestService $quests = null;

    /** 随机源：spawnMonster 构造 MonsterActor 与战斗浮动共用；缺省 null 时回退系统随机。Random source: shared by spawnMonster's MonsterActor construction and combat variance; falls back to the system random source when null. */
    private readonly RandomSourceInterface $random;

    /**
     * mmorpg 类型模块配置（R4 试点，attachMmorpg 回填；缺省 null = 威胁/仇恨与重生未启用——
     * 世界侧行为与接入前逐字节等价）。 The mmorpg type-module config (the R4 pilot, back-filled by attachMmorpg;
     * default null = threat/hate and respawn off — the world side stays byte-for-byte equivalent to the pre-integration behavior).
     */
    private ?MmorpgConfig $mmorpg = null;

    /** 重生调度器（attachMmorpg 随配置创建；缺省 null = 不重生）。 The respawn scheduler (created with the config by attachMmorpg; default null = no respawn). */
    private ?Respawner $respawner = null;

    /** 玩家自动复活调度器（attachMmorpg 随 playerRespawnMs > 0 创建；缺省 null = 复活仅路由驱动，P5a 语义）。 The player auto-revive scheduler (created by attachMmorpg when playerRespawnMs > 0; default null = route-driven revive only, the P5a semantics). */
    private ?Respawner $playerRespawner = null;

    /** 热区 governor（P9a 区域降频；缺省 null = 未启用）。 The hot-cell governor (the P9a region downgrade; default null = off). */
    private ?CellDensityGovernor $hotCellGovernor = null;

    /** base tick 计数（P9b 移动广播节流的节拍源：非到期 tick 跳过热区实体的移动广播）。 The base-tick counter (the P9b move-broadcast throttle's beat source: move broadcasts for hot entities skip on non-due ticks). */
    private int $tickCounter = 0;

    /** @var null|callable(): array<string, mixed> 房间指标提供者（P9c 准入：registry 心跳 meta 汇入 rooms/deferred 等指标；缺省 null = 不上报）。 The room-metrics provider (the P9c admission: registry heartbeat metadata gains rooms/deferred metrics; default null = not reported). */
    private $roomMetricsProvider = null;

    /** draining 标记（P16 动态扩缩容）：true = 不接新会话（auth 拒绝 draining），存量连接不受影响。 The draining flag (the P16 dynamic scaling): true = no new sessions (auth rejects with draining), existing connections unaffected. */
    private bool $draining = false;

    /**
     * 怪物出生登记表（monsterId => 出生参数）：重生回锚点所需的锚点/造型/血量快照（respawnMs 为 P11
     * 逐怪重生延迟，null = MmorpgConfig.respawnMs 全局值）。
     * The monster-spawn registry (monsterId => spawn parameters): the anchor/type/maxHp snapshot needed to respawn
     * back to the anchor (respawnMs is the P11 per-monster respawn delay; null = the global MmorpgConfig.respawnMs).
     *
     * @var array<string, array{maxHp: int, position: array{x: int, y: int}, typeId: string, patrolRadius: ?int, respawnMs: ?int}>
     */
    private array $spawnRegistry = [];

    /**
     * 组装 Map 服依赖：网络服务、序列化、Token、世界与连接注册表（慢客户端软/硬阈值与单帧字节配额用 RealtimeServer 缺省值）。
     * Wires the map server dependencies: networking, serializer, token manager, world and connection registry (the slow-client
     * soft/hard thresholds and the per-frame byte quota use RealtimeServer's defaults).
     *
     * @param ServerInterface $server WebSocket 服务 WebSocket server
     * @param BatchSerializerInterface $serializer 批量序列化器（请求解码/响应编码，一包一帧或一包多帧） Batch serializer (request decode / response encode; one packet may hold one or many frames)
     * @param TokenManagerInterface $tokenManager Token 签发/消费 Token issuing/consumption
     * @param WorldInterface $world 世界（实体/AOI/Actor 门面） World facade (entities/AOI/actors)
     * @param ConnectionRegistry $registry 连接-实体双向映射 Connection-entity bidirectional mapping
     * @param ?ClockInterface $clock 世界帧时钟；与 $timer 同时注入时启动 50ms 世界 tick，缺省 null = 纯消息模式 World frame clock; when injected together with $timer starts the 50ms world tick, default null = message-only mode
     * @param ?TimerInterface $timer 定时器；缺省 null = 不启动世界 tick（单测/纯消息模式） Timer; default null = no world tick (unit-test/message-only mode)
     * @param ?string $flushRegion flush 任务投递的调度分区名；null = 投递 default 区（addTask） Scheduler region the flush task is submitted to; null = default region via addTask
     * @param string $serviceId 实例标识（编码 {mapId}#{channelId}，如 map-1#ch-1） Instance identifier ({mapId}#{channelId} encoding, e.g. map-1#ch-1)
     * @param string $mapId 本实例地图标识（MAJOR-1 mapId 比对基准） This instance's map identifier (MAJOR-1 comparison baseline)
     * @param ?ServiceRegistryInterface $serviceRegistry 服务注册表；缺省 null = 未接入集群（register/心跳/unregister 全部跳过，旧单进程形态） Service registry; default null = not clustered (register/heartbeat/unregister skipped, legacy single-process mode)
     * @param string $wsAddress 注册 meta 上报的对外 WebSocket 地址（Gateway discover 后下发客户端） Public WebSocket address reported in register meta (Gateway hands it to clients after discover)
     * @param ?DropTable $dropTable 掉落表（spawnMonster 注入 MonsterActor 用） Drop table (injected into MonsterActor by spawnMonster)
     * @param ?EntityTypeIndex $typeIndex 实体类型索引（auth/spawnMonster 登记、cleanup/死亡摘除） Entity type index (registered on auth/spawnMonster, removed on cleanup/death)
     * @param array<string, Inventory> $inventories 初始玩家背包表 entityId => Inventory（auth 时缺失键自动补建） Initial player-inventory table entityId => Inventory (missing keys are auto-created on auth)
     * @param ?ArchivePipeline $archive 归档管线；缺省 null = 不持久化（pickup 后标脏背包） Archive pipeline; default null = no persistence (inventory marked dirty after pickup)
     * @param ?SkillRepository $skills 技能注册表（skill:cast 前置校验用） Skill repository (used for skill:cast pre-validation)
     * @param ?RandomSourceInterface $random 随机源；缺省 null = SystemRandomSource Random source; default null = SystemRandomSource
     * @param ?float $snapshotResyncIntervalSeconds 视野快照周期重同步间隔（秒）；缺省 null = 关闭（单测/纯消息模式） Periodic vision-snapshot resync interval in seconds; default null = off (unit-test/message-only mode)
     * @param ?RoomHub $rooms 房间编排中枢（ADR-024 starter-kit 接线）；缺省 null = 不启用房间路由（room:* 请求 404） Room orchestration hub (ADR-024 starter-kit wiring); default null = room routing disabled (room:* requests get 404)
     * @param int $spawnProtectionFrames 出生保护窗口帧数（R4 类型模块试点参数化；缺省 PlayerActor 基准值） Spawn-protection window frames (parameterized in the R4 type-module pilot; default the PlayerActor baseline).
     * @param array{x: int, y: int} $spawnPoint 出生/复活点（P7b 参数化）：auth 挂载与复活传送共用；缺省原点 (0,0)。
     *   装配层应与 mmorpg 安全区圆心对齐（P7c）。 The spawn/revive point (the P7b parameterization): shared by the
     *   auth mount and the revive teleport; default the origin (0,0). The assembly layer should align it with the
     *   mmorpg safe-zone center (the P7c).
     * @param ?MmorpgConfig $mmorpg mmorpg 类型模块配置（R4 试点）；缺省 null = 威胁/仇恨与重生未启用
     *   The mmorpg type-module config (the R4 pilot); default null = threat/hate and respawn off.
     */
    public function __construct(
        ServerInterface $server,
        BatchSerializerInterface $serializer,
        private readonly TokenManagerInterface $tokenManager,
        WorldInterface $world,
        ConnectionRegistry $registry,
        private readonly ?ClockInterface $clock = null,
        private readonly ?TimerInterface $timer = null,
        private readonly ?string $flushRegion = null,
        private readonly string $serviceId = 'map-1#ch-1',
        private readonly string $mapId = 'map-1',
        private readonly ?ServiceRegistryInterface $serviceRegistry = null,
        private readonly string $wsAddress = 'ws://127.0.0.1:18081',
        // 掉落表（P11 玩法数据外置热载）：非 readonly——drops 表 config.changed 时经 replaceDropTable 原子换入
        // （在场怪物持有旧表引用自然耗尽，新出生/重生怪物用新表）。
        // The drop table (the P11 hot reload): non-readonly — a drops config.changed swaps in atomically via
        // replaceDropTable (live monsters keep draining their old-table reference; newly spawned/respawned ones use the new table).
        private ?DropTable $dropTable = null,
        private readonly ?EntityTypeIndex $typeIndex = null,
        array $inventories = [],
        private readonly ?ArchivePipeline $archive = null,
        private readonly ?SkillRepository $skills = null,
        ?RandomSourceInterface $random = null,
        private readonly ?float $snapshotResyncIntervalSeconds = null,
        private readonly ?RoomHub $rooms = null,
        private readonly int $spawnProtectionFrames = PlayerActor::SPAWN_PROTECTION_FRAMES,
        // 出生/复活点（P7b 参数化）：auth 挂载与复活传送共用；缺省原点 (0,0)（与接入前逐字节等价）。
        // 装配层应与 mmorpg 安全区圆心对齐（P7c）。非 readonly（P11）：gameplay 表热载经 applyGameplayConfig 换入。
        // The spawn/revive point (the P7b parameterization): shared by the auth mount and the revive teleport;
        // default the origin (0,0) (byte-for-byte equivalent to the pre-integration behavior). The assembly layer
        // should align it with the mmorpg safe-zone center (the P7c). Non-readonly (the P11): a gameplay-table hot
        // reload swaps it in via applyGameplayConfig.
        private array $spawnPoint = ['x' => 0, 'y' => 0],
        ?MmorpgConfig $mmorpg = null,
        // 跨 map 迁移票据存储（P15 / ADR-025）：null = 未装配（不导出/不导入，接入前语义）。
        // The cross-map migration ticket store (the P15 / ADR-025): null = unassembled (no export/import, the pre-integration semantics).
        private readonly ?PlayerTransferStoreInterface $transfers = null,
        // 容量上限（P16 动态扩缩容）：>0 时注册 meta 携带 maxCapacity（gateway 路由过滤）+ auth 硬守卫
        // （playerCount 达顶拒绝 map_full）；0 = 不限量（缺省，接入前语义）。
        // The capacity cap (the P16 dynamic scaling): when >0 the register meta carries maxCapacity (the
        // gateway's routing filter) plus an auth hard guard (playerCount at the cap rejects with map_full);
        // 0 = unlimited (the default, the pre-integration semantics).
        private readonly int $maxCapacity = 0,
        // 归档恢复开关（P18 工程债收尾）：true = auth 时无转移票据则读归档恢复背包（关闭「归档只写」
        // 半闭环）；缺省 false = 全新背包（接入前语义，存量验收依赖逐跑全新背包）。
        // The archive-restore switch (the P18 engineering-debt close-out): true = auth falls back to an
        // archive read for the inventory when no transfer ticket exists (closing the write-only archive's
        // half-open loop); default false = a fresh inventory (the pre-integration semantics — existing
        // acceptance depends on a per-run fresh inventory).
        private readonly bool $archiveRestore = false,
        // 玩家初始血量基线（P18 玩法数据外置）：gameplay 表 player.maxHp 驱动，auth 挂载时经
        // initVitals 一次性注入；缺省 100 = 逐字节等价。
        // The player's initial vitals baseline (the P18 gameplay-data externalization): driven by the
        // gameplay table's player.maxHp and injected once via initVitals at auth mount; the default 100
        // stays byte-for-byte equivalent.
        private readonly int $playerMaxHp = 100,
    ) {
        parent::__construct($server, $serializer, $world, $registry);
        $this->inventories = $inventories;
        $this->random = $random ?? new SystemRandomSource();
        $this->mmorpg = $mmorpg;
    }

    // ── RealtimeServer 钩子 ──

    /**
     * 注册表完整 meta（register 与 heartbeat 共用）：心跳携带同一份完整 meta 是「Redis 数据丢失后
     * 免重启自愈」的前提——只有 playerCount 的心跳合并在服务 hash 被清空后会产出缺 mapId/wsAddress
     * 的残缺 meta，selectChannel 将永久拒绝该频道。
     * The full registry meta (shared by register and heartbeat): heartbeats carrying the same complete meta
     * are the precondition for restart-free self-healing after a Redis data loss — a playerCount-only
     * heartbeat merge over an emptied service hash yields a crippled meta that selectChannel rejects forever.
     *
     * @return array<string, int|string>
     */
    private function registryMeta(): array
    {
        $meta = [
            'mapId' => $this->mapId,
            'channelId' => $this->channelId(),
            'playerCount' => $this->playerCount,
            'wsAddress' => $this->wsAddress,
            'status' => 'serving',
        ];
        // 容量上限（P16 准入）：>0 时写入 meta——gateway selectChannel 据此跳过满员实例，
        // auth 侧另有硬守卫（并发窗口下 selectChannel 与 auth 之间仍可能超员）
        // The capacity cap (the P16 admission): written when >0 — the gateway's selectChannel skips full
        // instances, and the auth side keeps a hard guard (the concurrent window can still overshoot).
        if ($this->maxCapacity > 0) {
            $meta['maxCapacity'] = $this->maxCapacity;
        }

        return $meta;
    }

    /**
     * worker 启动：① 集群注册（meta 携带 mapId/channelId/playerCount/wsAddress/status）② 5s 心跳（完整 meta 含 playerCount）
     * ③ 50ms 世界 tick（时钟推进 + world 更新 + 经调度分区投递帧末 flush）④ 归档 30s 兜底 ⑤ 视野快照周期重同步。
     * Worker start: ① cluster registration (meta carries mapId/channelId/playerCount/wsAddress/status) ② 5s heartbeat
     * (full meta incl. playerCount) ③ 50ms world tick (clock advance + world update + frame-end flush submitted via the scheduler
     * region) ④ the archive 30s backstop ⑤ periodic vision-snapshot resync.
     */
    protected function onStart(): void
    {
        $serviceRegistry = $this->serviceRegistry;
        if ($serviceRegistry !== null) {
            $serviceRegistry->register(self::SERVICE_TYPE_MAP, $this->serviceId, $this->registryMeta());
        }

        $timer = $this->timer;
        if ($timer !== null && $serviceRegistry !== null) {
            $timer->add(self::HEARTBEAT_INTERVAL_SECONDS, function () use ($serviceRegistry): void {
                // 异常边界（故障演练 redis-down 场景实抓）：心跳是常驻定时器，Redis 短暂不可用时
                // heartbeat 抛出的异常若不就地消化会打死定时器——注册表从此不再回填，Redis 恢复后
                // 该实例永久 503。任何常驻周期任务的回调都必须自带兜底（与 PerfSampler/ArchivePipeline
                // 的「采样失败只记日志」同口径）。
                // The exception boundary (caught live by the fault-drill's redis-down scenario): the heartbeat is
                // a recurring timer — an exception thrown while Redis blips would kill the timer outright, so the
                // registry never refills and the instance stays permanently 503 even after Redis recovers. Every
                // recurring callback must carry its own fallback (same convention as PerfSampler/ArchivePipeline's
                // "log only, never throw").
                try {
                    // 心跳携带完整注册 meta（而非仅 playerCount）——heartbeat 的 Lua 合并对「未提及字段保留」，
                    // 但 Redis 数据丢失（无持久化重启）后服务 hash 为空，仅 playerCount 的合并会产出残缺 meta
                    // （缺 mapId/wsAddress/status），selectChannel 过滤链将永久拒绝该频道直到 worker 重启。
                    // 携带完整 meta 后，首个心跳（≤5s）即可无损重建注册条目——「Redis 恢复后免重启自愈」
                    // 契约（ADR-028）由此兑现。故障演练 redis-down 场景实抓。
                    // The heartbeat carries the FULL registration meta (not just playerCount) — the heartbeat Lua
                    // merge keeps untouched fields, but after a Redis data loss (a no-persistence restart) the
                    // service hash is empty and a playerCount-only merge produces a crippled meta (no mapId /
                    // wsAddress / status) that selectChannel's filter chain rejects until a worker restart. With
                    // the full meta, the first heartbeat (≤5s) rebuilds the registry entry losslessly — this is
                    // how the "self-heal without restart after Redis recovery" contract (ADR-028) is honored.
                    // Caught live by the fault-drill's redis-down scenario.
                    $meta = $this->registryMeta();
                    // 房间指标（P9c 准入）：provider 注入时汇入 rooms/deferred 等——registry 消费方据此路由
                    // The room metrics (the P9c admission): merged in when a provider is injected — registry
                    // consumers route on these.
                    if ($this->roomMetricsProvider !== null) {
                        $meta = array_merge($meta, ($this->roomMetricsProvider)());
                    }
                    $serviceRegistry->heartbeat(self::SERVICE_TYPE_MAP, $this->serviceId, $meta);
                } catch (\Throwable $e) {
                    error_log(sprintf('[MapServer] heartbeat failed (will retry next tick): %s', $e->getMessage()));
                }
            }, true);
        }

        $clock = $this->clock;
        if ($timer !== null && $clock !== null) {
            $timer->add(self::TICK_INTERVAL_SECONDS, function () use ($clock): void {
                // tick 时序：world->update() 内部 runFrame 执行「上一帧」投递的 flush 任务（广播延迟至多 1 帧），
                // 之后投递「本帧」flush 任务，待下一帧 runFrame 执行
                // Tick ordering: runFrame inside world->update() runs the flush task submitted by the previous frame (broadcast latency at most one frame),
                // then this frame's flush task is submitted and run by the next frame's runFrame
                $clock->tick();
                $this->tickCounter++;
                $this->world->update();
                $this->tickMmorpg(self::TICK_INTERVAL_SECONDS);

                $scheduler = $this->world->getScheduler();
                if ($this->flushRegion !== null) {
                    $scheduler->addTaskToRegion($this->flushRegion, $this->flushOutbox(...));
                } else {
                    $scheduler->addTask($this->flushOutbox(...));
                }
            }, true);
        }

        if ($timer !== null && $this->archive !== null) {
            $timer->add(ArchivePipeline::FLUSH_INTERVAL_SECONDS, $this->archive->periodicFlush(...), true);
        }

        if ($timer !== null && $this->snapshotResyncIntervalSeconds !== null) {
            $timer->add($this->snapshotResyncIntervalSeconds, $this->resyncVision(...), true);
        }
    }

    /** worker 退出：注销集群实例 + 归档残留脏记录兜底。 Worker stop: cluster unregistration + archive dirty-flush backstop. */
    protected function onStop(): void
    {
        if ($this->serviceRegistry !== null) {
            $this->serviceRegistry->unregister(self::SERVICE_TYPE_MAP, $this->serviceId);
        }

        $this->archive?->flush();
    }

    /** 已认证路由：move/attack/skill:cast/pickup/logout + room:*（装配了房间中枢时），其余 404。 Authenticated routing: move/attack/skill:cast/pickup/logout plus room:* (when a room hub is assembled), anything else 404. */
    protected function handleAuthenticated(ConnectionInterface $conn, Message $message): void
    {
        // room:* 前置分发：entityId 经 registry 解析后连同伴发 connId 交由房间中枢处理（未装配中枢时落入下方 404）
        // room:* pre-dispatch: entityId resolved via the registry and handed to the room hub together with the connId (falls through to the 404 below when no hub is assembled)
        if ($this->rooms !== null && str_starts_with($message->type, 'room:')) {
            $entityId = $this->registry->getEntityId($conn->getId());
            if ($entityId === null) {
                $this->send($conn, Message::create('error', ['code' => 401, 'message' => 'unauthorized'], $message->requestId));
                $conn->close();

                return;
            }
            $this->rooms->handle($conn->getId(), $entityId, $message->type, $message->payload);

            return;
        }

        switch ($message->type) {
            case 'move':
                $this->handleMoveRouted($conn, $message);

                return;
            case 'attack':
                $this->handleAttack($conn, $message);

                return;
            case 'skill:cast':
                $this->handleSkillCast($conn, $message);

                return;
            case 'skill:cast_aoe':
                $this->handleSkillCastAoE($conn, $message);

                return;
            case 'pickup':
                $this->handlePickup($conn, $message);

                return;
            case 'logout':
                $this->handleLogout($conn);

                return;
            case 'player:revive':
                $this->handlePlayerRevive($conn, $message);

                return;
            case 'gm:exec':
                $this->handleGmExec($conn, $message);

                return;
            case 'equip':
            case 'unequip':
            case 'auction:sell':
            case 'auction:buy':
            case 'auction:cancel':
            case 'mail:list':
            case 'mail:claim':
            case 'mail:delete':
            case 'economy:deposit':
                $this->handleEconomyRouted($conn, $message);

                return;
            case 'buff:apply':
            case 'buff:remove':
            case 'matching:enqueue':
            case 'matching:cancel':
            case 'quest:list':
            case 'quest:claim':
            case 'quest:talk':
                $this->handleGameplayRouted($conn, $message);

                return;
        }

        $this->unknownType($conn, $message);
    }

    /**
     * GM 命令执行路由（R3 最小内核）：解析发起者 uid → 总线分发（未知命令/权限拒绝/执行异常都在总线内
     * 转结构化结果）→ gm:result 定向回执（携带 requestId）。未装配总线时按未知类型 404。
     * The gm:exec route (the R3 minimal kernel): resolves the issuer uid → bus dispatch (unknown command /
     * permission denial / execution exceptions all become structured results inside the bus) → a directed
     * gm:result receipt (carrying the requestId). Without an assembled bus it falls through to the unknown-type 404.
     */
    private function handleGmExec(ConnectionInterface $conn, Message $message): void
    {
        if ($this->gm === null) {
            $this->unknownType($conn, $message);

            return;
        }

        $entityId = $this->registry->getEntityId($conn->getId());
        $actor = $entityId !== null ? ($this->actors[$entityId] ?? null) : null;
        $uid = $actor instanceof PlayerActor ? $actor->uid() : null;
        if ($uid === null) {
            $this->send($conn, Message::create('error', ['code' => 401, 'message' => 'unauthorized'], $message->requestId));
            $conn->close();

            return;
        }

        $commandName = $message->payload['command'] ?? null;
        if (!is_string($commandName) || $commandName === '') {
            $this->send($conn, Message::create('error', ['code' => 400, 'message' => 'payload 缺少 command 字段'], $message->requestId));

            return;
        }

        $result = $this->gm->dispatch($uid, $commandName, $message->payload);
        $this->send($conn, Message::create('gm:result', ['code' => $result->status, 'message' => $result->message], $message->requestId));
    }

    // ── 经济批路由（R3 starter-kit 接线） ──
    // Economy-batch routing (the R3 starter-kit wiring)

    /**
     * 已认证玩家上下文解析（经济批与玩法批路由共用）：发起者 entityId/PlayerActor/uid/背包/装备栏五元组。
     * 任一失败定向 401 并断连返回 null。
     * The authenticated-player context resolution (shared by the economy- and gameplay-route preludes): the issuer's
     * entityId/PlayerActor/uid/bag/equipment quintuple. Any failure sends a directed 401, closes and returns null.
     *
     * @return array{0: string, 1: PlayerActor, 2: string, 3: Inventory, 4: Equipment}|null 校验通过的五元组，失败为 null The validated quintuple, or null on failure.
     */
    private function resolvePlayerContext(ConnectionInterface $conn, Message $message): ?array
    {
        $entityId = $this->registry->getEntityId($conn->getId());
        $actor = $entityId !== null ? ($this->actors[$entityId] ?? null) : null;
        if ($entityId === null || !$actor instanceof PlayerActor) {
            $this->send($conn, Message::create('error', ['code' => 401, 'message' => 'unauthorized'], $message->requestId));
            $conn->close();

            return null;
        }

        $uid = $actor->uid();
        if ($uid === null) {
            $this->send($conn, Message::create('error', ['code' => 401, 'message' => 'unauthorized'], $message->requestId));
            $conn->close();

            return null;
        }

        return [$entityId, $actor, $uid, $this->inventories[$entityId] ??= new Inventory(), $this->equipments[$entityId] ??= new Equipment()];
    }

    /**
     * 经济批路由公共前置：服务组已装配 + 已认证玩家上下文。
     * 任一失败定向错误回执并返回 null；未装配服务组按未知类型 404。
     * The shared economy-route prelude: the service group is assembled plus an authenticated-player context.
     * Any failure sends a directed error receipt and returns null; without the assembled group it falls through to
     * the unknown-type 404.
     *
     * @return array{0: string, 1: PlayerActor, 2: string, 3: Inventory, 4: Equipment}|null 校验通过的五元组，失败为 null The validated quintuple, or null on failure.
     */
    private function resolveEconomyActor(ConnectionInterface $conn, Message $message): ?array
    {
        if ($this->mail === null || $this->auction === null || $this->ledger === null || $this->economyItems === null) {
            $this->unknownType($conn, $message);

            return null;
        }

        return $this->resolvePlayerContext($conn, $message);
    }

    /**
     * 经济批请求分发：equip/unequip/auction:sell/auction:buy/auction:cancel/mail:list/mail:claim/mail:delete/
     * economy:deposit。业务异常（参数非法/货不足等 InvalidArgumentException）转 economy:result 错误回执，
     * 连接不断（比照 RoomHub 的状态机异常口径）。
     * Economy request dispatch: equip / unequip / auction:sell / auction:buy / auction:cancel / mail:list /
     * mail:claim / mail:delete / economy:deposit. Business exceptions (illegal arguments, insufficient goods etc.
     * as InvalidArgumentException) become economy:result error receipts with the connection kept open (mirroring
     * RoomHub's state-machine exception convention).
     */
    private function handleEconomyRouted(ConnectionInterface $conn, Message $message): void
    {
        try {
            match ($message->type) {
                'equip' => $this->handleEquip($conn, $message),
                'unequip' => $this->handleUnequip($conn, $message),
                'auction:sell' => $this->handleAuctionSell($conn, $message),
                'auction:buy' => $this->handleAuctionBuy($conn, $message),
                'auction:cancel' => $this->handleAuctionCancel($conn, $message),
                'mail:list' => $this->handleMailList($conn, $message),
                'mail:claim' => $this->handleMailClaim($conn, $message),
                'mail:delete' => $this->handleMailDelete($conn, $message),
                'economy:deposit' => $this->handleEconomyDeposit($conn, $message),
                default => throw new \LogicException(sprintf('未知经济路由: %s', $message->type)),
            };
        } catch (\InvalidArgumentException $e) {
            [$entityId] = $this->resolveEconomyActorSilently($conn);
            if ($entityId !== null) {
                $this->sendToEntity($entityId, 'economy:result', [
                    'op' => $message->type,
                    'code' => 'invalid_argument',
                    'message' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * 异常回执路径的宽松解析（不触发 404/断连，仅取 entityId 供定向回执；缺失返回 [null, null]）。
     * The lenient resolution for exception-receipt paths (never 404/disconnects, only fetches entityId for a
     * directed receipt; returns [null, null] when missing).
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function resolveEconomyActorSilently(ConnectionInterface $conn): array
    {
        $entityId = $this->registry->getEntityId($conn->getId());
        $actor = $entityId !== null ? ($this->actors[$entityId] ?? null) : null;
        $uid = $actor instanceof PlayerActor ? $actor->uid() : null;

        return [$entityId, $uid];
    }

    /**
     * 穿戴路由：itemId 经物品注册表校验为 equipment 型 → 背包扣货 → 装备栏穿戴（同槽顶替回包）→
     * player:stats 属性同步（maxHp 聚合变化）→ 背包标脏落库 → economy:result ok。
     * Equip route: itemId validated as equipment-typed via the item repository → bag debit → equip into the slot
     * (a displaced item returns to the bag) → player:stats stat sync (the aggregated maxHp change) → bag marked
     * dirty for persistence → economy:result ok.
     */
    private function handleEquip(ConnectionInterface $conn, Message $message): void
    {
        [$entityId, $player, $uid, $inventory, $equipment] = $this->requireEconomyContext($conn, $message);

        $itemId = $message->payload['itemId'] ?? null;
        if (!is_string($itemId) || $itemId === '') {
            throw new \InvalidArgumentException('payload 缺少 itemId 字段');
        }

        $definition = $this->economyItems?->get($itemId);
        if ($definition === null) {
            throw new \InvalidArgumentException(sprintf('物品不存在或未注册: %s', $itemId));
        }
        if ($definition->type !== ItemDefinition::TYPE_EQUIPMENT) {
            throw new \InvalidArgumentException(sprintf('物品 %s 非 equipment 型，不可穿戴', $itemId));
        }
        if ($inventory->count($itemId) < 1) {
            throw new \InvalidArgumentException(sprintf('背包中不存在物品: %s', $itemId));
        }

        $displaced = $equipment->equip($definition);
        $inventory->remove($itemId, 1);
        if ($displaced !== null) {
            $inventory->add($displaced, 1);
        }
        $player->clampHpToMax();
        $this->broadcastStats($entityId, $player);
        $this->markInventoryDirty($uid, $inventory);

        $this->replyEconomy($entityId, 'equip', 'ok', sprintf('%s 已穿戴', $itemId));
    }

    /**
     * 卸下路由：槽位校验 → 装备栏卸下 → 物品回包 → player:stats 属性同步 → 背包标脏 → economy:result ok。
     * Unequip route: slot validation → unequip from the slot → the item returns to the bag → player:stats sync →
     * bag marked dirty → economy:result ok.
     */
    private function handleUnequip(ConnectionInterface $conn, Message $message): void
    {
        [$entityId, $player, $uid, $inventory, $equipment] = $this->requireEconomyContext($conn, $message);

        $slot = $message->payload['slot'] ?? null;
        if (!is_string($slot) || $slot === '') {
            throw new \InvalidArgumentException('payload 缺少 slot 字段');
        }

        $unequipped = $equipment->unequip($slot);
        if ($unequipped === null) {
            throw new \InvalidArgumentException(sprintf('槽位 %s 为空', $slot));
        }

        $inventory->add($unequipped, 1);
        $player->clampHpToMax();
        $this->broadcastStats($entityId, $player);
        $this->markInventoryDirty($uid, $inventory);

        $this->replyEconomy($entityId, 'unequip', 'ok', sprintf('%s 已卸下', $unequipped));
    }

    /**
     * 挂单路由：{itemId, count, price} → AuctionService::sell（扣货托管）→ economy:result ok 附 auctionId。
     * Sell route: {itemId, count, price} → AuctionService::sell (escrow by debiting the bag) → economy:result ok carrying auctionId.
     */
    private function handleAuctionSell(ConnectionInterface $conn, Message $message): void
    {
        [$entityId, $player, $uid, $inventory, $equipment] = $this->requireEconomyContext($conn, $message);

        [$itemId, $count, $price] = $this->requireSellPayload($message);

        $auctionId = $this->auction?->sell($uid, $inventory, $itemId, $count, $price) ?? '';
        $this->markInventoryDirty($uid, $inventory);
        $this->sendToEntity($entityId, 'economy:result', [
            'op' => 'auction:sell',
            'code' => 'ok',
            'message' => sprintf('%s x%d 挂单成功', $itemId, $count),
            'auctionId' => $auctionId,
        ]);
    }

    /**
     * 购买路由：{auctionId, price} → AuctionService::buy（Lua 原子结算+发货邮件）→ economy:result
     * （失败 code 原样透传：no_listing/self_purchase/price_mismatch/insufficient_balance）。
     * Buy route: {auctionId, price} → AuctionService::buy (atomic Lua settlement + delivery mail) → economy:result
     * (failure codes pass through: no_listing/self_purchase/price_mismatch/insufficient_balance).
     */
    private function handleAuctionBuy(ConnectionInterface $conn, Message $message): void
    {
        [$entityId, $player, $uid, $inventory, $equipment] = $this->requireEconomyContext($conn, $message);

        $auctionId = $message->payload['auctionId'] ?? null;
        $price = $message->payload['price'] ?? null;
        if (!is_string($auctionId) || $auctionId === '') {
            throw new \InvalidArgumentException('payload 缺少 auctionId 字段');
        }
        if (!is_int($price) || $price <= 0) {
            throw new \InvalidArgumentException('payload 缺少正整数 price 字段');
        }

        $result = $this->auction?->buy($uid, $auctionId, $price) ?? ['ok' => false, 'code' => 'not_ready'];
        $this->replyEconomy($entityId, 'auction:buy', $result['ok'] ? 'ok' : $result['code'], $result['ok'] ? '购买成功，货物经邮件发放' : '购买失败');
    }

    /**
     * 撤单路由：{auctionId} → AuctionService::cancel（归属校验+退回邮件）→ economy:result ok。
     * Cancel route: {auctionId} → AuctionService::cancel (ownership check + return mail) → economy:result ok.
     */
    private function handleAuctionCancel(ConnectionInterface $conn, Message $message): void
    {
        [$entityId, $player, $uid, $inventory, $equipment] = $this->requireEconomyContext($conn, $message);

        $auctionId = $message->payload['auctionId'] ?? null;
        if (!is_string($auctionId) || $auctionId === '') {
            throw new \InvalidArgumentException('payload 缺少 auctionId 字段');
        }

        $cancelled = $this->auction?->cancel($uid, $auctionId) ?? false;
        $this->replyEconomy($entityId, 'auction:cancel', $cancelled ? 'ok' : 'not_found', $cancelled ? '撤单成功，货物经邮件退回' : '挂单不存在或非本人挂单');
    }

    /**
     * 收件箱列表路由：MailService::list → mail:list 并行标量列表帧（V7：批量帧并行等长标量列表，
     * 附件明细不进列表帧——领取时经 mail:claimed 的 msgpack attachments 字段下发）。
     * Mailbox-list route: MailService::list → a mail:list parallel-scalar-list frame (V7: batch frames use parallel
     * equal-length scalar lists; attachment details never enter list frames — they ride mail:claimed's msgpack
     * attachments field on claim).
     */
    private function handleMailList(ConnectionInterface $conn, Message $message): void
    {
        [$entityId, $player, $uid, $inventory, $equipment] = $this->requireEconomyContext($conn, $message);

        $mails = $this->mail?->list($uid) ?? [];
        $this->sendToEntity($entityId, 'mail:list', [
            'mailIds' => array_column($mails, 'mailId'),
            'titles' => array_column($mails, 'title'),
            'bodies' => array_column($mails, 'body'),
            'hasAttachments' => array_map(static fn (array $m): bool => $m['attachments'] !== [], $mails),
        ]);
    }

    /**
     * 附件领取路由：{mailId} → MailService::claimAttachments（幂等三态）→ claimed 时附件入包 +
     * mail:claimed 帧（attachments 为 msgpack 字节串，V7 嵌套路径）；already_claimed/not_found 转
     * economy:result 回执。
     * Attachment-claim route: {mailId} → MailService::claimAttachments (the idempotent tri-state) → on claimed the
     * attachments enter the bag plus a mail:claimed frame (attachments as a msgpack byte string, the V7 nested path);
     * already_claimed/not_found become economy:result receipts.
     */
    private function handleMailClaim(ConnectionInterface $conn, Message $message): void
    {
        [$entityId, $player, $uid, $inventory, $equipment] = $this->requireEconomyContext($conn, $message);

        $mailId = $message->payload['mailId'] ?? null;
        if (!is_string($mailId) || $mailId === '') {
            throw new \InvalidArgumentException('payload 缺少 mailId 字段');
        }

        $result = $this->mail?->claimAttachments($uid, $mailId) ?? ['status' => 'not_found', 'attachments' => []];

        if ($result['status'] !== 'claimed') {
            $this->replyEconomy($entityId, 'mail:claim', $result['status'], $result['status'] === 'already_claimed' ? '附件已领取过' : '邮件不存在');

            return;
        }

        foreach ($result['attachments'] as $attachment) {
            $inventory->add($attachment['itemId'], $attachment['count']);
        }
        $this->markInventoryDirty($uid, $inventory);

        // V7：嵌套附件负载走 MsgpackSerializer 路径（msgpack 字节串作为 STRING 字段承载）
        // V7: the nested attachment payload rides the MsgpackSerializer path (a msgpack byte string carried as a STRING field)
        $attachmentsBytes = $this->msgpack?->pack($result['attachments']) ?? '';
        $this->sendToEntity($entityId, 'mail:claimed', [
            'mailId' => $mailId,
            'attachments' => $attachmentsBytes,
        ]);
    }

    /**
     * 删除邮件路由：{mailId} → MailService::delete → economy:result ok/not_found。
     * Mail-deletion route: {mailId} → MailService::delete → economy:result ok/not_found.
     */
    private function handleMailDelete(ConnectionInterface $conn, Message $message): void
    {
        [$entityId, $player, $uid, $inventory, $equipment] = $this->requireEconomyContext($conn, $message);

        $mailId = $message->payload['mailId'] ?? null;
        if (!is_string($mailId) || $mailId === '') {
            throw new \InvalidArgumentException('payload 缺少 mailId 字段');
        }

        $deleted = $this->mail?->delete($uid, $mailId) ?? false;
        $this->replyEconomy($entityId, 'mail:delete', $deleted ? 'ok' : 'not_found', $deleted ? '邮件已删除' : '邮件不存在');
    }

    /**
     * 入账路由（demo 规模的最小入账入口）：{count} → CurrencyLedger::deposit → economy:result ok。
     * 生产语义应由掉落/任务结算驱动入账；本路由供 E2E 验收与运营补发使用。
     * Deposit route (the demo-scale minimal crediting entry): {count} → CurrencyLedger::deposit → economy:result ok.
     * Production semantics would credit via drop/quest settlement; this route serves E2E acceptance and ops grants.
     */
    private function handleEconomyDeposit(ConnectionInterface $conn, Message $message): void
    {
        [$entityId, $player, $uid, $inventory, $equipment] = $this->requireEconomyContext($conn, $message);

        $count = $message->payload['count'] ?? null;
        if (!is_int($count) || $count <= 0) {
            throw new \InvalidArgumentException('payload 缺少正整数 count 字段');
        }

        $this->ledger?->deposit($uid, $count);
        $this->replyEconomy($entityId, 'economy:deposit', 'ok', sprintf('入账 %d', $count));
    }

    /**
     * 经济路由上下文强解析：resolveEconomyActor 失败即抛 InvalidArgumentException（由 handleEconomyRouted
     * 统一转 economy:result 回执）；返回 [entityId, PlayerActor, uid, Inventory, Equipment] 五元组。
     * The strict economy-route context resolution: a resolveEconomyActor failure throws InvalidArgumentException
     * (uniformly turned into an economy:result receipt by handleEconomyRouted); returns the
     * [entityId, PlayerActor, uid, Inventory, Equipment] quintuple.
     *
     * @return array{0: string, 1: PlayerActor, 2: string, 3: Inventory, 4: Equipment}
     */
    private function requireEconomyContext(ConnectionInterface $conn, Message $message): array
    {
        $resolved = $this->resolveEconomyActor($conn, $message);
        if ($resolved === null) {
            throw new \InvalidArgumentException('经济服务未就绪或连接未认证');
        }

        return $resolved;
    }

    /**
     * 挂单负载解析：{itemId, count, price} 三字段类型校验。
     * Sells-payload resolution: type checks over the {itemId, count, price} triple.
     *
     * @return array{0: string, 1: int, 2: int}
     */
    private function requireSellPayload(Message $message): array
    {
        $itemId = $message->payload['itemId'] ?? null;
        $count = $message->payload['count'] ?? null;
        $price = $message->payload['price'] ?? null;
        if (!is_string($itemId) || $itemId === '') {
            throw new \InvalidArgumentException('payload 缺少 itemId 字段');
        }
        if (!is_int($count) || $count <= 0) {
            throw new \InvalidArgumentException('payload 缺少正整数 count 字段');
        }
        if (!is_int($price) || $price <= 0) {
            throw new \InvalidArgumentException('payload 缺少正整数 price 字段');
        }

        return [$itemId, $count, $price];
    }

    /**
     * economy:result 统一回执（op/code/message 标量三元组）。
     * The uniform economy:result receipt (the op/code/message scalar triple).
     */
    private function replyEconomy(string $entityId, string $op, string $code, string $detail): void
    {
        $this->sendToEntity($entityId, 'economy:result', ['op' => $op, 'code' => $code, 'message' => $detail]);
    }

    /**
     * player:stats 定向属性同步（装备变更后的 maxHp/hp 口径刷新）。
     * Directed player:stats sync (refreshes the maxHp/hp contract after equipment changes).
     */
    private function broadcastStats(string $entityId, PlayerActor $player): void
    {
        $this->sendToEntity($entityId, 'player:stats', [
            'id' => $entityId,
            'hp' => $player->hp(),
            'maxHp' => $player->maxHp(),
        ]);
    }

    /**
     * 背包标脏落库（比照 handlePickup 的归档口径；uid 缺失跳过）。
     * Marks the bag dirty for persistence (mirroring handlePickup's archive convention; skipped without a uid).
     */
    private function markInventoryDirty(string $uid, Inventory $inventory): void
    {
        if ($this->archive !== null) {
            $this->archive->markDirty($uid, ['inventory' => $inventory->all()]);
        }
    }

    /**
     * 新邮件在线通知实现（MailNotifierInterface）：按 uid 匹配在线 PlayerActor 定向入队 mail:new 帧；
     * 离线静默（邮件已持久化，登录后可拉取）。
     * The new-mail online-notification implementation (MailNotifierInterface): matches online PlayerActors by uid
     * and enqueues directed mail:new frames; offline stays silent (mail is persisted and pullable after login).
     */
    public function notifyNewMail(string $uid, string $mailId): void
    {
        foreach ($this->actors as $actor) {
            if ($actor instanceof PlayerActor && $actor->uid() === $uid) {
                $this->sendToEntity($actor->getPlayerId(), 'mail:new', ['mailId' => $mailId]);
            }
        }
    }

    // ── 玩法批路由（R3 starter-kit 接线） ──
    // Gameplay-batch routing (the R3 starter-kit wiring)

    /**
     * 回填玩法批服务组（R3 starter-kit 接线，NYTHROS_GAMEPLAY=1 装配）：Buff 服务/技能冷却表/匹配服务/
     * 任务服务一并注入；$matching 允许 null（房间装配未启用时匹配不可用，matching:* 路由回执 unavailable）。
     * 缺省全 null = buff:* / matching:* / quest:* 路由按未知类型 404，技能冷却表不启用。
     * Back-fills the gameplay-batch service group (the R3 starter-kit wiring, assembled with NYTHROS_GAMEPLAY=1):
     * the buff service / skill-cooldown table / matching service / quest service are injected together; $matching may
     * be null (without room assembly matching is unavailable and the matching:* routes answer unavailable). All-null
     * default = the buff:* / matching:* / quest:* routes fall through to the unknown-type 404 and the cooldown table stays off.
     */
    public function attachGameplay(BuffService $buffs, SkillCooldownTable $cooldowns, ?MatchingService $matching, QuestService $quests): void
    {
        $this->buffs = $buffs;
        $this->cooldowns = $cooldowns;
        $this->matching = $matching;
        $this->quests = $quests;
    }

    /**
     * 匹配撮合扫描（组装层定时器周期调用 + enqueue 后立即调用）：开房记录逐成员定向投递
     * matching:matched{roomId, memberIds}。未装配匹配服务时静默。
     * The matching sweep (invoked periodically by the assembly layer's timer and right after an enqueue): delivers
     * each built room to its members as directed matching:matched{roomId, memberIds} frames. Silent without an
     * assembled matching service.
     */
    public function sweepMatching(): void
    {
        $matching = $this->matching;
        if ($matching === null) {
            return;
        }

        try {
            $builtRooms = $matching->tick(microtime(true));
        } catch (\Throwable $e) {
            error_log(sprintf('[MapServer] sweepMatching tick EXCEPTION: %s: %s', get_class($e), $e->getMessage()));

            return;
        }
        foreach ($builtRooms as $built) {
            foreach ($built['entityIds'] as $entityId) {
                $this->sendToEntity($entityId, 'matching:matched', [
                    'roomId' => $built['roomId'],
                    'memberIds' => $built['uids'],
                ]);
            }
        }
    }

    /**
     * 玩法批请求分发：buff:apply/buff:remove/matching:enqueue/matching:cancel/quest:list/quest:claim/quest:talk。
     * 业务异常（参数非法等 InvalidArgumentException）转定向 quest:result 错误回执，连接不断
     * （比照经济批路由口径）；未装配服务组按未知类型 404。
     * 路由级可用性守卫（P2 收口）：原「全组非空才放行」把 quest:* 与房间依赖的 matching 绑死——
     * GAMEPLAY 单独开启（无房间装配）时任务路由 404；现按路由组各自判空（buff 组依赖 BuffService、
     * matching 组依赖 MatchingService、quest 组依赖 QuestService，互不阻塞）。
     * Gameplay request dispatch: buff:apply / buff:remove / matching:enqueue / matching:cancel / quest:list /
     * quest:claim / quest:talk. Business exceptions (illegal arguments etc. as InvalidArgumentException) become
     * directed quest:result error receipts with the connection kept open (mirroring the economy-route convention);
     * without the assembled service group it falls through to the unknown-type 404.
     * Per-route availability guard (the P2 close-out): the old "every group non-null" gate tied quest:* to the
     * room-dependent matching service — with GAMEPLAY alone (no room assembly) quest routes were dead 404s; the
     * guard now checks each route group on its own (buff routes need BuffService, matching routes need
     * MatchingService, quest routes need QuestService; no cross-blocking).
     */
    private function handleGameplayRouted(ConnectionInterface $conn, Message $message): void
    {
        $available = match ($message->type) {
            'buff:apply', 'buff:remove' => $this->buffs !== null,
            'matching:enqueue', 'matching:cancel' => $this->matching !== null,
            'quest:list', 'quest:claim', 'quest:talk' => $this->quests !== null,
            default => false,
        };
        if (!$available) {
            $this->unknownType($conn, $message);

            return;
        }

        try {
            match ($message->type) {
                'buff:apply' => $this->handleBuffApply($conn, $message),
                'buff:remove' => $this->handleBuffRemove($conn, $message),
                'matching:enqueue' => $this->handleMatchingEnqueue($conn, $message),
                'matching:cancel' => $this->handleMatchingCancel($conn, $message),
                'quest:list' => $this->handleQuestList($conn, $message),
                'quest:claim' => $this->handleQuestClaim($conn, $message),
                'quest:talk' => $this->handleQuestTalk($conn, $message),
                default => throw new \LogicException(sprintf('未知玩法路由: %s', $message->type)),
            };
        } catch (\InvalidArgumentException $e) {
            [$entityId] = $this->resolveEconomyActorSilently($conn);
            if ($entityId !== null) {
                $this->sendToEntity($entityId, 'quest:result', [
                    'op' => $message->type,
                    'code' => 'invalid_argument',
                    'message' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Buff 施加路由：{buffId, targetId} → 目标解析为在线玩家 Actor → BuffService::apply
     * （叠加裁决与 buff:applied 广播在服务内闭环）。目标非玩家/不在线抛 InvalidArgumentException。
     * The buff-apply route: {buffId, targetId} → the target resolves to an online player actor →
     * BuffService::apply (stacking adjudication and the buff:applied broadcast close inside the service).
     * A non-player or offline target throws InvalidArgumentException.
     */
    private function handleBuffApply(ConnectionInterface $conn, Message $message): void
    {
        $this->requireGameplayContext($conn, $message);

        $buffId = $message->payload['buffId'] ?? null;
        $targetId = $message->payload['targetId'] ?? null;
        if (!is_string($buffId) || $buffId === '') {
            throw new \InvalidArgumentException('payload 缺少 buffId 字段');
        }
        if (!is_string($targetId) || $targetId === '') {
            throw new \InvalidArgumentException('payload 缺少 targetId 字段');
        }

        // 宿主约束：BuffService 的属性修正挂载在 BasePlayer 上，怪物宿主留待后续批次。
        // Host constraint: BuffService mounts attribute modifiers on BasePlayer; monster hosts belong to a later batch.
        $target = $this->getActor($targetId);
        if (!$target instanceof BasePlayer) {
            throw new \InvalidArgumentException(sprintf('目标不存在或非玩家: %s', $targetId));
        }

        $buffs = $this->buffs;
        if ($buffs !== null) {
            $buffs->apply($targetId, $target, $buffId, microtime(true));
        }
    }

    /**
     * Buff 移除路由（主动驱散）：{buffId} → 对发起者本人驱散。
     * The buff-remove route (active dispel): {buffId} dispels from the issuer.
     */
    private function handleBuffRemove(ConnectionInterface $conn, Message $message): void
    {
        [$entityId, $player] = $this->requireGameplayContext($conn, $message);

        $buffId = $message->payload['buffId'] ?? null;
        if (!is_string($buffId) || $buffId === '') {
            throw new \InvalidArgumentException('payload 缺少 buffId 字段');
        }

        $buffs = $this->buffs;
        if ($buffs !== null) {
            $buffs->remove($entityId, $player, $buffId);
        }
    }

    /**
     * 匹配入队路由：{queueId, level} → MatchingService::enqueue（准入校验）→ 成功即回执并立即撮合扫描
     * （低延迟开房；周期兜底扫描由组装层定时器驱动）。失败转 matching:ok{code=rejected}。
     * The matching-enqueue route: {queueId, level} → MatchingService::enqueue (admission validated) → on success a
     * receipt plus an immediate matching sweep (low-latency room building; the periodic backstop sweep rides the
     * assembly layer's timer). Failures become matching:ok{code=rejected}.
     */
    private function handleMatchingEnqueue(ConnectionInterface $conn, Message $message): void
    {
        [$entityId, , $uid] = $this->requireGameplayContext($conn, $message);

        $matching = $this->matching;
        if ($matching === null) {
            // 房间装配未启用：匹配不可用回执（连接不断）。
            // Room assembly off: an unavailable receipt (connection kept).
            $this->sendToEntity($entityId, 'matching:ok', ['op' => 'enqueue', 'code' => 'unavailable', 'message' => '匹配服务未启用']);

            return;
        }

        $queueId = $message->payload['queueId'] ?? null;
        $level = $message->payload['level'] ?? null;
        if (!is_string($queueId) || $queueId === '') {
            throw new \InvalidArgumentException('payload 缺少 queueId 字段');
        }
        if (!is_int($level)) {
            throw new \InvalidArgumentException('payload 缺少整数 level 字段');
        }

        $enqueued = $matching->enqueue($queueId, $uid, $entityId, $level, microtime(true));
        $this->sendToEntity($entityId, 'matching:ok', [
            'op' => 'enqueue',
            'code' => $enqueued ? 'ok' : 'rejected',
            'message' => $enqueued ? '已进入匹配队列' : '无法入队（条件不符或已在队列）',
        ]);

        if ($enqueued) {
            $this->sweepMatching();
        }
    }

    /**
     * 匹配取消路由：MatchingService::cancel → matching:ok{op=cancel, code=ok|not_queued|unavailable}。
     * The matching-cancel route: MatchingService::cancel → matching:ok{op=cancel, code=ok|not_queued|unavailable}.
     */
    private function handleMatchingCancel(ConnectionInterface $conn, Message $message): void
    {
        [$entityId, , $uid] = $this->requireGameplayContext($conn, $message);

        $cancelled = $this->matching?->cancel($uid);
        if ($cancelled === null) {
            $this->sendToEntity($entityId, 'matching:ok', ['op' => 'cancel', 'code' => 'unavailable', 'message' => '匹配服务未启用']);

            return;
        }
        $this->sendToEntity($entityId, 'matching:ok', [
            'op' => 'cancel',
            'code' => $cancelled ? 'ok' : 'not_queued',
            'message' => $cancelled ? '已取消匹配' : '不在匹配队列中',
        ]);
    }

    /**
     * 任务列表路由：以任务定义为权威全集合并进度（未开始任务 count=0）→ quest:rows 并行标量列表帧。
     * The quest-list route: merges progress under the definitions as the authoritative full set (unstarted quests
     * count=0) → a quest:rows parallel-scalar-list frame.
     */
    private function handleQuestList(ConnectionInterface $conn, Message $message): void
    {
        [$entityId, , $uid] = $this->requireGameplayContext($conn, $message);

        $quests = $this->quests;
        if ($quests === null) {
            return;
        }

        $questIds = [];
        $counts = [];
        $required = [];
        $completed = [];
        $rewarded = [];
        foreach ($quests->definitions()->all() as $definition) {
            $progress = $quests->progressOf($uid, $definition->id);
            $questIds[] = $definition->id;
            $counts[] = $progress->count ?? 0;
            $required[] = $definition->requiredCount;
            $completed[] = $progress->completed ?? false;
            $rewarded[] = $progress->rewarded ?? false;
        }

        $this->sendToEntity($entityId, 'quest:rows', [
            'questIds' => $questIds,
            'counts' => $counts,
            'required' => $required,
            'completed' => $completed,
            'rewarded' => $rewarded,
        ]);
    }

    /**
     * 任务领奖路由：{questId} → QuestService::claimReward（幂等三态）→ 奖励入包 + 背包标脏落库 +
     * item:added 定向帧逐项通知 → quest:result。未完成/已领奖转 quest:result{code=not_ready|already_claimed}。
     * The quest-claim route: {questId} → QuestService::claimReward (the idempotent tri-state) → rewards into the bag
     * + dirty-marked persistence + per-entry directed item:added frames → quest:result. Uncompleted/already-claimed
     * become quest:result{code=not_ready|already_claimed}.
     */
    private function handleQuestClaim(ConnectionInterface $conn, Message $message): void
    {
        [$entityId, , $uid, $inventory] = $this->requireGameplayContext($conn, $message);

        $questId = $message->payload['questId'] ?? null;
        if (!is_string($questId) || $questId === '') {
            throw new \InvalidArgumentException('payload 缺少 questId 字段');
        }

        $quests = $this->quests;
        $claimed = $quests !== null && $quests->claimReward($uid, $questId, $inventory);
        if (!$claimed) {
            $this->sendToEntity($entityId, 'quest:result', [
                'op' => 'claim',
                'code' => 'not_ready',
                'message' => '任务未完成或奖励已领取',
            ]);

            return;
        }

        $definition = $quests->definitions()->get($questId);
        foreach ($definition->rewards ?? [] as $reward) {
            $this->sendToEntity($entityId, 'item:added', [
                'itemId' => (string) $reward['itemId'],
                'count' => (int) $reward['count'],
            ]);
        }
        $this->markInventoryDirty($uid, $inventory);
        $this->sendToEntity($entityId, 'quest:result', [
            'op' => 'claim',
            'code' => 'ok',
            'message' => sprintf('任务 %s 奖励已发放', $questId),
        ]);
    }

    /**
     * 玩家复活路由（P5a 接入，消费 awaitingRevive 标记）：待复活玩家 → applyRevive 复活核心（P6a 抽取，
     * 与自动复活共用：满血回生 + 清标记 + 重挂出生保护 + 传送出生点 + 即时视野差分（P6b）+ 回执）。
     * 非待复活态回执 not_ready（幂等拒绝，不重复回血）。
     * The player-revive route (the P5a wiring, consuming the awaitingRevive marker): an awaiting-revive player →
     * the applyRevive core (the P6a extraction, shared with auto-revive: full restore + clear the marker + spawn
     * protection + teleport to the spawn + the immediate vision diff (the P6b) + the receipt). A non-awaiting
     * player gets a not_ready receipt (idempotent rejection, no repeated healing).
     */
    private function handlePlayerRevive(ConnectionInterface $conn, Message $message): void
    {
        $entityId = $this->registry->getEntityId($conn->getId());
        if ($entityId === null) {
            $this->send($conn, Message::create('error', ['code' => 401, 'message' => 'unauthorized'], $message->requestId));
            $conn->close();

            return;
        }

        $actor = $this->actors[$entityId] ?? null;
        if (!$actor instanceof PlayerActor) {
            $this->send($conn, Message::create('error', ['code' => 401, 'message' => 'unauthorized'], $message->requestId));
            $conn->close();

            return;
        }

        if (!$actor->isAwaitingRevive()) {
            $this->sendToEntity($entityId, 'player:revive', ['code' => 'not_ready', 'message' => '玩家不在待复活状态']);

            return;
        }

        $this->applyRevive($entityId, $actor);
    }

    /**
     * 复活核心（P6a 抽取，路由与自动复活共用）：满血回生 + 清标记 + 重挂出生保护（与 auth 挂载同口径）
     * + 传送回出生点 + 即时视野差分（P6b）+ player:stats 属性同步 + player:revive 回执（携带落点）。
     * 调用方保证 actor 处于待复活态（revive 幂等短路兜底，不重复回血）。
     * The revive core (the P6a extraction, shared by the route and auto-revive): full restore + clear the marker +
     * re-activate spawn protection (same as the auth mount) + teleport back to the spawn point + the immediate vision
     * diff (the P6b) + the player:stats stat sync + the player:revive receipt (carrying the landing position). The
     * caller guarantees the awaiting-revive state (revive's idempotent short-circuit backstops, no repeated healing).
     */
    private function applyRevive(string $entityId, PlayerActor $actor): void
    {
        // 复活：满血回生 + 清标记 + 重挂出生保护（复活的保护窗口与登录一致，防复活即被集火）
        // Revive: full restore + clear the marker + re-activate spawn protection (the revival window matches login,
        // so a revive is never instantly focused down).
        $actor->revive();
        $actor->enableSpawnProtection($this->spawnProtectionFrames);

        // 传送回出生点（P7b 参数化）：世界/容器双路径（V6 容器化路由），复用移动模板的广播语义；AOI 索引随后即时刷新（P6b）。
        // 自动复活路径无消息上下文——连接经 registry 反查（在线玩家恒可解析），无连接回落宿主世界。
        // Teleport to the spawn point (the P7b parameterization): both world and container paths (V6 containerized
        // routing), reusing the move template's broadcast semantics; the AOI index refreshes immediately afterwards
        // (the P6b). The auto-revive path has no message context — the connection is resolved back via the registry
        // (always resolvable for an online player), with no connection falling back to the host world.
        $connId = $this->registry->getConnectionId($entityId);
        $context = $connId !== null
            ? $this->registry->resolveContainerContext($connId, $this->world)
            : ['container' => null, 'entityManager' => $this->world->getEntityManager(), 'aoi' => $this->world->getAOI()];
        $entity = $context['entityManager']->get($entityId);
        $position = $this->spawnPoint;
        if ($entity !== null) {
            $current = $entity->getPosition();
            $entity->move($this->spawnPoint['x'] - $current['x'], $this->spawnPoint['y'] - $current['y']);
            $position = $entity->getPosition();
            $this->broadcastToViewIn($context['aoi'], $entity, 'entity_moved', [
                'id' => $entityId,
                'position' => $position,
            ]);

            // 即时视野差分（P6b）：传送跨越 AOI 单元时 enter/leave 不再等 World::update 下一帧——传送后立即
            // 刷新索引并双向补发（AoiDiffEnvelopes 同语义的帧级直发）：进入的新邻居收到 entity_enter{me}、
            // 我收到新邻居 entity_enter；离开的旧邻居收到 entity_leave{me}、我收到旧邻居 entity_leave。
            // 未跨格/全量视野（UniversalAOI 恒空差分）时补发自然为空。差分先行消费后，下一帧 World::update
            // 的 drainMoved 再次 updateEntity 走同格 fast path（空差分），不产生重复信封。
            // The immediate vision diff (the P6b): a teleport crossing AOI cells no longer waits for the next
            // World::update frame — the index refreshes right after the move and the pair frames are emitted
            // bidirectionally (the frame-level twin of AoiDiffEnvelopes' semantics): a newly entered neighbor gets
            // entity_enter{me} while I get its entity_enter; a departed old neighbor gets entity_leave{me} while I
            // get its entity_leave. Same-cell teleports (or UniversalAOI, always an empty diff) backfill nothing.
            // With the diff consumed first, the next frame's World::update drainMoved re-runs updateEntity on the
            // same cell (the empty fast path), producing no duplicate envelopes.
            $diff = $context['aoi']->updateEntity($entity);
            $this->broadcastEntityEnter($diff['entered'], $entityId, $position);
            foreach ($diff['entered'] as $neighbor) {
                $this->sendToEntity($entityId, 'entity_enter', ['id' => $neighbor->getId(), 'position' => $neighbor->getPosition()]);
            }
            foreach ($diff['left'] as $neighbor) {
                $this->sendToEntity($neighbor->getId(), 'entity_leave', ['id' => $entityId]);
                $this->sendToEntity($entityId, 'entity_leave', ['id' => $neighbor->getId()]);
            }
        }

        $this->sendToEntity($entityId, 'player:stats', ['id' => $entityId, 'hp' => $actor->hp(), 'maxHp' => $actor->maxHp()]);
        $this->sendToEntity($entityId, 'player:revive', ['code' => 'ok', 'position' => $position]);
    }

    /**
     * 任务对话路由：{npcId} → QuestService::reportTalk（对话进度源显式上报）→ quest:result ok。
     * The quest-talk route: {npcId} → QuestService::reportTalk (the talk source's explicit report) → quest:result ok.
     */
    private function handleQuestTalk(ConnectionInterface $conn, Message $message): void
    {
        [$entityId, , $uid] = $this->requireGameplayContext($conn, $message);

        $npcId = $message->payload['npcId'] ?? null;
        if (!is_string($npcId) || $npcId === '') {
            throw new \InvalidArgumentException('payload 缺少 npcId 字段');
        }

        $this->quests?->reportTalk($uid, $npcId);
        $this->sendToEntity($entityId, 'quest:result', [
            'op' => 'talk',
            'code' => 'ok',
            'message' => sprintf('与 %s 对话完成', $npcId),
        ]);
    }

    /**
     * 玩法路由上下文强解析：已认证玩家五元组（与经济批解耦——玩法批不依赖经济批装配），
     * 失败抛 InvalidArgumentException 由统一 catch 转 quest:result。
     * The strict gameplay-route context resolution: the authenticated-player quintuple (decoupled from the economy
     * batch — gameplay never depends on economy assembly); failures throw for the unified catch's quest:result conversion.
     *
     * @return array{0: string, 1: PlayerActor, 2: string, 3: Inventory, 4: Equipment}
     */
    private function requireGameplayContext(ConnectionInterface $conn, Message $message): array
    {
        $resolved = $this->resolvePlayerContext($conn, $message);
        if ($resolved === null) {
            throw new \InvalidArgumentException('连接未认证');
        }

        return $resolved;
    }

    /**
     * 认证握手（MAJOR-1 + MINOR-R1 顺序）：peek → mapId 比对 → consume 五态，成功后挂载实体、Actor 与 registry。
     * Auth handshake (MAJOR-1 + MINOR-R1 ordering): peek → mapId comparison → five-state consume; on success mounts entity, actor and registry entries.
     *
     * mapId 比对必须先于 consume 且基于 peek 的只读记录：mapId 不符时不 consume——token 保留、'map' scope 未写墓碑，
     * 客户端拿同一 token 重连正确频道即可正常 consume 认证（Valid），无需重新登录；比对失败即断开，实体不挂载，无脏状态。
     * The mapId comparison must precede consume and rely on peek's read-only record: on mismatch the token is not consumed — it is kept with no
     * 'map'-scope tombstone, so the client can reconnect to the correct channel with the same token and consume Valid without re-login;
     * the connection closes before any entity is mounted, leaving no dirty state.
     */
    protected function handleAuthMessage(ConnectionInterface $conn, Message $message): void
    {
        // 准入守卫（P16 动态扩缩容）：draining（摘除中，不接新会话）或满员（maxCapacity 达顶）时
        // 在 token 消费前置拒绝——token 保留可重连其他频道（与 map_mismatch 不 consume 同口径）。
        // The admission guard (the P16 dynamic scaling): rejected before the token consume when draining
        // (being drained, no new sessions) or full (maxCapacity reached) — the token is kept so the client can
        // reconnect to another channel (the same convention as map_mismatch's no-consume).
        $admissionRejection = $this->admissionRejection();
        if ($admissionRejection !== null) {
            $this->send($conn, Message::create('auth_failed', ['code' => 403, 'reason' => $admissionRejection], $message->requestId));
            $conn->close();

            return;
        }

        // 协议版本守卫：装配期注入最低版本（NYTHROS_MIN_CLIENT_VERSION），缺省 null = 不启用（存量客户端零影响）。
        // 启用时 version 缺失/非法/低于最低版本一律拒绝——token 不消费（与准入守卫同口径），客户端升级后可重连。
        // The protocol-version guard: the minimum version is injected at assembly (NYTHROS_MIN_CLIENT_VERSION);
        // null (default) disables the guard — zero impact on existing clients. When enabled, a missing/invalid/
        // too-old version is rejected without consuming the token (same convention as the admission guard), so the
        // client can reconnect after upgrading.
        $version = $message->payload['version'] ?? null;
        if ($this->minClientVersion !== null
            && (!is_int($version) || $version < $this->minClientVersion)) {
            $this->send($conn, Message::create('auth_failed', ['code' => 403, 'reason' => 'client_version_too_old'], $message->requestId));
            $conn->close();

            return;
        }

        $token = $message->payload['token'] ?? null;
        if (!is_string($token) || $token === '') {
            $this->send($conn, Message::create('error', ['code' => 400, 'message' => 'payload 缺少 token 字段'], $message->requestId));
            $conn->close();

            return;
        }

        $record = $this->tokenManager->peek($token);
        if ($record === null) {
            $status = $this->tokenManager->consume($token, 'map');
            $reason = $this->authFailedReason($status);
            $this->send($conn, Message::create('auth_failed', ['code' => 403, 'reason' => $reason], $message->requestId));
            $conn->close();

            return;
        }

        if ($record->mapId !== $this->mapId) {
            // 日志归因：可发现「客户端拿旧 token 撞错频道 / Gateway 分配 bug」两类问题
            // Attribution log: surfaces both "client hits the wrong channel with a stale token" and "Gateway assignment bug"
            error_log(sprintf('[MapServer] mapId mismatch: token=%s uid=%s instance=%s', $record->mapId, $record->uid, $this->serviceId));
            $this->send($conn, Message::create('auth_failed', ['code' => 403, 'reason' => 'map_mismatch'], $message->requestId));
            $conn->close();

            return;
        }

        $status = $this->tokenManager->consume($token, 'map');
        if ($status !== TokenStatus::Valid) {
            $reason = $this->authFailedReason($status);
            $this->send($conn, Message::create('auth_failed', ['code' => 403, 'reason' => $reason], $message->requestId));
            $conn->close();

            return;
        }

        // entityId = uid@connectionId：同一账号多开互不干扰，断开清理互不影响
        // entityId = uid@connectionId: multiple logins of the same account stay independent, and cleanup on disconnect never collides
        $entityId = sprintf('%s@%s', $record->uid, $conn->getId());

        // 迁移快照消费（P15 / ADR-025 §3.3）：attach 时原子取走转移票据——同图恢复坐标、异图落目的图
        // 缺省入场点；背包/血量同图异图均恢复（无票 = 全新入场，与接入前语义一致）。
        // The migration snapshot consume (the P15 / ADR-025 §3.3): at attach the transfer ticket is atomically
        // taken — the position restores for the same map while a different map lands on the destination's
        // default entry point; the inventory and hp restore for both (no ticket = a fresh entry, matching the
        // pre-integration semantics).
        $restored = $this->consumeTransferSnapshot($record->uid);
        $arrival = $restored['position'] ?? $this->spawnPoint;
        $entity = new BaseEntity($entityId, new Position($arrival['x'], $arrival['y']));
        $this->entityManager->add($entity);

        // 首次登记返回视野差分：entered = 新实体九宫格内的旧邻居；驱动邻居视角广播；自身视角用全量快照下发
        // The first registration returns the vision delta whose entered set holds the old neighbors inside the new entity's 3x3 view;
        // the delta drives the neighbor-view broadcast, and the self view is delivered by the full snapshot
        // （UniversalAOI 恒返回空差分：全量可见下没有「邻居进入」，加入/出生通知由全量广播路径承担）
        // (UniversalAOI always returns an empty delta: no "neighbor entered" under full visibility, the join/birth notice is
        // carried by the full-broadcast path)
        $diff = $this->aoi->updateEntity($entity);
        $this->broadcastEntityEnter($diff['entered'], $entityId, $entity->getPosition());
        $this->enqueueVisionSnapshot($entity, $conn);

        $actor = new PlayerActor($entityId, $this);
        $actor->bindEntity($entity);
        $actor->attachConnection($conn->getId(), $record->uid);
        // 出生保护窗口（R4 出生保护批）：auth 挂载即激活（帧数可由装配层按类型模块配置覆盖），
        // 保护期内怪物感知/攻击跳过该玩家
        // Spawn-protection window (the R4 spawn-protection batch): activated on auth mount (frames overridable by
        // the assembly layer per type-module config); monster perception/attacks skip the player while protected
        $actor->enableSpawnProtection($this->spawnProtectionFrames);
        // 装备栏随 auth 初始化并挂载进 BasePlayer（属性聚合口径：maxHp = 基础 + 装备加成）
        // The equipment set initializes with auth and mounts into BasePlayer (the aggregation contract: maxHp = base + equipment bonus)
        $equipment = new Equipment();
        $this->equipments[$entityId] = $equipment;
        $actor->attachEquipment($equipment);
        // 初始血量基线（P18 玩法数据外置）：gameplay 表 player.maxHp 驱动（先于快照 hp 导入——
        // importHp 的 clamp 上界依赖合成上限）。
        // The initial vitals baseline (the P18): driven by the gameplay table's player.maxHp (before the
        // snapshot hp import — importHp's clamp ceiling depends on the composed ceiling).
        $actor->initVitals($this->playerMaxHp);
        $this->actors[$entityId] = $actor;
        if ($restored !== null) {
            $this->inventories[$entityId] = $restored['inventory'];
            if ($restored['hp'] !== null) {
                $actor->importHp($restored['hp']);
            }
        } elseif (!isset($this->inventories[$entityId])) {
            // 归档兜底恢复（P18，env 门控）：票据缺席（TTL 过期/未导出）时读最近归档的背包——
            // 尽力而为：无归档/坏行回落全新背包。装配层预注入的背包（inventories 构造参数）优先，
            // 不被恢复路径覆盖。
            // The archive fallback restore (the P18, env-gated): with the ticket absent (TTL expired / never
            // exported) the most recently archived inventory is read — best-effort: no archive or a malformed
            // row degrades to a fresh inventory. An assembly-preseeded inventory (the constructor's inventories)
            // wins and is never overwritten by the restore path.
            $this->inventories[$entityId] = $this->restoreInventoryFromArchive($record->uid) ?? new Inventory();
        }
        if ($this->typeIndex !== null) {
            $this->typeIndex->set($entityId, EntityTypeIndex::KIND_PLAYER);
        }
        $this->mountPlayer($conn, $entity, $actor);
        $this->playerCount++;

        $this->send($conn, Message::create('auth_ok', ['uid' => $record->uid, 'id' => $entityId], $message->requestId));
    }

    /** 视野帧负载装饰：掉落物实体附加 itemId（掉落视野进入与 drop:spawned 信息等价）。 View-frame decoration: drop entities carry itemId (drop view-enters stay equivalent to drop:spawned). */
    protected function decorateViewPayload(string $sourceEntityId, array $payload): array
    {
        $source = $this->entityManager->get($sourceEntityId);
        if ($source instanceof DropEntity) {
            $payload['itemId'] = $source->itemId;
        }

        return $payload;
    }

    /** 实体清理后钩子：怪物/玩家 Actor 摘除、类型索引清理、断连落库兜底、玩法批状态清理与在线计数调整。 Post-entity-cleanup hook: actor removal, type-index cleanup, the disconnect persistence flush, gameplay-batch state cleanup and the online-count adjustment. */
    protected function onEntityCleanedUp(ConnectionInterface $conn, string $entityId): void
    {
        if (isset($this->actors[$entityId])) {
            $actor = $this->actors[$entityId];
            // 断连立即冲刷（A-4 落库断链修复）：玩家断连时把标脏背包立即落库（键取 uid()）；怪物 Actor 无 uid 跳过
            // Immediate disconnect flush (A-4 persistence-chain fix): a disconnecting player's dirty inventory is saved at once
            // (keyed by uid()); monster actors carry no uid and are skipped
            if ($actor instanceof PlayerActor && $actor->uid() !== null) {
                $this->archive?->flushId($actor->uid());

                // 迁移快照导出（P15 / ADR-025 方案 C）：detach 时把世界本地状态（位置/血量/背包）写入转移票据，
                // 目的端 attach 时原子消费重建——重连、换频道、切图共用同一导出路径；store 未装配零操作。
                // The migration snapshot export (the P15 / ADR-025 option C): at detach the world-local state
                // (position/hp/inventory) is written into the transfer ticket, atomically consumed and rebuilt at the
                // destination's attach — reconnect, channel switch and map switch all share this one export path; a
                // store-less assembly is a no-op.
                $this->exportTransferSnapshot($entityId, $actor);
            }
            $this->actorSystem->remove($actor);
            unset($this->actors[$entityId]);
            $this->typeIndex?->remove($entityId);
            $this->playerCount--;

            // 玩法批断连清理（R3）：在身 buff 全摘（无广播——连接已断）、冷却记录清空、匹配票摘除。
            // Gameplay-batch disconnect cleanup (R3): all live buffs dropped (no broadcast — the connection is gone),
            // cooldown records cleared and match tickets purged.
            $this->buffs?->purgeHost($entityId);
            $this->cooldowns?->reset($entityId);
            $this->matching?->purgeOffline([$entityId]);

            // 房间归属处置（R2 review MINOR-6）：断连者是某房创建者时标记无主（evict 路径同样汇入本钩子）。
            // Room-ownership disposal (R2 review MINOR-6): a disconnecting room creator is marked ownerless
            // (the eviction path converges into this same hook).
            $this->rooms?->handleCreatorDisconnected($entityId);

            error_log(sprintf('[MapServer] cleanup connection [%s] entity [%s]', $conn->getId(), $entityId));
        }
    }

    /**
     * 迁移快照导出（P15 / ADR-025 §3.2）：把玩家世界本地状态写入转移票据（uid 键，SETEX 覆盖）——
     * fromMapId/position/hp（clamp ≥1，死亡态不迁移）/inventory 全量。store 未装配或 uid 未挂连接时零操作。
     * Exports the migration snapshot (the P15 / ADR-025 §3.2): the player's world-local state is written into the
     * transfer ticket (uid-keyed, SETEX overwrite) — fromMapId/position/hp (clamped >=1, death state never
     * migrates) / the full inventory. A store-less assembly or an unattached uid is a no-op.
     *
     * @return bool 是否实际导出（测试观察口） Whether an export actually happened (a test observation port).
     */
    public function exportTransferSnapshot(string $entityId, PlayerActor $actor): bool
    {
        $transfers = $this->transfers;
        $uid = $actor->uid();
        if ($transfers === null || $uid === null) {
            return false;
        }

        $position = $this->world->getEntityManager()->get($entityId)?->getPosition() ?? $this->spawnPoint;
        $inventory = $this->inventories[$entityId] ?? null;
        $transfers->export($uid, [
            'fromMapId' => $this->mapId,
            'position' => ['x' => $position['x'], 'y' => $position['y']],
            'hp' => max(1, $actor->hp()),
            'inventory' => $inventory?->all() ?? [],
        ]);

        return true;
    }

    /**
     * 进入 draining（P16 动态扩缩容，GmDrainHandlerInterface 实现）：注册心跳 meta 置 status=draining
     * （gateway selectChannel 不再路由新会话到本实例）+ 本地守卫激活（新 auth 拒绝 draining）；存量连接不受
     * 影响，在场玩家归零后由外部编排停机摘除（scale-in）。重复 drain 幂等返回 false；未接集群返回 false。
     * Enters draining (the P16 dynamic scaling, the GmDrainHandlerInterface implementation): the registry
     * heartbeat meta flips status=draining (the gateway's selectChannel stops routing new sessions here) and the
     * local guard activates (new auths reject with draining); existing connections stay unaffected and once the
     * player count reaches zero the external orchestration stops the worker (scale-in). Idempotent repeats return
     * false; false without a cluster.
     */
    public function drain(): bool
    {
        if ($this->draining) {
            return false;
        }
        $this->draining = true;
        $this->serviceRegistry?->heartbeat(self::SERVICE_TYPE_MAP, $this->serviceId, ['status' => 'draining']);
        error_log(sprintf('[MapServer] draining [%s]（不再接入新会话 / no new sessions）', $this->serviceId));

        return true;
    }

    public function isDraining(): bool
    {
        return $this->draining;
    }

    /**
     * 准入裁决（P16）：draining → 'draining'；满员（maxCapacity>0 且 playerCount 达顶）→ 'map_full'；
     * 其余 null（放行）。
     * The admission verdict (the P16): draining -> 'draining'; full (maxCapacity>0 with playerCount at the cap)
     * -> 'map_full'; otherwise null (admitted).
     */
    private function admissionRejection(): ?string
    {
        if ($this->draining) {
            return 'draining';
        }
        if ($this->maxCapacity > 0 && $this->playerCount >= $this->maxCapacity) {
            return 'map_full';
        }

        return null;
    }

    /**
     * 归档兜底恢复（P18）：从最近归档记录重建背包——数据形状与 markDirty 写入口径对称
     * （{inventory: {itemId: count}}）；开关关闭/store 未装配/无归档/坏行返回 null（全新背包）。
     * Restores from the archive (the P18): rebuilds the inventory from the most recent archived record — the
     * data shape mirrors markDirty's write convention ({inventory: {itemId: count}}); returns null (a fresh
     * inventory) when the switch is off / the store is unassembled / no archive / a malformed row.
     *
     * @return Inventory|null 恢复的背包，无恢复 null The restored inventory, or null when nothing restored.
     */
    private function restoreInventoryFromArchive(string $uid): ?Inventory
    {
        if (!$this->archiveRestore || $this->archive === null) {
            return null;
        }
        $archived = $this->archive->load($uid);
        if ($archived === null || !is_array($archived['inventory'] ?? null)) {
            return null;
        }

        $inventory = new Inventory();
        foreach ($archived['inventory'] as $itemId => $count) {
            if (is_string($itemId) && $itemId !== '' && is_int($count) && $count > 0) {
                $inventory->add($itemId, $count);
            }
        }

        return $inventory;
    }

    /**
     * 迁移快照消费（P15 / ADR-025 §3.3，auth attach 路径调用）：原子取走票据并解析为重建三元组——
     * 位置仅同图恢复（异图回落 null = 目的图缺省入场点，经典换线语义）；背包重建 Inventory；hp clamp ≥1。
     * Consumes the migration snapshot (the P15 / ADR-025 §3.3, invoked on the auth attach path): atomically
     * takes the ticket and parses it into the rebuild triple — the position restores only for the same map
     * (a different map falls back to null = the destination's default entry point, the classic zoning
     * semantics); the inventory rebuilds an Inventory; hp clamps >=1.
     *
     * @return array{position: array{x: int, y: int}|null, hp: int|null, inventory: Inventory}|null 无票/坏票返回 null。
     *   Null on a missing/malformed ticket.
     */
    private function consumeTransferSnapshot(string $uid): ?array
    {
        $transfers = $this->transfers;
        if ($transfers === null) {
            return null;
        }
        $snapshot = $transfers->consume($uid);
        if (!is_array($snapshot)) {
            return null;
        }

        $position = null;
        if (($snapshot['fromMapId'] ?? null) === $this->mapId
            && is_array($snapshot['position'] ?? null)
            && isset($snapshot['position']['x'], $snapshot['position']['y'])
            && is_int($snapshot['position']['x'])
            && is_int($snapshot['position']['y'])
        ) {
            $position = ['x' => $snapshot['position']['x'], 'y' => $snapshot['position']['y']];
        }

        $hp = is_int($snapshot['hp'] ?? null) ? max(1, $snapshot['hp']) : null;

        $inventory = new Inventory();
        $items = $snapshot['inventory'] ?? null;
        if (is_array($items)) {
            foreach ($items as $itemId => $count) {
                if (is_string($itemId) && $itemId !== '' && is_int($count) && $count > 0) {
                    $inventory->add($itemId, $count);
                }
            }
        }

        return ['position' => $position, 'hp' => $hp, 'inventory' => $inventory];
    }

    // ── 公共组装接口（战斗层依赖倒置） ──
    // Public assembly surface (combat-tier inversion of control)

    /** 回填战斗服务（依赖循环规避：CombatService 构造时依赖本 MapServer 做广播，组装顺序 new MapServer → new CombatService → attachCombat）。 Back-fills the combat service (circular-dependency avoidance; assembly order: new MapServer → new CombatService → attachCombat). */
    public function attachCombat(CombatService $combatService): void
    {
        $this->combatService = $combatService;
    }

    /**
     * 注入最低客户端协议版本（版本协商守卫，见 handleAuthMessage；缺省 null = 不启用）。
     * Injects the minimum client protocol version (the negotiation guard, see handleAuthMessage; null by default = off).
     */
    public function setMinClientVersion(?int $minClientVersion): void
    {
        $this->minClientVersion = $minClientVersion;
    }

    /**
     * 回填 mmorpg 类型模块接线（R4 试点，NYTHROS_MMORPG=1 装配）：注入配置、按 respawnMs 创建重生调度器，
     * 并订阅 combat.kill 埋点——怪物死亡即登记重生（重生回锚点由世界 tick 的 tickMmorpg 驱动）。
     * 缺省不调用 = 威胁/仇恨与重生未启用，世界侧行为与接入前逐字节等价。
     * Back-fills the mmorpg type-module wiring (the R4 pilot, assembled with NYTHROS_MMORPG=1): injects the config,
     * builds the respawn scheduler from respawnMs, and subscribes to the combat.kill instrumentation — a monster
     * death registers a respawn (the respawn back to the anchor is driven by tickMmorpg on the world tick).
     * Not invoked by default = threat/hate and respawn off, the world side stays byte-for-byte equivalent to the
     * pre-integration behavior.
     */
    public function attachMmorpg(MmorpgConfig $config, EventDispatcherInterface $combatEvents): void
    {
        // 安全区/出生点同源校验（P8a，装配期 fail-fast）：安全区的保护语义锚定出生/复活落点——两者偏离
        // 意味着复活玩家落在保护区外（复活即被集火）或保护了非出生区域。偏差即装配错误，延迟到运行期
        // 暴露会把不变量责任泄漏到 AI 行为层。
        // The safe-zone/spawn-point alignment check (the P8a, assembly-time fail-fast): the zone's protection
        // semantics anchor on the spawn/revive point — a mismatch means revived players land outside the zone
        // (focused down on revive) or a non-spawn area gets shielded. A mismatch is an assembly error; deferring
        // it to runtime would leak the invariant's duty into the AI behavior layer.
        if ($config->safeZone !== null
            && ($config->safeZone['x'] !== $this->spawnPoint['x'] || $config->safeZone['y'] !== $this->spawnPoint['y'])) {
            throw new \LogicException(sprintf(
                'mmorpg 安全区圆心 (%d,%d) 与出生点 (%d,%d) 不一致——两者必须同源声明 / mmorpg safeZone center (%d,%d) must align with the spawn point (%d,%d)',
                $config->safeZone['x'],
                $config->safeZone['y'],
                $this->spawnPoint['x'],
                $this->spawnPoint['y'],
                $config->safeZone['x'],
                $config->safeZone['y'],
                $this->spawnPoint['x'],
                $this->spawnPoint['y'],
            ));
        }

        $this->mmorpg = $config;
        $this->respawner = new Respawner($config->respawnMs);

        // 玩家自动复活（P6a）：playerRespawnMs > 0 时随配置创建调度器——玩家死亡即登记复活时刻
        // （now + playerRespawnMs），到期由世界 tick 的 tickMmorpg 驱动服务端复活（关闭 P5 遗留的
        // 「死亡实体长期滞留 AOI」债务）；0 = 关闭，复活仅路由驱动（P5a 语义）。
        // Player auto-revive (the P6a): with playerRespawnMs > 0 the scheduler rides the config — a player death
        // registers the revive instant (now + playerRespawnMs), consumed by tickMmorpg on the world tick (closing
        // the P5 debt of a dead player lingering in the AOI); 0 = off, revive stays route-driven (the P5a semantics).
        if ($config->playerRespawnMs > 0) {
            $this->playerRespawner = new Respawner($config->playerRespawnMs);
        }

        // 热区 governor（P9a 区域降频）：策略存在时创建——tickMmorpg 每 base tick 采样密度并给实体指派
        // 分频；null = 未启用，实体恒逐帧（零影响）。
        // The hot-cell governor (the P9a region downgrade): created when a policy exists — tickMmorpg samples
        // density and assigns divisors every base tick; null = off, entities always update per frame (zero impact).
        $this->hotCellGovernor = $config->hotCell === null
            ? null
            : new CellDensityGovernor(self::AOI_CELL_SIZE, $config->hotCell);

        $combatEvents->listen(CombatService::EVENT_KILL, function (array $payload): void {
            $victimId = $payload['victimId'] ?? null;
            if (!is_string($victimId)) {
                return;
            }
            if (isset($this->spawnRegistry[$victimId])) {
                // 逐怪重生延迟（P11 怪物表 respawnMs）：登记时按出生参数覆盖全局值（null 回落全局）
                // The per-monster respawn delay (the P11 monster-table respawnMs): the registration overrides the
                // global value from the spawn parameters (null falls back to the global).
                $this->respawner?->registerDeath($victimId, microtime(true), $this->spawnRegistry[$victimId]['respawnMs'] ?? null);

                return;
            }

            // 死亡掉落（P13 死亡掉落归属）：victim 为玩家 Actor 且装配了 DeathDropPolicy 时，按策略从
            // 背包 roll 掉落为 DropEntity（killerUid 归属绑定 + 窗口内 not_owner 拾取保护，复用既有机制）；
            // 策略关闭（缺省）零操作——与接入前语义逐字节等价。
            // Death drops (the P13 death-drop ownership): when the victim is a player actor and a DeathDropPolicy
            // is assembled, the inventory rolls into DropEntities per the policy (killerUid ownership binding +
            // the in-window not_owner pickup protection, reusing the existing mechanics); with the policy off
            // (the default) this is a no-op — byte-for-byte equivalent to the pre-integration semantics.
            if (($this->actors[$victimId] ?? null) instanceof PlayerActor) {
                $this->dropInventoryOnDeath($victimId, is_string($payload['killerUid'] ?? null) ? $payload['killerUid'] : null);
            }

            // 玩家死亡登记自动复活（P6a）：victim 为待复活玩家 Actor 时入队（怪物 victim 已在上分支消费；
            // 复活调度器未启用时零操作——行为与 P5a 路由驱动语义一致）。
            // Player-death auto-revive registration (the P6a): the victim joins the queue when it is an
            // awaiting-revive player actor (monster victims are consumed in the branch above; without the
            // scheduler this is a no-op — matching the P5a route-driven semantics).
            if (($this->actors[$victimId] ?? null) instanceof PlayerActor
                && $this->actors[$victimId]->isAwaitingRevive()) {
                $this->playerRespawner?->registerDeath($victimId, microtime(true));
            }
        });
    }

    /**
     * 回填 GM 命令总线（R3 starter-kit 接线）：组装层以本 MapServer 为 status/broadcast/kick 的能力实现
     * 构造总线后回填；缺省 null = gm:exec 请求按未知类型 404。
     * Back-fills the GM command bus (the R3 starter-kit wiring): the assembly layer builds the bus with this
     * MapServer as the status/broadcast/kick capability implementation, then back-fills; default null = gm:exec
     * requests fall through to the unknown-type 404.
     */
    public function attachGm(GmCommandBus $bus): void
    {
        $this->gm = $bus;
    }

    /**
     * 回填经济批服务组（R3 starter-kit 接线，NYTHROS_ECONOMY=1 装配）：邮件/交易行/账本/物品注册表与
     * msgpack 编码器一并注入；本 MapServer 同时作为 MailNotifierInterface 实现（notifyNewMail 按 uid
     * 解析在线实体定向入队 mail:new 帧），故 MailService 可直接以 $map 为 notifier 构造后传入。
     * 缺省 null = equip/unequip/auction 与 mail 及 economy:deposit 路由按未知类型 404。
     * Back-fills the economy-batch service group (the R3 starter-kit wiring, assembled with NYTHROS_ECONOMY=1):
     * mail/auction/ledger/item-repository plus the msgpack encoder are injected together; this MapServer doubles as
     * the MailNotifierInterface implementation (notifyNewMail resolves online entities by uid and enqueues directed
     * mail:new frames), so MailService can be built with $map as its notifier before being passed in.
     * Default null = the equip/unequip/auction/mail/economy:deposit routes fall through to the unknown-type 404.
     */
    public function attachEconomy(
        MailService $mail,
        AuctionService $auction,
        CurrencyLedger $ledger,
        ItemRepository $items,
        MsgpackSerializer $msgpack,
    ): void {
        $this->mail = $mail;
        $this->auction = $auction;
        $this->ledger = $ledger;
        $this->economyItems = $items;
        $this->msgpack = $msgpack;
    }

    /**
     * 生成怪物（demo 组装层调用）：BaseEntity 空间实体 + MonsterActor（extends BaseMonster）战斗状态，
     * 依次完成 entityManager/AOI 登记、bindEntity、actorSystem/$actors/typeIndex 登记，并广播 monster:spawned。
     * Spawns a monster (invoked by the demo assembly layer): a BaseEntity spatial entity plus a MonsterActor (extends BaseMonster)
     * carrying combat state; registers entityManager/AOI, binds the entity, registers actorSystem/$actors/typeIndex, then broadcasts monster:spawned.
     *
     * @param string $monsterId 怪物实体/怪物 Actor 共用 id Shared monster entity/actor id.
     * @param int $maxHp 最大生命值 Maximum hit points.
     * @param array{x: int, y: int} $position 出生位置（patrolAnchor 同源） Spawn position (also the patrol anchor).
     * @param string $typeId 怪物类型 id（monster:spawned 广播的造型标识） Monster type id (the visual identity in the monster:spawned broadcast).
     * @param int|null $patrolRadius 巡逻半径覆盖；null = 用 MonsterActor 缺省（10）。锚点离出生玩家视野边界
     *   不足缺省半径时（如负半轴锚），按锚到视野边界的距离收窄。
     *   Patrol-radius override; null = the MonsterActor default (10). Narrow it to the anchor's distance to the spawn
     *   players' vision boundary when the anchor sits close to that boundary (e.g. anchors on the negative axis).
     * @param bool $registerSpawn 是否登记出生参数（reviewer MINOR-1 密度副本传 false）：重生密度副本
     *   不登记——副本死亡不触发整锚点重生，防指数增长；仅锚点本体登记。
     *   Whether to register the spawn parameters (reviewer MINOR-1: density copies pass false): density copies
     *   stay unregistered — a copy's death never triggers a full-anchor respawn, preventing exponential growth;
     *   only the anchor itself registers.
     * @param int|null $respawnMs 逐怪重生延迟（P11 怪物表参数；null = MmorpgConfig.respawnMs 全局值）。
     *   The per-monster respawn delay (the P11 monster-table parameter; null = the global MmorpgConfig.respawnMs).
     */
    public function spawnMonster(string $monsterId, int $maxHp, array $position, string $typeId, ?int $patrolRadius = null, bool $registerSpawn = true, ?int $respawnMs = null): void
    {
        $combat = $this->combatService;
        $dropTable = $this->dropTable;
        $typeIndex = $this->typeIndex;
        if ($combat === null || $dropTable === null || $typeIndex === null) {
            throw new \LogicException('spawnMonster 需要先注入战斗依赖（dropTable/typeIndex/combatService）');
        }

        // 出生参数登记（R4 mmorpg 重生器）：mmorpg 启用时记录锚点/造型/血量快照，供死亡后重生回锚点。
        // Spawn-parameter registration (the R4 mmorpg respawner): with mmorpg on, the anchor/type/maxHp snapshot is
        // recorded so the monster can respawn back to its anchor after death.
        if ($this->mmorpg !== null && $registerSpawn) {
            $this->spawnRegistry[$monsterId] = [
                'maxHp' => $maxHp,
                'position' => $position,
                'typeId' => $typeId,
                'patrolRadius' => $patrolRadius,
                'respawnMs' => $respawnMs,
            ];
        }

        $entity = new BaseEntity($monsterId, new Position($position['x'], $position['y']));
        $this->entityManager->add($entity);

        // 登记进视野提供者并补发 entered 差分：UniversalAOI（全量世界）恒返回空差分，补发自然为空——
        // 出生通知由 monster:spawned 承担（全量可见）；entered 非空时旧邻居收到「新怪物进入视野」
        // Register into the view provider and back-fill the entered delta: UniversalAOI (full world) always returns an
        // empty delta so the back-fill is naturally empty — monster:spawned carries the birth notice; a non-empty entered
        // set notifies old neighbors that the new monster entered their view
        $diff = $this->aoi->updateEntity($entity);
        $this->broadcastEntityEnter($diff['entered'], $monsterId, $entity->getPosition());

        // 威胁表（R4 mmorpg 类型模块试点）：mmorpg 启用时按配置构造 ThreatRules/ThreatTable 注入 MonsterActor
        // （受击方记录攻击者威胁，aggro 选择切换目标）；未启用时 null = 行为与接入前逐字节等价。
        // Threat table (the R4 mmorpg type-module pilot): with mmorpg on, a ThreatRules/ThreatTable built from the
        // config is injected into MonsterActor (the hit side records the attacker's threat; aggro switches targets);
        // null without it = byte-for-byte equivalent to the pre-integration behavior.
        $threatTable = null;
        if ($this->mmorpg !== null) {
            $threatTable = new ThreatTable(new ThreatRules(
                aggroRange: $this->mmorpg->aggroRange,
                threatDecayPerSec: $this->mmorpg->threatDecayPerSec,
                tauntMultiplier: $this->mmorpg->tauntMultiplier,
                maxThreat: $this->mmorpg->maxThreat,
            ));
        }

        // patrolAnchor = 出生点：怪物巡逻只在出生点附近徘徊，不无限漂移（e2e 稳定性 + 刷怪点语义）
        // patrolAnchor = the spawn point: monsters roam near their spawn instead of drifting away forever
        // typeId 透传（P2 收口）：任务击杀匹配键——此前未传导致 combat.kill 的 monsterTypeId 恒为空串，
        // 击杀进度源永不匹配（reporter MINOR 级装配缺口，E2E 走线缆暴露）。
        // typeId passthrough (the P2 close-out): the quest kill-matching key — it used to be omitted, so
        // combat.kill's monsterTypeId stayed empty and the kill progress source never matched (an assembly-level
        // gap exposed by the E2E's first wire pass).
        $monster = new MonsterActor($monsterId, $maxHp, $this->world, $combat, $dropTable, $this, $typeIndex, $this->random, $this, patrolAnchor: $position, patrolRadius: $patrolRadius ?? 10, threatTable: $threatTable, typeId: $typeId, safeZone: $this->mmorpg?->safeZone, attackRange: $this->mmorpg !== null ? $this->mmorpg->attackRange : 0);
        $monster->bindEntity($entity);
        $this->actorSystem->add($monster);
        $this->actors[$monsterId] = $monster;
        $typeIndex->set($monsterId, EntityTypeIndex::KIND_MONSTER);

        $this->broadcastToVision($monsterId, 'monster:spawned', [
            'id' => $monsterId,
            'typeId' => $typeId,
            'position' => ['x' => $position['x'], 'y' => $position['y']],
        ]);
    }

    /**
     * 掉落表热载换入（P11 玩法数据外置）：drops 表 config.changed 时由装配层构建新表后调用。
     * 本表引用随 spawnMonster 注入 MonsterActor——在场怪物持有旧表实例自然耗尽，新出生/重生怪物用新表。
     * Swaps a drop table in for hot reload (the P11 data externalization): the assembly layer builds a new table
     * on a drops config.changed and calls this. The table reference rides spawnMonster into MonsterActor — live
     * monsters keep draining their old-table instance while newly spawned/respawned ones use the new table.
     */
    public function replaceDropTable(DropTable $dropTable): void
    {
        $this->dropTable = $dropTable;
    }

    /**
     * gameplay 表热载应用（P11 玩法数据外置）：换入出生/复活点 + 怪物表 diff——
     * ① 已在场（实体存在或已登记出生参数）的怪物：登记参数热更（锚点/血量/造型/巡逻域/重生延迟），
     *   重生侧生效，在场怪物不驱逐（战斗中移除属破坏性操作，删除行的效果 = 不再重生 + 登记作废）；
     * ② 新增行：立即 spawn（monster:spawned 广播可达）；
     * ③ 删除行：出生登记摘除（死亡登记在重生消费时 spawn 查空自然作废）。
     * Applies a gameplay table for hot reload (the P11 data externalization): swaps in the spawn/revive point plus
     * a monster-table diff — ① monsters already present (entity exists or spawn parameters registered): registry
     * params hot-updated (anchor/maxHp/type/patrol/respawn delay), effective on the respawn side; live monsters are
     * never evicted (removing one mid-combat is destructive — a deleted row's effect = no more respawns + the
     * registry entry voided); ② new rows: spawn immediately (monster:spawned broadcast receivable); ③ deleted rows:
     * the spawn registration is removed (a pending death registration voids naturally when respawn finds no spawn).
     */
    public function applyGameplayConfig(GameplayConfig $config): void
    {
        $this->spawnPoint = $config->spawnPoint;

        $specs = $config->monstersById();
        foreach ($this->spawnRegistry as $monsterId => $spawn) {
            $spec = $specs[$monsterId] ?? null;
            if ($spec !== null) {
                $this->spawnRegistry[$monsterId] = [
                    'maxHp' => $spec->maxHp,
                    'position' => $spec->anchor,
                    'typeId' => $spec->typeId,
                    'patrolRadius' => $spec->patrolRadius,
                    'respawnMs' => $spec->respawnMs,
                ];

                continue;
            }
            unset($this->spawnRegistry[$monsterId]);
        }

        foreach ($specs as $monsterId => $spec) {
            if (!isset($this->spawnRegistry[$monsterId]) && $this->world->getEntityManager()->get($monsterId) === null) {
                $this->spawnMonster($monsterId, $spec->maxHp, $spec->anchor, $spec->typeId, patrolRadius: $spec->patrolRadius, respawnMs: $spec->respawnMs);
            }
        }
    }

    /**
     * 当前出生/复活点（auth 挂载与复活传送共用；热载可变）。
     * The current spawn/revive point (shared by the auth mount and the revive teleport; mutable via hot reload).
     *
     * @return array{x: int, y: int}
     */
    public function spawnPoint(): array
    {
        return $this->spawnPoint;
    }

    /**
     * 视野广播实现（VisionBroadcasterInterface）：向 centerEntityId 视野内全部实体对应连接入队一帧（帧末 flush）。
     * 事件帧语义：包含视野中心实体自己的连接（combat:hit/monster:spawned 等以中心实体为通告对象，query 含自身）。
     * View-broadcast implementation (VisionBroadcasterInterface): enqueues one frame to the connections of every entity
     * in the centerEntityId's view (flushed at frame end). EVENT-frame semantics: the center entity's own connection is
     * included (combat:hit/monster:spawned announce to the center itself; the query includes self).
     */
    public function broadcastToVision(string $centerEntityId, string $type, array $payload): void
    {
        $center = $this->entityManager->get($centerEntityId);
        if ($center === null) {
            return;
        }

        $this->broadcastToView($center, $type, $payload, skipSelf: false);
    }

    /**
     * 向实体在世界视野内的邻居广播 entity_leave（状态帧语义跳过自身，镜像 RealtimeServer::closeConnection 的
     * "先广播后摘除"时序与帧格式），供 RoomHub::handleJoin 摘除世界登记前调用；实体不在世界 EM 时静默跳过
     * （该查空即"先摘除后广播"错误时序的天然失败信号——调用方必须持未摘除状态调用）。
     * Broadcasts entity_leave to the entity's world-view neighbors (STATE-frame semantics skipping self, mirroring
     * RealtimeServer::closeConnection's "broadcast before removal" ordering and frame format), for
     * RoomHub::handleJoin to call before removing the world registration; silently skipped when the entity is absent
     * from the world EM (that miss is the natural failure signal of a wrong "remove-then-broadcast" ordering —
     * callers must invoke this while the entity is still registered).
     */
    public function broadcastEntityLeave(string $entityId): void
    {
        $entity = $this->entityManager->get($entityId);
        if ($entity === null) {
            return;
        }

        $this->broadcastToView($entity, 'entity_leave', [
            'id' => $entityId,
            'position' => $entity->getPosition(),
        ]);
    }

    /**
     * 全服广播实现（GmBroadcasterInterface）：向本进程全部已认证连接入队 gm:broadcast 帧（帧末批量发送）。
     * 广播范围 = 本频道进程内连接；跨进程全服广播属后续批次。
     * Server-broadcast implementation (GmBroadcasterInterface): enqueues a gm:broadcast frame to every
     * authenticated connection in this process (batch-sent at frame end). The scope is this channel's process;
     * cross-process whole-server broadcast belongs to a later batch.
     */
    public function broadcast(string $message): void
    {
        foreach ($this->connections as $connId => $conn) {
            if (!$this->registry->has($connId)) {
                continue;
            }

            $this->frameMerger->enqueue($conn, 'gm:broadcast', ['message' => $message]);
        }
    }

    /**
     * 踢人实现（GmKickerInterface）：按 uid 匹配 PlayerActor 并关闭其连接（close 触发 onClose →
     * 既有断连清理链：entity_leave 广播/摘除/持久化冲刷），返回实际断开的连接数。
     * Kick implementation (GmKickerInterface): matches PlayerActors by uid and closes their connections
     * (close triggers onClose → the existing disconnect-cleanup chain: entity_leave broadcast / removal /
     * persistence flush), returning how many connections were actually closed.
     */
    public function kick(string $uid): int
    {
        $closed = 0;
        foreach ($this->actors as $actor) {
            if (!$actor instanceof PlayerActor || $actor->uid() !== $uid) {
                continue;
            }

            $connId = $actor->connectionId();
            $conn = $connId !== null ? ($this->connections[$connId] ?? null) : null;
            if ($conn === null) {
                continue;
            }

            $conn->close();
            $closed++;
        }

        return $closed;
    }

    /** 服务状态快照实现（GmStatusProviderInterface）：serviceId/mapId/在线数/连接数标量键值对。 Status-snapshot implementation (GmStatusProviderInterface): scalar key-value pairs of serviceId/mapId/player count/connection count. */
    public function status(): array
    {
        return [
            'serviceId' => $this->serviceId,
            'mapId' => $this->mapId,
            'playerCount' => $this->playerCount,
            'connections' => count($this->connections),
        ];
    }

    /** Actor 查找实现（ActorLookupInterface）：以 $actors 表按 entityId 返回已登记 Actor。 Actor-lookup implementation: returns the registered actor for an entityId from the actors table. */
    public function getActor(string $entityId): ?ActorInterface
    {
        return $this->actors[$entityId] ?? null;
    }

    /**
     * 登记 Actor（房间装配层用）：房内直入刷怪的 MonsterActor 进入 $actors 表，AoE 命中结算经
     * ActorLookupInterface 才能解析到目标；与 removeActor（死亡自清理）对称。
     * Registers an actor (the room assembly layer's use): directly-spawned in-room MonsterActors enter the actors
     * table so AoE hit settlement can resolve them via the ActorLookupInterface; symmetric with removeActor (death self-cleanup).
     */
    public function registerActor(string $entityId, ActorInterface $actor): void
    {
        $this->actors[$entityId] = $actor;
    }

    /** Actor 摘除实现（ActorLookupInterface）：怪物死亡自清理时把 MonsterActor 从 $actors 表移除（修复 MINOR-2 内存泄漏）。 Actor-removal implementation: monster-death self-cleanup removes the MonsterActor from the actors table (fixes the MINOR-2 leak). */
    public function removeActor(string $entityId): void
    {
        unset($this->actors[$entityId]);
    }

    /**
     * 目标离场通知（R4 CHASE 卡滞修复）：实体离开当前容器（transfer 进房等）时遍历 $actors 表，
     * 把以该实体为追击/攻击目标的 MonsterActor 全部放弃目标回 PATROL——目标 Actor 仍在共享表可跨容器解析，
     * 不通知则世界怪 CHASE 原地卡滞（moveTowardTarget 对缺失实体 no-op）。
     * Target-left notification (the R4 CHASE-stall fix): when an entity leaves its current container (e.g. a room
     * transfer), walk the actors table and make every MonsterActor targeting it drop the target back to PATROL —
     * the target actor stays resolvable cross-container via the shared table, so without this notice world monsters
     * stall in CHASE in place (moveTowardTarget no-ops on a missing entity).
     */
    public function notifyTargetLeft(string $entityId): void
    {
        foreach ($this->actors as $actor) {
            if ($actor instanceof MonsterActor) {
                $actor->onTargetLeft($entityId);
            }
        }
    }

    // ── 连接容器维度编排代理（ADR-024 §9 V6，RoomHub 编排入口） ──
    // Connection-container orchestration proxies (ADR-024 §9 V6, the RoomHub orchestration entry)

    /**
     * 按连接 id 标记当前容器：join 入房后标记到房间；null = 回落宿主世界。registry 代理薄封装。
     * Marks the current container by connection id: marked to the room after join; null = host-world fallback.
     * A thin proxy over the registry.
     */
    public function moveToContainer(string $connId, ?object $container): void
    {
        $this->registry->moveToContainer($connId, $container);
    }

    /**
     * 按实体 id 标记其连接的当前容器：实体无连接（已断连）静默跳过。
     * 房间销毁回填世界等按成员遍历的编排路径使用（成员表以实体为键）。
     * Marks the current container of an entity's connection by entity id; silently skipped when the entity has no
     * connection (already disconnected). Used by orchestration paths that iterate members (room-destroy world
     * back-fill), where the member table is keyed by entity.
     */
    public function moveEntityToContainer(string $entityId, ?object $container): void
    {
        $connId = $this->registry->getConnectionId($entityId);
        if ($connId === null) {
            return;
        }

        $this->registry->moveToContainer($connId, $container);
    }

    /** 从 serviceId 编码解析 channelId（约定 {mapId}#{channelId}，以最后一个 # 切分；无 # 时整体视为 channelId）。 Parses channelId from the serviceId encoding (split at the last #). */
    private function channelId(): string
    {
        $hash = strrpos($this->serviceId, '#');

        return $hash === false ? $this->serviceId : substr($this->serviceId, $hash + 1);
    }

    // ── 战斗路由 ──
    // Combat routing

    /**
     * move 路由分流（ADR-024 §9 V6）：无容器记录的连接走父类世界模板（行为逐字节等价）；
     * 有容器记录（已 join 进房）走房间上下文的同构移动路径——房内 EM 解析实体、房内 AOI 广播
     * 移动帧，分支顺序（401 → 400 → 500）与父类模板一致。反作弊校验（R4 MINOR-7 债务关闭）：
     * 房内分支复用与世界模板同一 MovementValidator 实例（阈值与窗口状态单一来源），拒绝口径一致
     * （403 error 帧 + 坐标零副作用）。
     * Move-route split (ADR-024 §9 V6): connections without a container record take the parent world template
     * (byte-for-byte equivalent behavior); connections with one (joined into a room) take the isomorphic in-room
     * path — the entity resolves from the room EM and the move frame broadcasts over the room AOI, with the branch
     * order (401 → 400 → 500) matching the parent template. Anti-cheat validation (the R4 MINOR-7 debt closed):
     * the in-room branch reuses the same MovementValidator instance as the world template (thresholds and window
     * state keep a single source of truth) with an identical rejection contract (a 403 error frame, zero side effects).
     */
    /**
     * 回填房间指标提供者（P9c 准入）：组装层把 RoomInstanceManager 的房间数/顺延数接入心跳元数据，
     * registry 侧（网关/匹配）据此做 busy 判定与路由规避。缺省 null = 不上报。
     * Back-fills the room-metrics provider (the P9c admission): the assembly layer feeds the
     * RoomInstanceManager's room/deferral counts into the heartbeat metadata so registry consumers
     * (gateway/matching) can adjudicate busy and route around. Default null = not reported.
     */
    public function setRoomMetricsProvider(?callable $provider): void
    {
        $this->roomMetricsProvider = $provider;
    }

    /**
     * 移动广播节流（P9b 覆写）：热区分频下，实体移动广播只在到期 tick 发（tickCounter % divisor === 0）——
     * O(N²) 聚团流量的主要砍口；位置照常应用，被跳过的中间帧由视野快照重同步（1s 周期）/后续移动补发，
     * 语义为「保留最新位置」而非丢帧。
     * The move-broadcast throttle (the P9b override): under hot-cell cadence, an entity's move broadcasts go
     * out only on due ticks (tickCounter % divisor === 0) — the main cut for O(N²) clustering traffic; positions
     * still apply, and skipped intermediate frames are reconciled by the vision-snapshot resync (1s period) or a
     * later move — "keep the latest position" semantics, not frame loss.
     */
    protected function shouldBroadcastMove(string $entityId): bool
    {
        $actor = $this->actors[$entityId] ?? null;
        if (!$actor instanceof BasePlayer && !$actor instanceof MonsterActor) {
            return true;
        }
        $divisor = $actor->tickDivisor();

        return $divisor <= 1 || $this->tickCounter % $divisor === 0;
    }

    private function handleMoveRouted(ConnectionInterface $conn, Message $message): void
    {
        $context = $this->registry->resolveContainerContext($conn->getId(), $this->world);
        if ($context['container'] === null) {
            $this->handleMove($conn, $message);

            return;
        }

        $entityId = $this->registry->getEntityId($conn->getId());
        if ($entityId === null) {
            $this->send($conn, Message::create('error', ['code' => 401, 'message' => 'unauthorized'], $message->requestId));
            $conn->close();

            return;
        }

        $dx = $message->payload['dx'] ?? 0;
        $dy = $message->payload['dy'] ?? 0;
        if (!is_int($dx) || !is_int($dy)) {
            $this->send($conn, Message::create('error', ['code' => 400, 'message' => 'dx/dy 必须是整数'], $message->requestId));

            return;
        }

        $entity = $context['entityManager']->get($entityId);
        if ($entity === null) {
            $this->send($conn, Message::create('error', ['code' => 500, 'message' => 'entity not found'], $message->requestId));
            $conn->close();

            return;
        }

        // 反作弊钩子（MINOR-7 债务关闭）：与世界模板同一 validator 实例；校验失败回 403 并保留实体坐标
        // （拒绝即无副作用），拒绝口径与父类 handleMove 模板逐字段一致
        // The anti-cheat hook (the MINOR-7 debt closed): the same validator instance as the world template; a failed
        // validation replies 403 and keeps the entity coordinates untouched (a rejection has no side effects), field-for-field
        // identical to the parent handleMove template's rejection contract
        if ($this->movementValidator !== null) {
            $position = $entity->getPosition();
            $reason = $this->movementValidator->validate($entityId, $dx, $dy, $position['x'], $position['y'], microtime(true));
            if ($reason !== null) {
                $this->send($conn, Message::create('error', ['code' => 403, 'message' => sprintf('move rejected: %s', $reason)], $message->requestId));

                return;
            }
        }

        // 先改容器状态再广播：房内邻居拿到的 position 才是最新值；AOI 索引不动，位置更新交给房间 update 全量刷新
        // Mutate the container state before broadcasting so in-room neighbours always see the latest position; the AOI
        // index is untouched here — position updates go through the room update's full sweep
        $entity->move($dx, $dy);
        if ($this->shouldBroadcastMove($entityId)) {
            $this->broadcastToViewIn($context['aoi'], $entity, 'entity_moved', [
                'id' => $entityId,
                'position' => $entity->getPosition(),
            ]);
        }
    }

    /**
     * 视野广播（AOI 参数化）：向 center 在给定视野提供者下的视野内实体连接入 outbox。
     * 与 RealtimeServer::broadcastToView 同构，AOI 由调用方按实体所在容器选取（V6 容器化路由）；
     * 状态帧语义 skipSelf=true 由调用方传入。
     * View broadcast (AOI-parameterized): enqueues frames to the connections of every entity inside center's view
     * under the given view provider. Isomorphic to RealtimeServer::broadcastToView, with the AOI picked by the caller
     * per the entity's container (V6 containerized routing); callers pass skipSelf=true for STATE-frame semantics.
     *
     * @param array<string, mixed> $payload 帧负载 Frame payload.
     */
    private function broadcastToViewIn(AOIProviderInterface $aoi, EntityInterface $center, string $type, array $payload, bool $skipSelf = true): void
    {
        foreach ($aoi->query($center) as $other) {
            if ($skipSelf && $other->getId() === $center->getId()) {
                continue;
            }

            $connId = $this->registry->getConnectionId($other->getId());
            if ($connId === null) {
                continue;
            }

            $conn = $this->connections[$connId] ?? null;
            if ($conn === null) {
                continue;
            }

            $this->frameMerger->enqueue($conn, $type, $payload);
        }
    }

    /**
     * 普攻路由：前置校验（攻击方为玩家、目标有效且存活、非自身、视野距离、攻击冷却）全过后 combat->attack 结算。
     * Attack route: every precondition passes (attacker is a player, target valid and alive, not self, in-view distance,
     * attack cooldown), then combat->attack settles.
     */
    private function handleAttack(ConnectionInterface $conn, Message $message): void
    {
        $resolved = $this->resolveCombatant($conn, $message);
        if ($resolved === null) {
            return; // 前置失败已定向 combat:error Preconditions failed; a directed combat:error was already sent.
        }

        [$entityId, $player, $target] = $resolved;

        // PVP 对抗门（P13 对抗治理）：玩家间攻击在结算前置位拒绝（无副作用：攻击冷却未启动）——
        // pvp_disabled（治理开关缺省关闭）/in_safe_zone（任一方在出生安全区内）/spawn_protected
        // （受击方出生保护期）。PVE（怪物目标）不治理， MonsterActor 侧 safeZone 门自洽。
        // The PVP combat gate (the P13 governance): player-vs-player attacks are rejected pre-settlement
        // (side-effect-free: the attack cooldown has not started) — pvp_disabled (the governance switch,
        // off by default) / in_safe_zone (either party inside the spawn safe zone) / spawn_protected (the
        // victim inside spawn protection). PVE (monster targets) is ungoverned — MonsterActor's own safeZone
        // gate stays self-consistent.
        $pvpRejection = $this->pvpRejection($player, $target);
        if ($pvpRejection !== null) {
            $this->sendToEntity($entityId, 'combat:error', ['code' => $pvpRejection, 'message' => 'PVP 治理拒绝（' . $pvpRejection . '）']);

            return;
        }

        $combat = $this->resolveCombatService($conn->getId());
        if ($combat === null) {
            $this->sendToEntity($entityId, 'combat:error', ['code' => 'not_ready', 'message' => '战斗服务未就绪']);

            return;
        }

        $combat->attack($player, $target);
        $player->startAttackCooldown();
    }

    /**
     * 技能施放路由：同 attack 前置 + skillId 经 SkillRepository 校验 → 技能冷却表校验（R3 玩法批收编：
     * per-skill 秒制冷却，未装配表时跳过）→ combat->castSkill 结算 → 置冷。
     * Skill-cast route: the attack preconditions plus a SkillRepository check → the skill-cooldown-table check
     * (absorbed in the R3 gameplay batch: per-skill second-based cooldowns, skipped when no table is assembled)
     * → combat->castSkill settles → chill down.
     */
    private function handleSkillCast(ConnectionInterface $conn, Message $message): void
    {
        $resolved = $this->resolveCombatant($conn, $message);
        if ($resolved === null) {
            return;
        }

        [$entityId, $player, $target] = $resolved;

        // PVP 对抗门（P13）：单体技能与普攻同口径（结算前置位拒绝，冷却未启动）；AoE 路径经
        // CombatService 的 pvpGate 逐目标门控（被挡目标静默跳过，不出现在 combat:aoe 命中列表）。
        // The PVP combat gate (the P13): single-target skills match the normal-attack convention (pre-settlement
        // rejection, cooldowns not started); the AoE path is gated per-target via CombatService's pvpGate
        // (gate-rejected targets are silently skipped, absent from the combat:aoe hit list).
        $pvpRejection = $this->pvpRejection($player, $target);
        if ($pvpRejection !== null) {
            $this->sendToEntity($entityId, 'combat:error', ['code' => $pvpRejection, 'message' => 'PVP 治理拒绝（' . $pvpRejection . '）']);

            return;
        }

        $skillId = $message->payload['skillId'] ?? null;
        $definition = is_string($skillId) && $skillId !== '' ? $this->skills?->get($skillId) : null;
        if ($definition === null) {
            $this->sendToEntity($entityId, 'combat:error', ['code' => 'invalid_skill', 'message' => '技能不存在或未注册']);

            return;
        }

        // 技能距离门（P7a）：与 AoE 路径（P6c）统一为「技能距离」裁决层——此前单体路径的距离约束由
        // 视野距离（resolveCombatant）承担，definition->range 从未被消费；施法者到目标超 range 即
        // out_of_range 拒绝（无副作用：冷却/攻击冷却均未启动）。
        // The skill-distance gate (the P7a): unified with the AoE path's (the P6c) into one "skill distance"
        // adjudication layer — the single-target path's distance was previously ruled by the view distance
        // (resolveCombatant) and definition->range was never consumed; a caster beyond the target's range is
        // rejected with out_of_range (side-effect-free: neither cooldown has started).
        $context = $this->registry->resolveContainerContext($conn->getId(), $this->world);
        $casterEntity = $context['entityManager']->get($entityId);
        $targetId = $message->payload['targetId'] ?? null;
        $targetEntity = is_string($targetId) ? $context['entityManager']->get($targetId) : null;
        if ($casterEntity === null || $targetEntity === null) {
            $this->sendToEntity($entityId, 'combat:error', ['code' => 'not_ready', 'message' => '施法/目标实体不存在']);

            return;
        }
        $origin = $casterEntity->getPosition();
        $destination = $targetEntity->getPosition();
        $distance = hypot($destination['x'] - $origin['x'], $destination['y'] - $origin['y']);
        if ($distance > $definition->range) {
            $this->sendToEntity($entityId, 'combat:error', [
                'code' => 'out_of_range',
                'message' => sprintf('施法距离超出（%.1f > %d）', $distance, $definition->range),
            ]);

            return;
        }

        $cooldowns = $this->cooldowns;
        if ($cooldowns !== null && !$cooldowns->isReady($entityId, $skillId, microtime(true))) {
            $this->sendToEntity($entityId, 'combat:error', [
                'code' => 'cooldown',
                'message' => sprintf('技能冷却中（剩余 %.1fs）', $cooldowns->remaining($entityId, $skillId, microtime(true))),
            ]);

            return;
        }

        $combat = $this->resolveCombatService($conn->getId());
        if ($combat === null) {
            $this->sendToEntity($entityId, 'combat:error', ['code' => 'not_ready', 'message' => '战斗服务未就绪']);

            return;
        }

        $combat->castSkill($player, (string) $skillId, $target);
        $cooldowns?->start($entityId, (string) $skillId, $definition->cooldownSeconds, microtime(true));
        $player->startAttackCooldown();

        // 嘲讽结算（P4b 接入，关闭 P1 预留）：技能定义声明 tauntThreat > 0 且目标为怪物 Actor 时，
        // 把嘲讽威胁量写入目标威胁表（tauntMultiplier 倍率裁决归 ThreatTable::applyTaunt；未启用威胁表的
        // 怪物零操作）。非怪物目标静默无效果（技能伤害照常结算）。施法者的攻击冷却在伤害结算后启动，
        // 嘲讽威胁立即生效、下一个攻击 tick 的 aggro 切换即选中嘲讽者。
        // Taunt settlement (the P4b wiring, closing the P1 reservation): when the skill definition declares
        // tauntThreat > 0 and the target is a monster actor, the taunt amount is written into the target's threat
        // table (the tauntMultiplier adjudication stays inside ThreatTable::applyTaunt; monsters without a threat
        // table no-op). Non-monster targets silently get no effect (the skill damage still settles). The caster's
        // attack cooldown starts after the damage settlement, the taunt threat applies immediately, and the next
        // attack tick's aggro switch picks the taunter.
        if ($definition->tauntThreat > 0.0 && $target instanceof MonsterActor) {
            $target->applyTaunt($entityId, $definition->tauntThreat);
        }
    }

    /**
     * AoE 施法路由（P5c 接入）：{skillId, cx, cy, r} → 技能定义须声明 AoE 能力（aoe ≠ null）→
     * CombatService::castSkillAoE（形状为世界绝对坐标；施法距离门（P6c）：中心距施法者超 definition->range
     * 即 out_of_range 拒绝）→ 冷却/攻击冷却结算与单体路径同口径 → tauntThreat > 0 时对命中怪物逐一写入
     * 嘲讽威胁（与单体路径同裁决：tauntMultiplier 倍率归威胁表）。combat:aoe 合并广播由 castSkillAoE 内闭环。
     * The AoE skill-cast route (the P5c wiring): {skillId, cx, cy, r} → the skill definition must declare AoE
     * capability (aoe ≠ null) → CombatService::castSkillAoE (the shape is world-absolute; the cast-distance gate
     * (the P6c) rejects a center beyond definition->range from the caster with out_of_range) → cooldown/attack-
     * cooldown settlement matches the single-target path → with tauntThreat > 0 every hit monster gets the taunt
     * threat written (same adjudication as the single-target path: the tauntMultiplier belongs to the threat
     * table). The merged combat:aoe broadcast closes inside castSkillAoE.
     */
    private function handleSkillCastAoE(ConnectionInterface $conn, Message $message): void
    {
        $entityId = $this->registry->getEntityId($conn->getId());
        if ($entityId === null) {
            $this->send($conn, Message::create('error', ['code' => 401, 'message' => 'unauthorized'], $message->requestId));
            $conn->close();

            return;
        }

        $player = $this->actors[$entityId] ?? null;
        if (!$player instanceof PlayerActor) {
            $this->send($conn, Message::create('error', ['code' => 401, 'message' => 'unauthorized'], $message->requestId));
            $conn->close();

            return;
        }

        $skillId = $message->payload['skillId'] ?? null;
        $cx = $message->payload['cx'] ?? null;
        $cy = $message->payload['cy'] ?? null;
        if (!is_string($skillId) || $skillId === '' || !is_int($cx) || !is_int($cy)) {
            $this->sendToEntity($entityId, 'combat:error', ['code' => 'invalid_target', 'message' => 'payload 缺少/非法 skillId/cx/cy 字段']);

            return;
        }

        $definition = $this->skills?->get($skillId);
        if ($definition === null) {
            $this->sendToEntity($entityId, 'combat:error', ['code' => 'invalid_skill', 'message' => '技能不存在或未注册']);

            return;
        }
        if ($definition->aoe === null) {
            $this->sendToEntity($entityId, 'combat:error', ['code' => 'invalid_skill', 'message' => '技能不支持 AoE 施法（定义未声明形状参数）']);

            return;
        }

        // 形状构造（P8b）：按定义声明的形状键构造引擎形状值对象——circle 消费 payload r（半径，形状中心
        // 即圆心）；rect 消费 payload w/h（宽高，cx/cy 为几何中心，RectangleShape 锚点为最小角：
        // anchor = center - half，整除向下）。形状键与 payload 参数不匹配均 invalid_target/invalid_skill 拒绝。
        // The shape construction (the P8b): the engine shape value object is built by the declared shape key —
        // circle consumes the payload r (radius, the shape center doubles as the circle center); rect consumes the
        // payload w/h (extents, cx/cy as the geometric center while a RectangleShape anchors at its min corner:
        // anchor = center - half, floor-divided). Mismatched shape keys or payload params are rejected with
        // invalid_target/invalid_skill.
        $shape = null;
        if ($definition->aoe['shape'] === SkillDefinition::SHAPE_CIRCLE) {
            $r = $message->payload['r'] ?? null;
            if (!is_int($r) || $r < 0) {
                $this->sendToEntity($entityId, 'combat:error', ['code' => 'invalid_target', 'message' => 'payload 缺少/非法 r 字段（圆形形状）']);

                return;
            }
            $shape = new CircleShape($cx, $cy, $r);
        } elseif ($definition->aoe['shape'] === SkillDefinition::SHAPE_RECT) {
            $w = $message->payload['w'] ?? null;
            $h = $message->payload['h'] ?? null;
            if (!is_int($w) || $w < 0 || !is_int($h) || $h < 0) {
                $this->sendToEntity($entityId, 'combat:error', ['code' => 'invalid_target', 'message' => 'payload 缺少/非法 w/h 字段（矩形形状）']);

                return;
            }
            $shape = new RectangleShape($cx - intdiv($w, 2), $cy - intdiv($h, 2), $w, $h);
        } else {
            $this->sendToEntity($entityId, 'combat:error', ['code' => 'invalid_skill', 'message' => '未支持的 AoE 形状声明']);

            return;
        }

        // 施法距离门（P6c）：形状为世界绝对坐标，此前施法者可在任意远处施放（P5 遗留债务）——形状中心
        // 距施法者超过技能定义 range 即拒（out_of_range，无副作用：冷却/攻击冷却均未启动）。
        // The cast-distance gate (the P6c): the shape is world-absolute, so a caster could previously cast from
        // anywhere (the P5 debt) — a shape center beyond the skill definition's range from the caster is rejected
        // (out_of_range, side-effect-free: neither cooldown has started).
        $context = $this->registry->resolveContainerContext($conn->getId(), $this->world);
        $caster = $context['entityManager']->get($entityId);
        if ($caster === null) {
            $this->sendToEntity($entityId, 'combat:error', ['code' => 'not_ready', 'message' => '施法实体不存在']);

            return;
        }
        $origin = $caster->getPosition();
        $distance = hypot($cx - $origin['x'], $cy - $origin['y']);
        if ($distance > $definition->range) {
            $this->sendToEntity($entityId, 'combat:error', [
                'code' => 'out_of_range',
                'message' => sprintf('施法距离超出（%.1f > %d）', $distance, $definition->range),
            ]);

            return;
        }

        $cooldowns = $this->cooldowns;
        if ($cooldowns !== null && !$cooldowns->isReady($entityId, $skillId, microtime(true))) {
            $this->sendToEntity($entityId, 'combat:error', [
                'code' => 'cooldown',
                'message' => sprintf('技能冷却中（剩余 %.1fs）', $cooldowns->remaining($entityId, $skillId, microtime(true))),
            ]);

            return;
        }

        $combat = $this->resolveCombatService($conn->getId());
        if ($combat === null) {
            $this->sendToEntity($entityId, 'combat:error', ['code' => 'not_ready', 'message' => '战斗服务未就绪']);

            return;
        }

        $hits = $combat->castSkillAoE($player, $skillId, $shape);
        $cooldowns?->start($entityId, $skillId, $definition->cooldownSeconds, microtime(true));
        $player->startAttackCooldown();

        // 嘲讽结算（P5c）：AoE 命中怪物逐一写入嘲讽威胁——多目标嘲讽语义：形状内全部怪物被嘲讽者拉取
        // Taunt settlement (the P5c): every monster hit by the AoE gets the taunt threat — multi-target taunt
        // semantics: all monsters inside the shape are pulled by the taunter.
        if ($definition->tauntThreat > 0.0) {
            foreach ($hits as $hit) {
                $target = $this->getActor((string) $hit['targetId']);
                if ($target instanceof MonsterActor) {
                    $target->applyTaunt($entityId, $definition->tauntThreat);
                }
            }
        }
    }

    /**
     * 拾取路由：dropId 经攻击方所在容器的 entityManager 解析为 DropEntity（附视野距离校验）→ combat->pickup →
     * archive?->markDirty(uid, inventory)。容器解析按 registry 容器维度（ADR-024 §9 V6）：无记录回落宿主世界，
     * 世界侧路径与容器化前逐字节等价。
     * Pickup route: dropId resolved to a DropEntity via the attacker's container entityManager (with an in-view range
     * check) → combat->pickup → archive?->markDirty(uid, inventory). Container resolution follows the registry's
     * container dimension (ADR-024 §9 V6): no record falls back to the host world, byte-for-byte equivalent to the
     * pre-containerization world path.
     */
    private function handlePickup(ConnectionInterface $conn, Message $message): void
    {
        $connId = $conn->getId();
        $entityId = $this->registry->getEntityId($connId);
        if ($entityId === null) {
            $this->send($conn, Message::create('error', ['code' => 401, 'message' => 'unauthorized'], $message->requestId));
            $conn->close();

            return;
        }

        $player = $this->actors[$entityId] ?? null;
        if (!$player instanceof PlayerActor) {
            $this->send($conn, Message::create('error', ['code' => 401, 'message' => 'unauthorized'], $message->requestId));
            $conn->close();

            return;
        }

        $dropId = $message->payload['dropId'] ?? null;
        if (!is_string($dropId) || $dropId === '') {
            $this->sendToEntity($entityId, 'combat:error', ['code' => 'invalid_target', 'message' => 'payload 缺少 dropId 字段']);

            return;
        }

        $context = $this->registry->resolveContainerContext($connId, $this->world);
        $dropEntity = $context['entityManager']->get($dropId);
        if (!$dropEntity instanceof DropEntity) {
            $this->sendToEntity($entityId, 'combat:error', ['code' => 'invalid_target', 'message' => '掉落物不存在']);

            return;
        }

        $playerEntity = $context['entityManager']->get($entityId);
        if ($playerEntity === null) {
            // 容器解析后玩家实体仍缺失（既不在世界也不在所记容器）：500+断连兜底，不静默吞帧（比照 move 模板）
            // The player entity is missing even after container resolution (in neither the world nor the recorded
            // container): the 500+disconnect backstop, never silently swallowing frames (mirroring the move template)
            $this->send($conn, Message::create('error', ['code' => 500, 'message' => 'entity not found'], $message->requestId));
            $conn->close();

            return;
        }
        if (!$this->isNeighborIn($context['aoi'], $playerEntity, $dropId)) {
            $this->sendToEntity($entityId, 'combat:error', ['code' => 'out_of_range', 'message' => '掉落物不在拾取范围内']);

            return;
        }

        $combat = $this->resolveCombatService($connId);
        if ($combat === null) {
            $this->sendToEntity($entityId, 'combat:error', ['code' => 'not_ready', 'message' => '战斗服务未就绪']);

            return;
        }

        $inventory = $this->inventories[$entityId] ??= new Inventory();
        $combat->pickup($player, $dropEntity, $inventory);

        if ($this->archive !== null && $player->uid() !== null) {
            $this->archive->markDirty($player->uid(), ['inventory' => $inventory->all()]);
        }
    }

    /**
     * 登出处理（A-6 落库断链修复）：取 PlayerActor uid 立即冲刷标脏背包后关闭连接——客户端主动登出是强制同步点。
     * Logout handling (A-6 persistence-chain fix): flushes the dirty inventory immediately by the PlayerActor uid, then
     * closes the connection — an explicit client logout is a forced sync point.
     */
    private function handleLogout(ConnectionInterface $conn): void
    {
        $entityId = $this->registry->getEntityId($conn->getId());
        if ($entityId === null) {
            $conn->close();

            return;
        }

        $actor = $this->actors[$entityId] ?? null;
        if ($actor instanceof PlayerActor && $actor->uid() !== null) {
            $this->archive?->flushId($actor->uid());
        }

        $conn->close();
    }

    /**
     * PVP 对抗门（P13 对抗治理）：裁决一次攻击/施法是否被治理规则拒绝——
     * ① mmorpg 未装配：null（维持接入前语义，零治理）；
     * ② 非玩家对（PVE / 怪物攻击玩家）：null（怪物侧 safeZone 门已在 MonsterActor 自洽）；
     * ③ pvpEnabled = false：'pvp_disabled'（治理裁决缺省关闭——接入前玩家间攻击事实上可行，
     *   安全区/出生保护语义齐备后由部署显式开启）；
     * ④ 任一方在出生安全区内：'in_safe_zone'（区外才可 PVP，safeZone 消费扩展到玩家间攻击）；
     * ⑤ 受击方处于出生保护期：'spawn_protected'（与怪物感知/攻击跳过同口径）。
     * The PVP combat gate (the P13 governance): rules whether an attack/cast is rejected by governance —
     * ① mmorpg unassembled: null (the pre-integration semantics, zero governance);
     * ② non-player pairs (PVE / monsters attacking players): null (the monster-side safeZone gate is
     *   already self-consistent inside MonsterActor);
     * ③ pvpEnabled = false: 'pvp_disabled' (the governance ruling defaults off — PVP was de-facto possible
     *   pre-integration, deployments opt in explicitly now that safe-zone/spawn-protection semantics exist);
     * ④ either party inside the spawn safe zone: 'in_safe_zone' (PVP only outside the zone — the safeZone
     *   consumption extended to player-vs-player attacks);
     * ⑤ the victim inside spawn protection: 'spawn_protected' (matching the monster perception/attack skip).
     */
    public function pvpRejection(Damageable $attacker, Damageable $target): ?string
    {
        $mmorpg = $this->mmorpg;
        if ($mmorpg === null || !$attacker instanceof PlayerActor || !$target instanceof PlayerActor) {
            return null;
        }
        if (!$mmorpg->pvpEnabled) {
            return 'pvp_disabled';
        }

        $zone = $mmorpg->safeZone;
        if ($zone !== null) {
            foreach ([$attacker, $target] as $combatant) {
                $position = $this->world->getEntityManager()->get($combatant->getPlayerId())?->getPosition();
                if ($position !== null
                    && hypot($position['x'] - $zone['x'], $position['y'] - $zone['y']) <= $zone['radius']) {
                    return 'in_safe_zone';
                }
            }
        }

        return $target->isSpawnProtected() ? 'spawn_protected' : null;
    }

    /**
     * 死亡掉落结算（P13 死亡掉落归属）：玩家死亡时按 DeathDropPolicy 从背包逐单位 roll 掉落——
     * 绑定物品跳过、单次死亡条目上限封顶；掉落单位即从背包扣除（同帧一致）并归档标脏；
     * DropEntity 经 spawnDrops 生成（归属窗口覆盖为策略 ownerWindowSeconds，killerUid 归属绑定 +
     * not_owner 拾取保护复用既有机制，零新协议）。击杀者 uid 为 null（怪物反杀）时掉落无归属，
     * 窗口语义退化为「到期前全场不可拾取→ 过期自由拾取」之外的自由拾取——与怪物掉落口径一致。
     * Death-drop settlement (the P13 death-drop ownership): on player death the inventory drops per the
     * DeathDropPolicy with per-unit rolls — bound items skipped, capped at the per-death entry limit; dropped
     * units are deducted from the inventory in the same beat (consistency) and the archive is marked dirty;
     * DropEntities spawn via spawnDrops (the ownership-window override = the policy's ownerWindowSeconds,
     * killerUid binding + the not_owner pickup protection reused — zero new protocol). A null killer uid
     * (killed by a monster) yields unowned drops, free pickup — matching the monster-drop convention.
     *
     * @param string $victimId 死者实体 id The victim's entity id.
     * @param ?string $killerUid 击杀者 uid（归属绑定；null = 无归属自由拾取） The killer's uid (ownership binding; null = unowned free pickup).
     * @return int 掉落条目数（0 = 策略关闭/空背包/全部绑定/未命中概率） The dropped-kind count (0 = policy off / empty inventory / all bound / probability missed).
     */
    public function dropInventoryOnDeath(string $victimId, ?string $killerUid): int
    {
        $policy = $this->mmorpg?->deathDrop;
        $combat = $this->combatService;
        $inventory = $this->inventories[$victimId] ?? null;
        if ($policy === null || $combat === null || $inventory === null) {
            return 0;
        }

        $position = $this->world->getEntityManager()->get($victimId)?->getPosition() ?? ['x' => 0, 'y' => 0];
        $drops = [];
        foreach ($inventory->all() as $itemId => $count) {
            if (count($drops) >= $policy->maxDropsPerDeath) {
                break; // 条目上限封顶（遍历序即背包序） Capped at the entry limit (the traversal order is the inventory order).
            }
            if (in_array((string) $itemId, $policy->boundItemIds, true) || $count < 1) {
                continue;
            }
            // 逐单位独立 roll（产品草案口径：每计数单位以 dropRatioPercent 概率掉落）
            // Per-unit independent rolls (the product draft: every count unit drops with dropRatioPercent probability).
            $dropCount = 0;
            for ($i = 0; $i < $count; $i++) {
                if ($this->random->randomInt(1, 100) <= $policy->dropRatioPercent) {
                    ++$dropCount;
                }
            }
            if ($dropCount > 0) {
                $drops[] = ['itemId' => (string) $itemId, 'count' => $dropCount];
            }
        }
        if ($drops === []) {
            return 0;
        }

        // 掉落单位即扣包（同帧一致：掉落与扣包同一次结算内完成），归档标脏走既有拾取口径
        // Dropped units are deducted immediately (same-beat consistency: the drop and the deduction complete
        // in one settlement); the archive dirty-marking mirrors the existing pickup convention.
        foreach ($drops as $drop) {
            $inventory->remove($drop['itemId'], $drop['count']);
        }
        $victim = $this->actors[$victimId] ?? null;
        if ($victim instanceof PlayerActor && $victim->uid() !== null) {
            $this->archive?->markDirty($victim->uid(), ['inventory' => $inventory->all()]);
        }

        $combat->spawnDrops($victimId, $position, $drops, $killerUid, $policy->ownerWindowSeconds);

        return count($drops);
    }

    /**
     * 战斗前置校验公共路径（attack/skill:cast 共用）：解析攻击方实体与 Actor、目标 id、非自身、目标有效且存活、
     * 视野距离、攻击冷却。全部通过返回 [entityId, PlayerActor, Damageable]；任一失败定向 combat:error 并返回 null。
     * 实体解析与视野判定走攻击方所在容器（registry 容器维度，ADR-024 §9 V6）：无记录回落宿主世界，世界侧
     * 路径与容器化前逐字节等价；目标 Actor 经共享 $actors 表解析可跨容器命中——世界玩家攻击房内实体时
     * isNeighbor 用攻击方（世界）AOI 查不到房内实体，out_of_range 天然拒绝（跨容器误伤防护语义）。
     * Shared combat precondition path (attack/skill:cast): resolves the attacker entity and actor, the target id, no-self,
     * a valid alive target, in-view distance and the attack cooldown. On success returns [entityId, PlayerActor, Damageable];
     * any failure sends a directed combat:error and returns null. Entity resolution and view checks run in the attacker's
     * container (the registry container dimension, ADR-024 §9 V6): no record falls back to the host world, byte-for-byte
     * equivalent to the pre-containerization world path; the target actor resolves through the shared actors table and may
     * hit cross-container — a world player attacking an in-room entity misses it via the attacker's (world) AOI, so
     * out_of_range rejects naturally (the cross-container friendly-fire guard semantics).
     *
     * @return array{0: string, 1: PlayerActor, 2: Damageable}|null 校验通过的三元组，失败为 null The validated tuple, or null on failure.
     */
    private function resolveCombatant(ConnectionInterface $conn, Message $message): ?array
    {
        $connId = $conn->getId();
        $entityId = $this->registry->getEntityId($connId);
        if ($entityId === null) {
            $this->send($conn, Message::create('error', ['code' => 401, 'message' => 'unauthorized'], $message->requestId));
            $conn->close();

            return null;
        }

        $attacker = $this->actors[$entityId] ?? null;
        if (!$attacker instanceof PlayerActor) {
            $this->send($conn, Message::create('error', ['code' => 401, 'message' => 'unauthorized'], $message->requestId));
            $conn->close();

            return null;
        }

        $targetId = $message->payload['targetId'] ?? null;
        if (!is_string($targetId) || $targetId === '') {
            $this->sendToEntity($entityId, 'combat:error', ['code' => 'invalid_target', 'message' => 'payload 缺少 targetId 字段']);

            return null;
        }

        if ($targetId === $entityId) {
            $this->sendToEntity($entityId, 'combat:error', ['code' => 'invalid_target', 'message' => '不能攻击自己']);

            return null;
        }

        $target = $this->getActor($targetId);
        if (!$target instanceof Damageable || $target->isDead()) {
            $this->sendToEntity($entityId, 'combat:error', ['code' => 'invalid_target', 'message' => '目标无效或已死亡']);

            return null;
        }

        $context = $this->registry->resolveContainerContext($connId, $this->world);
        $attackerEntity = $context['entityManager']->get($entityId);
        if ($attackerEntity === null) {
            // 容器解析后攻击方实体仍缺失（既不在世界也不在所记容器）：500+断连兜底，不静默吞帧（比照 move 模板）
            // The attacker entity is missing even after container resolution (in neither the world nor the recorded
            // container): the 500+disconnect backstop, never silently swallowing frames (mirroring the move template)
            $this->send($conn, Message::create('error', ['code' => 500, 'message' => 'entity not found'], $message->requestId));
            $conn->close();

            return null;
        }
        if (!$this->isNeighborIn($context['aoi'], $attackerEntity, $targetId)) {
            $this->sendToEntity($entityId, 'combat:error', ['code' => 'out_of_range', 'message' => '目标不在攻击范围内']);

            return null;
        }

        if (!$attacker->isAttackReady()) {
            $this->sendToEntity($entityId, 'combat:error', ['code' => 'cooldown', 'message' => '攻击冷却中']);

            return null;
        }

        return [$entityId, $attacker, $target];
    }

    /**
     * 解析结算用战斗服务（ADR-024 §9 V6）：攻击方有容器记录（已 join 进房）时用房间的
     * CombatService——房内 EM/AOI 门面让伤害帧走房内视野、死亡掉落入房内 EM、拾取从房内 EM 摘除；
     * 无记录（或容器上下文缺失）回落宿主世界服务，世界侧路径逐字节等价。
     * Resolves the combat service for settlement (ADR-024 §9 V6): an attacker with a container record (joined into
     * a room) uses the room's CombatService — the in-room EM/AOI facade routes damage frames across the in-room view,
     * lands death drops in the room EM and removes pickups from the room EM; without a record (or when the container
     * context is missing) the host-world service takes over, byte-for-byte equivalent on the world side.
     */
    private function resolveCombatService(string $connId): ?CombatService
    {
        $container = $this->registry->getContainer($connId);
        if ($container instanceof RoomInstanceInterface) {
            $roomCombat = $this->rooms?->combatInContainer($container);
            if ($roomCombat !== null) {
                return $roomCombat;
            }
        }

        return $this->combatService;
    }

    /**
     * 视野判定（AOI 参数化）：目标是否在中心实体于给定视野提供者下的视野内。
     * 与 RealtimeServer::isNeighbor 同判据，但 AOI 由调用方按攻击方所在容器选取（V6 容器化路由）；
     * 无容器记录时传入宿主 AOI，行为与基类模板逐字节等价。
     * View check (AOI-parameterized): whether the target id sits inside the center entity's view under the given
     * view provider. Same criterion as RealtimeServer::isNeighbor, but the AOI is picked by the caller per the
     * attacker's container (V6 containerized routing); with no container record the host AOI is passed and behavior
     * stays byte-for-byte equivalent to the base template.
     */
    private function isNeighborIn(AOIProviderInterface $aoi, EntityInterface $center, string $targetId): bool
    {
        foreach ($aoi->query($center) as $other) {
            if ($other->getId() === $targetId) {
                return true;
            }
        }

        return false;
    }

    /** consume 五态 → auth_failed reason 映射（Invalid 为兜底分支）。 Maps a consume verdict to the auth_failed reason (Invalid is the fallback). */
    private function authFailedReason(TokenStatus $status): string
    {
        return match ($status) {
            TokenStatus::Expired => 'expired',
            TokenStatus::Replayed => 'replayed',
            TokenStatus::Unauthorized => 'unauthorized',
            default => 'invalid',
        };
    }

    /**
     * mmorpg 世界 tick（R4 试点，onStart 的 50ms tick 内调用）：威胁衰减（遍历世界怪物按帧时长衰减）
     * + 重生处理（到期怪物按出生登记回锚点重生）。mmorpg 未启用时零操作（缺省路径零开销）。
     * The mmorpg world tick (the R4 pilot, invoked inside onStart's 50ms tick): threat decay (every world monster
     * decays by the frame duration) + respawn processing (due monsters respawn back to their anchors from the spawn
     * registry). A no-op without mmorpg (zero cost on the default path).
     */
    private function tickMmorpg(float $dt): void
    {
        $mmorpg = $this->mmorpg;
        if ($mmorpg === null) {
            return;
        }

        // 热区采样与分频指派（P9a 区域降频）：以全部在线玩家位置采样格子密度（密度度量=玩家数），
        // 随后给所有实体按其所在坐标指派分频（玩家与怪物都受档位约束——玩家侧降攻击率、怪物侧降
        // AI 节拍；未启用 governor 时零操作）。
        // The hot-cell sampling and divisor assignment (the P9a region downgrade): samples cell densities
        // from all online players' positions (the density metric = player count), then assigns a divisor to
        // every entity by its coordinates (players and monsters both bound to the tier — the player side
        // sheds attack rate, the monster side sheds AI cadence; a no-op without a governor).
        $governor = $this->hotCellGovernor;
        if ($governor !== null) {
            $positions = [];
            foreach ($this->actors as $actor) {
                if (!$actor instanceof PlayerActor) {
                    continue;
                }
                $position = $this->world->getEntityManager()->get($actor->entityId())?->getPosition();
                if ($position !== null) {
                    $positions[] = $position;
                }
            }
            $governor->sample($positions);
            foreach ($this->actors as $actor) {
                // 分频 trait 只在 BasePlayer/BaseMonster 上（NPC 等其他 Actor 不参与区域降频）；
                // 实体 id 取各自的访问器（PlayerActor::entityId / MonsterActor::monsterId）。
                // The cadence trait lives on BasePlayer/BaseMonster only (other actors such as NPCs stay out
                // of region downgrading); the entity id comes from each class's own accessor
                // (PlayerActor::entityId / MonsterActor::monsterId).
                if ($actor instanceof MonsterActor) {
                    $id = $actor->monsterId();
                    $position = $this->world->getEntityManager()->get($id)?->getPosition();
                } elseif ($actor instanceof PlayerActor) {
                    $id = $actor->entityId();
                    $position = $this->world->getEntityManager()->get($id)?->getPosition();
                } else {
                    continue;
                }
                if ($position !== null) {
                    $divisor = $governor->divisorFor($position['x'], $position['y']);
                    if ($actor->tickDivisor() !== $divisor) {
                        $actor->setTickDivisor($divisor);
                        // 速率帧（P9b）：分频变化即通知客户端调整插值窗口（base tick × divisor）
                        // The rate frame (the P9b): on a divisor change, tell the client to adjust its
                        // interpolation window (base tick × divisor).
                        $this->sendToEntity($id, 'world:tick_rate', ['divisor' => $divisor]);
                    }
                }
            }
        }

        foreach ($this->actors as $actor) {
            if ($actor instanceof MonsterActor) {
                $actor->decayThreats($dt);
            }
        }

        $respawner = $this->respawner;
        if ($respawner === null) {
            return;
        }
        foreach ($respawner->due(microtime(true)) as $monsterId) {
            $spawn = $this->spawnRegistry[$monsterId] ?? null;
            if ($spawn === null) {
                $respawner->clear($monsterId);
                continue;
            }

            // 重生按密度消费（reviewer MINOR-1）：spawnDensity 此前从未被消费——每锚点重生 spawnDensity 只，
            // 密度副本 id 加后缀、锚点偏移避免重叠；仅锚点本体登记重生（副本死亡不触发整锚点重生，防指数增长）。
            // Respawn consumes the density (reviewer MINOR-1): spawnDensity was never consumed before — each anchor
            // respawns spawnDensity monsters, density copies get a suffixed id and an anchor offset to avoid overlap;
            // only the anchor itself registers respawns (a copy's death never triggers a full-anchor respawn,
            // preventing exponential growth).
            $density = $mmorpg->spawnDensity;
            for ($i = 0; $i < $density; $i++) {
                $this->spawnMonster(
                    $i === 0 ? $monsterId : $monsterId . '#' . ($i + 1),
                    $spawn['maxHp'],
                    $this->densityPosition($spawn['position'], $i),
                    $spawn['typeId'],
                    $spawn['patrolRadius'],
                    registerSpawn: $i === 0,
                    respawnMs: $spawn['respawnMs'] ?? null,
                );
            }

            // 先 spawn 后 clear（reviewer MINOR-4）：spawn 抛异常时登记保留，下个 tick 重试——
            // 原实现先 clear 后 spawn，异常即丢登记且不重试。
            // Spawn before clear (reviewer MINOR-4): a spawn exception keeps the registration for the next tick's
            // retry — the old clear-then-spawn order dropped the registration on exception with no retry.
            $respawner->clear($monsterId);
        }

        // 玩家自动复活消费（P6a）：到期待复活玩家服务端直接复活（满血回生 + 传送出生点 + 出生保护，
        // 与路由复活同核心 applyRevive）；玩家若已手动复活（路由先行消费标记）则登记静默作废。
        // 先复活后 clear（MINOR-4 同口径）：applyRevive 抛异常时登记保留，下个 tick 重试。
        // The player auto-revive consumption (the P6a): due awaiting-revive players revive server-side (full
        // restore + teleport to the spawn + spawn protection, the same applyRevive core as the route); a player
        // already revived manually (the route consumed the marker first) voids the registration silently.
        // Revive before clear (the MINOR-4 convention): an applyRevive exception keeps the registration for the
        // next tick's retry.
        $playerRespawner = $this->playerRespawner;
        if ($playerRespawner !== null) {
            foreach ($playerRespawner->due(microtime(true)) as $playerId) {
                $player = $this->actors[$playerId] ?? null;
                if ($player instanceof PlayerActor && $player->isAwaitingRevive()) {
                    $this->applyRevive($playerId, $player);
                }
                $playerRespawner->clear($playerId);
            }
        }
    }

    /**
     * 密度副本锚点偏移（reviewer MINOR-1）：第 0 只落锚点本体，第 i 只沿对角方向偏移 2×⌈i/2⌉ 格
     * （i 奇正偶负），避免多只重叠在同一格；偏移量小（AOI cellSize 10 内仍同视野）。
     * Density-copy anchor offset (reviewer MINOR-1): copy 0 lands on the anchor itself, copy i shifts
     * 2×⌈i/2⌉ cells along the diagonal (odd i positive, even i negative), keeping copies from stacking on the
     * same cell; the offset stays small (still within the same AOI view at cellSize 10).
     *
     * @param array{x: int, y: int} $position 锚点 Anchor position.
     * @return array{x: int, y: int}
     */
    private function densityPosition(array $position, int $index): array
    {
        if ($index === 0) {
            return $position;
        }
        $shift = 2 * (int) ceil($index / 2);
        $sign = ($index % 2 === 1) ? 1 : -1;

        return ['x' => $position['x'] + $sign * $shift, 'y' => $position['y'] + $sign * $shift];
    }
}
