<?php

declare(strict_types=1);

namespace Nythros\Framework\Combat;

/**
 * 视野/定向广播接口：战斗结算依赖它出帧，由 MapServer 实现（持有 FrameMerger 帧合并器 + connections + registry）。
 * Vision/directed broadcast interface: combat settlement depends on it for frames, implemented by MapServer (which owns the FrameMerger + connections + registry).
 */
interface VisionBroadcasterInterface
{
    /**
     * 向 centerEntityId 视野内的全部连接广播一帧（帧末 flush）。
     * Broadcasts one frame to every connection inside the centerEntityId's view (flushed at frame end).
     *
     * @param string $centerEntityId 视野中心实体 id The view-center entity id.
     * @param string $type 消息类型 Message type.
     * @param array<string, mixed> $payload 消息负载 Message payload.
     */
    public function broadcastToVision(string $centerEntityId, string $type, array $payload): void;

    /**
     * 定向发送一帧给某 entityId 对应连接（拾取者/攻击发起者回执）。
     * Sends one frame directed to the connection of an entityId (pickup actor / attack initiator receipts).
     *
     * @param string $entityId 目标实体 id The target entity id.
     * @param string $type 消息类型 Message type.
     * @param array<string, mixed> $payload 消息负载 Message payload.
     */
    public function sendToEntity(string $entityId, string $type, array $payload): void;
}
