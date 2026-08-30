<?php

declare(strict_types=1);

namespace Nythros\Demo;

use InvalidArgumentException;
use Nythros\Demo\Gameplay\GameplayConfig;
use Nythros\Framework\Combat\DropTable;
use Nythros\Framework\Config\ConfigRepository;
use Nythros\Framework\Config\ConfigSchema;
use Nythros\Framework\Plugin\Item\ItemRepository;
use Nythros\Framework\Plugin\Skill\SkillDefinition;
use Nythros\Framework\Plugin\Skill\SkillRepository;

/**
 * 玩法数据表装配中枢（P11 玩法数据外置）：三张内容表（gameplay / skills / drops）的
 * schema 声明、现状缺省表、feature 过滤与到框架对象（SkillDefinition/DropTable/GameplayConfig）的装配。
 * 数据来源：NYTHROS_CONFIG_DIR 目录下的同名 *.php 文件经 ConfigRepository 装载（schema fail-fast +
 * 热载回滚）；文件缺席时回落本类缺省表（与外置前硬编码值逐字段一致，零破坏）。
 * The gameplay-table assembly hub (the P11 data externalization): schema declarations, status-quo default tables,
 * feature filtering, and the assembly into framework objects (SkillDefinition/DropTable/GameplayConfig) for the
 * three content tables (gameplay / skills / drops). Data source: same-named *.php files under NYTHROS_CONFIG_DIR
 * loaded through ConfigRepository (schema fail-fast + hot-reload rollback); missing files fall back to this class's
 * default tables (field-for-field identical to the pre-externalization hardcoded values, zero breakage).
 *
 * feature 维度：行可声明 feature（mmorpg/rooms/economy/gameplay/anticheat），仅对应 env 开关启用时装配——
 * 取代此前 MapChannelFactory 内「mmorpg 块注册 taunt 系技能 / economy 分支追加 sword 掉落」的硬编码条件。
 * The feature dimension: a row may declare a feature (mmorpg/rooms/economy/gameplay/anticheat) and is assembled
 * only when the matching env switch is on — replacing MapChannelFactory's hardcoded conditionals (the mmorpg-block
 * taunt-skill registrations and the economy-branch sword drop entry).
 *
 * @internal starter-kit 装配层（demo 包不对外承诺 API） starter-kit assembly (no API promise outside the demo package).
 */
final class GameplayTables
{
    public const FEATURE_MMORPG = 'mmorpg';
    public const FEATURE_ROOMS = 'rooms';
    public const FEATURE_ECONOMY = 'economy';
    public const FEATURE_GAMEPLAY = 'gameplay';
    public const FEATURE_ANTICHEAT = 'anticheat';

    /** 表键 => 特性 env 开关（feature 行的启用判定源） Table key => feature env switch (the enablement source for feature-tagged rows). */
    private const FEATURE_ENVS = [
        self::FEATURE_MMORPG => 'NYTHROS_MMORPG',
        self::FEATURE_ROOMS => 'NYTHROS_ROOMS',
        self::FEATURE_ECONOMY => 'NYTHROS_ECONOMY',
        self::FEATURE_GAMEPLAY => 'NYTHROS_GAMEPLAY',
        self::FEATURE_ANTICHEAT => 'NYTHROS_ANTICHEAT',
    ];

    /** ConfigRepository 挂载的玩法表键（同名 *.php 文件 + 同名配置键） The gameplay table keys mounted in ConfigRepository (same-named *.php files and config keys). */
    public const TABLE_KEYS = ['gameplay', 'skills', 'drops'];

