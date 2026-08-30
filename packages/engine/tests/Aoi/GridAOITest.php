<?php

declare(strict_types=1);

namespace Nythros\Aoi\Tests;

use Nythros\Aoi\GridAOI;
use Nythros\Contracts\EntityInterface;
use Nythros\Entity\BaseEntity;
use Nythros\Entity\CircleShape;
use Nythros\Entity\Position;
use Nythros\Entity\RectangleShape;
use Nythros\Entity\SectorShape;
use PHPUnit\Framework\TestCase;

/**
 * GridAOITest - 覆盖 GridAOI 基于九宫格的可见性、跨格移动、视野差分与实体移除行为。
 * Tests covering GridAOI 3x3-neighborhood visibility, cross-cell movement, visibility deltas, and entity removal.
 */
final class GridAOITest extends TestCase
{
    public function testEntitiesInSameCellAreVisible(): void
    {
        $aoi = new GridAOI(10);
        $a = new BaseEntity('a', new Position(1, 1));
        $b = new BaseEntity('b', new Position(2, 2));

        $aoi->updateEntity($a);
        $aoi->updateEntity($b);

        self::assertSame(['a', 'b'], $this->ids($aoi->query($a)));
    }

    public function testMovingAcrossCellsRemovesFromOldCellAndAppearsInNewCell(): void
    {
        $aoi = new GridAOI(10);
        $a = new BaseEntity('a', new Position(1, 1)); // 0:0 cell 0:0
        $b = new BaseEntity('b', new Position(15, 1)); // 1:0 cell 1:0
        $c = new BaseEntity('c', new Position(5, 5)); // 0:0 cell 0:0

        $aoi->updateEntity($a);
        $aoi->updateEntity($b);
        $aoi->updateEntity($c);

        // 九宫格语义：a 与 c 同格、b 在相邻格，三者互相可见 3x3 semantics: a and c share a cell and b sits in an adjacent cell, so all three see each other
        self::assertSame(['a', 'b', 'c'], $this->ids($aoi->query($c)));
        self::assertSame(['a', 'b', 'c'], $this->ids($aoi->query($b)));

        // a 移到 (21,1) 即 2:0，跨出 c 的九宫格、进入 b 的九宫格 a moves to (21,1), cell 2:0, leaving c's neighborhood and entering b's
        $a->move(20, 0);
        $aoi->updateEntity($a);

        self::assertSame(['b', 'c'], $this->ids($aoi->query($c)));
        self::assertSame(['a', 'b', 'c'], $this->ids($aoi->query($b)));
        self::assertSame(['a', 'b'], $this->ids($aoi->query($a)));
    }

    public function testEntitiesInDifferentCellsAreInvisible(): void
    {
        $aoi = new GridAOI(10);
        $a = new BaseEntity('a', new Position(0, 0)); // 0:0 cell 0:0
        $b = new BaseEntity('b', new Position(21, 0)); // 2:0，超出 a 的九宫格（cx-1..cx+1） cell 2:0, outside a's 3x3 neighborhood (cx-1..cx+1)

        $aoi->updateEntity($a);
        $aoi->updateEntity($b);

        self::assertSame(['a'], $this->ids($aoi->query($a)));
        self::assertSame(['b'], $this->ids($aoi->query($b)));
    }

    public function testRemovedEntityIsNoLongerVisible(): void
    {
        $aoi = new GridAOI(10);
        $a = new BaseEntity('a', new Position(1, 1));

        $aoi->updateEntity($a);
        $aoi->remove($a);

        self::assertSame([], $aoi->query($a));
    }

