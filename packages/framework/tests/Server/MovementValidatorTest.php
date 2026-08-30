<?php

declare(strict_types=1);

namespace Nythros\Framework\Tests\Server;

use Nythros\Framework\Server\MovementValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * MovementValidatorTest - 覆盖反作弊基线校验器：合法步/超速/超频/瞬移四态表驱动、窗滚动重锚、
 * 实体状态隔离与 O(1) 常数行为。
 * MovementValidatorTest - covers the anti-cheat baseline validator: the legal/overspeed/rate-limited/teleport
 * four-state table, window rollover re-anchoring, per-entity state isolation and constant-time behavior.
 */
final class MovementValidatorTest extends TestCase
{
    /** 构造被测校验器（缺省参数：轴 2 / 欧氏 2.5 / 窗 1s 内 30 条 / 窗内距离 10）。 Builds the subject (defaults: axis 2 / euclid 2.5 / 30 cmds per 1s window / in-window distance 10). */
    private function validator(): MovementValidator
    {
        return new MovementValidator();
    }

    /**
     * 四态表驱动之步长维度：合法步通过；轴向超限/欧氏超限/巨型跳步 → overspeed。
     * The step dimension of the four-state table: legal steps pass; axis or Euclidean overruns and huge jumps → overspeed.
     */
    public static function provideStepCases(): iterable
    {
        yield 'legal single step' => ['p1', 1, 0, 0.0, null];
        yield 'legal knight-ish diagonal (2,1)=√5≤2.5' => ['p1', 2, 1, 0.0, null];
        yield 'overspeed dx axis' => ['p1', 3, 0, 0.0, MovementValidator::REASON_OVERSPEED];
        yield 'overspeed dy axis' => ['p1', 0, -3, 0.0, MovementValidator::REASON_OVERSPEED];
        yield 'overspeed euclid (2,2)=√8>2.5 with axes legal' => ['p1', 2, 2, 0.0, MovementValidator::REASON_OVERSPEED];
        yield 'overspeed huge jump' => ['p1', 100000, 0, 0.0, MovementValidator::REASON_OVERSPEED];
    }

    /** @dataProvider provideStepCases */
    #[DataProvider('provideStepCases')]
    public function testStepValidationTable(string $entityId, int $dx, int $dy, float $now, ?string $expected): void
    {
        self::assertSame($expected, $this->validator()->validate($entityId, $dx, $dy, 0, 0, $now));
    }

    /**
     * 在原点附近往返振荡打满窗预算（提议坐标始终距锚点 ≤1，不触瞬移阈值）。
     * Drains the window budget by oscillating around the origin (the proposed position stays within 1 of the
     * anchor, never tripping the teleport threshold).
     */
    private function drainBudget(MovementValidator $v, string $entityId, int $budget): void
    {
        for ($i = 0; $i < $budget; $i++) {
            $outbound = $i % 2 === 0;
            self::assertNull(
                $v->validate($entityId, $outbound ? 1 : -1, 0, $outbound ? 0 : 1, 0, 0.0),
                "窗内第 {$i} 条必须放行。Command #$i inside the window must pass.",
            );
        }
    }

    public function testLegalDiagonalWithinEuclideanCapPasses(): void
    {
        // (1,2) 欧氏 √5 ≈ 2.236 ≤ 2.5 且各轴 ≤ 2：合法
        // (1,2) has a Euclidean length of √5 ≈ 2.236 ≤ 2.5 with each axis ≤ 2: legal
        self::assertNull($this->validator()->validate('p1', 1, 2, 0, 0, 0.0));
    }

    /**
     * 频率门控：窗内第 31 条起拒绝 rate_limited；窗滚动后计数重置恢复放行。
     * Rate gating: from the 31st command inside the window reject rate_limited; after rollover the count resets and passes again.
     */
    public function testRateLimitRejectsBeyondWindowBudgetAndResetsOnRollover(): void
    {
        $v = $this->validator();

        $this->drainBudget($v, 'p1', 30);
        self::assertSame(MovementValidator::REASON_RATE_LIMITED, $v->validate('p1', 1, 0, 0, 0, 0.5), '同窗第 31 条必须拒绝 rate_limited。The 31st command in the same window must be rate-limited.');

        // 窗滚动（now - windowStart ≥ 1s）：计数重置，重新放行
        // Window rollover (now - windowStart ≥ 1s): the count resets and commands pass again
        self::assertNull($v->validate('p1', 1, 0, 0, 0, 1.0));
    }

