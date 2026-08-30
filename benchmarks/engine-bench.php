<?php

declare(strict_types=1);

// 定位：benchmarks/engine-bench.php — 引擎核心基准（一次性离线执行，不依赖 Redis/MySQL/网络）。
// 覆盖五个热点，输出 ops/s 与均值/分位：World::update 帧耗时（实体数梯度）、GridAOI 查询/更新、
// SimpleEventBus 入队+批量 flush 吞吐、BinaryBatchSerializer 与 JsonSerializer 编解码吞吐、
// RegionScheduler 预算截断不超支验证。
// Located at: benchmarks/engine-bench.php — engine-core benchmark (one-shot offline; no Redis/MySQL/network).
// Covers five hotspots with ops/s and mean/percentile output: World::update frame cost (entity-count gradient),
// GridAOI query/update, SimpleEventBus enqueue + batched flush throughput, BinaryBatchSerializer vs
// JsonSerializer encode/decode throughput, and RegionScheduler budget-cutoff non-overshoot.
//
// 输出模式：缺省人类可读文本；`--json` 输出机器可读 JSON（指标名 → 数值，稳定键名），
// 供 tools/bench-gate.php 与 benchmarks/results/engine-bench.json 基线做回归门禁比对。
// Output modes: human-readable text by default; `--json` emits machine-readable JSON (metric name → value,
// stable keys) for tools/bench-gate.php to compare against the benchmarks/results/engine-bench.json baseline.

declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';

use Nythros\Actor\SimpleActorSystem;
use Nythros\Aoi\GridAOI;
use Nythros\Contracts\WorldType;
use Nythros\Entity\BaseEntity;
use Nythros\Entity\Position;
use Nythros\Event\SimpleEventBus;
use Nythros\Protocol\BinaryBatchSerializer;
use Nythros\Protocol\JsonSerializer;
use Nythros\Protocol\Message;
use Nythros\Protocol\ProtocolVocabulary;
use Nythros\Scheduler\RegionScheduler;
use Nythros\World\SimpleEntityManager;
use Nythros\World\World;

$jsonOutput = in_array('--json', $argv ?? [], true);

// --json 模式：全程输出缓冲，结束时丢弃缓冲只吐 JSON——stdout 保证机器可解析。
// --json mode: buffer all output and drop it at the end so stdout stays machine-parseable.
if ($jsonOutput) {
    ob_start();
}

/** @var array<string, float> 全部指标同步落入此表（--json 时整体输出）。 Every metric lands here too (dumped wholesale under --json). */
$metrics = [];

$hrtime = static function (callable $fn, int $iters = 1000): float {
    // 预热（iters/10，至少 1 次）：吸收首触成本（autoload/CPU 缓存/调度升频），降低跨进程方差。
    // Warmup (iters/10, at least 1): absorbs first-touch costs (autoload/CPU caches/scheduler ramp-up), reducing cross-process variance.
    $warmup = max(1, intdiv($iters, 10));
    for ($i = 0; $i < $warmup; $i++) {
        $fn();
    }
    $t0 = hrtime(true);
    for ($i = 0; $i < $iters; $i++) {
        $fn();
    }
    return (hrtime(true) - $t0) / 1e6; // ms total
};

echo "== 引擎基准 Engine Benchmarks ==" . PHP_EOL;

// ── 1. World::update 帧耗时（实体数梯度；含 AOI 差分 + 事件入队 + 调度） ──
// 1. World::update frame cost (entity-count gradient; AOI diff + event enqueue + scheduler)
echo PHP_EOL . "[1] World::update frame cost (ms)" . PHP_EOL;
foreach ([100, 500, 1000] as $count) {
    $clock = static fn (): float => microtime(true); // World 的 clock 是 callable（update 内调用取时间） World's clock is a callable (called inside update to read the time)
    $world = new World(
        new SimpleEntityManager(),
        new SimpleActorSystem(),
        new GridAOI(10),
        new SimpleEventBus(50000),
        new RegionScheduler(100.0),
        WorldType::AOI,
        $clock,
    );
    for ($i = 0; $i < $count; $i++) {
        $world->getEntityManager()->add(new BaseEntity('e' . $i, new Position(($i % 50) * 10, intdiv($i, 50) * 10)));
    }
    // 预登记进 AOI（updateEntity 按 position 建格；预登记后 update 走真实同帧差分路径）
    // Pre-register into the AOI (updateEntity builds the cell by position; pre-registration makes update walk the real same-frame diff path)
    $aoi = $world->getAOI();
    foreach ($world->getEntityManager()->all() as $e) {
        $aoi->updateEntity($e);
    }
    $ms = $hrtime(static fn () => $world->update(), 1000);
    $metrics["world_update_ms_per_frame_entities{$count}"] = $ms / 1000;
    printf("  entities=%-5d avg=%.3f ms/frame (%d iters)\n", $count, $ms / 1000, 1000);
}

// ── 2. GridAOI 查询（九宫格视野）与更新吞吐 ──
// 2. GridAOI query (3x3 view) and update throughput
echo PHP_EOL . "[2] GridAOI query/update" . PHP_EOL;
$aoi = new GridAOI(10);
$entities = [];
for ($i = 0; $i < 1000; $i++) {
    $e = new BaseEntity('a' . $i, new Position(($i % 100) * 10, intdiv($i, 100) * 10));
    $aoi->updateEntity($e);
    $entities[] = $e;
}
$center = $entities[0];
$ms = $hrtime(static fn () => $aoi->query($center), 30000);
$metrics['aoi_query_ops_per_sec'] = 30000 / $ms * 1000;
printf("  query: %.0f ops/s (9格视野平均 %.2f 个实体)\n", 30000 / $ms * 1000, count($aoi->query($center)));
$ms = $hrtime(static fn () => $aoi->updateEntity($center), 30000);
$metrics['aoi_update_entity_ops_per_sec'] = 30000 / $ms * 1000;
printf("  updateEntity (移动): %.0f ops/s\n", 30000 / $ms * 1000);

