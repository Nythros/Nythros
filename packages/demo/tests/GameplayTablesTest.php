<?php

declare(strict_types=1);

namespace Nythros\Demo\Tests;

use InvalidArgumentException;
use Nythros\Demo\Gameplay\GameplayConfig;
use Nythros\Demo\GameplayTables;
use Nythros\Framework\Config\ConfigRepository;
use Nythros\Framework\Event\EventDispatcher;
use Nythros\Framework\Plugin\Item\ItemDefinition;
use Nythros\Framework\Plugin\Item\ItemRepository;
use Nythros\Framework\Plugin\Skill\SkillRepository;
use PHPUnit\Framework\TestCase;

/**
 * GameplayTablesTest - 覆盖 P11 玩法数据表装配中枢：缺省表过 schema、参考表文件与缺省值同源
 * （反漂移）、feature 过滤装配、热载重放 diff（增改删）、掉落表引用完整性 fail-fast。
 * GameplayTablesTest - covers the P11 gameplay-table assembly hub: default tables pass the schema, the
 * reference table files stay in sync with the defaults (anti-drift), feature-filtered assembly, hot-reload
 * replay diffs (add/edit/remove) and drop-table referential-integrity fail-fast.
 */
final class GameplayTablesTest extends TestCase
{
    private const FEATURES_OFF = ['mmorpg' => false, 'rooms' => false, 'economy' => false, 'gameplay' => false, 'anticheat' => false];

    public function testDefaultTablesPassTheirSchemas(): void
    {
        $schemas = GameplayTables::schemas();
        foreach (GameplayTables::TABLE_KEYS as $key) {
            self::assertSame([], $schemas[$key]->errors(GameplayTables::defaultTable($key)), "缺省表 $key 必须过自身 schema。The default $key table must pass its own schema.");
        }
    }

    public function testReferenceConfigFilesMatchDefaults(): void
    {
        // 反漂移：packages/demo/config/ 参考表必须过 schema，且缺省表经同一 schema 归一化
        // （optional 默认值回填）后与文件产出同值——两份事实源永远一致
        // Anti-drift: the packages/demo/config/ reference tables must pass the schema, and the default table,
        // normalized through the same schema (optional defaults back-filled), must equal the file output — the two
        // sources of truth stay identical forever.
        $schemas = GameplayTables::schemas();
        $repo = new ConfigRepository(new EventDispatcher());
        foreach (GameplayTables::TABLE_KEYS as $key) {
            $path = dirname(__DIR__) . '/config/' . $key . '.php';
            $repo->registerFile($key, $path, $schemas[$key]);
            self::assertSame(
                $schemas[$key]->normalized(GameplayTables::defaultTable($key)),
                $repo->get($key),
                "参考表 $key 与缺省表漂移。The reference table $key drifted from the default table.",
            );
        }
    }

    public function testApplySkillsFiltersByFeature(): void
    {
        $skills = new SkillRepository();
        $rows = GameplayTables::defaultTable('skills');

        $ids = GameplayTables::applySkills($skills, $rows, self::FEATURES_OFF);
        self::assertSame(['fireball', 'ice_bolt'], $ids, '无 feature 行恒生效，feature 行全过滤。Feature-less rows always apply, feature rows all filtered.');

        GameplayTables::applySkills($skills, $rows, ['mmorpg' => true] + self::FEATURES_OFF);
        foreach (['fireball', 'ice_bolt', 'taunt', 'taunt_aoe', 'slash_rect'] as $expected) {
            self::assertNotNull($skills->get($expected));
        }
        self::assertSame(1000.0, $skills->get('taunt')?->tauntThreat);
        self::assertSame(['shape' => 'rect', 'width' => 6, 'height' => 4], $skills->get('slash_rect')?->aoe);
    }

