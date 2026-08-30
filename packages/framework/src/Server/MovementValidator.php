<?php

declare(strict_types=1);

namespace Nythros\Framework\Server;

/**
 * 移动校验器（R3 反作弊基线）：O(1) 热路径的 move 指令合法性门控，纯 framework——
 * 速度语义是业务知识，engine 零业务铁律排除引擎层。
 * Movement validator (the R3 anti-cheat baseline): an O(1) hot-path legality gate for move instructions,
 * pure framework — speed semantics are business knowledge, so the engine's zero-business rule keeps it out.
 *
 * 三层校验（全部常数时间，每实体一行状态）：
 * - 单步位移上限：|dx|、|dy| 各自上限 + 欧氏距离上限 → overspeed；
 * - 指令频率门控：固定时间窗内指令数上限 → rate_limited；
 * - 瞬移检测：本时间窗起点锚点（窗首实体的权威坐标）到提议坐标的直线距离超阈值 → teleport——
 *   即使每步都合法，窗内累计位移也不可能超过该阈值，堵住「小步快刷」型加速。
 * Three layers (all constant-time, one state row per entity):
 * - Step caps: per-axis |dx|/|dy| limits plus a Euclidean distance limit → overspeed;
 * - Rate gating: a cap on commands inside a fixed time window → rate_limited;
 * - Teleport detection: straight-line distance from the window-start anchor (the entity's authoritative position
 *   at window open) to the proposed position beyond the threshold → teleport — even if every step is legal, the
 *   accumulated in-window displacement cannot exceed it, closing the "many tiny legal steps" speed hack.
 *
 * 时间窗滚动时重置计数并重设锚点为当前传入坐标（调用方传实体权威坐标，服务端传送/复活等带外位移
 * 不会造成误判）；validate 返回 null = 通过，否则返回拒绝原因常量。校验通过与否都不改实体状态，
 * 实体坐标变更仍由 RealtimeServer::handleMove 模板负责。
 * A window rollover resets the count and re-anchors at the passed-in current position (callers pass the
 * authoritative position, so out-of-band displacements like server-side teleports or respawns never cause false
 * positives); validate returns null on pass, otherwise a rejection-reason constant. Neither outcome mutates the
 * entity — coordinate changes remain the RealtimeServer::handleMove template's job.
 */
final class MovementValidator
{
    /** 拒绝原因：单步位移超限（轴向或欧氏距离）。 Rejection reason: step displacement over the axis or Euclidean cap. */
    public const REASON_OVERSPEED = 'overspeed';

    /** 拒绝原因：时间窗内指令数超限。 Rejection reason: too many commands inside the time window. */
    public const REASON_RATE_LIMITED = 'rate_limited';

    /** 拒绝原因：窗内累计位移超阈值（瞬移）。 Rejection reason: accumulated in-window displacement over the threshold (teleport). */
    public const REASON_TELEPORT = 'teleport';

    /** @var array<string, array{windowStart: float, count: int, anchorX: int, anchorY: int}> entityId => 窗状态 entityId => window state */
    private array $windows = [];

    /**
     * @param int $maxStepAxis 单步 |dx|/|dy| 各自上限 Per-axis |dx|/|dy| step cap.
     * @param float $maxStepDistance 单步欧氏距离上限（须小于轴上限的 √2 倍才真正参与约束） Per-step Euclidean distance cap (must sit below the axis cap's √2 multiple to bind at all).
     * @param int $maxCommandsPerWindow 时间窗内指令数上限 Command cap inside the time window.
     * @param float $windowSeconds 频率时间窗长度（秒） Rate-window length in seconds.
     * @param float $maxWindowDistance 窗内锚点到提议坐标的距离上限（瞬移阈值） In-window anchor→proposed distance cap (the teleport threshold).
     */
    public function __construct(
        private readonly int $maxStepAxis = 2,
        private readonly float $maxStepDistance = 2.5,
        private readonly int $maxCommandsPerWindow = 30,
        private readonly float $windowSeconds = 1.0,
        private readonly float $maxWindowDistance = 10.0,
    ) {
    }

    /**
     * 校验一次 move 指令（O(1)）：单步上限 → 频率门控 → 瞬移检测，任一失败即短路返回原因。
     * Validates one move instruction (O(1)): step caps → rate gating → teleport detection, short-circuiting on the first failure.
     *
     * @param string $entityId 实体 id（频率与瞬移状态的键） The entity id (key of the rate/teleport state).
     * @param int $dx 移动增量 dx The dx delta.
     * @param int $dy 移动增量 dy The dy delta.
     * @param int $fromX 实体当前权威 x（移动前，瞬移锚点基准） The entity's authoritative x before the move (the teleport anchor base).
     * @param int $fromY 实体当前权威 y The entity's authoritative y.
     * @param float $now 当前时间（秒，可注入假时钟） Current time in seconds (a fake clock may be injected).
     */
    public function validate(string $entityId, int $dx, int $dy, int $fromX, int $fromY, float $now): ?string
    {
        // ① 单步位移上限：轴向 + 欧氏距离（平方比较免开方）
        // ① Step caps: per-axis plus Euclidean (squared comparison avoids the square root)
        if (abs($dx) > $this->maxStepAxis || abs($dy) > $this->maxStepAxis
            || ($dx * $dx + $dy * $dy) > $this->maxStepDistance ** 2) {
            return self::REASON_OVERSPEED;
        }

        $window = $this->windows[$entityId] ?? null;
        if ($window === null || ($now - $window['windowStart']) >= $this->windowSeconds) {
            // 新窗：重置计数并重设锚点为当前权威坐标（带外位移不误判）
            // New window: reset the count and re-anchor at the current authoritative position (no false positives from out-of-band moves)
            $window = ['windowStart' => $now, 'count' => 0, 'anchorX' => $fromX, 'anchorY' => $fromY];
        }

        // ② 频率门控：本窗指令数已满即拒绝
        // ② Rate gating: reject once this window's command budget is spent
        if ($window['count'] >= $this->maxCommandsPerWindow) {
            $this->windows[$entityId] = $window;

            return self::REASON_RATE_LIMITED;
        }

        // ③ 瞬移检测：窗首锚点到提议坐标（from + d）的累计位移
        // ③ Teleport detection: accumulated displacement from the window-open anchor to the proposed position (from + d)
        $toX = $fromX + $dx;
        $toY = $fromY + $dy;
        $ddx = $toX - $window['anchorX'];
        $ddy = $toY - $window['anchorY'];
        if (($ddx * $ddx + $ddy * $ddy) > $this->maxWindowDistance ** 2) {
            $this->windows[$entityId] = $window;

            return self::REASON_TELEPORT;
        }

        $window['count']++;
        $this->windows[$entityId] = $window;

        return null;
    }

    /**
     * 丢弃某实体的时间窗状态（断连清理路径）：窗口行按 entityId 无界增长且无 TTL，接线层在断连清理
     * 模板（RealtimeServer::closeConnection）调用本方法摘除对应状态行；未知 entityId 幂等无操作。
     * Drops one entity's time-window state (the disconnect-cleanup path): window rows grow unbounded by entityId
     * with no TTL, so the wiring layer invokes this from its disconnect template (RealtimeServer::closeConnection);
     * unknown entityIds are an idempotent no-op.
     */
    public function forget(string $entityId): void
    {
        unset($this->windows[$entityId]);
    }
}
