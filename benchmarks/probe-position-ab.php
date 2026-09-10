<?php

declare(strict_types=1);

/*
 * BaseEntity::getPosition() 数组缓存 A/B 探针（一次性实验）。
 * 变体 E1-cache：缓存 ['x','y'] 数组，随 Position 实例变更失效。
 *   - 实现 A（实体侧缓存）：BaseEntity 内 ?array $posArray，move/setPosition 置 null。
 *   - 实现 B（值对象侧缓存）：Position 自带懒算 toArray()，BaseEntity::getPosition 委托之。
 *     Position 不可变 → 每个 Position 实例缓存恒有效，实体无需失效逻辑，最省心。
 * 资格：move 序列后返回值必须与原实现逐值全等（缓存不能读到陈旧坐标）。
 * 关键正确性测试：A/B 缓存被调用方改写后是否污染下次读（PHP 数组 COW 保证返回值安全，验证之）。
 */

require __DIR__ . '/../vendor/autoload.php';

use Nythros\Entity\BaseEntity;
use Nythros\Entity\Position;

// ── 实体侧缓存变体（实现 A） ──
final class BaseEntityA
{
    private bool $moved = false;
    private ?array $posCache = null;

    public function __construct(private readonly string $id, private Position $position)
    {
    }
    public function getId(): string
    {
        return $this->id;
    }
    public function getPosition(): array
    {
        return $this->posCache ??= ['x' => $this->position->x, 'y' => $this->position->y];
    }
    public function move(int $dx, int $dy): void
    {
        $this->position = $this->position->move($dx, $dy);
        $this->posCache = null;
        $this->moved = true;
    }
    public function setPosition(int $x, int $y): void
    {
        $this->position = new Position($x, $y);
        $this->posCache = null;
        $this->moved = true;
    }
}

// ── 值对象侧缓存变体（实现 B） ──
final class PositionB
{
    private ?array $cache = null;
    public function __construct(public readonly int $x, public readonly int $y)
    {
    }
    public function move(int $dx, int $dy): self
    {
        return new self($this->x + $dx, $this->y + $dy);
    }
    public function asArray(): array
    {
        return $this->cache ??= ['x' => $this->x, 'y' => $this->y];
    }
}
final class BaseEntityB
{
    public function __construct(private readonly string $id, private PositionB $position)
    {
    }
    public function getId(): string
    {
        return $this->id;
    }
    public function getPosition(): array
    {
        return $this->position->asArray();
    }
    public function move(int $dx, int $dy): void
    {
        $this->position = $this->position->move($dx, $dy);
    }
    public function setPosition(int $x, int $y): void
    {
        $this->position = new PositionB($x, $y);
    }
}

// ── 正确性：模拟随机 move 序列，两变体每步必须与原实现逐值全等 ──
$orig = new BaseEntity('e', new Position(0, 0));
$va = new BaseEntityA('e', new Position(0, 0));
$vb = new BaseEntityB('e', new PositionB(0, 0));
$okA = $okB = true;
mt_srand(7);
for ($i = 0; $i < 100000; $i++) {
    $dx = mt_rand(-5, 5);
    $dy = mt_rand(-5, 5);
    $orig->move($dx, $dy);
    $va->move($dx, $dy);
    $vb->move($dx, $dy);
    // 每次 move 后可能多次读（缓存路径）+ 断言值
    for ($r = 0; $r < 3; $r++) {
        $po = $orig->getPosition();
        if ($va->getPosition() !== $po) {
            $okA = false;
        }
        if ($vb->getPosition() !== $po) {
            $okB = false;
        }
    }
    if ($i % 5000 === 0) {   // 掺 setPosition
        $orig->setPosition(mt_rand(-100, 100), mt_rand(-100, 100));
        $va->setPosition($orig->getPosition()['x'], $orig->getPosition()['y']);
        $vb->setPosition($orig->getPosition()['x'], $orig->getPosition()['y']);
        if ($va->getPosition() !== $orig->getPosition()) {
            $okA = false;
        }
        if ($vb->getPosition() !== $orig->getPosition()) {
            $okB = false;
        }
    }
}
echo 'E1-A 实体缓存 值全等: ' . ($okA ? 'OK' : 'FAIL') . "\n";
echo 'E1-B 值对象缓存 值全等: ' . ($okB ? 'OK' : 'FAIL') . "\n";
if (!$okA || !$okB) {
    exit(1);
}

// ── COW 安全：调用方改写返回值不得污染缓存 ──
$ca = new BaseEntityA('c', new Position(1, 2));
$p1 = $ca->getPosition();
$p1['x'] = 999;                       // 调用方本地改写
$p2 = $ca->getPosition();             // 再取
$cowA = ($p2 === ['x' => 1, 'y' => 2]);
echo 'E1-A 调用方改写不污染缓存: ' . ($cowA ? 'OK' : 'FAIL') . "\n";

// ── 吞吐：读密集（AOI 帧内典型）与读写混合 ──
function bench(callable $f, int $n): float
{
    $t = hrtime(true);
    for ($i = 0; $i < $n; $i++) {
        $f();
    } return (hrtime(true) - $t) / 1e6 / $n;
}
$med = static function (array $a): float {
    sort($a);
    return $a[intdiv(count($a), 2)];
};

$scenarios = [
    '纯读×3(AOI近似)' => ['reads' => 3, 'moves' => 1],
    '热区读×8+移动' => ['reads' => 8, 'moves' => 1],
    '纯移动(每帧一次)' => ['reads' => 0, 'moves' => 1],
];
foreach ($scenarios as $label => $sc) {
    $ro = new BaseEntity('s', new Position(0, 0));
    $ra = new BaseEntityA('s', new Position(0, 0));
    $rb = new BaseEntityB('s', new PositionB(0, 0));
    $tO = $tA = $tB = [];
    for ($round = 0; $round < 5; $round++) {
        $tO[] = bench(static function () use ($ro, $sc): void {
            for ($r = 0; $r < $sc['reads']; $r++) {
                $x = $ro->getPosition()['x'];
            }
            $ro->move($x ?? 1, 1);
        }, 300000);
        $tA[] = bench(static function () use ($ra, $sc): void {
            for ($r = 0; $r < $sc['reads']; $r++) {
                $x = $ra->getPosition()['x'];
            }
            $ra->move($x ?? 1, 1);
        }, 300000);
        $tB[] = bench(static function () use ($rb, $sc): void {
            for ($r = 0; $r < $sc['reads']; $r++) {
                $x = $rb->getPosition()['x'];
            }
            $rb->move($x ?? 1, 1);
        }, 300000);
    }
    $o = $med($tO);
    printf(
        "%-22s 原 %.6f | A实体 %.6f (%+.1f%%) | B值对象 %.6f (%+.1f%%)\n",
        $label,
        $o,
        $med($tA),
        ($o / $med($tA) - 1) * 100,
        $med($tB),
        ($o / $med($tB) - 1) * 100
    );
}
