<?php

declare(strict_types=1);

namespace Nythros\Demo;

use Nythros\Actor\SimpleActorSystem;
use Nythros\Aoi\GridAOI;
use Nythros\Aoi\UniversalAOI;
use Nythros\Cluster\RedisServiceRegistry;
use Nythros\Contracts\EventBusInterface;
use Nythros\Contracts\EventEnvelope;
use Nythros\Contracts\RoomInstanceInterface;
use Nythros\Contracts\WorldType;
use Nythros\Demo\Gameplay\GameplayConfig;
use Nythros\Demo\Plugin\AnnouncerPlugin;
use Nythros\Demo\Protocol\MapCodec;
use Nythros\Event\SimpleEventBus;
use Nythros\Framework\Actor\PlayerActor;
use Nythros\Framework\Auction\AuctionService;
use Nythros\Framework\Auction\AuctionStore;
use Nythros\Framework\Auction\CurrencyLedger;
use Nythros\Framework\BasePlayer;
use Nythros\Framework\Cluster\RedisPlayerTransferStore;
use Nythros\Framework\Combat\BuffService;
use Nythros\Framework\Combat\CombatService;
use Nythros\Framework\Combat\EntityTypeIndex;
use Nythros\Framework\Combat\SeededRandomSource;
use Nythros\Framework\Combat\SkillCooldownTable;
use Nythros\Framework\Combat\SystemRandomSource;
use Nythros\Framework\Config\ConfigRepository;
use Nythros\Framework\Container\Container;
use Nythros\Framework\Damageable;
use Nythros\Framework\Event\EventDispatcher;
use Nythros\Framework\Game\Horde\HordeConfig;
use Nythros\Framework\Game\Horde\HordePlugin;
use Nythros\Framework\Game\Mmorpg\DeathDropPolicy;
use Nythros\Framework\Game\Mmorpg\HotCellPolicy;
use Nythros\Framework\Game\Mmorpg\MmorpgConfig;
use Nythros\Framework\Game\Mmorpg\MmorpgPlugin;
use Nythros\Framework\Gm\Command\BroadcastCommand;
use Nythros\Framework\Gm\Command\DrainCommand;
use Nythros\Framework\Gm\Command\KickCommand;
use Nythros\Framework\Gm\Command\StatusCommand;
use Nythros\Framework\Gm\GmCommandBus;
use Nythros\Framework\Mail\MailService;
use Nythros\Framework\Mail\RedisMailStore;
use Nythros\Framework\Matching\MatchCriteria;
use Nythros\Framework\Matching\MatchingService;
use Nythros\Framework\Observability\PerfSampler;
use Nythros\Framework\Persistence\ArchivePipeline;
use Nythros\Framework\Plugin\Buff\BuffDefinition;
use Nythros\Framework\Plugin\Buff\BuffPlugin;
use Nythros\Framework\Plugin\Buff\BuffRepository;
use Nythros\Framework\Plugin\Item\ItemDefinition;
use Nythros\Framework\Plugin\Item\ItemPlugin;
use Nythros\Framework\Plugin\Item\ItemRepository;
use Nythros\Framework\Plugin\PluginRegistry;
use Nythros\Framework\Plugin\Skill\SkillPlugin;
use Nythros\Framework\Plugin\Skill\SkillRepository;
use Nythros\Framework\Quest\QuestChain;
use Nythros\Framework\Quest\QuestDefinition;
use Nythros\Framework\Quest\QuestRepository;
use Nythros\Framework\Quest\QuestService;
use Nythros\Framework\Quest\RedisQuestStore;
use Nythros\Framework\Server\ConnectionRegistry;
use Nythros\Framework\Server\MovementValidator;
use Nythros\Kernel\PerfProbe;
use Nythros\KernelWorkerman\WorkermanClock;
use Nythros\KernelWorkerman\WorkermanTimer;
use Nythros\NetworkWorkerman\WorkermanWebSocketServer;
use Nythros\Persistence\MySqlStorage;
use Nythros\Protocol\BatchSerializerInterface;
use Nythros\Protocol\MsgpackSerializer;
use Nythros\Scheduler\RegionScheduler;
use Nythros\Security\TokenManagerInterface;
use Nythros\World\RoomInstanceManager;
use Nythros\World\SimpleEntityManager;
use Nythros\World\World;

/**
 * 频道组装工厂：装配一个 Map 频道的完整运行时（master 期调用，worker 进程经 fork 继承状态）。
 * 被 run-worker.php（外部逐频道入口）与 bin/start-maps.php（Workerman 原生多频道单入口）共用，
 * 保证两种形态的频道组装完全一致。
 *
 * 组装内容：分区调度器 + World（AOI 局域 / 全量广播，AOI 恒非空）→ 战斗插件装配（Skill/Item/Buff 经
 * PluginRegistry 生命周期注入 Container）→ 归档管线（MySqlStorage + 标脏）→ MapServer（含集群注册/心跳/
 * 世界 tick/视野快照重同步，均挂在 onWorkerStart/onWorkerStop）→ CombatService 回填 → 追加 worker 初始化
 * 处理器（建表 + 初始怪物 spawn + 性能采样）。
 *
 * Channel-assembly factory: builds one Map channel's complete runtime (called in the master process; the worker
 * process inherits the state after fork). Shared by run-worker.php (the external per-channel entry) and
 * bin/start-maps.php (the native multi-channel single entry), so both shapes assemble a channel identically.
 *
 * Assembly: the region-budgeted scheduler + World (AOI-local / full broadcast; the AOI is never null) → combat
 * plugin assembly (Skill/Item/Buff through the PluginRegistry lifecycle into the Container) → the archive pipeline
 * (MySqlStorage + dirty marking) → MapServer (cluster register/heartbeat/world tick/vision-snapshot resync, all on
 * the onWorkerStart/onWorkerStop hooks) → CombatService back-fill → an extra worker-init handler (schema creation
 * + initial monster spawn + performance sampling).
 */
final class MapChannelFactory
{
    /** 房间宿主心跳间隔（秒）：固定 15ms——取活动房间最小周期上界（ADR-024 §D-B 开放问题的实测裁决： 15ms 心跳下调度延迟 ≤15ms，远小于 horde 50ms 帧预算；线性扫描成本由 room-bench ③ 锁定为百房微秒量级， 自适应「活动房间最小周期」需维护周期注册表而收益在 demo 规模不可测，故取固定值）。 Room host-heartbeat interval in seconds: a fixed 15ms — the upper bound of active-room minimum periods (the measured ruling on ADR-024 §D-B's open question: scheduling latency stays ≤15ms, far below the horde's 50ms frame budget; the linear scan cost is locked by room-bench ③ at microseconds for a hundred rooms, while adaptive "minimum active period" bookkeeping buys nothing measurable at demo scale — hence the fixed value). */
    private const ROOM_HOST_TICK_SECONDS = 0.015;

