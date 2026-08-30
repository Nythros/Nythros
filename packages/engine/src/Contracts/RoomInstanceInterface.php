<?php

declare(strict_types=1);

namespace Nythros\Contracts;

/**
 * 房间实例契约：短生命周期小世界，扩展 WorldInterface 附加生命周期与成员进出能力。
 * 与持久大世界（World）平级为「空间容器」：共享子系统实现类、不共享实例；EventBus 共享宿主注入。
 * Room instance contract: a short-lived small world extending WorldInterface with lifecycle and membership
 * capabilities. Peer of the persistent world (World) as a "spatial container": subsystem implementation classes
 * are shared, instances are not; the EventBus is the injected shared host bus.
 *
 * tick 驱动不在本契约（由 RoomManagerInterface 统一到期驱动），实现保留被驱动的内部更新路径（update）。
 * Tick driving is not part of this contract (the RoomManagerInterface drives rooms when due); implementations keep
 * the internally driven update path (update).
 */
interface RoomInstanceInterface extends WorldInterface
{
    /**
     * 成员进入事件类型：广播给房间既有成员（source=进入者，targetScope=既有成员）。
     * Member-enter event type: broadcast to existing room members (source=joiner, targetScope=existing member).
     */
    public const EVENT_MEMBER_ENTER = 'room.member_enter';

    /**
     * 成员离开事件类型：广播给留守成员（source=离开者，targetScope=留守成员）。
     * Member-leave event type: broadcast to remaining members (source=departer, targetScope=remaining member).
     */
    public const EVENT_MEMBER_LEAVE = 'room.member_leave';

    /**
     * 房间快照事件类型：发给进入者（source=roomId，payload 含既有成员清单）。
     * Room-snapshot event type: sent to the joiner (source=roomId, payload carries the existing member list).
     */
    public const EVENT_ROOM_SNAPSHOT = 'room.snapshot';

    /**
     * 离开回执事件类型：发给离开者本人（source=roomId，targetScope=离开者）。
     * Room-left receipt event type: sent to the departer (source=roomId, targetScope=departer).
     */
    public const EVENT_ROOM_LEFT = 'room.left';

    /**
     * 房间结算事件类型：结算时发给每个存活成员（source=roomId，targetScope=存活成员）。
     * Room-closed event type: sent to every surviving member on settle (source=roomId, targetScope=surviving member).
     */
    public const EVENT_ROOM_CLOSED = 'room.closed';

    /**
     * 获取房间唯一标识。
     * Get the unique room id.
     */
    public function getRoomId(): string;

    /**
     * 获取当前生命周期状态。
     * Get the current lifecycle state.
     */
    public function getState(): RoomState;

    /**
     * 只读房间配置（roomId/periodMs/maxMembers/aoiFactory/maxCatchUpTicks）：单一访问器整体返回
     * Contracts 公开值对象，不设逐字段访问器（ADR-024 §9 V2）。
     * The read-only room configuration (roomId/periodMs/maxMembers/aoiFactory/maxCatchUpTicks): a single accessor
     * returning the public Contracts value object whole, with no per-field accessors (ADR-024 §9 V2).
     */
    public function getConfig(): RoomConfig;

    /**
     * 成员进入：EM 登记（即 markMoved 首帧进 AOI 索引）+ 可选 Actor 注册；
     * Created/Running 态方可加入（Created 首次加入即激活为 Running）；已是成员或满员返回 false；
     * Settled/Closed 态加入属非法状态迁移，抛 InvalidArgumentException。
     * Member admission: registers into the EM (which marks moved so the first frame enters the AOI index) plus an
     * optional actor; only Created/Running admit (the first join activates a Created room into Running); returns
     * false for existing members or at capacity; joining Settled/Closed is an illegal transition and throws.
     *
     * 双向通知语义对齐 World：既有成员收 EVENT_MEMBER_ENTER、进入者收 EVENT_ROOM_SNAPSHOT（信封入共享宿主总线）。
     * Bidirectional notification aligned with World: existing members receive EVENT_MEMBER_ENTER and the joiner
     * receives EVENT_ROOM_SNAPSHOT (envelopes go onto the shared host bus).
     */
    public function join(EntityInterface $entity, ?ActorInterface $actor = null): bool;

    /**
     * 成员离开：摘 EM + AOI + ActorSystem；非成员返回 false。
     * 双向通知：留守成员收 EVENT_MEMBER_LEAVE、离开者收 EVENT_ROOM_LEFT 回执。
     * Member departure: removes from EM + AOI + ActorSystem; returns false for non-members.
     * Bidirectional notification: remaining members receive EVENT_MEMBER_LEAVE and the departer receives the EVENT_ROOM_LEFT receipt.
     */
    public function leave(string $entityId): bool;

    /**
     * 结算：Running→Settled（从未开房的 Created 空房允许静默结算）；停收成员，
     * 向存活成员发 EVENT_ROOM_CLOSED 信封，保留只读查询。重复结算抛 LogicException。
     * Settle: Running→Settled (a never-opened empty Created room may settle silently); admissions stop,
     * every surviving member receives an EVENT_ROOM_CLOSED envelope, read-only queries remain. Double-settle throws.
     */
    public function settle(): void;

    /**
     * 关闭：Settled→Closed，清空成员与索引，终态不可逆。非 Settled 态关闭抛 LogicException。
     * Close: Settled→Closed, clears members and indexes; terminal and irreversible. Closing from any other state throws.
     */
    public function close(): void;
}
