<?php

declare(strict_types=1);

namespace Nythros\Framework\Capability;

/**
 * 能力目录（单一事实源）：框架对「可挑选的能力块」的集中声明——名称、说明、装配入口（env 门/插件类/
 * 服务类）与所属层。make:capabilities 命令与开发者文档同源于此,不在两处维护两张清单。
 * The capability catalog (single source of truth): the framework's central declaration of its selectable
 * capability blocks — name, summary, assembly entry (env gate / plugin / service class) and owning layer. The
 * make:capabilities command and developer docs share this one list instead of drifting in two places.
 *
 * 目录语义:
 * - key 即能力开关名（与 FeaturePluginInterface::featureName 与 NYTHROS_FEATURE_<KEY> 对齐）;
 * - 'env' 为 demo/入门套件装配门的既有形态（NYTHROS_MMORPG=1 等）,能力开关是其上的第二闸;
 * - 'core' 条目恒装配（无开关）,列出只为报告完整性。
 * Catalog semantics: the key IS the feature flag name (aligned with FeaturePluginInterface::featureName and
 * NYTHROS_FEATURE_<KEY>); 'env' names the starter/demo assembly gate the feature flag stacks onto; 'core'
 * entries are always assembled (listed for report completeness only).
 */
final class CapabilityCatalog
{
    /**
     * 能力清单（只读静态目录;新能力块接入框架时必须在此登记,CI 测试锁 key 唯一性）。
     * The capability list (read-only static catalog; new framework capability blocks must register here, with a
     * CI test locking key uniqueness).
     *
     * @return array<string, array{summary: string, layer: string, env: ?string, entry: ?string}>
     */
    public static function all(): array
    {
        return [
            'combat'     => ['summary' => '战斗结算链（伤害/技能冷却/掉落/团队归属）', 'layer' => 'framework/Combat', 'env' => null, 'entry' => \Nythros\Framework\Combat\CombatService::class],
            'skill'      => ['summary' => '技能数据仓库（定义外置 skills.php）', 'layer' => 'framework/Plugin', 'env' => null, 'entry' => \Nythros\Framework\Plugin\Skill\SkillPlugin::class],
            'item'       => ['summary' => '物品数据仓库（定义外置）', 'layer' => 'framework/Plugin', 'env' => null, 'entry' => \Nythros\Framework\Plugin\Item\ItemPlugin::class],
            'buff'       => ['summary' => 'Buff 运行时（属性修正/DOT/叠加,tick 驱动）', 'layer' => 'framework/Plugin', 'env' => null, 'entry' => \Nythros\Framework\Plugin\Buff\BuffPlugin::class],
            'quest'      => ['summary' => '任务状态机（击杀/收集/对话三进度源 + 链式解锁 + 写回缓冲）', 'layer' => 'framework/Quest', 'env' => 'NYTHROS_GAMEPLAY', 'entry' => \Nythros\Framework\Quest\QuestService::class],
            'inventory'  => ['summary' => '背包（会话缓存 + Redis 权威写回,快照导出）', 'layer' => 'framework/Inventory', 'env' => null, 'entry' => \Nythros\Framework\Inventory\RedisInventoryStore::class],
            'social'     => ['summary' => '好友/组队/公会/聊天五通道/位置快照', 'layer' => 'framework/Social', 'env' => null, 'entry' => \Nythros\Framework\Social\SocialService::class],
            'mail'       => ['summary' => '邮件（含附件 claim 协议）', 'layer' => 'framework/Mail', 'env' => 'NYTHROS_GAMEPLAY', 'entry' => \Nythros\Framework\Mail\MailService::class],
            'auction'    => ['summary' => '拍卖行（挂单/出价/成交,强一致例外区）', 'layer' => 'framework/Auction', 'env' => 'NYTHROS_GAMEPLAY', 'entry' => \Nythros\Framework\Auction\AuctionService::class],
            'economy'    => ['summary' => '货币账本（Redis 原子 Lua,强一致例外区）', 'layer' => 'framework/Auction', 'env' => 'NYTHROS_GAMEPLAY', 'entry' => \Nythros\Framework\Auction\CurrencyLedger::class],
            'matching'   => ['summary' => '匹配撮合（票据/条件/房间编排钩子）', 'layer' => 'framework/Matching', 'env' => 'NYTHROS_ROOMS', 'entry' => \Nythros\Framework\Matching\MatchingService::class],
            'rooms'      => ['summary' => '副本房间实例（RoomInstance + AoE 批量管线 + 结算）', 'layer' => 'engine/World', 'env' => 'NYTHROS_ROOMS', 'entry' => \Nythros\World\RoomInstanceManager::class],
            'leaderboard' => ['summary' => '排行榜（Redis ZSET,读侧）', 'layer' => 'framework/Leaderboard', 'env' => null, 'entry' => \Nythros\Framework\Leaderboard\RedisLeaderboardStore::class],
            'gm'         => ['summary' => 'GM 命令总线（权限/踢人/广播/排空钩子）', 'layer' => 'framework/Gm', 'env' => null, 'entry' => \Nythros\Framework\Gm\GmCommandBus::class],
            'mmorpg'     => ['summary' => 'MMORPG 玩法包（威胁/重生/热区治理/死亡掉落策略）', 'layer' => 'framework/Game', 'env' => 'NYTHROS_MMORPG', 'entry' => \Nythros\Framework\Game\Mmorpg\MmorpgPlugin::class],
            'horde'      => ['summary' => '一波流生存玩法包（波次/掉落雨/出生保护/结算）', 'layer' => 'framework/Game', 'env' => 'NYTHROS_ROOMS', 'entry' => \Nythros\Framework\Game\Horde\HordePlugin::class],
            'persistence' => ['summary' => '玩家归档导出（export=Redis 权威+Stream+exporter / mysql=直写回退）', 'layer' => 'framework/Persistence', 'env' => null, 'entry' => \Nythros\Framework\Persistence\RedisExportPipeline::class],
            'observability' => ['summary' => '性能探针 + Redis 采样导出（Prometheus 端点另起 metrics-exporter）', 'layer' => 'framework/Observability', 'env' => null, 'entry' => \Nythros\Framework\Observability\PerfSampler::class],
        ];
    }

    /**
     * 单个能力的条目查询;未登记返回 null。
     * Look up one capability entry; null when unregistered.
     *
     * @return array{summary: string, layer: string, env: ?string, entry: ?string}|null
     */
    public static function get(string $capability): ?array
    {
        return self::all()[$capability] ?? null;
    }
}