    /** 房间 tick 周期预算（毫秒）：宿主心跳的 60%（ADR-024 §D-B 预算口径随宿主周期缩放）。 Room-tick cycle budget in milliseconds: 60% of the host heartbeat (ADR-024 §D-B's budget scales with the host period). */
    private const ROOM_TICK_BUDGET_MS = 9.0;
    /**
     * 组装并挂载一个频道到给定 server（master 期执行；返回 MapServer 供调用方按需使用）。
     * Assembles and mounts one channel onto the given server (runs in the master process; returns the MapServer).
     *
     * @param WorkermanWebSocketServer $server 该频道的 WebSocket 服务器（已按端口创建） This channel's WebSocket server (already created for its port).
     * @param string $mapId 地图标识 Map identifier.
     * @param string $channelId 频道标识 Channel identifier.
     * @param string $worldType 世界类型 'aoi'（局域九宫格）或 'full'（全量广播） World type: 'aoi' (local 3x3) or 'full' (full broadcast).
     * @param int $port 对外端口（wsAddress 上报与注册 meta） Public port (reported as wsAddress).
     * @param BatchSerializerInterface $serializer 批量序列化器（demo 用 MapCodec 二进制） Batch serializer (MapCodec binary in the demo).
     * @param TokenManagerInterface $tokenManager Token 签发/消费 Token issuing/consumption.
     * @param RedisServiceRegistry $serviceRegistry 集群服务注册表 Cluster service registry.
     * @param \Closure(): \Redis $redisFactory Redis 连接工厂（worker fork 后 lazy 建连） Redis connection factory (lazily connected after fork).
     * @param \Closure(): \PDO $pdoFactory MySQL/PDO 连接工厂（归档用，lazy 建连） PDO connection factory for the archive (lazily connected).
     * @return MapServer 组装好的服务 The assembled server.
     */
    public static function attachChannel(
        WorkermanWebSocketServer $server,
        string $mapId,
        string $channelId,
        string $worldType,
        int $port,
        BatchSerializerInterface $serializer,
        TokenManagerInterface $tokenManager,
        RedisServiceRegistry $serviceRegistry,
        \Closure $redisFactory,
        \Closure $pdoFactory,
    ): MapServer {
        $serviceId = sprintf('%s#%s', $mapId, $channelId);

        // 分区调度器：actors/network/maintenance 三个预算分区（World 帧末网络 flush 走 network 区）
        // Region-budgeted scheduler: actors/network/maintenance regions (the frame-end network flush goes to the network region)
        $scheduler = new RegionScheduler(totalBudgetMs: 6.0);
        $scheduler->registerRegion('actors', 2.0);
        $scheduler->registerRegion('network', 3.0);
        $scheduler->registerRegion('maintenance', 1.0);

        // 世界类型（AOI / 全量广播）：两种都注入 AOI —— GridAOI（九宫格视野）或 UniversalAOI（全量 = 全世界即视野）；
        // 消费方（MapServer/战斗层）不再判空；World 的 WorldType 仅决定帧内是否做 AOI 差分
        // World type (AOI / full broadcast): both inject an AOI — GridAOI (3x3 view) or UniversalAOI
        // (full = the whole world is the view); consumers never null-check, and World's WorldType only decides
        // whether the per-frame AOI diff sweep runs
        $useFull = $worldType === 'full';
        $entityManager = new SimpleEntityManager();
        $aoi = $useFull ? new UniversalAOI($entityManager) : new GridAOI(10);
        // 事件总线队列容量可配置（性能批次实测：10k 缺省只在 join 洪峰——百人同帧入场的 O(J×N)
        // enter 信封瞬时脉冲——触顶丢弃，稳态丢弃为 0；缺省上调 30k 覆盖常规洪峰，快照重同步仍是兜底。
        // NYTHROS_EVENTBUS_QUEUE 覆盖装配缺省。）
        // Configurable envelope queue cap (measured: the 10k default only fills during join bursts — an
        // O(J×N) enter-envelope pulse when a hundred clients enter the same frame — steady-state drops are 0;
        // the assembly default is raised to 30k to cover normal bursts, with the snapshot resync as backstop.
        // NYTHROS_EVENTBUS_QUEUE overrides the assembly default.)
        $rawQueue = getenv('NYTHROS_EVENTBUS_QUEUE');
        $queueCap = is_string($rawQueue) && preg_match('/^\d+$/', trim($rawQueue)) === 1 && (int) trim($rawQueue) > 0
            ? (int) trim($rawQueue)
            : 30000;
        $world = new World($entityManager, new SimpleActorSystem(), $aoi, new SimpleEventBus(maxQueueSize: $queueCap), $scheduler, $useFull ? WorldType::FULL_BROADCAST : WorldType::AOI);
        $timer = new WorkermanTimer();
        $clock = new WorkermanClock($timer, 0.05);
        $connectionRegistry = new ConnectionRegistry();

        // P11 玩法数据外置 · 配置仓库装配（前移到玩法数据消费之前）：NYTHROS_CONFIG_DIR 指向存在的目录时启用——
        // 玩法三表（gameplay/skills/drops）按同名文件挂载并挂 schema（坏表启动 fail-fast、错误带行号；热载改坏
        // 走 check() 回滚），其余 *.php 沿用 R3 目录注册语义（无 schema）。文件缺席的表回落缺省表（零破坏）。
        // fork 后各 worker 进程独立 mtime 轮询（5s 周期，onWorkerStart 内注册定时器）。
        // P11 config-repository assembly (moved ahead of gameplay-data consumption): active when NYTHROS_CONFIG_DIR
        // points at an existing directory — the three gameplay tables (gameplay/skills/drops) mount from same-named
        // files with schemas (a rejected table fails startup fast with line numbers; a bad hot-reload rolls back via
        // check()), while remaining *.php files keep the R3 directory semantics (schema-less). A table without its
        // file falls back to the default table (zero breakage). Each forked worker polls mtimes independently
        // (5s period, timer registered inside onWorkerStart).
        $configRepository = null;
        $configEvents = null;
        $configDir = getenv('NYTHROS_CONFIG_DIR');
        if (is_string($configDir) && $configDir !== '' && is_dir($configDir)) {
            $configEvents = new EventDispatcher();
            $configEvents->listen(ConfigRepository::EVENT_CHANGED, static function (array $payload): void {
                error_log(sprintf('[ConfigRepository] reloaded %s (%s)', $payload['key'] ?? '?', $payload['path'] ?? '?'));
            });
            $configRepository = new ConfigRepository($configEvents);
            $schemas = GameplayTables::schemas();
            $registered = [];
            foreach (GameplayTables::TABLE_KEYS as $tableKey) {
                $tablePath = rtrim($configDir, '/\\') . '/' . $tableKey . '.php';
                if (is_file($tablePath)) {
                    $configRepository->registerFile($tableKey, $tablePath, $schemas[$tableKey]);
                    $registered[$tableKey] = true;
                }
            }
            $otherPaths = glob(rtrim($configDir, '/\\') . '/*.php') ?: [];
            sort($otherPaths);
            foreach ($otherPaths as $otherPath) {
                $otherKey = basename($otherPath, '.php');
                if (!isset($registered[$otherKey])) {
                    $configRepository->registerFile($otherKey, $otherPath);
                }
            }
            $server->onWorkerStart(static function () use ($timer, $configRepository): void {
                $configRepository->startPolling($timer, 5.0);
            });
        }

        // 战斗插件装配（ADR-017 §6 官方插件数据）：Skill/Item/Buff 插件经 PluginRegistry::load 走完整生命周期
        // （register → repository 注册进 Container + 事件订阅），组装时从 Container 取 repository 注入
        // CombatService——插件机制真正运转（load/register → enable）。
        // Combat plugin assembly (ADR-017 §6 official-plugin data): the Skill/Item/Buff plugins go through the full
        // lifecycle via PluginRegistry::load (register → repositories into the Container + event subscription), and the
        // repositories are resolved from the Container into CombatService — the plugin mechanism is actually in play.
        $container = new Container();
        $dispatcher = new EventDispatcher();
        $pluginRegistry = new PluginRegistry();
        $pluginRegistry->load(new SkillPlugin(), $container, $dispatcher);
        $pluginRegistry->load(new ItemPlugin(), $container, $dispatcher);
        $pluginRegistry->load(new BuffPlugin(), $container, $dispatcher);
        // P12 教程玩具插件（docs/plugin-guide.md 随教程产物）：完整生命周期示范——注册计数服务 +
        // 订阅 combat.kill，enable/disable 门控计数。装配接线即教程「第 5 步」的原样落地。
        // The P12 tutorial toy plugin (built alongside docs/plugin-guide.md): a full-lifecycle
        // demonstration — registers the counting service + subscribes to combat.kill, with enable/disable
        // gating the counting. This wiring is tutorial "step 5" applied verbatim.
        $pluginRegistry->load(new AnnouncerPlugin(), $container, $dispatcher);
        $pluginRegistry->enable('skill');
        $pluginRegistry->enable('item');
        $pluginRegistry->enable('buff');
        $pluginRegistry->enable('announcer');

        /** @var SkillRepository $skills 插件注册进 Container 的技能仓库 The skill repository registered by the plugin. */
        $skills = $container->get('skill.repository');

        // P11 玩法数据外置 · 技能表：声明数据化（skills.php 表行 → SkillDefinition），feature 标注行按 env
        // 开关过滤（taunt 系仅 mmorpg 启用时装配——取代原 mmorpg 块内的硬编码注册）；表数据来自
        // ConfigRepository（缺文件回落缺省表）。增删一行即生效：启动装配 + 热载重放（reapplySkills）双路径。
        // P11 skill table: declarations as data (skills.php rows → SkillDefinition), feature-tagged rows filtered
        // by the env switches (the taunt family assembles only with mmorpg on — replacing the mmorpg-block hardcoded
        // registrations); table data comes from ConfigRepository (missing file falls back to the default table).
        // Adding/removing a row takes effect via both paths: the startup assembly and the hot-reload replay
        // (reapplySkills).
        $enabledFeatures = GameplayTables::enabledFeatures();
        $appliedSkillIds = GameplayTables::applySkills($skills, GameplayTables::table($configRepository, 'skills'), $enabledFeatures);

        /** @var ItemRepository $items 插件注册进 Container 的物品仓库 The item repository registered by the plugin. */
        $items = $container->get('item.repository');
        $items->register(new ItemDefinition('gold', '金币', ItemDefinition::TYPE_CURRENCY));
        $items->register(new ItemDefinition('potion', '生命药水', ItemDefinition::TYPE_CONSUMABLE));
        $items->register(new ItemDefinition('bone', '兽骨', ItemDefinition::TYPE_MATERIAL));

        // R3 经济批装配（NYTHROS_ECONOMY=1 启用，缺省关闭——存量部署零影响，比照 NYTHROS_ROOMS 先例）：
        // 注册装备型物品（sword：武器槽、maxHp+30）供 verify-economy 的穿戴/挂单/购买/邮件全链验收使用；
        // sword 的掉落条目改为 drops 表 feature=economy 行（P11），物品注册条件留守此处。
        // R3 economy-batch assembly (enabled with NYTHROS_ECONOMY=1, off by default — zero impact on existing
        // deployments, mirroring the NYTHROS_ROOMS precedent): registers the equipment item (sword: weapon slot,
        // maxHp+30) for verify-economy's equip/list/buy/mail full-chain acceptance; sword's drop entry became a
        // drops-table feature=economy row (the P11) while the item-registration condition stays here.
        $economyEnabled = getenv('NYTHROS_ECONOMY') === '1';
        if ($economyEnabled) {
            $items->register(new ItemDefinition('sword', '铁剑', ItemDefinition::TYPE_EQUIPMENT, 'weapon', ['maxHp' => 30]));
        }

        // P11 玩法数据外置 · 掉落表：声明数据化（drops.php 行 → DropTable），feature 过滤同技能表；
        // 条目 itemId 引用完整性在此 fail-fast（未注册物品 = 装配错误）。
        // P11 drop table: declarations as data (drops.php rows → DropTable), feature filtering as the skill table's;
        // entry-itemId referential integrity fails fast here (an unregistered item is an assembly error).
        $dropTable = GameplayTables::buildDropTable(GameplayTables::table($configRepository, 'drops'), $enabledFeatures, $items);
        $typeIndex = new EntityTypeIndex();

        // P14 随机性注入：NYTHROS_RANDOM_SEED（数字）存在时注入 Mt19937 种子源——战斗伤害浮动/掉落 roll
        // 全链同种子同序列（E2E 复现确定性）；缺省系统随机（生产口径）。
        // The P14 randomness injection: with NYTHROS_RANDOM_SEED (numeric digits) present, the Mt19937 seeded
        // source is injected — the whole chain of combat damage variance and drop rolls reproduces per seed
        // (E2E determinism); the default stays the system random source (the production convention).
        $rawSeed = getenv('NYTHROS_RANDOM_SEED');
        $random = is_string($rawSeed) && preg_match('/^\d+$/', trim($rawSeed)) === 1
            ? new SeededRandomSource((int) trim($rawSeed))
            : new SystemRandomSource();

        // 归档管线：pickup 背包变更经 markDirty 标脏；存储为 MySqlStorage（lazy PDO 工厂，worker 进程内首用建连）；
        // 30s 兜底定时器与建表在 onWorkerStart 内注册（fork 后执行，幂等），断连/登出 flush 由 MapServer 触发。
        // Archive pipeline: pickup inventory changes are marked dirty via markDirty; the storage is MySqlStorage (lazy PDO
        // factory); the 30s fallback timer and schema creation are registered in onWorkerStart (after fork, idempotent).
        $archive = new ArchivePipeline(new MySqlStorage($pdoFactory), 'players');

        // R2 房间装配（ADR-024 starter-kit 接线）：NYTHROS_ROOMS=1 启用，缺省关闭——存量部署零影响。
        // 房间管理器注入宿主事件总线（信封统一队列、帧末统一 flush，ADR-024 §D-A）。
        // R4 类型模块试点：Horde 插件经 PluginRegistry 生命周期注册 horde 配置进 Container，
        // 组装层解析后注入 RoomHub 与 MapServer（framework 提供参数与规则，starter-kit 装配）。
        // R2 room assembly (ADR-024 starter-kit wiring): enabled by NYTHROS_ROOMS=1, off by default — zero impact on
        // existing deployments. The room manager shares the host event bus (one unified envelope queue, frame-end flush, ADR-024 §D-A).
        // R4 type-module pilot: the Horde plugin registers the horde config into the Container through the
        // PluginRegistry lifecycle; the assembly layer resolves it into RoomHub and MapServer (the framework provides
        // parameters and rules, the starter kit assembles).
        $roomManager = null;
        $roomHub = null;
        $hordeConfig = null;
        if (getenv('NYTHROS_ROOMS') === '1') {
            $pluginRegistry->load(new HordePlugin(), $container, $dispatcher);
            $pluginRegistry->enable('horde');
            /** @var HordeConfig $hordeConfig 插件注册进 Container 的 horde 配置 The horde config registered by the plugin. */
            $hordeConfig = $container->get(HordePlugin::CONFIG_ID);

            // 准入上限与动态周期上限（P9c）：env 注入——maxRooms 触顶时 create 抛 OverflowException
            // （RoomHub 转译为 busy 拒绝），maxDynamicPeriodMs 为预算压力下房间周期膨胀地板。
            // The admission cap and dynamic-period ceiling (the P9c): env-injected — create() throws an
            // OverflowException when maxRooms is full (RoomHub translates it into a busy rejection), and
            // maxDynamicPeriodMs backstops the room-period inflation under budget pressure.
            $maxRooms = 0;
            $rawMaxRooms = getenv('NYTHROS_ROOMS_MAX');
            if (is_string($rawMaxRooms) && preg_match('/^\d+$/', trim($rawMaxRooms)) === 1) {
                $maxRooms = (int) trim($rawMaxRooms);
            }
            $maxPeriodMs = 50;
            $rawMaxPeriod = getenv('NYTHROS_ROOMS_MAX_PERIOD_MS');
            if (is_string($rawMaxPeriod) && preg_match('/^\d+$/', trim($rawMaxPeriod)) === 1) {
                $maxPeriodMs = max(15, (int) trim($rawMaxPeriod));
            }
            $roomManager = new RoomInstanceManager(null, self::ROOM_TICK_BUDGET_MS, $world->getEventBus(), $maxPeriodMs, $maxRooms);
            $roomHub = new RoomHub($roomManager, $world, $skills, $items, $random, $dropTable, $typeIndex, $hordeConfig);
        }

        // R4 mmorpg 类型模块试点装配（NYTHROS_MMORPG=1 启用，缺省关闭——存量部署零影响，比照 NYTHROS_ROOMS 先例）：
        // Mmorpg 插件经 PluginRegistry 生命周期注册 mmorpg 配置进 Container，组装层解析后注入 MapServer
        // （威胁表/重生器接线；framework 提供参数与规则，starter-kit 装配）。
        // 任务链配置（P2 收口）：NYTHROS_MMORPG_CHAINS='id=q1,q2;id=q3,q4' 解析为 list<QuestChain> 注入
        // MmorpgConfig.questChains，玩法批装配时传给 QuestService（链式解锁由框架规则判定）。
        // R4 mmorpg type-module pilot assembly (enabled with NYTHROS_MMORPG=1, off by default — zero impact on
        // existing deployments, mirroring the NYTHROS_ROOMS precedent): the Mmorpg plugin registers the mmorpg config
        // into the Container through the PluginRegistry lifecycle; the assembly layer resolves it into MapServer
        // (the threat-table/respawner wiring; the framework provides parameters and rules, the starter kit assembles).
        // Quest-chain config (the P2 close-out): NYTHROS_MMORPG_CHAINS='id=q1,q2;id=q3,q4' parses into a
        // list<QuestChain> injected into MmorpgConfig.questChains, handed to QuestService by the gameplay wiring
        // (chained unlocking judged by the framework rules).
        $mmorpgConfig = null;
        if (getenv('NYTHROS_MMORPG') === '1') {
            $mmorpgConfig = self::mmorpgConfigFromEnv();
            $pluginRegistry->load(new MmorpgPlugin(config: $mmorpgConfig), $container, $dispatcher);
            $pluginRegistry->enable('mmorpg');
            /** @var MmorpgConfig $mmorpgConfig 插件注册进 Container 的 mmorpg 配置 The mmorpg config registered by the plugin. */
            $mmorpgConfig = $container->get(MmorpgPlugin::CONFIG_ID);
        }

        // 出生/复活点（P7b → P11 数据外置）：gameplay 表 spawnPoint 驱动；配置表缺席时回落 env 覆盖
        // （NYTHROS_SPAWN_POINT='x,y'，既有语义）再回落缺省 (0,0)。开启安全区（P7c）时装配层应与其圆心对齐。
        // The spawn/revive point (the P7b, externalized in the P11): driven by the gameplay table's spawnPoint; a
        // missing config table falls back to the env override (NYTHROS_SPAWN_POINT='x,y', the existing semantics),
        // then to the default (0,0). With a safe zone (the P7c) the assembly layer should align it with the zone center.
        $gameplayFromConfig = $configRepository?->config()?->has('gameplay') ?? false;
        $gameplayConfig = GameplayConfig::fromTable(GameplayTables::table($configRepository, 'gameplay'));
        $spawnPoint = $gameplayConfig->spawnPoint;
        if (!$gameplayFromConfig) {
            $rawSpawn = getenv('NYTHROS_SPAWN_POINT');
            if (is_string($rawSpawn) && preg_match('/^-?\d+\s*,\s*-?\d+$/', trim($rawSpawn)) === 1) {
                [$sx, $sy] = array_map('intval', explode(',', trim($rawSpawn)));
                $spawnPoint = ['x' => $sx, 'y' => $sy];
                $gameplayConfig = new GameplayConfig($spawnPoint, $gameplayConfig->monsters);
            }
        }

        // P15 跨 map 迁移票据存储（ADR-025 方案 C）：Redis 工厂注入（fork 后 lazy 建连）——
        // detach 导出快照 / attach 原子消费重建；导出挂 closeConnection 清理路径、导入挂 auth，
        // 零新增协议帧（词表零改动）。
        // The P15 cross-map migration ticket store (ADR-025's option C): the Redis factory is injected (lazy
        // after fork) — snapshot export at detach / atomic consume-and-rebuild at attach; the export rides the
        // closeConnection cleanup path and the import rides auth, with zero new protocol frames (no vocabulary change).
        $transfers = new RedisPlayerTransferStore($redisFactory);

        // P16 动态扩缩容 · 容量准入：NYTHROS_MAP_CAPACITY（数字，0 = 不限量缺省）——注册 meta 携带
        // maxCapacity（gateway selectChannel 跳过满员实例）+ auth 硬守卫（并发窗口兜底）。
        // The P16 dynamic-scaling capacity admission: NYTHROS_MAP_CAPACITY (digits, 0 = unlimited default) —
        // the register meta carries maxCapacity (the gateway's selectChannel skips full instances) plus an auth
        // hard guard (backstopping the concurrent window).
        $rawCapacity = getenv('NYTHROS_MAP_CAPACITY');
        $maxCapacity = is_string($rawCapacity) && preg_match('/^\d+$/', trim($rawCapacity)) === 1
            ? (int) trim($rawCapacity)
            : 0;

        // P18 归档恢复开关：NYTHROS_ARCHIVE_RESTORE=1 启用（缺省关闭——存量验收依赖逐跑全新背包）。
        // The P18 archive-restore switch: enabled via NYTHROS_ARCHIVE_RESTORE=1 (off by default — existing
        // acceptance depends on a per-run fresh inventory).
        $archiveRestore = getenv('NYTHROS_ARCHIVE_RESTORE') === '1';

        $map = new MapServer(
            $server,
            $serializer,
            $tokenManager,
            $world,
            $connectionRegistry,
            clock: $clock,
            timer: $timer,
            flushRegion: 'network',
            serviceId: $serviceId,
            mapId: $mapId,
            serviceRegistry: $serviceRegistry,
            wsAddress: sprintf('ws://127.0.0.1:%d', $port),
            dropTable: $dropTable,
            typeIndex: $typeIndex,
            archive: $archive,
            skills: $skills,
            random: $random,
            snapshotResyncIntervalSeconds: 1.0,
            rooms: $roomHub,
            spawnProtectionFrames: $hordeConfig?->spawnProtection->frames ?? PlayerActor::SPAWN_PROTECTION_FRAMES,
            spawnPoint: $spawnPoint,
            mmorpg: $mmorpgConfig,
            transfers: $transfers,
            maxCapacity: $maxCapacity,
            archiveRestore: $archiveRestore,
            playerMaxHp: $gameplayConfig->playerMaxHp,
        );

        // 协议版本守卫装配（版本协商，ADR-027）：NYTHROS_MIN_CLIENT_VERSION 注入最低版本，
        // 未设置 = 守卫不启用（存量客户端零影响）。见 docs/security.md §3。
        // The version-guard assembly (version negotiation, ADR-029): NYTHROS_MIN_CLIENT_VERSION injects the
        // minimum version; unset = guard off (zero impact on existing clients). See docs/security.md §3.
        $rawMinClientVersion = getenv('NYTHROS_MIN_CLIENT_VERSION');
        if (is_string($rawMinClientVersion) && preg_match('/^\d+$/', trim($rawMinClientVersion)) === 1) {
            $map->setMinClientVersion((int) trim($rawMinClientVersion));
        }

        // 依赖循环规避：CombatService 以 $map 本身（VisionBroadcaster/ActorLookup 实现）构造后回填；
        // 房间中枢同样回填宿主引用（RoomVisionBroadcaster 投递依赖）。
        // 应用级事件派发器随构造注入（R3 玩法批 D4 缺口补埋：击杀/拾取业务事件的发布总线，
        // 任务服务在玩法批装配块中订阅）。
        // Circular-dependency avoidance: CombatService is constructed with $map itself, then back-filled; the room hub
        // is back-filled with the host reference the same way (RoomVisionBroadcaster delivery depends on it).
        // The application-level event dispatcher rides construction (the R3 gameplay batch's D4-gap instrumentation:
        // the publish bus for kill/pickup business events; the quest service subscribes in the gameplay wiring block).
        $combatEvents = new EventDispatcher();
        // actorLookup 注入（P5c 补齐）：世界侧 CombatService 此前漏传——castSkillAoE 的 AoE 命中结算依赖
        // ActorLookupInterface（房间路径早已注入；世界侧首个生产调用点 skill:cast_aoe 暴露该缺口）。
        // The actorLookup injection (the P5c close-out): the world-side CombatService used to omit it — castSkillAoE's
        // AoE hit settlement depends on the ActorLookupInterface (the room path always injected it; the world side's
        // first production caller, skill:cast_aoe, surfaced the gap).
        // AoE PVP 对抗门（P13 对抗治理）：mmorpg 装配时把 MapServer 的 pvpRejection 注入 CombatService——
        // AoE 命中管线对被治理规则拒绝的目标（pvp_disabled/in_safe_zone/spawn_protected）静默跳过；普攻与
        // 单体技能的路由级门在 MapServer 路由内自洽。非 mmorpg 装配（含房间侧 CombatService）无门 = 现状。
        // The AoE PVP combat gate (the P13 governance): with mmorpg assembled, MapServer's pvpRejection is
        // injected into CombatService — the AoE hit pipeline silently skips targets rejected by the governance
        // rules (pvp_disabled/in_safe_zone/spawn_protected); route-level gates for normal attacks and single-target
        // skills live inside the MapServer routes. Non-mmorpg assemblies (including the room-side CombatService)
        // have no gate = the status quo.
        $pvpGate = $mmorpgConfig !== null
            ? static fn (Damageable $attacker, Damageable $target): ?string => $map->pvpRejection($attacker, $target)
            : null;

        $map->attachCombat(new CombatService($world, $map, $skills, $items, $random, actorLookup: $map, events: $combatEvents, killCredit: $mmorpgConfig !== null ? $mmorpgConfig->killCredit : MmorpgConfig::KILL_CREDIT_LAST_HIT, pvpGate: $pvpGate));

        // mmorpg 接线（NYTHROS_MMORPG=1）：配置注入 + 重生调度器 + combat.kill 订阅（怪物死亡登记重生）。
        // The mmorpg wiring (NYTHROS_MMORPG=1): config injection + the respawn scheduler + the combat.kill
        // subscription (monster deaths register respawns).
        if ($mmorpgConfig !== null) {
            $map->attachMmorpg($mmorpgConfig, $combatEvents);

            // 嘲讽系技能（taunt/taunt_aoe/slash_rect）自 P11 起由 skills 表 feature=mmorpg 行驱动装配
            // （威胁语义：tauntThreat 写入怪物威胁表，tauntMultiplier 倍率裁决归 ThreatTable::applyTaunt；
            // taunt_aoe 的 range=10 与 AoE 半径同量级是 E2E step9 的实测对齐，见缺省表注释）。
            // The taunt-family skills (taunt/taunt_aoe/slash_rect) are driven by skills-table feature=mmorpg rows
            // since the P11 (threat semantics: tauntThreat lands in the monster threat table, the tauntMultiplier
            // adjudication belongs to ThreatTable::applyTaunt; taunt_aoe's range=10 aligning with its AoE radius is
            // the E2E step9 measured alignment — see the default-table comments).
        }

        // R3 反作弊基线接线：NYTHROS_ANTICHEAT=1 启用（缺省关闭——存量部署零影响，比照 NYTHROS_ROOMS 先例）。
        // demo 阈值取宽松值（轴 128 / 欧氏 200 / 窗 1s 内 60 条 / 窗内累计 500），完整覆盖 verify-combat 的
        // ±30 与 ±(100,100) 既有移动路径；超速拒绝断言用 dx=100000 越界触发。
        // R3 anti-cheat baseline wiring: enabled by NYTHROS_ANTICHEAT=1 (off by default — zero impact on existing
        // deployments, mirroring the NYTHROS_ROOMS precedent). The demo thresholds are generous (axis 128 /
        // Euclidean 200 / 60 commands per 1s window / in-window accumulation 500), fully covering verify-combat's
        // existing ±30 and ±(100,100) move paths; the overspeed-rejection assert trips with dx=100000.
        if (getenv('NYTHROS_ANTICHEAT') === '1') {
            $map->setMovementValidator(new MovementValidator(
                maxStepAxis: 128,
                maxStepDistance: 200.0,
                maxCommandsPerWindow: 60,
                windowSeconds: 1.0,
                maxWindowDistance: 500.0,
            ));
        }

        // R3 GM 最小内核接线：NYTHROS_GM_UIDS 白名单非空时装配（GM 白名单留守 starter-kit 的裁决落点）。
        // status/broadcast/kick 以本 MapServer 为能力实现构造总线后回填（比照 attachCombat 循环规避）。
        // R3 GM minimal-kernel wiring: assembled when the NYTHROS_GM_UIDS whitelist is non-empty (the ruling's
        // landing spot for keeping the GM whitelist in starter-kit). status/broadcast/kick build the bus with this
        // MapServer as their capability implementation, then back-fill (mirroring attachCombat's circular avoidance).
        $gmUids = array_values(array_filter(array_map('trim', explode(',', getenv('NYTHROS_GM_UIDS') ?: ''))));
        if ($gmUids !== []) {
            $gmBus = new GmCommandBus(new StaticGmAuthorizer(array_fill_keys($gmUids, true)));
            $gmBus->register(new StatusCommand($map));
            $gmBus->register(new BroadcastCommand($map));
            $gmBus->register(new KickCommand($map));
            // drain（P16 动态扩缩容）：标记本实例 draining——目录停止路由新会话，存量连接不受影响
            // drain (the P16 dynamic scaling): marks this instance draining — the directory stops routing new
            // sessions while existing connections stay unaffected.
            $gmBus->register(new DrainCommand($map));
            $map->attachGm($gmBus);
        }

        // R3 经济批接线（NYTHROS_ECONOMY=1）：邮件/交易行/账本服务组以本 MapServer 为在线通知实现
        // （MailNotifierInterface）构造后回填；msgpack 编码器供 mail:claimed 的嵌套附件负载走 V7 路径。
        // R3 economy-batch wiring (NYTHROS_ECONOMY=1): the mail/auction/ledger service group is built with this
        // MapServer as the online-notification implementation (MailNotifierInterface), then back-filled; the msgpack
        // encoder routes mail:claimed's nested attachment payload through the V7 path.
        if ($economyEnabled) {
            $mail = new MailService(new RedisMailStore($redisFactory), $map);
            $auction = new AuctionService(
                new AuctionStore($redisFactory),
                new CurrencyLedger($redisFactory),
                $mail,
            );
            $map->attachEconomy($mail, $auction, new CurrencyLedger($redisFactory), $items, new MsgpackSerializer());
        }

        // R3 玩法批接线（NYTHROS_GAMEPLAY=1 启用，缺省关闭——存量部署零影响，比照 NYTHROS_ROOMS 先例）：
        // Buff 服务（0.5s 周期 tick：到期摘除 + DOT 结算，宿主经 MapServer Actor 表解析）+ 技能冷却表 +
        // 匹配服务（1s 周期兜底撮合；join 编排委托 MatchJoinOrchestrator 走 RoomHub transfer 全链——
        // 组装边界：framework 只依赖 RoomManagerInterface 与 MatchJoinHandlerInterface 契约；房间装配
        // 未启用时匹配服务缺省 null，matching:* 路由回执 unavailable）+ 任务服务（CombatService 击杀/
        // 拾取埋点驱动 kill/collect 进度源）。
        // R3 gameplay-batch wiring (enabled by NYTHROS_GAMEPLAY=1, off by default — zero impact on existing
        // deployments, mirroring the NYTHROS_ROOMS precedent): the buff service (a 0.5s periodic tick for expiry
        // removal + DOT settlement, hosts resolved via the MapServer actor table) + the skill-cooldown table + the
        // matching service (a 1s periodic backstop sweep; join orchestration delegates to MatchJoinOrchestrator over
        // RoomHub's full transfer chain — assembly boundary: the framework depends only on the RoomManagerInterface
        // and MatchJoinHandlerInterface contracts; without room assembly the matching service stays null and the
        // matching:* routes answer unavailable) + the quest service (CombatService's kill/pickup instrumentation
        // drives the kill/collect progress sources).
        if (getenv('NYTHROS_GAMEPLAY') === '1') {
            $buffDefinitions = new BuffRepository();
            $buffDefinitions->register(new BuffDefinition('rage', '狂暴', 4.0, ['attributes' => ['maxHp' => 50]]));
            $buffDefinitions->register(new BuffDefinition('poison', '中毒', 6.0, ['dot' => ['damage' => 5, 'intervalSeconds' => 1.0]], BuffDefinition::STACK_STACK, 3));
            $buffs = new BuffService($buffDefinitions, $map);

            $cooldowns = new SkillCooldownTable();

            // 匹配服务仅在房间装配启用时可用（开房编排依赖 RoomManagerInterface 实例）。
            // The matching service is available only with room assembly enabled (room building needs a RoomManagerInterface instance).
            $matching = null;
            if ($roomHub !== null) {
                $matching = new MatchingService($roomManager, new MatchJoinOrchestrator($roomHub), static fn (): GridAOI => new GridAOI(10));
                $matching->registerCriteria(new MatchCriteria('duo-2', 2, 1, 999));
            }

            $questRepository = new QuestRepository();
            $questRepository->register(new QuestDefinition('kill_wolves', '猎狼', QuestDefinition::SOURCE_KILL, 'wolf', 2, [['itemId' => 'potion', 'count' => 2]]));
            // collect_bones 5→1（P2 节奏与链门对齐）：掉落表狼死恒掉 1 骨；链式解锁下 kill1 的骨在 kill_wolves
            // 完成前被链门忽略（拾取成功但进度不计），kill2 的骨在解锁后计入——required 1 让两杀后集骨即完成，
            // 一条链两杀一谈闭环（原 5 骨需五杀、required 2 需三杀，试点验收时长均不可接受）。
            // collect_bones 5→1 (the P2 pacing + chain-gate alignment): the drop table always yields 1 bone per
            // wolf kill; under chained unlocking kill1's bone is ignored by the chain gate (the pickup succeeds but
            // the progress is not counted — the ring is still locked), and kill2's bone counts once unlocked —
            // required 1 completes collecting after two kills, closing a whole chain with two kills and one talk
            // (the former 5 bones needed five kills and required 2 would need three; both are unacceptable
            // acceptance durations).
            $questRepository->register(new QuestDefinition('collect_bones', '集骨', QuestDefinition::SOURCE_COLLECT, 'bone', 1));
            $questRepository->register(new QuestDefinition('talk_elder', '见长老', QuestDefinition::SOURCE_TALK, 'npc-elder', 1));
            // 任务链配置注入（P2 链式解锁）：来自 mmorpg 配置（NYTHROS_MMORPG_CHAINS env → MmorpgConfig.questChains）；
            // 缺省 [] = 无链，全部任务恒解锁（与链前行为一致）。
            // Quest-chain injection (the P2 chained unlocking): from the mmorpg config (NYTHROS_MMORPG_CHAINS env →
            // MmorpgConfig.questChains); default [] = chainless, every quest always unlocked (pre-chain behavior).
            // P4c 进度持久化：进程内 InMemoryQuestStore 换成 RedisQuestStore（跨进程持久后端，服务器重启
            // 进度不丢——击杀/收集/对话/领奖标记全量落盘；键族与社交状态同前缀，见 RedisQuestStore）。
            // P4c progress persistence: the in-process InMemoryQuestStore gives way to RedisQuestStore (a
            // cross-process persistent backend — progress survives server restarts, kill/collect/talk progress and
            // the claim flag all persist; the key family shares the social-state prefix, see RedisQuestStore).
            $quests = new QuestService(new RedisQuestStore($redisFactory), $questRepository, $mmorpgConfig->questChains ?? []);
            $quests->attachDispatcher($combatEvents);

            $map->attachGameplay($buffs, $cooldowns, $matching, $quests);

            // 玩法批定时器：Buff tick 0.5s（到期/DOT）；匹配兜底撮合 1s。
            // Gameplay timers: a 0.5s buff tick (expiry/DOT); a 1s matching backstop sweep.
            $server->onWorkerStart(static function () use ($timer, $buffs, $map): void {
                $timer->add(0.5, static function () use ($buffs, $map): void {
                    $buffs->tick(microtime(true), static function (string $hostKey) use ($map): ?BasePlayer {
                        $actor = $map->getActor($hostKey);

                        return $actor instanceof BasePlayer ? $actor : null;
                    });
                }, true);
                $timer->add(1.0, static function () use ($map): void {
                    $map->sweepMatching();
                }, true);
            });
        }

        if ($roomHub !== null) {
            $roomHub->attach($map);
            self::subscribeRoomEnvelopes($world->getEventBus(), $map);

            // V3 断连跨容器清理兜底（ADR-024 §9 V3）：closeConnection 模板在世界 EM 查空（玩家已 transfer
            // 进房）时调用本回调 → manager->evictFromAny 复用房间 leave 全链（摘 EM/AOI/ActorSystem +
            // member_leave/room.left 信封）；持久化冲刷由既有 onEntityCleanedUp 钩子覆盖，此处不重复。
            // V3 cross-container disconnect-cleanup fallback (ADR-024 §9 V3): when the closeConnection template
            // misses in the world EM (the player transferred into a room), this callback runs →
            // manager->evictFromAny reuses the room's full leave chain (EM/AOI/ActorSystem removal plus the
            // member_leave/room.left envelopes); persistence flushing stays covered by the existing
            // onEntityCleanedUp hook, not duplicated here.
            $map->setCrossContainerCleanup(static fn (string $entityId): bool => $roomManager->evictFromAny($entityId));

            // 房间指标接入心跳（P9c 准入）：rooms/deferred/periodMap 汇入 registry 元数据
            // Room metrics into the heartbeat (the P9c admission): rooms/deferred/periodMap join the registry metadata.
            $map->setRoomMetricsProvider(static function () use ($roomManager): array {
                return [
                    'rooms' => count($roomManager->all()),
                    'roomsDeferred' => $roomManager->lastDeferred(),
                ];
            });

            // 单一宿主心跳（ADR-024 §D-B）：onWorkerStart 内注册（fork 后事件循环就绪即驱动），
            // now 取墙钟（管理器预算时钟独立实测 tick 耗时）
            // The single host heartbeat (ADR-024 §D-B): registered inside onWorkerStart (driven once the post-fork
            // event loop is up); now is the wall clock (the manager's budget clock independently measures tick cost)
            $server->onWorkerStart(static function () use ($timer, $roomManager): void {
                $timer->add(self::ROOM_HOST_TICK_SECONDS, static function () use ($roomManager): void {
                    $roomManager->tick(microtime(true));
                }, true);
            });
        }

        // P11 玩法表热载应用（config.changed → 表级重放）：mtime 轮询与事件源在装配头部（fork 后各 worker
        // 独立 5s 自查）；坏表在 ConfigRepository.check 内已被 schema 拦截走回滚，到得了这里的必为通过校验的表。
        // P11 gameplay-table hot-reload application (config.changed → per-table replay): the mtime polling and event
        // source live at the assembly head (each forked worker polls independently every 5s); a rejected table is
        // already intercepted by the schema inside ConfigRepository.check (rolled back), so only validated tables
        // reach here.
        // $configEvents 与 $configRepository 在装配头部同一分支内赋值（phpstan 联动收窄）：
        // 前者非空即后者已装配，无需重复判空。
        // $configEvents and $configRepository are assigned in the same assembly-head branch (phpstan interlinked
        // narrowing): the former being non-null implies the latter was assembled — no redundant null check.
        if ($configEvents !== null) {
            $repo = $configRepository;
            $configEvents->listen(ConfigRepository::EVENT_CHANGED, static function (array $payload) use ($repo, $skills, $items, $map, $roomHub, $enabledFeatures, &$appliedSkillIds): void {
                $key = is_string($payload['key'] ?? null) ? $payload['key'] : '';
                $config = $repo->config();
                if ($config === null) {
                    return;
                }
                switch ($key) {
                    case 'skills':
                        // 技能表：全量重放（增改覆盖、配置源删除行摘除；$appliedSkillIds 引用持有 diff 基线）
                        // The skill table: full replay (additions/edits overwrite, config-sourced deleted rows are
                        // removed; $appliedSkillIds by reference holds the diff baseline).
                        GameplayTables::reapplySkills($skills, GameplayTables::table($repo, 'skills'), $enabledFeatures, $appliedSkillIds);
                        break;
                    case 'drops':
                        // 掉落表：构建新表换入 MapServer 与 RoomHub（在场怪物耗尽旧引用，新出生/新波次用新表）
                        // The drop table: build and swap into MapServer and RoomHub (live monsters drain the old
                        // reference; new spawns/waves use the new table).
                        $reloadedDropTable = GameplayTables::buildDropTable(GameplayTables::table($repo, 'drops'), $enabledFeatures, $items);
                        $map->replaceDropTable($reloadedDropTable);
                        $roomHub?->replaceDropTable($reloadedDropTable);
                        break;
                    case 'gameplay':
                        // gameplay 表：出生点换入 + 怪物表 diff（已登记参数热更/新增行 spawn/删除行摘登记；在场怪物不驱逐）
                        // The gameplay table: the spawn point swapped in + a monster-table diff (registered params
                        // hot-updated / new rows spawn / deleted rows unregistered; live monsters are never evicted).
                        $map->applyGameplayConfig(GameplayConfig::fromTable(GameplayTables::table($repo, 'gameplay')));
                        break;
                    default:
                        // 非玩法表（R3 泛配置）：仅 ConfigRepository 侧日志，无玩法应用
                        // Non-gameplay tables (R3 generic config): the ConfigRepository-side log only, no gameplay application.
                        return;
                }
                error_log(sprintf('[GameplayTables] applied hot-reloaded table: %s', $key));
            });
        }

        // worker 进程内初始化（fork 后执行，幂等）：建表 + 初始怪物 spawn + 性能采样定时器。
        // 与 MapServer::register() 的 onWorkerStart 处理器并存（WorkermanWebSocketServer 追加式多处理器）；
        // 出怪放 onWorkerStart 的动机：初始怪物在「事件循环启动、连接可达」后才出生，出生广播真实可达。
        // Worker-process initialization (after fork, idempotent): schema creation + initial monster spawn + the
        // performance-sampling timer. Coexists with MapServer::register()'s own onWorkerStart handler (the server
        // appends multiple handlers); monsters spawn only once the loop is up so their birth broadcast is receivable.
        $server->onWorkerStart(static function () use ($pdoFactory, $map, $redisFactory, $timer, $serviceId, $archive, $gameplayConfig): void {
            // 注意：文件级闭包不自动捕获外部变量——$pdoFactory 必须显式 use，否则 fork 后执行时是 null（部署路径实测踩坑）
            // Note: file-scope closures never auto-capture outer variables — \$pdoFactory must be explicitly `use`d
            MySqlStorage::createSchema($pdoFactory(), MySqlStorage::DEFAULT_TABLE);

            // 归档 30s 兜底定时器（P5b 落实设计意图）：ArchivePipeline 在 fork 前构造（timer 缺省 null），
            // 周期兜底在此注册——断连/登出同步点之外的有界丢失窗口由定时批量 saveBatch 兜底（ADR-013 10.5 裁决 4）。
            // The archive 30s fallback timer (the P5b design-intent fulfillment): the pipeline is constructed before
            // the fork (timer defaults to null), so the periodic fallback registers here — the bounded-loss window
            // beyond the disconnect/logout sync points is backstopped by the timed batch saveBatch (ADR-013 10.5, ruling 4).
            $timer->add(ArchivePipeline::FLUSH_INTERVAL_SECONDS, $archive->periodicFlush(...), true);

            // 初始怪物 spawn（地图初始化路径，monster:spawned 出生事件一次广播；服务器就绪后才出生，广播可达）。
            // 锚点/血量/巡逻域/逐怪重生延迟全部来自 gameplay 表（P11 数据外置；缺省值即下述 R4 实测对齐结果）。
            // 锚点外移（R4 出生保护批）的历史裁决保留在表注释：旧锚 monster-1 (5,5) 与玩家出生格 (0,0) 同格
            // （cellSize=10 下 (5,5) 即 cell(0,0)），登录瞬间即被集火约 3s 打空 100 血（verify-room/
            // verify-matching 实测踩坑）；现锚 (15,15)/(-6,-6) 巡逻半径 4，活动域恒不回出生格且在出生玩家
            // 九宫格视野内，配合 auth 出生保护窗口（3s）双保险闭环。
            // Initial monster spawning (the map-initialization path; monsters are born after the server is ready).
            // Anchor/maxHp/patrol/per-monster-respawn all come from the gameplay table (the P11 externalization;
            // the defaults are the R4 measured alignment below). The R4 spawn-protection relocation ruling stays in
            // the table comments: the old anchor monster-1 (5,5) shared the players' spawn cell ((5,5) IS cell(0,0)
            // at cellSize=10), so clients were focused down from full hp in about 3s at the login instant
            // (verify-room / verify-matching measured pitfalls); the current anchors (15,15)/(-6,-6) with patrol
            // radius 4 keep roam domains that never re-enter the spawn cell while staying inside the spawn players'
            // 3x3 view, doubly closed with the 3s auth spawn-protection window.
            foreach ($gameplayConfig->monsters as $monsterSpec) {
                $map->spawnMonster($monsterSpec->id, $monsterSpec->maxHp, $monsterSpec->anchor, $monsterSpec->typeId, patrolRadius: $monsterSpec->patrolRadius, respawnMs: $monsterSpec->respawnMs);
            }

            // 运行期性能采样（C）：每 5s 读引擎探针快照 → 写 Redis；采样失败只记日志。
            // 组装层绑定具体探针实现（PerfProbe::instance），framework 侧只依赖 PerfSnapshotProviderInterface 契约
            // Runtime performance sampling (C): every 5s collect the engine probe snapshot into Redis; failures are logged only.
            // The assembly layer binds the concrete probe (PerfProbe::instance); the framework side depends on the
            // PerfSnapshotProviderInterface contract only
            $perfSampler = new PerfSampler(PerfProbe::instance(), $redisFactory, $serviceId, 5);
            $timer->add($perfSampler->intervalSeconds(), $perfSampler->sample(...), true);
        });

        // 挂载处理器（register 追加式：onConnect/onMessage/onClose/事件订阅 + onStart/onStop 钩子）——
        // 不调用 start()：单入口多频道形态由调用方统一 runAll
        // Mounts all handlers (register appends: connect/message/close/event subscription + the onStart/onStop hooks) —
        // start() is intentionally not called: single-entry multi-channel shapes run runAll() once
        $map->register();

        return $map;
    }

