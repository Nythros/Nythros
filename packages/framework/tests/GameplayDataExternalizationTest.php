<?php

declare(strict_types=1);

namespace Nythros\Framework\Tests;

require_once __DIR__ . '/CombatFakes.php';

use Nythros\Framework\Combat\DropTable;
use Nythros\Framework\Game\Mmorpg\Respawner;
use Nythros\Framework\Plugin\Skill\SkillDefinition;
use Nythros\Framework\Plugin\Skill\SkillRepository;
use PHPUnit\Framework\TestCase;

/**
 * GameplayDataExternalizationTest - 覆盖 P11 数据外置的三个框架侧扩展点：SkillRepository::remove/ids
 * （技能表热载删除行）、DropTable::fromRows（掉落表行声明构造）、Respawner 逐怪重生延迟覆盖。
 * GameplayDataExternalizationTest - covers the P11 externalization's three framework-side extensions:
 * SkillRepository::remove/ids (skill-table hot-reload row deletion), DropTable::fromRows (row-declaration
 * construction) and the Respawner's per-monster respawn-delay override.
 */
final class GameplayDataExternalizationTest extends TestCase
{
    public function testSkillRepositoryRemoveAndIds(): void
    {
        $skills = new SkillRepository();
        $skills->register(new SkillDefinition('fireball', '火球术', 1.5, 2.0, 3));
        $skills->register(new SkillDefinition('ice_bolt', '冰锥术', 1.2, 1.5, 3));

        self::assertSame(['fireball', 'ice_bolt'], $skills->ids());
        self::assertTrue($skills->remove('fireball'));
        self::assertFalse($skills->remove('fireball'), '重复摘除返回 false。A repeated removal returns false.');
        self::assertNull($skills->get('fireball'));
        self::assertSame(['ice_bolt'], $skills->ids());
    }

    public function testDropTableFromRowsAppliesDefaultsAndCounts(): void
    {
        // FixedRandomSource(6)：noDropWeight=5 下 roll(1, 8)=6 > 5 命中；count roll(1, 3)=3 取上界
        // FixedRandomSource(6): with noDropWeight=5 the roll(1, 8)=6 > 5 hits; the count roll(1, 3)=3 takes the upper bound.
        $table = DropTable::fromRows([
            ['itemId' => 'bone', 'weight' => 3],
            ['itemId' => 'potion', 'weight' => 2, 'minCount' => 1, 'maxCount' => 3],
        ], 5);

        self::assertSame([
            ['itemId' => 'bone', 'count' => 1],
            ['itemId' => 'potion', 'count' => 3],
        ], $table->roll(new FixedRandomSource(6)));
    }

    public function testDropTableFromRowsFailsFastOnIllegalCounts(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        DropTable::fromRows([['itemId' => 'bone', 'weight' => 1, 'minCount' => 3, 'maxCount' => 2]]);
    }

    public function testDropTableLegacyConstructorUnaffected(): void
    {
        // 旧版映射构造兼容：FixedRandomSource(100) 恒命中（noDropWeight=0 时 roll(1, 3) 收敛到 3 ≤ 权重段）
        // Legacy map-constructor compatibility: FixedRandomSource(100) always hits (with noDropWeight=0 the
        // roll(1, 3) clamps to 3, inside the weight segment).
        $table = new DropTable(['bone' => 3]);
        self::assertSame([['itemId' => 'bone', 'count' => 1]], $table->roll(new FixedRandomSource(100)));
    }

    public function testRespawnerPerMonsterDelayOverride(): void
    {
        $respawner = new Respawner(5000);
        $now = 1000.0;

        // 覆盖生效：逐怪 12000ms → 到期时刻 = now + 12s
        // Override applies: per-monster 12000ms → due at now + 12s
        $respawner->registerDeath('m-long', $now, 12000);
        self::assertSame([], $respawner->due($now + 11.9));
        self::assertSame(['m-long'], $respawner->due($now + 12.1));
        $respawner->clear('m-long');

        // null / <=0 回落全局 respawnMs（5000ms）
        // null / <=0 falls back to the global respawnMs (5000ms)
        $respawner->registerDeath('m-global', $now, null);
        self::assertSame([], $respawner->due($now + 4.9));
        self::assertSame(['m-global'], $respawner->due($now + 5.1));

        $respawner->registerDeath('m-zero', $now, 0);
        self::assertSame(['m-global', 'm-zero'], $respawner->due($now + 6.0));
    }

    public function testRespawnerRejectsNonPositiveGlobalDelay(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Respawner(0);
    }
}