    public function testQueryReturnsNineCellNeighborhood(): void
    {
        $aoi = new GridAOI(10);
        $center = new BaseEntity('center', new Position(5, 5)); // 0:0 cell 0:0
        $tl = new BaseEntity('tl', new Position(-5, -5)); // -1:-1 cell -1:-1
        $tm = new BaseEntity('tm', new Position(5, -5)); // 0:-1 cell 0:-1
        $tr = new BaseEntity('tr', new Position(15, -5)); // 1:-1 cell 1:-1
        $ml = new BaseEntity('ml', new Position(-5, 5)); // -1:0 cell -1:0
        $mr = new BaseEntity('mr', new Position(15, 5)); // 1:0 cell 1:0
        $bl = new BaseEntity('bl', new Position(-5, 15)); // -1:1 cell -1:1
        $bm = new BaseEntity('bm', new Position(5, 15)); // 0:1 cell 0:1
        $br = new BaseEntity('br', new Position(15, 15)); // 1:1 cell 1:1
        $far = new BaseEntity('far', new Position(25, 5)); // 2:0，九宫格外 cell 2:0, outside the neighborhood

        foreach ([$tl, $tm, $tr, $ml, $center, $mr, $bl, $bm, $br, $far] as $entity) {
            $aoi->updateEntity($entity);
        }

        $visible = $this->ids($aoi->query($center));
        // 九宫格 9 格各 1 个实体，齐集且无重复；far 在九宫格外、center 自身包含在内 all 9 cells contribute exactly one entity, fully collected without duplicates; far sits outside and center itself is included
        self::assertSame(['bl', 'bm', 'br', 'center', 'ml', 'mr', 'tl', 'tm', 'tr'], $visible);
        self::assertCount(9, $visible);
        self::assertContains('center', $visible);
    }

    public function testUpdateEntityReturnsEnteredAndLeftDiff(): void
    {
        $aoi = new GridAOI(10);
        $a = new BaseEntity('a', new Position(1, 1)); // 0:0 cell 0:0
        $b = new BaseEntity('b', new Position(31, 1)); // 3:0，远离 a 的九宫格 cell 3:0, far outside a's neighborhood

        // 新登记 a：周围无人，entered 与 left 均空 fresh registration of a: no one around, so entered and left are both empty
        self::assertSame(['entered' => [], 'left' => []], $aoi->updateEntity($a));

        // 登记 b（远离 a 的九宫格）：diff 均为空 registering b (outside a's neighborhood) yields an empty delta
        self::assertSame(['entered' => [], 'left' => []], $aoi->updateEntity($b));

        // 同格移动：(3,3) 仍在 0:0 → fast path 返回空 diff same-cell move: (3,3) stays in 0:0, so the fast path returns an empty delta
        $a->move(2, 2);
        $delta = $aoi->updateEntity($a);
        self::assertSame([], $delta['entered']);
        self::assertSame([], $delta['left']);

        // 跨格移近 b：(23,3) → 2:0，b（3:0）进入 a 的九宫格 cross-cell move toward b: (23,3) lands in 2:0, so b (3:0) enters a's neighborhood
        $a->move(20, 0);
        $delta = $aoi->updateEntity($a);
        self::assertSame(['b'], $this->ids($delta['entered']));
        self::assertSame([], $delta['left']);

        // 再移回：(3,3) → 0:0，b 离开 a 的九宫格 moving back: (3,3) returns to 0:0, so b leaves a's neighborhood
        $a->move(-20, 0);
        $delta = $aoi->updateEntity($a);
        self::assertSame([], $delta['entered']);
        self::assertSame(['b'], $this->ids($delta['left']));

        // 新登记：m 登记时 n 已在相邻格，entered 应含 n fresh registration: when m registers, n already sits in an adjacent cell, so entered must contain n
        $aoi2 = new GridAOI(10);
        $n = new BaseEntity('n', new Position(12, 2)); // 1:0 cell 1:0
        $m = new BaseEntity('m', new Position(2, 2)); // 0:0 cell 0:0
        $aoi2->updateEntity($n);
        $delta = $aoi2->updateEntity($m);
        self::assertSame(['n'], $this->ids($delta['entered']));
        self::assertSame([], $delta['left']);
    }

    public function testRemoveUsesReverseIndex(): void
    {
        $aoi = new GridAOI(10);
        $watcher = new BaseEntity('w', new Position(5, 5)); // 0:0 cell 0:0
        $entity = new BaseEntity('x', new Position(15, 5)); // 1:0，w 的九宫格内 cell 1:0, inside w's neighborhood

        $aoi->updateEntity($watcher);
        $aoi->updateEntity($entity);

        self::assertSame(['w', 'x'], $this->ids($aoi->query($watcher)));

        $aoi->remove($entity);
        // 移除后 w 的九宫格不再含 x after removal, x is gone from w's neighborhood
        self::assertSame(['w'], $this->ids($aoi->query($watcher)));

        // 重复登记同 id（不同实例、不同格子）：旧格不残留、只保留最新登记 re-registering the same id (different instance, different cell) leaves no residue in the old cell and keeps only the latest registration
        $aoi->updateEntity($entity);
        $moved = new BaseEntity('x', new Position(50, 50)); // 5:5 cell 5:5
        $aoi->updateEntity($moved);

        self::assertSame(['w'], $this->ids($aoi->query($watcher)));
        self::assertSame(['x'], $this->ids($aoi->query($moved)));

        // 移除从未登记的实体：静默忽略 removing an entity that was never registered is silently ignored
        $ghost = new BaseEntity('ghost', new Position(5, 5));
        $aoi->remove($ghost);
        self::assertSame(['w'], $this->ids($aoi->query($watcher)));
    }

