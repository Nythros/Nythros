<?php

declare(strict_types=1);

// 定位：benchmarks/room-bench.php — R2 房间与 AoE 批量管线基准门禁（ADR-024 §T8，一次性离线执行，
// 不依赖 Redis/MySQL/网络）。四项测量：
// Located at: benchmarks/room-bench.php — the R2 room & AoE batch-pipeline benchmark gate (ADR-024 §T8,
// one-shot offline; no Redis/MySQL/network). Four measurements:
//   ① 千级实体房间单帧 update 成本（稳态静止 + 全量移动最坏情况；RoomInstance 固定帧序全路径）
//     Per-frame RoomInstance::update cost at 1k entities (steady stationary + all-moving worst case; the full fixed frame order).
//   ② GridAOI queryShape 圆形查询 @500/1000 实体 ops/s（AoE 命中管线原语）
//     GridAOI circle queryShape ops/s at 500/1000 entities (the AoE hit-pipeline primitive).
//   ③ RoomInstanceManager::tick 100 房间调度成本（15ms 宿主心跳节奏，含到期筛选与逐房驱动）
//     RoomInstanceManager::tick scheduling cost for 100 rooms (15ms host-heartbeat cadence, due filtering plus per-room driving).
//   ④ SimpleEntityManager::drainMoved @1000 实体（冷启动全量 moved 与稳态零 moved 两口径）
//     SimpleEntityManager::drainMoved at 1k entities (cold all-moved and steady zero-moved).
//
// 门禁阈值（ADR-024 §D-E.2 / T8）：drainMoved 或心跳扫描单帧 >0.5ms @1000 实体 → 触发 SoA 批量布局另案评估。
// Gate threshold (ADR-024 §D-E.2 / T8): drainMoved or heartbeat sweep above 0.5ms/frame at 1k entities → escalate to a separate SoA layout effort.

require __DIR__ . '/../vendor/autoload.php';

use Nythros\Aoi\GridAOI;
use Nythros\Contracts\RoomConfig;
use Nythros\Entity\BaseEntity;
use Nythros\Entity\CircleShape;
use Nythros\Entity\Position;
use Nythros\Event\SimpleEventBus;
use Nythros\World\RoomInstance;
use Nythros\World\RoomInstanceManager;
use Nythros\World\SimpleEntityManager;

/**
 * 计时帮手：执行 $iters 次并返回总耗时（毫秒）。
 * Timing helper: runs $iters iterations and returns the total elapsed milliseconds.
 */
$hrtime = static function (callable $fn, int $iters): float {
    $t0 = hrtime(true);
    for ($i = 0; $i < $iters; $i++) {
        $fn();
    }

    return (hrtime(true) - $t0) / 1e6;
};

echo "== 房间基准 Room Benchmarks ==" . PHP_EOL;

// ── 1. 千级实体房间单帧 update 成本 ──
// 1. Per-frame RoomInstance::update cost at 1k entities
echo PHP_EOL . "[1] RoomInstance::update frame cost @1000 entities" . PHP_EOL;
$benchRoom = new RoomInstance(
    new RoomConfig('bench-room', 50, 2048, static fn (): GridAOI => new GridAOI(10)),
    new SimpleEventBus(50000),
);
for ($i = 0; $i < 1000; $i++) {
    $benchRoom->getEntityManager()->add(new BaseEntity('e' . $i, new Position(($i % 50) * 10, intdiv($i, 50) * 10)));
}
// 预热一帧：首帧 drainMoved 把全部实体索引进 AOI（一次性成本，不计入稳态）
// Warm-up frame: the first frame's drainMoved indexes every entity into the AOI (one-time cost, excluded from steady state)
$benchRoom->update();

// 稳态静止：drainMoved 全表扫描但零实际移动、零差分信封
// Steady stationary: drainMoved scans the full table with zero actual movement and zero diff envelopes
$ms = $hrtime(static fn () => $benchRoom->update(), 300);
$steadyMs = $ms / 300;
printf("  稳态静止 stationary:      avg=%.4f ms/frame (%d iters)\n", $steadyMs, 300);

// 全量移动最坏情况：每帧 1000 实体全部置位 moved（同坐标 setPosition），含 AOI 重索引与差分信封
// All-moving worst case: every frame marks all 1k entities moved (same-coordinate setPosition), including AOI re-indexing and diff envelopes
$entities = $benchRoom->getEntityManager()->all();
$ms = $hrtime(static function () use ($benchRoom, $entities): void {
    foreach ($entities as $entity) {
        ['x' => $x, 'y' => $y] = $entity->getPosition();
        $entity->setPosition($x, $y); // 同坐标重定位仍置位 moved（ADR-024 §D-E.1 口径） Same-coordinate repositioning still marks moved (ADR-024 §D-E.1).
    }
    $benchRoom->update();
}, 100);
$movingMs = $ms / 100;
printf("  全量移动 all-moving:      avg=%.4f ms/frame (%d iters)\n", $movingMs, 100);