    /**
     * 三张表的 schema 声明（ConfigRepository 注册文件时挂载：启动 fail-fast + 热载回滚共用）。
     * The three tables' schema declarations (mounted when ConfigRepository registers the files: shared by startup
     * fail-fast and hot-reload rollback).
     *
     * @return array<string, ConfigSchema>
     */
    public static function schemas(): array
    {
        $feature = ConfigSchema::enum(...array_keys(self::FEATURE_ENVS))->nullable()->optional(null);

        return [
            'gameplay' => ConfigSchema::shape([
                'spawnPoint' => ConfigSchema::shape([
                    'x' => ConfigSchema::int(),
                    'y' => ConfigSchema::int(),
                ]),
                'player' => ConfigSchema::shape([
                    'maxHp' => ConfigSchema::int(min: 1),
                ])->optional(['maxHp' => 100]),
                'monsters' => ConfigSchema::listOf(ConfigSchema::shape([
                    'id' => ConfigSchema::string(minLength: 1),
                    'typeId' => ConfigSchema::string(minLength: 1),
                    'maxHp' => ConfigSchema::int(min: 1),
                    'anchor' => ConfigSchema::shape([
                        'x' => ConfigSchema::int(),
                        'y' => ConfigSchema::int(),
                    ]),
                    'patrolRadius' => ConfigSchema::int(min: 0)->nullable()->optional(null),
                    'respawnMs' => ConfigSchema::int(min: 1)->nullable()->optional(null),
                ])),
            ]),
            'skills' => ConfigSchema::listOf(ConfigSchema::shape([
                'id' => ConfigSchema::string(minLength: 1),
                'name' => ConfigSchema::string(minLength: 1),
                'damageMultiplier' => ConfigSchema::float(min: 0.0),
                'cooldownSeconds' => ConfigSchema::float(min: 0.0),
                'range' => ConfigSchema::int(min: 0),
                'aoe' => ConfigSchema::shape([
                    'shape' => ConfigSchema::enum(SkillDefinition::SHAPE_CIRCLE, SkillDefinition::SHAPE_RECT),
                    'radius' => ConfigSchema::int(min: 0)->optional(0),
                    'width' => ConfigSchema::int(min: 0)->optional(0),
                    'height' => ConfigSchema::int(min: 0)->optional(0),
                ])->nullable()->optional(null),
                'mpCost' => ConfigSchema::int(min: 0)->optional(0),
                'itemCostId' => ConfigSchema::string(minLength: 1)->nullable()->optional(null),
                'itemCostCount' => ConfigSchema::int(min: 1)->optional(1),
                'tauntThreat' => ConfigSchema::float(min: 0.0)->optional(0.0),
                'feature' => $feature,
            ])),
            'drops' => ConfigSchema::shape([
                'noDropWeight' => ConfigSchema::int(min: 0)->optional(0),
                'entries' => ConfigSchema::listOf(ConfigSchema::shape([
                    'itemId' => ConfigSchema::string(minLength: 1),
                    'weight' => ConfigSchema::int(min: 1),
                    'minCount' => ConfigSchema::int(min: 1)->optional(1),
                    'maxCount' => ConfigSchema::int(min: 1)->optional(1),
                    'feature' => $feature,
                ])),
            ]),
        ];
    }

    /**
     * 现状缺省表（零破坏口径）：与外置前 MapChannelFactory 硬编码值逐字段一致；packages/demo/config/ 下的
     * 参考表文件与其同值（feature 标注显式化）。
     * The status-quo default tables (the zero-breakage baseline): field-for-field identical to MapChannelFactory's
     * pre-externalization hardcoded values; the reference table files under packages/demo/config/ share these
     * values (with the feature tags made explicit).
     *
     * @return array<mixed>
     */
    public static function defaultTable(string $key): array
    {
        return match ($key) {
            'gameplay' => [
                'spawnPoint' => ['x' => 0, 'y' => 0],
                'player' => ['maxHp' => 100],
                'monsters' => [
                    ['id' => 'monster-1', 'typeId' => 'slime', 'maxHp' => 100, 'anchor' => ['x' => 15, 'y' => 15], 'patrolRadius' => 4],
                    ['id' => 'monster-2', 'typeId' => 'wolf', 'maxHp' => 150, 'anchor' => ['x' => -6, 'y' => -6], 'patrolRadius' => 4],
                ],
            ],
            'skills' => [
                ['id' => 'fireball', 'name' => '火球术', 'damageMultiplier' => 1.5, 'cooldownSeconds' => 2.0, 'range' => 3, 'aoe' => ['shape' => SkillDefinition::SHAPE_CIRCLE, 'radius' => 70], 'mpCost' => 10],
                ['id' => 'ice_bolt', 'name' => '冰锥术', 'damageMultiplier' => 1.2, 'cooldownSeconds' => 1.5, 'range' => 3],
                ['id' => 'taunt', 'name' => '嘲讽', 'damageMultiplier' => 0.6, 'cooldownSeconds' => 6.0, 'range' => 3, 'tauntThreat' => 1000.0, 'feature' => self::FEATURE_MMORPG],
                ['id' => 'taunt_aoe', 'name' => '嘲讽风暴', 'damageMultiplier' => 0.3, 'cooldownSeconds' => 8.0, 'range' => 10, 'aoe' => ['shape' => SkillDefinition::SHAPE_CIRCLE, 'radius' => 10], 'tauntThreat' => 1000.0, 'feature' => self::FEATURE_MMORPG],
                ['id' => 'slash_rect', 'name' => '矩形斩击', 'damageMultiplier' => 0.8, 'cooldownSeconds' => 5.0, 'range' => 6, 'aoe' => ['shape' => SkillDefinition::SHAPE_RECT, 'width' => 6, 'height' => 4], 'feature' => self::FEATURE_MMORPG],
            ],
            'drops' => [
                'noDropWeight' => 0,
                'entries' => [
                    ['itemId' => 'bone', 'weight' => 3],
                    ['itemId' => 'potion', 'weight' => 1],
                    ['itemId' => 'sword', 'weight' => 1, 'feature' => self::FEATURE_ECONOMY],
                ],
            ],
            default => throw new InvalidArgumentException(sprintf('未知玩法表键: %s', $key)),
        };
    }

