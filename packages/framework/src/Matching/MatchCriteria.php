<?php

declare(strict_types=1);

namespace Nythros\Framework\Matching;

/**
 * 撮合条件值对象：一个队列（房间类型）的准入与开房参数。
 * Match criteria value object: the admission and room-building parameters of one queue (room type).
 *
 * 等级区间是准入条件（enqueue 时校验候选者 level ∈ [minLevel, maxLevel]）；同一队列的准入区间相同，
 * 故同队成员天然等级相容，撮合阶段只按人数 FIFO 凑满开房（不做事后等级排序）。
 * The level range is an admission condition (enqueue validates candidate level within [minLevel, maxLevel]);
 * one queue shares one admission range, so teammates are level-compatible by construction and matching only fills
 * rooms FIFO by headcount (no post-hoc level ordering).
 */
final readonly class MatchCriteria
{
    /**
     * @param string $queueId 队列/房间类型 id（如 'horde-6'） Queue/room-type id (e.g. 'horde-6').
     * @param int $teamSize 撮合人数：凑满即开房 Match size: a room is built once this many candidates are gathered.
     * @param int $minLevel 准入等级下界（含） Inclusive minimum admission level.
     * @param int $maxLevel 准入等级上界（含） Inclusive maximum admission level.
     * @param int $roomPeriodMs 开房 tick 周期（毫秒，透传 RoomConfig） Built-room tick period in milliseconds (passed through to RoomConfig).
     * @param int $roomMaxMembers 开房受管成员上限（透传 RoomConfig） Built-room managed-member cap (passed through to RoomConfig).
     */
    public function __construct(
        public string $queueId,
        public int $teamSize,
        public int $minLevel,
        public int $maxLevel,
        public int $roomPeriodMs = 50,
        public int $roomMaxMembers = 512,
    ) {
        if ($this->teamSize < 1) {
            throw new \InvalidArgumentException('MatchCriteria teamSize 必须为正 / teamSize must be positive');
        }
        if ($this->minLevel > $this->maxLevel) {
            throw new \InvalidArgumentException('MatchCriteria 等级区间倒挂 / minLevel exceeds maxLevel');
        }
    }

    /**
     * 候选者等级是否满足准入区间（含边界）。
     * Whether a candidate's level satisfies the admission range (bounds inclusive).
     */
    public function admits(int $level): bool
    {
        return $level >= $this->minLevel && $level <= $this->maxLevel;
    }
}