    /**
     * queryShape：bounds 覆盖格粗筛 + contains 精判。圆跨多格，格内但形状外的实体被精判剔除，
     * 形状外但覆盖格内的实体同样剔除，包围盒外实体不参与判定。
     * queryShape: bounds-covered-cell coarse filter + contains precision check. The circle spans several
     * cells; entities inside covered cells but outside the shape are rejected by the precision pass, and
     * entities beyond the bounding box never take part.
     */
    public function testQueryShapeCircleFiltersByBoundsThenContains(): void
    {
        $aoi = new GridAOI(10);
        // 圆心 (0,0) 半径 12：包围盒 [-12,12]²，覆盖格 -2..2 × -2..2 circle center (0,0) r=12: box [-12,12]², covering cells -2..2 × -2..2
        $shape = new CircleShape(0, 0, 12);
        $insideA = new BaseEntity('in-a', new Position(0, 0));
        $insideB = new BaseEntity('in-b', new Position(10, 5));
        $coveredButOutside = new BaseEntity('covered-out', new Position(-11, -11)); // 覆盖格内、圆外（对角距离 ≈15.6） in a covered cell but outside the circle (diagonal ≈15.6)
        $outsideBounds = new BaseEntity('far-out', new Position(30, 30)); // 包围盒外 beyond the box

        foreach ([$insideA, $insideB, $coveredButOutside, $outsideBounds] as $entity) {
            $aoi->updateEntity($entity);
        }

        self::assertSame(['in-a', 'in-b'], $this->ids($aoi->queryShape($shape)));
    }

    public function testQueryShapeRectangleBoundaryInclusive(): void
    {
        $aoi = new GridAOI(10);
        // 矩形 [0,20]×[0,10]，边界点 (20,10) 含入 rectangle [0,20]×[0,10], boundary point (20,10) inclusive
        $shape = new RectangleShape(0, 0, 20, 10);
        $onCorner = new BaseEntity('corner', new Position(20, 10));
        $justOutside = new BaseEntity('outside', new Position(21, 10));

        $aoi->updateEntity($onCorner);
        $aoi->updateEntity($justOutside);

        self::assertSame(['corner'], $this->ids($aoi->queryShape($shape)));
    }

    public function testQueryShapeSectorCrossZeroHeading(): void
    {
        $aoi = new GridAOI(10);
        // 朝向 350°、张角 30°：正东 (10,0) 在跨度 [335°,365°] 内，正北 (0,10)（90°）在外 facing 350° aperture 30°: due east (10,0) inside span [335°,365°], due north (0,10) (90°) outside
        $shape = new SectorShape(0, 0, 20, 350, 30);
        $east = new BaseEntity('east', new Position(10, 0));
        $north = new BaseEntity('north', new Position(0, 10));
        $center = new BaseEntity('center', new Position(0, 0)); // 圆心恒命中 center always contained

        foreach ([$east, $north, $center] as $entity) {
            $aoi->updateEntity($entity);
        }

        self::assertSame(['center', 'east'], $this->ids($aoi->queryShape($shape)));
    }

    public function testQueryShapeNegativeCoordinates(): void
    {
        $aoi = new GridAOI(10);
        $shape = new CircleShape(-25, -25, 7);
        $inside = new BaseEntity('neg-in', new Position(-25, -25)); // -3:-3 cell -3:-3
        $nearbyOutside = new BaseEntity('neg-out', new Position(-17, -25)); // -2:-3，包围盒外（x>-18）且形状外 -2:-3, beyond the box (x>-18) and outside the shape

        $aoi->updateEntity($inside);
        $aoi->updateEntity($nearbyOutside);

        self::assertSame(['neg-in'], $this->ids($aoi->queryShape($shape)));
    }