    /**
     * 当前进程启用的 feature 集（env 开关快照，装配期读一次）。
     * The process's enabled feature set (an env-switch snapshot, read once at assembly).
     *
     * @return array<string, bool>
     */
    public static function enabledFeatures(): array
    {
        $enabled = [];
        foreach (self::FEATURE_ENVS as $feature => $env) {
            $enabled[$feature] = getenv($env) === '1';
        }

        return $enabled;
    }

    /**
     * 行过滤：未标 feature 的行恒保留；标了 feature 的行仅在该 feature 启用时保留。
     * Row filtering: feature-less rows always stay; feature-tagged rows stay only when their feature is enabled.
     *
     * @param array<mixed> $rows 原始行 Raw rows.
     * @param array<string, bool> $enabled 启用 feature 集 The enabled feature set.
     * @return list<array<string, mixed>>
     */
    public static function filterRowsByFeature(array $rows, array $enabled): array
    {
        $kept = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $feature = $row['feature'] ?? null;
            if (is_string($feature) && !($enabled[$feature] ?? false)) {
                continue;
            }
            unset($row['feature']);
            $kept[] = $row;
        }

        return $kept;
    }

    /**
     * 装配技能表：过滤 feature → 逐行构造 SkillDefinition 注册进仓库；返回本次装配的 id 集
     * （热载 diff 的基线）。跨字段语义（AoE 形状参数完备性）在此 fail-fast，错误消息带技能 id。
     * Assembles the skill table: filters by feature → builds a SkillDefinition per row into the repository; returns
     * the assembled id set (the hot-reload diff baseline). Cross-field semantics (AoE shape-parameter completeness)
     * fail fast here with the skill id in the message.
     *
     * @param array<mixed> $rows 技能表行（原始，含 feature） Skill-table rows (raw, feature included).
     * @param array<string, bool> $enabled 启用 feature 集 The enabled feature set.
     * @return list<string> 本次装配的技能 id The assembled skill ids.
     */
    public static function applySkills(SkillRepository $skills, array $rows, array $enabled): array
    {
        $applied = [];
        foreach (self::filterRowsByFeature($rows, $enabled) as $row) {
            $skills->register(self::skillDefinitionFromRow($row));
            $applied[] = (string) $row['id'];
        }

        return $applied;
    }

    /**
     * 技能表热载重放（config.changed → skills）：表内 id 全量重注册（同 id 覆盖 = 增改生效），
     * 上一轮由配置装配、本轮缺席的 id 摘除（删除行生效）；手写注册的非配置技能不受影响。
     * Skill-table hot-reload replay (config.changed → skills): every table id re-registers (same id overwrites =
     * additions and edits take effect), ids assembled from config last round but absent this round are removed
     * (deletions take effect); hand-registered non-config skills stay untouched.
     *
     * @param array<mixed> $rows 新表行（原始，含 feature） The new table rows (raw, feature included).
     * @param array<string, bool> $enabled 启用 feature 集 The enabled feature set.
     * @param list<string> $appliedIds [引用] 上一轮配置装配的 id 集；返回后更新为本轮 [by reference] the ids
     *   assembled from config last round; updated to this round's set on return.
     */
    public static function reapplySkills(SkillRepository $skills, array $rows, array $enabled, array &$appliedIds): void
    {
        $current = self::applySkills($skills, $rows, $enabled);
        foreach (array_diff($appliedIds, $current) as $removedId) {
            $skills->remove($removedId);
        }
        $appliedIds = $current;
    }

    /**
     * 装配掉落表：过滤 feature → fromRows 构建；条目 itemId 必须已在物品仓库注册（引用完整性 fail-fast，
     * 掉落风暴roll 出未注册物品 = 拾取链断裂，属装配错误）。
     * Assembles the drop table: filters by feature → builds via fromRows; each entry's itemId must already be
     * registered in the item repository (referential-integrity fail-fast — rolling an unregistered item would
     * break the pickup chain, an assembly error).
     *
     * @param array<mixed> $table drops 表（原始，含 feature） The drops table (raw, feature included).
     * @param array<string, bool> $enabled 启用 feature 集 The enabled feature set.
     */
    public static function buildDropTable(array $table, array $enabled, ItemRepository $items): DropTable
    {
        $entries = $table['entries'] ?? [];
        if (!is_array($entries)) {
            $entries = [];
        }
        $rows = self::filterRowsByFeature($entries, $enabled);
        foreach ($rows as $row) {
            $itemId = (string) ($row['itemId'] ?? '');
            if ($items->get($itemId) === null) {
                throw new InvalidArgumentException(sprintf('掉落表引用未注册物品: %s（先在物品表注册）', $itemId));
            }
        }

        return DropTable::fromRows($rows, (int) ($table['noDropWeight'] ?? 0));
    }

    /**
     * 技能行 → SkillDefinition（schema 已保字段类型；AoE 形状参数完备性在此 fail-fast）。
     * A skill row → SkillDefinition (the schema guarantees field types; AoE shape-parameter completeness fails fast here).
     *
     * @param array<string, mixed> $row 已滤除 feature 的行 The row with feature stripped.
     */
    private static function skillDefinitionFromRow(array $row): SkillDefinition
    {
        $aoe = null;
        $aoeData = is_array($row['aoe'] ?? null) ? $row['aoe'] : null;
        if ($aoeData !== null) {
            $shape = (string) $aoeData['shape'];
            $width = (int) ($aoeData['width'] ?? 0);
            $height = (int) ($aoeData['height'] ?? 0);
            $radius = (int) ($aoeData['radius'] ?? 0);
            $aoe = $shape === SkillDefinition::SHAPE_RECT
                ? ['shape' => $shape, 'width' => $width, 'height' => $height]
                : ['shape' => $shape, 'radius' => $radius];

            $complete = $shape === SkillDefinition::SHAPE_RECT
                ? $width > 0 && $height > 0
                : $radius > 0;
            if (!$complete) {
                throw new InvalidArgumentException(sprintf(
                    '技能 %s 的 AoE 形状参数不完整：shape=%s 需要 %s > 0',
                    (string) ($row['id'] ?? '?'),
                    $shape,
                    $shape === SkillDefinition::SHAPE_RECT ? 'width/height' : 'radius',
                ));
            }
        }

        return new SkillDefinition(
            (string) ($row['id'] ?? ''),
            (string) ($row['name'] ?? ''),
            (float) ($row['damageMultiplier'] ?? 0),
            (float) ($row['cooldownSeconds'] ?? 0),
            (int) ($row['range'] ?? 0),
            $aoe,
            (int) ($row['mpCost'] ?? 0),
            isset($row['itemCostId']) ? (string) $row['itemCostId'] : null,
            (int) ($row['itemCostCount'] ?? 1),
            (float) ($row['tauntThreat'] ?? 0.0),
        );
    }

    /**
     * 从配置仓库读表：键已注册（文件存在且通过校验）返回归一化数据，否则返回缺省表。
     * Reads a table from the config repository: a registered key (file present and validated) yields the
     * normalized data, otherwise the default table.
     *
     * @return array<mixed>
     */
    public static function table(?ConfigRepository $repository, string $key): array
    {
        $raw = $repository?->config()?->get($key);
        if (is_array($raw)) {
            return $raw;
        }

        return self::defaultTable($key);
    }
}
