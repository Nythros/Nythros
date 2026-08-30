<?php

declare(strict_types=1);

namespace Nythros\Framework\Game\Mmorpg;

/**
 * 热区策略（P9a，只读值对象）：格子密度 → 实体 tick 分频的档位表。tiers 按密度升序声明，
 * 「首个匹配档生效、无界末档（untilPlayers=0）兜底」；divisor=1 逐帧、2 半频、4 四分之一频
 * （base tick 50ms 下即 20/10/5Hz）。
 * Hot-cell policy (the P9a, a readonly value object): the tier table from cell density to entity tick
 * divisors. Tiers are declared by ascending density — the first matching tier wins and the unbounded
 * last tier (untilPlayers=0) backstops; divisor=1 every frame, 2 half rate, 4 quarter rate
 * (i.e. 20/10/5Hz on the 50ms base tick).
 */
final readonly class HotCellPolicy
{
    /**
     * @param list<array{untilPlayers: int, divisor: int}> $tiers 密度升序档位表（untilPlayers=0 = 无界兜底，
     *   须存在且仅一个、位于末位）。 The tier table by ascending density (untilPlayers=0 = the unbounded
     *   backstop, which must exist exactly once at the end).
     * @param int $hysteresisSeconds 降温滞回（秒）：密度回落后须持续该时长才降档，升温即时（快降慢升中
     *   的「快升」侧）。 The cooldown hysteresis (seconds): after density falls the demotion waits this long,
     *   while promotion applies immediately (the fast side of fast-up/slow-down).
     * @param int $neighborRadius 热区判定外扩的邻接格半径（0 = 仅自身格）。 The neighbor radius for hot-zone
     *   judgment (0 = the entity's own cell only).
     */
    public function __construct(
        public array $tiers,
        public int $hysteresisSeconds = 5,
        public int $neighborRadius = 0,
    ) {
        if ($this->tiers === []) {
            throw new \InvalidArgumentException('热区策略 tiers 不能为空 / hot-cell policy requires a non-empty tiers table');
        }
        $previous = -1;
        $previousDivisor = 0;
        $hasBackstop = false;
        foreach ($this->tiers as $index => $tier) {
            if ($tier['divisor'] < 1) {
                throw new \InvalidArgumentException(sprintf('热区策略 tiers[%d] divisor 必须为正', $index));
            }
            // 单调性：密度边界非降序 + divisor 非降序（密度越高分频越大/频率越低），防止配出反直觉的烂表
            // Monotonicity: density bounds and divisors must both be non-decreasing (higher density = bigger
            // divisor / lower rate), rejecting counter-intuitive tables at load time.
            if ($tier['untilPlayers'] !== 0 && $tier['untilPlayers'] <= $previous) {
                throw new \InvalidArgumentException(sprintf('热区策略 tiers[%d] untilPlayers 必须严格递增', $index));
            }
            if ($tier['divisor'] < $previousDivisor) {
                throw new \InvalidArgumentException(sprintf('热区策略 tiers[%d] divisor 必须非降序（密度越高频率越低）', $index));
            }
            if ($tier['untilPlayers'] === 0) {
                if ($index !== count($this->tiers) - 1) {
                    throw new \InvalidArgumentException('热区策略无界兜底档（untilPlayers=0）只能位于末位');
                }
                $hasBackstop = true;
            }
            $previous = max($previous, $tier['untilPlayers']);
            $previousDivisor = $tier['divisor'];
        }
        if (!$hasBackstop) {
            throw new \InvalidArgumentException('热区策略缺少无界兜底档（untilPlayers=0）');
        }
        if ($this->hysteresisSeconds < 0 || $this->neighborRadius < 0) {
            throw new \InvalidArgumentException('热区策略 hysteresisSeconds/neighborRadius 必须非负');
        }
    }
}