// ── 2. GridAOI queryShape 圆形查询吞吐 ──
// 2. GridAOI circle queryShape throughput
echo PHP_EOL . "[2] GridAOI queryShape (CircleShape r=30)" . PHP_EOL;
foreach ([500, 1000] as $count) {
    $aoi = new GridAOI(10);
    for ($i = 0; $i < $count; $i++) {
        $entity = new BaseEntity('q' . $i, new Position(($i % 50) * 10, intdiv($i, 50) * 10));
        $aoi->updateEntity($entity);
    }
    $shape = new CircleShape(120, 50, 30);
    $hits = count($aoi->queryShape($shape));
    $iters = 10000;
    $ms = $hrtime(static fn () => $aoi->queryShape($shape), $iters);
    printf("  entities=%-5d %.0f ops/s (avg=%.4f ms/op, 命中 hits=%d)\n", $count, $iters / $ms * 1000, $ms / $iters, $hits);
}

// ── 3. RoomInstanceManager::tick 100 房间调度成本（15ms 宿主心跳节奏） ──
// 3. RoomInstanceManager::tick scheduling cost for 100 rooms (15ms host-heartbeat cadence)
echo PHP_EOL . "[3] RoomManager::tick x100 rooms (hostTick=15ms)" . PHP_EOL;
$manager = new RoomInstanceManager(null, 9.0);
for ($r = 0; $r < 100; $r++) {
    $room = $manager->create(new RoomConfig(sprintf('room-%d', $r), 50, 64, static fn (): GridAOI => new GridAOI(10)));
    $room->join(new BaseEntity(sprintf('p-%d', $r), new Position(0, 0)));
}
$now = microtime(true);
$iters = 2000; // 模拟 30s 宿主心跳 Simulates 30s of host heartbeats.
$ms = $hrtime(static function () use (&$now, $manager): void {
    $now += 0.015;
    $manager->tick($now);
}, $iters);
$tickMs = $ms / $iters;
printf("  avg=%.4f ms/tick (%d ticks, 100 rooms, period=50ms)\n", $tickMs, $iters);

// ── 4. SimpleEntityManager::drainMoved @1000 实体 ──
// 4. SimpleEntityManager::drainMoved at 1k entities
echo PHP_EOL . "[4] drainMoved @1000 entities" . PHP_EOL;
$em = new SimpleEntityManager();
for ($i = 0; $i < 1000; $i++) {
    $em->add(new BaseEntity('d' . $i, new Position($i, $i)));
}
// 冷启动全量 moved：一次计测（首扫收集 1000 实体并复位标志）
// Cold all-moved: measured once (the first sweep collects 1k entities and resets flags)
$t0 = hrtime(true);
$coldCount = count($em->drainMoved());
$coldMs = (hrtime(true) - $t0) / 1e6;
printf("  冷启动全量 moved cold:    %.4f ms/call (collected=%d)\n", $coldMs, $coldCount);

// 稳态零 moved：仍是 O(N) 全表扫描（bool 检查成本），即每帧固定底价
// Steady zero-moved: still the O(N) full-table scan (bool-check cost) — the fixed per-frame floor
$ms = $hrtime(static fn () => $em->drainMoved(), 5000);
$steadyDrainMs = $ms / 5000;
printf("  稳态零 moved steady:      %.4f ms/call (%d iters)\n", $steadyDrainMs, 5000);

// ── 门禁判定 ──
// ── Gate verdicts ──
echo PHP_EOL . "== 门禁阈值判定 Gate verdicts (阈值 threshold: 0.5 ms) ==" . PHP_EOL;
$verdict = static function (string $label, float $ms): void {
    $ok = $ms <= 0.5;
    printf("  [%s] %s = %.4f ms\n", $ok ? 'PASS' : 'ESCALATE', $label, $ms);
    if (!$ok) {
        echo "    → 超阈值：触发 SoA 批量布局另案评估 / above threshold: escalate to a separate SoA layout effort" . PHP_EOL;
    }
};
$verdict('① 房间稳态单帧 update room steady frame', $steadyMs);
$verdict('③ 心跳调度成本 heartbeat tick (100 rooms)', $tickMs);
$verdict('④ drainMoved 冷启动 cold', $coldMs);
$verdict('④ drainMoved 稳态 steady', $steadyDrainMs);

echo PHP_EOL . "== 房间基准完成 ==" . PHP_EOL;
