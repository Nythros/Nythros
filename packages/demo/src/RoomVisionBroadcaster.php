<?php

declare(strict_types=1);

namespace Nythros\Demo;

use Nythros\Contracts\RoomInstanceInterface;
use Nythros\Framework\Combat\VisionBroadcasterInterface;

/**
 * 房间视野广播器（ADR-024 §D-A/§4 接线）：以房间自有 AOI/EntityManager 定视野，投递复用宿主 MapServer 的
 * 连接注册表与 FrameMerger（sendToEntity 公共面）——房间战斗代码经 VisionBroadcasterInterface 门面零改动运行于房间内。
 * 一次 broadcastToVision = 视野内每个连接各入队一帧（帧末 flushOutbox 统一批量下发），与宿主世界同构。
 * Room vision broadcaster (ADR-024 §D-A/§4 wiring): vision comes from the room's own AOI/EntityManager while delivery
 * reuses the host MapServer's connection registry and FrameMerger (the public sendToEntity surface) — room combat code
 * runs inside rooms unchanged behind the VisionBroadcasterInterface facade. One broadcastToVision call enqueues one
 * frame per in-view connection (batched at frame end by flushOutbox), isomorphic to the host world.
 */
final class RoomVisionBroadcaster implements VisionBroadcasterInterface
{
    /**
     * @param RoomInstanceInterface $room 目标房间（自有 EM/AOI 提供视野） The target room (its own EM/AOI provide vision).
     * @param MapServer $map 宿主 Map 服（连接注册表 + 帧合并出站管道） The host map server (connection registry + merged outbound pipeline).
     */
    public function __construct(
        private readonly RoomInstanceInterface $room,
        private readonly MapServer $map,
    ) {
    }

    /**
     * 向 centerEntityId 在房间内的视野广播一帧：房间 AOI 查询含自身（事件帧语义，施法者/通告对象可达），
     * 邻居经宿主 sendToEntity 定向入队（无连接者静默跳过）。
     * Broadcasts one frame across centerEntityId's in-room view: the room AOI query includes self (event-frame
     * semantics — the caster/announce target is reachable); neighbors enqueue via the host's sendToEntity
     * (connection-less neighbors are silently skipped).
     *
     * @param array<string, mixed> $payload 帧负载 Frame payload.
     */
    public function broadcastToVision(string $centerEntityId, string $type, array $payload): void
    {
        $center = $this->room->getEntityManager()->get($centerEntityId);
        if ($center === null) {
            return;
        }

        foreach ($this->room->getAOI()->query($center) as $neighbor) {
            $this->map->sendToEntity($neighbor->getId(), $type, $payload);
        }
    }

    /**
     * 定向发送一帧给某 entityId 对应连接（拾取者/攻击发起者回执），直接复用宿主公共面。
     * Sends one frame directed to an entityId's connection (pickup actor / attack initiator receipts), delegating to the host's public surface.
     *
     * @param array<string, mixed> $payload 帧负载 Frame payload.
     */
    public function sendToEntity(string $entityId, string $type, array $payload): void
    {
        $this->map->sendToEntity($entityId, $type, $payload);
    }
}
