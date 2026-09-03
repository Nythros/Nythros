<?php

declare(strict_types=1);

namespace Nythros\Framework\Matching;

/**
 * 匹配入房编排委托契约（framework → assembly layer 依赖倒置）：撮合成功后把一名候选者编排进指定房间。
 * The match-join orchestration delegation contract (framework → assembly layer inversion): after a successful match,
 * one candidate is orchestrated into the designated room.
 *
 * 组装边界（R3 玩法批裁决）：transfer 全链编排——宿主世界 EM/AOI 摘除、entity_leave 广播、
 * manager->transfer 原子入房与连接容器维度标记——全部依赖宿主 World/MapServer 能力，留在
 * （唯一组装点铁律）；本契约只暴露「entityId 进 roomId」的最小语义，framework 不感知编排细节。
 * Assembly boundary (the R3 gameplay-batch ruling): the full transfer chain — host-world EM/AOI removal, the
 * entity_leave broadcast, the atomic manager->transfer and the connection-container marking — all depend on host
 * World/MapServer capabilities and stay in the assembly layer (the single-assembly-point rule); this contract exposes only
 * the minimal "entityId into roomId" semantics, with no orchestration detail leaking into the framework.
 */
interface MatchJoinHandlerInterface
{
    /**
     * 把 entityId 对应的玩家编排进 roomId；false = 编排失败（满员/状态不可入/实体缺失等），
     * 撮合侧将该候选者重新入队等待下一拍。
     * Orchestrates the player behind entityId into roomId; false = orchestration failed (full room / non-admissible
     * state / missing entity), and the matcher re-queues that candidate for the next tick.
     */
    public function joinRoom(string $roomId, string $entityId): bool;
}