    /**
     * 订阅房间生命周期信封并转发为协议帧（ADR-024 §D-A 双向通知的客户端投递侧）：
     * 信封由房间 join/leave/settle 发布入共享宿主总线，帧末 flushOutbox 统一 flush 分发，
     * 经宿主 sendToEntity 定向入队（targetScope → 连接），与 AOI enter/leave 转发同构。
     * Subscribes room-lifecycle envelopes and forwards them as protocol frames (the client-delivery side of
     * ADR-024 §D-A's bidirectional notices): envelopes published by room join/leave/settle enter the shared host
     * bus, are dispatched by the frame-end flushOutbox flush, and enqueue directed via the host's sendToEntity
     * (targetScope → connection), isomorphic to the AOI enter/leave forwarding.
     */
    private static function subscribeRoomEnvelopes(EventBusInterface $bus, MapServer $map): void
    {
        $bus->subscribe(RoomInstanceInterface::EVENT_MEMBER_ENTER, static function (EventEnvelope $envelope) use ($map): void {
            if ($envelope->targetScope === null) {
                return;
            }
            $position = $envelope->payload['position'] ?? null;
            $map->sendToEntity($envelope->targetScope, 'room:member_enter', [
                'id' => $envelope->source,
                'roomId' => self::payloadRoomId($envelope),
                'position' => is_array($position) && isset($position['x'], $position['y']) ? ['x' => (int) $position['x'], 'y' => (int) $position['y']] : ['x' => 0, 'y' => 0],
            ]);
        });

        $bus->subscribe(RoomInstanceInterface::EVENT_ROOM_SNAPSHOT, static function (EventEnvelope $envelope) use ($map): void {
            if ($envelope->targetScope === null) {
                return;
            }
            // 成员清单转并行标量列表（二进制协议 LIST 元素仅支持标量/POS）
            // The member list becomes parallel scalar lists (the binary protocol's LIST elements support scalars/POS only)
            $members = $envelope->payload['members'] ?? [];
            $memberIds = [];
            $positions = [];
            if (is_array($members)) {
                foreach ($members as $member) {
                    if (!is_array($member)) {
                        continue;
                    }
                    $memberIds[] = (string) ($member['id'] ?? '');
                    $position = $member['position'] ?? null;
                    $positions[] = is_array($position) && isset($position['x'], $position['y']) ? ['x' => (int) $position['x'], 'y' => (int) $position['y']] : ['x' => 0, 'y' => 0];
                }
            }
            $map->sendToEntity($envelope->targetScope, 'room:snapshot', [
                'roomId' => $envelope->source,
                'memberIds' => $memberIds,
                'positions' => $positions,
            ]);
        });

        $bus->subscribe(RoomInstanceInterface::EVENT_MEMBER_LEAVE, static function (EventEnvelope $envelope) use ($map): void {
            if ($envelope->targetScope === null) {
                return;
            }
            $map->sendToEntity($envelope->targetScope, 'room:member_leave', [
                'id' => $envelope->source,
                'roomId' => self::payloadRoomId($envelope),
            ]);
        });

        $bus->subscribe(RoomInstanceInterface::EVENT_ROOM_LEFT, static function (EventEnvelope $envelope) use ($map): void {
            if ($envelope->targetScope === null) {
                return;
            }
            $map->sendToEntity($envelope->targetScope, 'room:left', ['roomId' => $envelope->source]);
        });

        $bus->subscribe(RoomInstanceInterface::EVENT_ROOM_CLOSED, static function (EventEnvelope $envelope) use ($map): void {
            if ($envelope->targetScope === null) {
                return;
            }
            $map->sendToEntity($envelope->targetScope, 'room:closed', ['roomId' => $envelope->source]);
        });
    }

