<?php

declare(strict_types=1);

namespace Nythros\Framework\Tests;

use Nythros\Framework\Game\Horde\HordeConfig;
use Nythros\Framework\Game\Horde\WaveDefinition;
use PHPUnit\Framework\TestCase;

/**
 * HordeConfigTest - horde 配置不变量测试（reviewer MINOR-3）：空 waves 在构造期拒绝
 * （InvalidArgumentException fail-fast），堵住 RoomHub::handleSpawn 的 waves[0] 未防御消费点；
 * 缺省配置与多波配置正常构造。
 * HordeConfigTest - the horde config invariant tests (reviewer MINOR-3): empty waves are rejected at construction
 * (an InvalidArgumentException fail-fast), closing RoomHub::handleSpawn's undefended waves[0] consumer; the default
 * and multi-wave configs construct normally.
 */
final class HordeConfigTest extends TestCase
{
    /**
     * 空 waves 构造期拒绝：装配点立即暴露配置错误（而非延迟到首次 room:spawn 才以运行期错误冒出）。
     * Empty waves are rejected at construction: the assembly point surfaces the config error immediately (instead of deferring it to the first room:spawn as a runtime error).
     */
    public function testEmptyWavesAreRejectedAtConstruction(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('horde 配置至少需要一个波次定义 / horde config requires at least one wave definition');

        new HordeConfig([]);
    }

    /**
     * 缺省配置（单波）与显式多波配置正常构造：断言只拦空列表，不误伤合法配置。
     * The default (single-wave) and explicit multi-wave configs construct normally: the guard only rejects empty lists, never legitimate configs.
     */
    public function testDefaultAndMultiWaveConfigsConstructNormally(): void
    {
        $default = HordeConfig::default();
        self::assertCount(1, $default->waves);
        self::assertSame(200, $default->waves[0]->count);

        $waves = [
            new WaveDefinition(count: 10, monsterMaxHp: 12, gridStartX: 0, gridStartY: 0, columns: 5, step: 2),
            new WaveDefinition(count: 20, monsterMaxHp: 24, gridStartX: 0, gridStartY: -10, columns: 10, step: 3),
        ];
        $multi = new HordeConfig($waves);
        self::assertSame($waves, $multi->waves);
    }
}