    /**
     * 瞬移检测：每步合法但窗内累计位移超阈值 → teleport；新窗重锚后同一坐标恢复放行。
     * Teleport detection: every step legal but accumulated in-window displacement over the threshold → teleport;
     * after the new window re-anchors, the same position passes again.
     */
    public function testTeleportDetectsAccumulatedInWindowDisplacement(): void
    {
        $v = $this->validator();
        $x = 0;

        // 4 步 × 2 格 = 8 ≤ 10：全部放行
        // 4 steps × 2 cells = 8 ≤ 10: all pass
        for ($i = 0; $i < 4; $i++) {
            self::assertNull($v->validate('p1', 2, 0, $x, 0, 0.0));
            $x += 2;
        }

        // 第 5 步：锚点(0) 到提议(10) 的累计位移 = 10 未超；第 6 步提议(12) 距锚点 12 > 10 → teleport
        // Step 5: anchor(0)→proposed(10) accumulates 10, not over; step 6: proposed(12) sits 12 from the anchor → teleport
        self::assertNull($v->validate('p1', 2, 0, $x, 0, 0.0));
        $x += 2;
        self::assertSame(MovementValidator::REASON_TELEPORT, $v->validate('p1', 2, 0, $x, 0, 0.0), '窗内累计位移超阈值必须判 teleport。Accumulated in-window displacement over the threshold must be judged teleport.');

        // 窗滚动重锚到当前权威坐标：继续小步放行（带外位移不误判）
        // Rollover re-anchors at the current authoritative position: small steps pass again (no false positives from out-of-band moves)
        self::assertNull($v->validate('p1', 2, 0, $x, 0, 1.0));
    }

    public function testEntitiesIsolateRateAndTeleportState(): void
    {
        $v = $this->validator();

        // p1 打满窗预算不影响 p2 的独立判定
        // p1 exhausting its window budget never affects p2's independent verdict
        $this->drainBudget($v, 'p1', 30);
        self::assertSame(MovementValidator::REASON_RATE_LIMITED, $v->validate('p1', 1, 0, 0, 0, 0.5));
        self::assertNull($v->validate('p2', 1, 0, 0, 0, 0.5), '实体间频率/瞬移状态必须隔离。Rate/teleport state must stay isolated per entity.');
    }

    public function testRejectedCommandsDoNotConsumeWindowBudget(): void
    {
        $v = $this->validator();

        // 超速拒绝不计入窗预算：随后合法步照常放行
        // An overspeed rejection spends no window budget: a following legal step still passes
        self::assertSame(MovementValidator::REASON_OVERSPEED, $v->validate('p1', 9, 9, 0, 0, 0.0));
        self::assertNull($v->validate('p1', 1, 0, 0, 0, 0.0));

        // 再打满剩余预算后 rate_limited 连续稳定（不扩张预算）
        // After draining the remaining budget, rate_limited stays stable (no budget growth)
        $this->drainBudget($v, 'p1', 29);
        self::assertSame(MovementValidator::REASON_RATE_LIMITED, $v->validate('p1', 1, 0, 0, 0, 0.5));
        self::assertSame(MovementValidator::REASON_RATE_LIMITED, $v->validate('p1', 1, 0, 0, 0, 0.6));
    }

    /**
     * 自定义阈值构造：演示参数化能力（大步长世界用更大上限）。
     * Custom-threshold construction: demonstrates parameterization (a large-step world uses bigger caps).
     */
    public function testCustomThresholdsParameterizeTheCaps(): void
    {
        $v = new MovementValidator(maxStepAxis: 10, maxStepDistance: 50.0, maxCommandsPerWindow: 2, windowSeconds: 1.0, maxWindowDistance: 100.0);

        self::assertNull($v->validate('p1', 10, 10, 0, 0, 0.0), '自定义上限下 (10,10) 必须合法。(10,10) must be legal under the custom caps.');
        self::assertNull($v->validate('p1', 10, 10, 20, 20, 0.0));
        self::assertSame(MovementValidator::REASON_RATE_LIMITED, $v->validate('p1', 1, 0, 40, 40, 0.0), '窗内第 3 条必须按自定义预算拒绝。The third command must hit the custom budget.');
    }

    /**
     * 断连清理（forget）：窗口状态丢弃后同窗时刻立即重开新窗（预算归零、锚点重置）——
     * 若状态残留会沿用旧窗预算继续 rate_limited；未知 entityId 幂等无操作。
     * Disconnect cleanup (forget): once dropped, the very same wall-clock instant opens a fresh window (budget
     * zeroed, anchor reset) — leftover state would keep rate-limiting on the old budget; unknown entityIds are an
     * idempotent no-op.
     */
    public function testForgetDropsTheWindowSoANewOneOpensFresh(): void
    {
        $v = new MovementValidator(maxStepAxis: 10, maxStepDistance: 50.0, maxCommandsPerWindow: 2, windowSeconds: 100.0, maxWindowDistance: 100.0);

        self::assertNull($v->validate('p1', 1, 0, 0, 0, 0.0));
        self::assertNull($v->validate('p1', 1, 0, 1, 0, 0.5));
        self::assertSame(MovementValidator::REASON_RATE_LIMITED, $v->validate('p1', 1, 0, 2, 0, 0.6), '窗预算耗尽后必须拒绝。A spent window budget must reject.');

        // 断连清理：窗口行被摘除 The disconnect cleanup drops the window row.
        $v->forget('p1');

        self::assertNull($v->validate('p1', 1, 0, 3, 0, 0.7), 'forget 后同窗时刻必须重开新窗而非沿用旧预算。After forget the same instant must open a fresh window, not reuse the old budget.');

        // 未知 entityId 幂等（不抛错） Unknown entityIds stay an idempotent no-op.
        $v->forget('ghost');
        self::assertNull($v->validate('ghost', 1, 0, 0, 0, 0.0));
    }
}
