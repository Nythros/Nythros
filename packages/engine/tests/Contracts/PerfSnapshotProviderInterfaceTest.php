<?php

declare(strict_types=1);

namespace Nythros\Contracts\Tests;

use Nythros\Contracts\PerfSnapshotProviderInterface;
use Nythros\Kernel\PerfProbe;
use PHPUnit\Framework\TestCase;

/**
 * PerfSnapshotProviderInterfaceTest - PerfProbe 对快照契约的实现语义：
 * collect 消费式读取（取走并清零）、peek 只读、直方图落桶与累计值、单例一致性、静态 API 兼容。
 * PerfSnapshotProviderInterfaceTest - PerfProbe's implementation semantics of the snapshot contract:
 * consuming collect (take-and-reset), read-only peek, histogram bucketing and totals, singleton identity,
 * and static-API compatibility.
 */
final class PerfSnapshotProviderInterfaceTest extends TestCase
{
    protected function setUp(): void
    {
        // 静态状态进程级共享：每用例先消费式清场，保证用例间隔离
        // Static state is process-wide: drain first in every case for isolation
        PerfProbe::instance()->collect();
    }

    public function testInstanceSatisfiesContract(): void
    {
        self::assertInstanceOf(PerfSnapshotProviderInterface::class, PerfProbe::instance());
    }

    public function testInstanceIsProcessSingleton(): void
    {
        self::assertSame(PerfProbe::instance(), PerfProbe::instance());
    }

    public function testCollectTakesAndResets(): void
    {
        $probe = PerfProbe::instance();
        PerfProbe::increment('events.a', 3);
        PerfProbe::recordDuration('frame.ms', 1.5);

        $window = $probe->collect();

        self::assertSame(['events.a' => 3], $window['counters']);
        self::assertSame([2 => 1], $window['histograms']['frame.ms']);
        self::assertSame(['frame.ms' => 1.5], $window['totals']);

        // 窗口语径：collect 后各表归零重新累计
        // Window semantics: after collect, all tables restart from zero
        $next = $probe->collect();
        self::assertSame([], $next['counters']);
        self::assertSame([], $next['histograms']);
        self::assertSame([], $next['totals']);
    }

    public function testPeekReadsWithoutClearing(): void
    {
        $probe = PerfProbe::instance();
        PerfProbe::increment('events.b');

        $first = $probe->peek();
        $second = $probe->peek();

        self::assertSame(['events.b' => 1], $first['counters']);
        self::assertSame($first['counters'], $second['counters']);

        // peek 不清零：数据仍可被后续 collect 取走
        // peek never clears: data remains available to a later collect
        self::assertSame(['events.b' => 1], $probe->collect()['counters']);
    }

    public function testRecordDurationsAccumulateTotalsAndBuckets(): void
    {
        $probe = PerfProbe::instance();
        PerfProbe::recordDuration('frame.ms', 0.3);
        PerfProbe::recordDuration('frame.ms', 0.7);
        PerfProbe::recordDuration('frame.ms', 70.0);

        $snapshot = $probe->peek();

        // 桶边界右开区间：0.3→桶0 / 0.7→桶1 / 70→桶8（≥64）
        // Right-open bucket edges: 0.3→bucket 0 / 0.7→bucket 1 / 70→bucket 8 (>=64)
        self::assertSame(1, $snapshot['histograms']['frame.ms'][0]);
        self::assertSame(1, $snapshot['histograms']['frame.ms'][1]);
        self::assertSame(1, $snapshot['histograms']['frame.ms'][8]);
        self::assertSame(71.0, $snapshot['totals']['frame.ms']);
    }

    public function testStaticApiRemainsCompatibleWithContractView(): void
    {
        PerfProbe::increment('events.c', 2);
        PerfProbe::record('hist.c', 4.0);

        // 静态便利入口与契约实例共享同一全局状态
        // Static convenience entries share the same global state as the contract instance
        self::assertSame(['events.c' => 2], PerfProbe::snapshot()['counters']);
        self::assertSame([4 => 1], PerfProbe::snapshot()['histograms']['hist.c']);
        self::assertSame(['events.c' => 2], PerfProbe::drain()['counters']);
        self::assertSame([], PerfProbe::instance()->collect()['counters']);
    }
}