    /**
     * 从信封负载提取 roomId（缺失回退空串）。
     * Extracts roomId from the envelope payload (falls back to an empty string).
     */
    private static function payloadRoomId(EventEnvelope $envelope): string
    {
        $roomId = $envelope->payload['roomId'] ?? '';

        return is_string($roomId) ? $roomId : '';
    }

    /**
     * mmorpg 配置装配（P2 任务链注入）：NYTHROS_MMORPG_CHAINS='id=q1,q2;id=q3,q4' 解析为任务链配置
     * （分号分隔链、'=' 分隔链 id 与逗号分隔任务顺序）；env 缺失时返回缺省配置（无任务链）。
     * 解析属 starter-kit 组装关切——framework MmorpgConfig 保持 env 无关。
     * The mmorpg config assembly (the P2 quest-chain injection): NYTHROS_MMORPG_CHAINS='id=q1,q2;id=q3,q4'
     * parses into quest chains (semicolon-separated chains, '=' splitting the chain id from the comma-ordered
     * quest ids); the env absent yields the default config (no chains). Parsing is a starter-kit assembly
     * concern — the framework MmorpgConfig stays env-agnostic.
     */
    private static function mmorpgConfigFromEnv(): MmorpgConfig
    {
        // 玩家自动复活延迟（P6a）：NYTHROS_MMORPG_PLAYER_RESPAWN_MS 注入（缺省 0 = 关闭，复活仅路由驱动）。
        // The player auto-revive delay (the P6a): injected via NYTHROS_MMORPG_PLAYER_RESPAWN_MS (default 0 = off,
        // revive stays route-driven).
        $playerRespawnMs = 0;
        $rawRespawn = getenv('NYTHROS_MMORPG_PLAYER_RESPAWN_MS');
        if (is_string($rawRespawn) && preg_match('/^\d+$/', trim($rawRespawn)) === 1) {
            $playerRespawnMs = (int) trim($rawRespawn);
        }

        // 热区策略（P9a 区域降频）：NYTHROS_MMORPG_HOT_CELL='12:1,25:2,0:4'（格子玩家密度:分频，0=无界
        // 兜底）+ 可选 HYSTERESIS/NEIGHBOR_RADIUS；缺省 null = 未启用，实体恒逐帧（零影响）。
        // The hot-cell policy (the P9a region downgrade): NYTHROS_MMORPG_HOT_CELL='12:1,25:2,0:4'
        // (players-in-cell:divisor, 0 = the unbounded backstop) plus optional HYSTERESIS/NEIGHBOR_RADIUS;
        // default null = off, entities always update per frame (zero impact).
        $hotCell = null;
        $rawHotCell = getenv('NYTHROS_MMORPG_HOT_CELL');
        if (is_string($rawHotCell) && trim($rawHotCell) !== '') {
            $tiers = [];
            foreach (explode(',', trim($rawHotCell)) as $segment) {
                $pair = explode(':', trim($segment));
                if (count($pair) !== 2 || !preg_match('/^\d+$/', trim($pair[0])) || !preg_match('/^\d+$/', trim($pair[1]))) {
                    throw new \InvalidArgumentException(sprintf('NYTHROS_MMORPG_HOT_CELL 段非法：%s（应为 密度:分频）', $segment));
                }
                $tiers[] = ['untilPlayers' => (int) trim($pair[0]), 'divisor' => (int) trim($pair[1])];
            }
            $hysteresis = 5;
            $rawHysteresis = getenv('NYTHROS_MMORPG_HOT_CELL_HYSTERESIS_S');
            if (is_string($rawHysteresis) && preg_match('/^\d+$/', trim($rawHysteresis)) === 1) {
                $hysteresis = (int) trim($rawHysteresis);
            }
            $neighborRadius = 0;
            $rawRadius = getenv('NYTHROS_MMORPG_HOT_CELL_NEIGHBOR_RADIUS');
            if (is_string($rawRadius) && preg_match('/^\d+$/', trim($rawRadius)) === 1) {
                $neighborRadius = (int) trim($rawRadius);
            }
            $hotCell = new HotCellPolicy(tiers: $tiers, hysteresisSeconds: $hysteresis, neighborRadius: $neighborRadius);
        }

        // 出生安全区（P7c）：NYTHROS_MMORPG_SAFE_ZONE='x,y,r' 注入（缺省 null = 未声明，零门禁）。
        // The spawn safe zone (the P7c): injected via NYTHROS_MMORPG_SAFE_ZONE='x,y,r' (default null = undeclared,
        // no gates).
        $safeZone = null;
        $rawZone = getenv('NYTHROS_MMORPG_SAFE_ZONE');
        if (is_string($rawZone) && preg_match('/^-?\d+\s*,\s*-?\d+\s*,\s*\d+$/', trim($rawZone)) === 1) {
            [$zx, $zy, $zr] = array_map('intval', explode(',', trim($rawZone)));
            $safeZone = ['x' => $zx, 'y' => $zy, 'radius' => $zr];
        }

        // PVP 开关（P13 对抗治理）：NYTHROS_PVP='1' 显式开启（缺省关闭——治理裁决，接入前 PVP 事实上可行）。
        // The PVP switch (the P13 governance): explicitly enabled via NYTHROS_PVP='1' (off by default — the
        // governance ruling; PVP was de-facto possible pre-integration).
        $pvpEnabled = getenv('NYTHROS_PVP') === '1';

        // 击杀归属裁决（P13 AoE 多源归属）：NYTHROS_KILL_CREDIT='damage_leader' 切伤害账本最高者
        // （缺省 last_hit 零行为变化）；白名单外值 fail-fast。
        // The kill-credit ruling (the P13 AoE multi-source attribution): NYTHROS_KILL_CREDIT='damage_leader'
        // switches to the damage-ledger leader (the default last_hit keeps zero behavior change); values outside
        // the whitelist fail fast.
        $killCredit = MmorpgConfig::KILL_CREDIT_LAST_HIT;
        $rawKillCredit = getenv('NYTHROS_KILL_CREDIT');
        if (is_string($rawKillCredit) && trim($rawKillCredit) !== '') {
            $candidate = trim($rawKillCredit);
            if (!in_array($candidate, [MmorpgConfig::KILL_CREDIT_LAST_HIT, MmorpgConfig::KILL_CREDIT_DAMAGE_LEADER], true)) {
                throw new \InvalidArgumentException(sprintf('NYTHROS_KILL_CREDIT 非法：%s（可选 last_hit|damage_leader）', $candidate));
            }
            $killCredit = $candidate;
        }

        // 死亡掉落策略（P13 死亡掉落归属，参数草案）：NYTHROS_DEATH_DROP='1' 启用（缺省关闭 = 死亡不掉落），
        // 可选细化：RATIO（0-100 逐单位百分比，缺省 30）/ WINDOW_SECONDS（归属窗口，缺省 60）/
        // MAX（单次死亡条目上限，缺省 8）/ BOUND（逗号分隔绑定物品，缺省 gold——账本货币不掉落）。
        // The death-drop policy (the P13 death-drop ownership, the parameter draft): enabled via
        // NYTHROS_DEATH_DROP='1' (off by default = deaths drop nothing), with optional refinements: RATIO
        // (the 0-100 per-unit percent, default 30) / WINDOW_SECONDS (the ownership window, default 60) /
        // MAX (the per-death entry cap, default 8) / BOUND (comma-separated bound items, default gold —
        // the ledger currency never drops).
        $deathDrop = null;
        if (getenv('NYTHROS_DEATH_DROP') === '1') {
            $ratio = 30;
            $rawRatio = getenv('NYTHROS_DEATH_DROP_RATIO');
            if (is_string($rawRatio) && preg_match('/^\d+$/', trim($rawRatio)) === 1) {
                $ratio = (int) trim($rawRatio);
            }
            $windowSeconds = 60;
            $rawWindow = getenv('NYTHROS_DEATH_DROP_WINDOW_SECONDS');
            if (is_string($rawWindow) && preg_match('/^\d+$/', trim($rawWindow)) === 1) {
                $windowSeconds = (int) trim($rawWindow);
            }
            $maxDrops = 8;
            $rawMax = getenv('NYTHROS_DEATH_DROP_MAX');
            if (is_string($rawMax) && preg_match('/^\d+$/', trim($rawMax)) === 1) {
                $maxDrops = (int) trim($rawMax);
            }
            $boundRaw = getenv('NYTHROS_DEATH_DROP_BOUND');
            $boundItems = is_string($boundRaw) && trim($boundRaw) !== ''
                ? array_values(array_filter(array_map('trim', explode(',', $boundRaw)), static fn (string $id): bool => $id !== ''))
                : ['gold'];
            $deathDrop = new DeathDropPolicy($ratio, max(1, $windowSeconds), max(1, $maxDrops), $boundItems);
        }

        $raw = getenv('NYTHROS_MMORPG_CHAINS');
        if (!is_string($raw) || trim($raw) === '') {
            return new MmorpgConfig(playerRespawnMs: $playerRespawnMs, safeZone: $safeZone, hotCell: $hotCell, deathDrop: $deathDrop, pvpEnabled: $pvpEnabled, killCredit: $killCredit);
        }

        $chains = [];
        foreach (explode(';', $raw) as $segment) {
            $segment = trim($segment);
            if ($segment === '') {
                continue;
            }
            $parts = explode('=', $segment, 2);
            if (count($parts) !== 2) {
                continue; // 非法段跳过（装配期宽容：不因单个坏链阻断启动） Invalid segments are skipped (assembly-tolerant: one bad chain never blocks boot).
            }
            [$chainId, $questList] = [trim($parts[0]), trim($parts[1])];
            $questIds = array_values(array_filter(array_map('trim', explode(',', $questList)), static fn (string $id): bool => $id !== ''));
            if ($chainId === '' || $questIds === []) {
                continue;
            }
            $chains[] = new QuestChain($chainId, $questIds);
        }

        return new MmorpgConfig(questChains: $chains, playerRespawnMs: $playerRespawnMs, safeZone: $safeZone, hotCell: $hotCell, deathDrop: $deathDrop, pvpEnabled: $pvpEnabled, killCredit: $killCredit);
    }
}