    public function testReapplySkillsHandlesAddEditAndRemove(): void
    {
        $skills = new SkillRepository();
        $applied = GameplayTables::applySkills($skills, [
            ['id' => 'old', 'name' => '旧技能', 'damageMultiplier' => 1.0, 'cooldownSeconds' => 1.0, 'range' => 1],
            ['id' => 'kept', 'name' => '保留', 'damageMultiplier' => 1.0, 'cooldownSeconds' => 1.0, 'range' => 1],
        ], self::FEATURES_OFF);
        // 手写注册的非配置技能：热载重放不得触碰
        // A hand-registered non-config skill: the hot-reload replay must never touch it
        $skills->register(new \Nythros\Framework\Plugin\Skill\SkillDefinition('handmade', '手工', 1.0, 1.0, 1));

        // 增 + 改 + 删：old 删除、kept 改倍率、new 新增
        // Remove + edit + add: old deleted, kept re-multiplied, new added
        GameplayTables::reapplySkills($skills, [
            ['id' => 'kept', 'name' => '保留', 'damageMultiplier' => 2.5, 'cooldownSeconds' => 1.0, 'range' => 1],
            ['id' => 'new', 'name' => '新技能', 'damageMultiplier' => 1.0, 'cooldownSeconds' => 1.0, 'range' => 1],
        ], self::FEATURES_OFF, $applied);

        self::assertNull($skills->get('old'), '删除行生效。A deleted row takes effect.');
        self::assertSame(2.5, $skills->get('kept')?->damageMultiplier, '修改行生效。An edited row takes effect.');
        self::assertSame(1.0, $skills->get('new')?->damageMultiplier);
        self::assertNotNull($skills->get('handmade'), '非配置技能不受热载影响。A non-config skill is untouched by the reload.');
        self::assertSame(['kept', 'new'], $applied);
    }

    public function testApplySkillsFailsFastOnIncompleteAoeShape(): void
    {
        $skills = new SkillRepository();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('fireball');
        GameplayTables::applySkills($skills, [
            ['id' => 'fireball', 'name' => '火球术', 'damageMultiplier' => 1.5, 'cooldownSeconds' => 2.0, 'range' => 3, 'aoe' => ['shape' => 'circle']],
        ], self::FEATURES_OFF);
    }

    public function testBuildDropTableFiltersFeatureAndFailsFastOnUnknownItem(): void
    {
        $items = new ItemRepository();
        $items->register(new ItemDefinition('bone', '兽骨', ItemDefinition::TYPE_MATERIAL));
        $table = ['noDropWeight' => 0, 'entries' => [
            ['itemId' => 'bone', 'weight' => 3],
            ['itemId' => 'sword', 'weight' => 1, 'feature' => 'economy'],
        ]];

        // economy 未启用：sword 行过滤，不触发引用校验
        // Economy off: the sword row is filtered out and never trips the referential check
        $table_off = GameplayTables::buildDropTable($table, self::FEATURES_OFF, $items);
        self::assertNotNull($table_off);

        // economy 启用但 sword 未注册：引用完整性 fail-fast
        // Economy on but sword unregistered: referential-integrity fail-fast
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('sword');
        GameplayTables::buildDropTable($table, ['economy' => true] + self::FEATURES_OFF, $items);
    }

    public function testGameplayConfigDefaultsMatchExternalizedValues(): void
    {
        $config = GameplayConfig::defaults();

        self::assertSame(['x' => 0, 'y' => 0], $config->spawnPoint);
        self::assertSame(100, $config->playerMaxHp, '初始血量基线缺省 100（P18 外置） / the initial vitals baseline defaults to 100 (the P18 externalization)');
        self::assertSame('monster-1', $config->monsters[0]->id);
        self::assertSame(100, $config->monsters[0]->maxHp);
        self::assertSame(['x' => 15, 'y' => 15], $config->monsters[0]->anchor);
        self::assertSame(4, $config->monsters[0]->patrolRadius);
        self::assertNull($config->monsters[0]->respawnMs);
        self::assertSame('wolf', $config->monsters[1]->typeId);
        self::assertSame(['x' => -6, 'y' => -6], $config->monsters[1]->anchor);
        self::assertSame(150, $config->monsters[1]->maxHp);
    }
}
