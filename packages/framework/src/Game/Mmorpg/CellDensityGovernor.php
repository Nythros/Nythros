<?php

declare(strict_types=1);

namespace Nythros\Framework\Game\Mmorpg;

/**
 * 格子密度 governor（P9a，区域降频的负载策略层）：每 base tick 采样一次玩家位置 → 计算各格子密度 →
 * 按 HotCellPolicy 档位推导格子热区等级（升温即时、降温滞回）→ 对外提供「任意坐标的分频」查询
 * （取实体自身格及 neighborRadius 邻接格的最热档，平滑格界梯度）。机制与状态全部在本类，Actor 只
 * 持有 governor 指派的 divisor——策略可整体替换/单测可注入时钟。
 * The cell-density governor (the P9a, the load-policy layer of region downgrading): samples player
 * positions once per base tick → computes per-cell densities → derives each cell's hot level from the
 * HotCellPolicy tiers (promotion immediate, demotion hysteresised) → answers "the divisor at any
 * coordinate" (the hottest tier among the entity's own cell and its neighborRadius neighborhood,
 * smoothing the cell-boundary gradient). All mechanism and state live here; actors only hold the
 * governor-assigned divisor — the strategy is swappable and unit tests can inject a clock.
 */
final class CellDensityGovernor
{
    /** 格子尺寸（世界单位，与 GridAOI cellSize 同源） The cell size (world units, same source as the GridAOI cellSize). */
    private readonly int $cellSize;

    /** @var array<string, array{density: int, level: int, changedAt: float}> 格子状态表（key "cx,cy"） The per-cell state table (key "cx,cy"). */
    private array $cells = [];

    /** 时钟（可注入；缺省 microtime）。 The clock (injectable; defaults to microtime). */
    private \Closure $clock;

    /**
     * @param \Closure(): float|null $clock 时钟注入（单测步进时钟） Clock injection (a stepped clock for unit tests).
     */
    public function __construct(
        int $cellSize,
        private readonly HotCellPolicy $policy,
        ?\Closure $clock = null,
    ) {
        if ($cellSize <= 0) {
            throw new \InvalidArgumentException('governor cellSize 必须为正 / governor requires a positive cellSize');
        }
        $this->cellSize = $cellSize;
        $this->clock = $clock ?? static fn (): float => microtime(true);
    }

    /**
     * 每 base tick 采样一次：以玩家位置重算各格密度并推进热区等级。升温即时生效；降温须在当前等级
     * 持续 hysteresisSeconds 后逐级回落（快升慢降，防临界抖动）。未出现在采样里的格子密度归零，
     * 状态保留（滞回计时继续）。
     * Sampled once per base tick: recomputes per-cell densities from the player positions and advances
     * hot levels. Promotion applies immediately; demotion requires the current level to persist for
     * hysteresisSeconds (fast-up/slow-down against threshold flapping). Cells absent from the sample
     * drop to zero density with their state retained (the hysteresis clock keeps running).
     *
     * @param list<array{x: int, y: int}> $positions 全部在线玩家的位置（密度度量） All online players' positions (the density metric).
     */
    public function sample(array $positions): void
    {
        $now = ($this->clock)();
        $densities = [];
        foreach ($positions as $position) {
            $key = $this->cellKey((int) $position['x'], (int) $position['y']);
            $densities[$key] = ($densities[$key] ?? 0) + 1;
        }

        // 全量格子（含上帧有状态、本帧无人的格子）统一推进：本帧采样缺席的格子密度归零
        // （人群离开 = 密度 0，降温交由滞回计时）。
        // Advance over all cells (including stateful ones absent from this frame's sample): unsampled
        // cells drop to zero density (an empty cell is zero density; the demotion rides the hysteresis).
        foreach ($this->cells as $key => $cell) {
            if (!isset($densities[$key])) {
                $this->cells[$key]['density'] = 0;
            }
        }
        foreach ($densities as $key => $density) {
            $this->cells[$key] ??= ['density' => 0, 'level' => 0, 'changedAt' => $now];
            $this->cells[$key]['density'] = $density;
        }
        foreach ($this->cells as $key => $cell) {
            $density = $this->cells[$key]['density'];
            $target = $this->targetLevel($density);
            $level = $cell['level'];
            if ($target > $level) {
                // 升温即时（负载出现立即 shedding） Promotion is immediate (shed as soon as load appears).
                $this->cells[$key]['level'] = $target;
                $this->cells[$key]['changedAt'] = $now;
            } elseif ($target < $level && ($now - $cell['changedAt']) >= $this->policy->hysteresisSeconds) {
                // 降温滞回：在当前等级持续足量时间才回落（逐级回落到目标，防锯齿）
                // Demotion hysteresis: fall back only after persisting at the level, down to the target (no sawtooth).
                $this->cells[$key]['level'] = $target;
                $this->cells[$key]['changedAt'] = $now;
            }
        }
    }

    /**
     * 坐标的实体分频：取自身格及 neighborRadius 邻接格的最热档（格界梯度平滑——邻格更热则按热档，
     * 避免怪物跨格时档位突变）。
     * The entity divisor at a coordinate: the hottest tier among the own cell and the neighborRadius
     * neighborhood (a smoothed boundary gradient — a hotter neighbor promotes the divisor, avoiding
     * abrupt tier changes as monsters cross cells).
     */
    public function divisorFor(int $x, int $y): int
    {
        $cx = intdiv($x, $this->cellSize);
        $cy = intdiv($y, $this->cellSize);
        $radius = $this->policy->neighborRadius;
        $divisor = 1;
        for ($dx = -$radius; $dx <= $radius; $dx++) {
            for ($dy = -$radius; $dy <= $radius; $dy++) {
                $cell = $this->cells[sprintf('%d,%d', $cx + $dx, $cy + $dy)] ?? null;
                if ($cell !== null && $cell['level'] > 0) {
                    $divisor = max($divisor, $this->policy->tiers[$cell['level'] - 1]['divisor']);
                }
            }
        }

        return $divisor;
    }

    /**
     * 密度 → 目标档位（tiers 按升序首个 untilPlayers ≥ 密度的档；无界兜底档收尾）。
     * Density → the target level (the first tier whose untilPlayers covers the density; the unbounded
     * backstop closes the table).
     */
    private function targetLevel(int $density): int
    {
        foreach ($this->policy->tiers as $index => $tier) {
            if ($tier['untilPlayers'] === 0 || $density <= $tier['untilPlayers']) {
                return $index + 1;
            }
        }

        return 0; // 不可达（构造期校验保证存在兜底档） Unreachable (construction validation guarantees a backstop).
    }

    private function cellKey(int $x, int $y): string
    {
        return sprintf('%d,%d', intdiv($x, $this->cellSize), intdiv($y, $this->cellSize));
    }
}
