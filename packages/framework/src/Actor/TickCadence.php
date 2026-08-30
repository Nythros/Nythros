<?php

declare(strict_types=1);

namespace Nythros\Framework\Actor;

/**
 * 实体 tick 分频（P9a，区域降频的实体侧机制）：全局定时器恒定 base tick，热区实体按所在格子的
 * 密度档位获得分频（divisor=N → 每 N 个 base tick 才行动一次），非热区恒 1（逐帧，与接入前
 * 逐字节等价）。机制归框架（poll 在各 update 模板内调用），负载策略（谁该是几档）归 governor——
 * 本 trait 只承载相位计数，不含任何密度知识。
 * Per-entity tick cadence (the P9a, the entity-side mechanism of region downgrading): the global timer
 * holds a constant base tick, and hot-zone entities get a divisor from their cell's density tier
 * (divisor=N → act once every N base ticks) while non-hot entities stay at 1 (every frame,
 * byte-for-byte equivalent to the pre-integration behavior). The mechanism belongs to the framework
 * (polling inside the update templates); the load policy (who deserves which tier) belongs to the
 * governor — this trait carries the phase counter only and knows nothing about density.
 */
trait TickCadence
{
    /** 分频（1 = 逐帧）。缺省 1：未接入 governor 的实体行为不变。 The divisor (1 = every frame). Default 1: entities without a governor behave unchanged. */
    private int $tickDivisor = 1;

    /** @var int 分频相位（0..divisor-1，归零即到期） The cadence phase (0..divisor-1, due when it wraps to 0). */
    private int $tickPhase = 0;

    /**
     * 设置分频（governor 每 base tick 重算指派）；非法值（<1）钳制为 1。
     * Sets the divisor (reassigned by the governor every base tick); invalid values (<1) clamp to 1.
     */
    public function setTickDivisor(int $divisor): void
    {
        $this->tickDivisor = max(1, $divisor);
        if ($this->tickPhase >= $this->tickDivisor) {
            $this->tickPhase = 0;
        }
    }

    public function tickDivisor(): int
    {
        return $this->tickDivisor;
    }

    /**
     * 每 base tick 调用一次（在 update 模板内）：推进相位并返回本帧是否到期行动。
     * divisor=1 恒 true（无分频语义，逐帧路径零开销）。
     * Invoked once per base tick (inside the update templates): advances the phase and returns whether
     * this entity acts on this frame. divisor=1 is always true (no cadence semantics, zero overhead
     * on the per-frame path).
     */
    protected function pollCadence(): bool
    {
        if ($this->tickDivisor <= 1) {
            return true;
        }
        $this->tickPhase = ($this->tickPhase + 1) % $this->tickDivisor;

        return $this->tickPhase === 0;
    }
}