// ── 3. SimpleEventBus 入队 + 批量 flush 吞吐 ──
// 3. SimpleEventBus enqueue + batched flush throughput
echo PHP_EOL . "[3] SimpleEventBus" . PHP_EOL;
$bus = new SimpleEventBus(100000);
$env = new \Nythros\Contracts\EventEnvelope('src', \Nythros\Contracts\EventEnvelope::TYPE_AOI_ENTER, 0.0, 'dst', false, true, ['position' => ['x' => 1, 'y' => 2]]);
$ms = $hrtime(static fn () => $bus->publishEnvelope($env), 200000);
$metrics['event_bus_publish_envelope_ops_per_sec'] = 200000 / $ms * 1000;
printf("  publishEnvelope: %.0f ops/s\n", 200000 / $ms * 1000);
$bus2 = new SimpleEventBus(100000);
$ms = $hrtime(static function () use ($bus2, $env): void {
    for ($i = 0; $i < 100; $i++) {
        $bus2->publishEnvelope($env);
    }
    $bus2->flush();
}, 2000);
$metrics['event_bus_enqueue100_flush_batches_per_sec'] = 2000 / $ms * 1000;
printf("  enqueue100+flush: %.0f batches/s\n", 2000 / $ms * 1000);

// ── 4. 序列化吞吐：二进制 vs JSON（批量 8 帧 × 20000 批） ──
// 4. Serializer throughput: binary vs JSON (batch of 8 frames × 20000 batches)
echo PHP_EOL . "[4] Serializer encode/decode" . PHP_EOL;
$vocab = new ProtocolVocabulary(
    typeCodes: ['entity_moved' => 1, 'combat:hit' => 2],
    keyCodes: ['id' => 1, 'position' => 2, 'x' => 3, 'y' => 4, 'damage' => 5, 'hp' => 6],
);
$binary = new BinaryBatchSerializer($vocab);
$json = new JsonSerializer();
$messages = [
    Message::create('entity_moved', ['id' => 'p-1', 'position' => ['x' => 1, 'y' => 2]]),
    Message::create('combat:hit', ['id' => 'm-1', 'damage' => 12, 'hp' => 88]),
    Message::create('entity_moved', ['id' => 'p-1', 'position' => ['x' => 9, 'y' => 9]]),
    Message::create('combat:hit', ['id' => 'm-2', 'damage' => 5, 'hp' => 95]),
    Message::create('entity_moved', ['id' => 'p-2', 'position' => ['x' => 3, 'y' => 3]]),
    Message::create('combat:hit', ['id' => 'm-1', 'damage' => 7, 'hp' => 81]),
    Message::create('entity_moved', ['id' => 'p-1', 'position' => ['x' => 4, 'y' => 4]]),
    Message::create('combat:hit', ['id' => 'm-3', 'damage' => 9, 'hp' => 91]),
];
$binBytes = strlen($binary->encodeBatch($messages));
$encoded = $binary->encodeBatch($messages);
$ms0 = $hrtime(static fn () => $binary->encodeBatch($messages), 20000);
$msDec = $hrtime(static fn () => $binary->decodeBatch($encoded), 20000);
$metrics['binary_batch_encode_batches_per_sec'] = 20000 / $ms0 * 1000;
$metrics['binary_batch_decode_batches_per_sec'] = 20000 / $msDec * 1000;
printf("  binary encode:  %.0f batches/s (%.0f frames/s, %d bytes/batch)\n", 20000 / $ms0 * 1000, 20000 / $ms0 * 1000 * 8, $binBytes);
printf("  binary decode:  %.0f batches/s\n", 20000 / $msDec * 1000);
$msJ = $hrtime(static fn () => $json->encode($messages[0]), 20000);
$metrics['json_encode_frames_per_sec'] = 20000 / $msJ * 1000;
printf("  json encode:    %.0f frames/s\n", 20000 / $msJ * 1000);

// ── 5. RegionScheduler 预算截断：超预算区不超支 ──
// 5. RegionScheduler budget cutoff: an over-budget region never overshoots
echo PHP_EOL . "[5] RegionScheduler budget cutoff" . PHP_EOL;
$sched = new RegionScheduler(0.1); // 100μs 预算相近于可忽略，任何任务都超 → 立即截断
$sched->registerRegion('a', 0.05);
$ran = 0;
$sched->addTaskToRegion('a', static function () use (&$ran): void {
    $ran++;
});
$sched->runFrame();
printf("  budget=0.05ms region, 1 task: ran=%d (期望 0-1，超限被截断) expected 0-1 (cutoff)\n", $ran);

if ($jsonOutput) {
    // 先丢弃人类可读缓冲，stdout 只含机器可读 JSON：指标名 → 数值（键名稳定，供 bench-gate 与基线比对）。
    // Drop the human-readable buffer first; stdout then carries only machine-readable JSON: metric name → value
    // (stable keys, for the bench-gate baseline comparison).
    ob_end_clean();
    echo json_encode($metrics, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), PHP_EOL;
} else {
    echo PHP_EOL . "== 引擎基准完成 ==" . PHP_EOL;
}
