<?php

declare(strict_types=1);

namespace Nythros\Contracts;

/**
 * 房间配置值对象：只读，描述一个房间实例的寻址、节奏、容量与 AOI 装配策略。
 * Room configuration value object: read-only; describes a room instance's addressing, cadence, capacity and AOI assembly policy.
 */
final class RoomConfig
{
    /**
     * 构造房间配置。
     * Creates a room configuration.
     *
     * @param string $roomId 房间唯一标识 Unique room id.
     * @param int $periodMs 房间 tick 周期（毫秒，典型 15–50） Room tick period in milliseconds (typically 15–50).
     * @param int $maxMembers 成员上限（正数）：仅约束 join() 路径的受管成员（玩家、经 transfer 进出的实体）——
     *                        准入控制语义；经 EM.add 直入的实体（服务端刷怪、掉落）不受限，
     *                        其规模由业务侧配额自控（ADR-024 §9 V4）。
     *                        Member cap (positive): constrains only managed members admitted via join()
     *                        (players, entities transferred in/out) — admission control; entities added directly
     *                        through EM.add (server-side spawning, drops) are not capped, their scale is governed
     *                        by business-side quotas (ADR-024 §9 V4).
     * @param mixed $aoiFactory 每房间独立 AOI 工厂 Per-room independent AOI factory.
     * @param int $maxCatchUpTicks 追帧跳帧上限：落后超过该周期数则跳帧对齐当前时刻 Catch-up frame cap: beyond this many periods behind, skip-frame aligns to the current moment.
     *
     * aoiFactory 契约（运行时校验，见 RoomInstance 构造）：callable(EntityManagerInterface): AOIProviderInterface——
     * 工厂可接收房间自有实体管理器（UniversalAOI 必须包裹它）；不需要 EM 的工厂（如 GridAOI）写成零参闭包即可，
     * 多传实参会被忽略。
     * aoiFactory contract (validated at runtime, see RoomInstance construction): a factory may take the room's own
     * entity manager (which UniversalAOI must wrap); EM-less factories (e.g. GridAOI) are plain zero-arg closures,
     * the extra argument is ignored.
     */
    public function __construct(
        public readonly string $roomId,
        public readonly int $periodMs,
        public readonly int $maxMembers,
        /** @var mixed 每房间独立 AOI 工厂，契约签名见类注释（callable(EntityManagerInterface): AOIProviderInterface） per-room AOI factory; contract signature in the class docblock */
        public readonly mixed $aoiFactory,
        public readonly int $maxCatchUpTicks = 4,
    ) {
        if ($periodMs < 1) {
            throw new \InvalidArgumentException('RoomConfig periodMs 必须为正 / periodMs must be positive');
        }
        if ($maxMembers < 1) {
            throw new \InvalidArgumentException('RoomConfig maxMembers 必须为正 / maxMembers must be positive');
        }
        if ($maxCatchUpTicks < 0) {
            throw new \InvalidArgumentException('RoomConfig maxCatchUpTicks 必须非负 / maxCatchUpTicks must be non-negative');
        }
    }
}
