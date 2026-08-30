<?php

declare(strict_types=1);

namespace Nythros\Contracts;

/**
 * 房间生命周期状态：Created（已创建未开房）→ Running（运行中，可加入成员）→ Settled（已结算，停收成员）→ Closed（终态，成员与索引清空）。
 * Room lifecycle states: Created (built, not yet open) → Running (live, admitting members) → Settled (closed for admission) → Closed (terminal, members and indexes cleared).
 */
enum RoomState
{
    /**
     * 已创建、尚未有成员进入；空房可被驱动推进轮次。
     * Built but never joined; an empty room may still be driven to advance its rounds.
     */
    case Created;

    /**
     * 运行中：接受成员加入，被 RoomManager 到期驱动执行帧更新。
     * Live: admits members and is driven frame-by-frame by the RoomManager's due scheduling.
     */
    case Running;

    /**
     * 已结算：停止收成员，存活成员已收到 room.closed 信封，保留只读查询。
     * Settled: admissions stopped, surviving members have received room.closed envelopes, read-only queries remain available.
     */
    case Settled;

    /**
     * 终态：成员与索引已清空，不可逆。
     * Terminal: members and indexes cleared; irreversible.
     */
    case Closed;
}
