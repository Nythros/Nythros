<?php

declare(strict_types=1);

namespace Nythros\Framework\Tests;

require_once __DIR__ . '/CombatFakes.php';

use Nythros\Framework\Combat\DropEntry;
use Nythros\Framework\Combat\DropTable;
use PHPUnit\Framework\TestCase;

/**
 * DropTableTest - 覆盖正式化语义：多条目独立 roll、数量区间、逐条目不掉落段与空表兜底（R3 经济批模块 2）。
 * DropTableTest - covers the formalized semantics: independent multi-entry rolls, count ranges, per-entry no-drop
 * segments and the empty-table fallback (economy-batch module 2).
 */
final class DropTableTest extends TestCase
{
    public function testEachEntryRollsIndependentlyAndBothMayHit(): void
    {
        // 正式条目：两条目均 weight=1 且无不掉落段——独立 roll 下同时命中
        // Formal entries: both entries weight=1 with no no-drop segment — under independent rolls both hit together
        $table = new DropTable([
            new DropEntry('gold', 1),
            new DropEntry('potion', 1),
        ]);

        self::assertSame([
            ['itemId' => 'gold', 'count' => 1],
            ['itemId' => 'potion', 'count' => 1],
        ], $table->roll(new FixedRandomSource([1, 1])));
    }

    public function testPerEntryNoDropSegmentSkipsOnlyThatEntry(): void
    {
        // 不掉落权重 2：denominator=3，roll≤2 落入不掉落段（前段约定与旧版一致），roll=3 命中
        // No-drop weight 2: denominator=3, a roll ≤2 lands in the leading no-drop segment (same convention as legacy), 3 hits
        $table = new DropTable([
            new DropEntry('gold', 1),
            new DropEntry('potion', 1),
        ], 2);

        self::assertSame([], $table->roll(new FixedRandomSource([2, 1])), '双条目均落入不掉落段。Both entries land in their no-drop segments.');
        self::assertSame([
            ['itemId' => 'gold', 'count' => 1],
            ['itemId' => 'potion', 'count' => 1],
        ], $table->roll(new FixedRandomSource([3, 3])), '双条目均命中。Both entries hit.');
        self::assertSame([
            ['itemId' => 'gold', 'count' => 1],
        ], $table->roll(new FixedRandomSource([3, 2])), '仅第一条目命中。Only the first entry hits.');
        self::assertSame([
            ['itemId' => 'potion', 'count' => 1],
        ], $table->roll(new FixedRandomSource([2, 3])), '仅第二条目命中。Only the second entry hits.');
    }

    public function testCountIsRolledInsideTheMinMaxRange(): void
    {
        $table = new DropTable([
            new DropEntry('bone', 1, minCount: 2, maxCount: 5),
        ]);

        self::assertSame([['itemId' => 'bone', 'count' => 2]], $table->roll(new FixedRandomSource([1, 2])));
        self::assertSame([['itemId' => 'bone', 'count' => 5]], $table->roll(new FixedRandomSource([1, 5])));
        self::assertSame([['itemId' => 'bone', 'count' => 4]], $table->roll(new FixedRandomSource([1, 4])));
    }

    public function testSingleValueRangeConsumesNoExtraRandom(): void
    {
        // min==max 时数量恒定，不消耗随机数序列
        // With min==max the count is constant and consumes no extra random values
        $table = new DropTable([
            new DropEntry('gold', 1),
        ]);

        self::assertSame([['itemId' => 'gold', 'count' => 1]], $table->roll(new FixedRandomSource(1)));
    }

    public function testLegacyWeightMapNormalizesToCountOneEntries(): void
    {
        // 旧版 itemId=>权重 映射：归一化为 count=1 条目，语义与正式条目一致
        // Legacy itemId=>weight map: normalized into count=1 entries, semantics identical to formal entries
        $table = new DropTable(['gold' => 1]);

        self::assertSame([['itemId' => 'gold', 'count' => 1]], $table->roll(new FixedRandomSource(1)));
    }

    public function testZeroWeightEntriesNeverHit(): void
    {
        $table = new DropTable([
            new DropEntry('ghost', 0),
            new DropEntry('gold', 1),
        ]);

        self::assertSame([['itemId' => 'gold', 'count' => 1]], $table->roll(new FixedRandomSource([1, 1])));
    }

    public function testRollOnEmptyEntriesReturnsEmpty(): void
    {
        $table = new DropTable([]);

        self::assertSame([], $table->roll(new FixedRandomSource(1)));
    }

    public function testInvalidCountRangeIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new DropEntry('broken', 1, minCount: 3, maxCount: 2);
    }
}
