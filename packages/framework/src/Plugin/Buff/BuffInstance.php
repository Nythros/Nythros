<?php

declare(strict_types=1);

namespace Nythros\Framework\Plugin\Buff;

/**
 * Buff 运行时实例：BuffService 状态机的可变状态单元（宿主键维度登记）。
 * 与 BuffDefinition（定义，readonly）相对——实例承载层数/到期时刻/DOT 下次结算时刻的演进状态。
 * Buff runtime instance: the mutable state unit of the BuffService state machine (registered per host key).
 * Counterpart of the readonly BuffDefinition — the instance carries the evolving stacks/expiry/next-DOT state.
 */
final class BuffInstance
{
    /**
     * @param string $buffId Buff 定义 id The buff-definition id.
     * @param string $hostKey 宿主键（玩家 entityId） Host key (the player's entityId).
     * @param int $stacks 当前层数（≥1） Current stacks (>=1).
     * @param float $expiresAt 到期时刻（microtime 秒） Expiry instant (microtime seconds).
     * @param ?float $nextDotAt DOT 下次结算时刻；null = 无 DOT 效果 The next DOT-settlement instant; null = no DOT effect.
     */
    public function __construct(
        public readonly string $buffId,
        public readonly string $hostKey,
        public int $stacks = 1,
        public float $expiresAt = 0.0,
        public ?float $nextDotAt = null,
    ) {
    }
}
