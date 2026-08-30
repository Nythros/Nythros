<?php

declare(strict_types=1);

namespace Nythros\Framework\Tests\Observability;

use Nythros\Contracts\PerfSnapshotProviderInterface;
use Nythros\Framework\Observability\PerfSampler;
use PHPUnit\Framework\TestCase;

/**
 * PerfSamplerTest - 采样器消费快照契约的行为：
 * 空窗口静默跳过 Redis、非空窗口按键约定写 Hash（counters/hist/totals）、collect 恰好一次、
 * Redis 故障被吞掉不抛上游。
 * PerfSamplerTest - sampler behavior over the snapshot contract: silent skip on empty windows,
 * Hash writes per key convention (counters/hist/totals) on non-empty windows, exactly one collect,
 * and Redis failures swallowed (never thrown upstream).
 */
final class PerfSamplerTest extends TestCase
{
    public function testEmptyWindowSkipsRedis(): void
    {
        $probe = new FakeProbe();
        $touched = false;
        $sampler = new PerfSampler($probe, function () use (&$touched): \Redis {
            $touched = true;

            return new \Redis();
        }, 'map-1#ch-1');

        $sampler->sample();

        self::assertFalse($touched, '空窗口不得触碰 Redis');
        self::assertSame(1, $probe->collectCalls);
    }

    public function testNonEmptyWindowWritesPipelineHashes(): void
    {
        $probe = new FakeProbe();
        $probe->queue = [
            'counters' => ['world.envelope_published' => 7],
            'histograms' => ['world.frame_ms' => [2 => 3]],
            'totals' => ['world.frame_ms' => 12.5],
        ];
        $redis = new FakeRedis();
        $sampler = new PerfSampler($probe, static fn (): FakeRedis => $redis, 'map-1#ch-1', 5);

        $sampler->sample();

        self::assertTrue($redis->pipelineBegan);
        self::assertSame([
            ['hIncrBy', 'nythros:perf:map-1#ch-1:counters', 'world.envelope_published', 7],
            ['hIncrBy', 'nythros:perf:map-1#ch-1:hist', 'world.frame_ms.2', 3],
            ['hIncrByFloat', 'nythros:perf:map-1#ch-1:totals', 'world.frame_ms', 12.5],
        ], $redis->ops);
        self::assertCount(1, $redis->sets, '最近快照时间戳恰好写一次');
        $lastSnapshot = json_decode($redis->sets[0][2], true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('ts', $lastSnapshot);
        self::assertSame('map-1#ch-1', $lastSnapshot['serviceId']);
        self::assertTrue($redis->execCalled);
    }

    public function testRedisFailureIsSwallowed(): void
    {
        $probe = new FakeProbe();
        $probe->queue = [
            'counters' => ['e' => 1],
            'histograms' => [],
            'totals' => [],
        ];
        $sampler = new PerfSampler($probe, static fn (): \Redis => throw new \RuntimeException('connection refused'), 'map-1#ch-1');

        // 采样失败只记日志，绝不抛给上游（探针不能拖垮游戏主循环）
        // Sampling failures are logged only and never thrown upstream
        $sampler->sample();

        self::assertSame(1, $probe->collectCalls);
    }

    public function testIntervalSecondsExposedForCallerTimer(): void
    {
        $sampler = new PerfSampler(new FakeProbe(), static fn (): \Redis => new \Redis(), 'map-1#ch-1', 9);

        self::assertSame(9, $sampler->intervalSeconds());
    }
}

/**
 * 契约假实现：可编程返回快照并记录 collect 调用次数。
 * Contract fake: programmable snapshot return plus collect call accounting.
 */
final class FakeProbe implements PerfSnapshotProviderInterface
{
    /** @var array{counters: array<string, int>, histograms: array<string, array<int, int>>, totals: array<string, float>} */
    public array $queue = ['counters' => [], 'histograms' => [], 'totals' => []];

    public int $collectCalls = 0;

    public function collect(): array
    {
        ++$this->collectCalls;

        return $this->queue;
    }

    public function peek(): array
    {
        return $this->queue;
    }
}

/**
 * Redis 假实现：记录 pipeline 操作序列，模拟 multi/exec 链式调用面。
 * Redis fake: records the pipeline op sequence, simulating the multi/exec call surface.
 */
final class FakeRedis
{
    public bool $pipelineBegan = false;

    /** @var list<array{0: string, 1: string, 2?: string|int, 3?: string|int|float}> */
    public array $ops = [];

    /** @var list<array{0: string, 1: string, 2: string}> */
    public array $sets = [];

    public bool $execCalled = false;

    public function multi(int $mode): self
    {
        $this->pipelineBegan = true;

        return $this;
    }

    public function hIncrBy(string $key, string $field, int $by): self
    {
        $this->ops[] = ['hIncrBy', $key, $field, $by];

        return $this;
    }

    public function hIncrByFloat(string $key, string $field, float $by): self
    {
        $this->ops[] = ['hIncrByFloat', $key, $field, $by];

        return $this;
    }

    public function set(string $key, string $value): self
    {
        $this->sets[] = ['set', $key, $value];

        return $this;
    }

    public function exec(): void
    {
        $this->execCalled = true;
    }
}
