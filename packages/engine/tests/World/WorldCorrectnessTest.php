<?php

declare(strict_types=1);

namespace Nythros\World\Tests;

use Nythros\Actor\SimpleActorSystem;
use Nythros\Aoi\GridAOI;
use Nythros\Contracts\ActorInterface;
use Nythros\Event\SimpleEventBus;
use Nythros\Scheduler\RegionScheduler;
use Nythros\World\SimpleEntityManager;
use Nythros\World\World;
use PHPUnit\Framework\TestCase;

/**
 * WorldCorrectnessTest - 规格正确性测试：用全部真实子系统（SimpleActorSystem / GridAOI / SimpleEventBus / RegionScheduler / SimpleEntityManager）
 * 组装 World，验证 100 Actor × 1000 Tick 的顺序一致性：每个 Actor 每帧恰好被驱动一次、不多不少。
 * Specification correctness tests: assemble the World entirely from real subsystems (SimpleActorSystem / GridAOI / SimpleEventBus /
 * RegionScheduler / SimpleEntityManager) and verify the sequential consistency of 100 actors over 1000 ticks: every actor is driven
 * exactly once per frame, never more, never less.
 *
 * 边界（裁决 4）：阶段 3 服务器以 min($workerCount, 1) 钳制为单 worker 单进程，Actor 由 SimpleActorSystem 在单帧内按注册顺序串行驱动。
 * 本测试验证的是该模型下的顺序一致性（每帧每个 Actor 恰好 update 一次），而不是多线程/多进程并发安全；
 * 多进程并发调度与 Actor 状态隔离属阶段 4 验收范围（见 blueprint/06 ADR-005 先单机后集群、质量门禁第 3 条）。
 * Boundary (ruling 4): stage 3 runs the server as a single worker / single process via the min($workerCount, 1) clamp, and actors are
 * driven serially in registration order within one frame by SimpleActorSystem. This test verifies sequential consistency under that model
 * (each actor updates exactly once per frame), not multi-threaded / multi-process concurrency safety; multi-process concurrent scheduling
 * and actor state isolation belong to stage 4 (see blueprint/06 ADR-005 single-machine-first and quality gate item 3).
 */
final class WorldCorrectnessTest extends TestCase
{
    public function testHundredActorsCompleteThousandTicksExactlyOnceEach(): void
    {
        $actorCount = 100;
        $tickCount = 1000;

        // 计数表：每个 Actor 独占一个槽位，匿名类通过引用共享该表并自增自己的槽位
        // Counter table: each actor owns one slot; the anonymous classes share the table by reference and increment their own slot
        /** @var array<int, int> $counts */
        $counts = array_fill(0, $actorCount, 0);

        $actorSystem = new SimpleActorSystem();
        for ($i = 0; $i < $actorCount; $i++) {
            // 循环变量拷贝到 $index 再捕获，避免 PHP 闭包/匿名类共享同一最终值的经典陷阱
            // Copy the loop counter into $index before capturing, avoiding the classic PHP pitfall of sharing one final value
            $index = $i;
            $actorSystem->add(new class ($counts, $index) implements ActorInterface {
                /** @var array<int, int> 引用共享的计数表 Counter table shared by reference. */
                private array $counts;

                /** @var int 本 Actor 的槽位下标 This actor's slot index. */
                private readonly int $index;

                /**
                 * 构造匿名 Actor：以引用接管计数表，后续自增直接写回共享数组。
                 * Creates the anonymous actor: takes over the counter table by reference so later increments write through to the shared array.
                 *
                 * @param array<int, int> $counts 共享计数表 Shared counter table.
                 * @param int $index 本 Actor 槽位 This actor's slot.
                 */
                public function __construct(array &$counts, int $index)
                {
                    $this->counts = &$counts;
                    $this->index = $index;
                }

                public function update(): void
                {
                    $this->counts[$this->index]++;
                }
            });
        }

        // 全部使用真实实现组装 World：不 mock 任何子系统，验证真实链路下的顺序调度
        // Assemble the World from real implementations only: no subsystem is mocked, verifying sequential scheduling on the real path
        $world = new World(
            new SimpleEntityManager(),
            $actorSystem,
            new GridAOI(10),
            new SimpleEventBus(),
            new RegionScheduler(),
        );

        for ($tick = 0; $tick < $tickCount; $tick++) {
            $world->update();
        }

        // 每个 Actor 恰好被驱动 1000 次；总和恰为 100 × 1000
        // Every actor is driven exactly 1000 times; the total is exactly 100 × 1000
        self::assertSame(array_fill(0, $actorCount, $tickCount), $counts);
        self::assertSame($actorCount * $tickCount, array_sum($counts));
    }
}
