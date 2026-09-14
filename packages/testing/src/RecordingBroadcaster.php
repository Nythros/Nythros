<?php

declare(strict_types=1);

namespace Nythros\Testing;

use Nythros\Framework\Combat\VisionBroadcasterInterface;

/**
 * RecordingBroadcaster - 记录 broadcastToVision/sendToEntity 调用的广播器。
 * RecordingBroadcaster - a broadcaster recording broadcastToVision/sendToEntity calls.
 */
final class RecordingBroadcaster implements VisionBroadcasterInterface
{
    /** @var list<array{center: string, type: string, payload: array<string, mixed>}> 视野广播调用记录 View-broadcast call records. */
    public array $vision = [];

    /** @var list<array{entity: string, type: string, payload: array<string, mixed>}> 定向发送调用记录 Directed-send call records. */
    public array $direct = [];

    public function broadcastToVision(string $centerEntityId, string $type, array $payload): void
    {
        $this->vision[] = ['center' => $centerEntityId, 'type' => $type, 'payload' => $payload];
    }

    public function sendToEntity(string $entityId, string $type, array $payload): void
    {
        $this->direct[] = ['entity' => $entityId, 'type' => $type, 'payload' => $payload];
    }
}