    public function testQueryShapeReturnsEmptyWhenNothingMatches(): void
    {
        $aoi = new GridAOI(10);
        $shape = new CircleShape(100, 100, 3);
        $entity = new BaseEntity('a', new Position(1, 1));

        $aoi->updateEntity($entity);

        self::assertSame([], $aoi->queryShape($shape));
    }

    public function testQueryShapeOnEmptyIndexReturnsEmpty(): void
    {
        $aoi = new GridAOI(10);

        self::assertSame([], $aoi->queryShape(new CircleShape(0, 0, 50)));
    }

    /**
     * 覆盖格数阈值兜底（R2 审查 MAJOR-1）：恶意超大半径的形状在进入双重循环前即被拒绝，
     * 引擎原语自防御，不依赖上层校验。
     * Covered-cell cap guard (R2 review MAJOR-1): a maliciously oversized shape is rejected before the
     * double loop ever runs — the engine primitive defends itself instead of relying on caller validation.
     */
    public function testQueryShapeThrowsWhenCoveredCellsExceedCap(): void
    {
        $aoi = new GridAOI(10);

        try {
            // r=1000000：bounds ±1e6 → 200001 格每边，远超 4096 上限 r=1000000: box ±1e6 → 200001 cells per side, far beyond the 4096 cap
            $aoi->queryShape(new CircleShape(0, 0, 1000000));
            self::fail('超大半径形状必须被拒绝 / an oversized shape must be rejected');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('queryShape 形状覆盖格数 200001 x 200001 超过上限 4096，已拒绝执行 / shape covers 200001 x 200001 cells, exceeding the 4096-cell cap', $e->getMessage());
        }
    }

    /**
     * 阈值不误伤合法大形状：r=300 圆（cellSize=10 下覆盖 61×61=3721 格 < 4096）正常查询，
     * 形状内实体照常命中。
     * The cap must not reject legitimate large shapes: a r=300 circle (61×61=3721 covered cells at cellSize=10 < 4096)
     * queries normally and entities inside still hit.
     */
    public function testQueryShapeAcceptsLargeLegalShapeBelowCap(): void
    {
        $aoi = new GridAOI(10);
        $inside = new BaseEntity('in', new Position(300, 0));
        $outside = new BaseEntity('out', new Position(400, 0));

        $aoi->updateEntity($inside);
        $aoi->updateEntity($outside);

        self::assertSame(['in'], $this->ids($aoi->queryShape(new CircleShape(0, 0, 300))));
    }

    /**
     * queryShape 与 updateEntity 返回语义无耦合：查询不得污染索引或 moved 标记——
     * 查询后同格实体的 updateEntity 仍走 fast path 返回空差分。
     * queryShape must not couple with updateEntity's delta semantics: a query may not pollute the index or
     * moved flags — after querying, a same-cell entity's updateEntity still takes the fast path with an empty delta.
     */
    public function testQueryShapeDoesNotDisturbUpdateEntityDiffs(): void
    {
        $aoi = new GridAOI(10);
        $a = new BaseEntity('a', new Position(5, 5));
        $b = new BaseEntity('b', new Position(15, 5)); // 相邻格 adjacent cell

        $aoi->updateEntity($a);
        $aoi->updateEntity($b);

        // 多次形状查询后多次形状查询 later, several shape queries
        $aoi->queryShape(new CircleShape(5, 5, 30));
        $aoi->queryShape(new RectangleShape(0, 0, 40, 40));

        // b 同格移动：仍应返回空差分（查询未把 b 标脏/未动索引） same-cell move of b: still an empty delta (the queries neither dirtied b nor touched the index)
        $b->move(1, 0);
        $delta = $aoi->updateEntity($b);

        self::assertSame([], $delta['entered']);
        self::assertSame([], $delta['left']);

        // 跨格移动差分照常工作 cross-cell diff still works
        $a->move(30, 0); // → (35,5) 即 3:0，脱离 b（1:0）的九宫格 → (35,5) i.e. 3:0, leaving b's (1:0) neighborhood
        $delta = $aoi->updateEntity($a);

        self::assertSame([], $delta['entered']);
        self::assertSame(['b'], $this->ids($delta['left']));
    }

    /**
     * @param list<EntityInterface> $entities
     *
     * @return list<string>
     */
    private function ids(array $entities): array
    {
        $ids = array_map(
            static fn (EntityInterface $entity): string => $entity->getId(),
            $entities,
        );
        sort($ids);

        return $ids;
    }
}
