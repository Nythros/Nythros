<?php

declare(strict_types=1);

namespace Nythros\Framework\Tests;

use Nythros\Contracts\ActorInterface;
use Nythros\Framework\Combat\ActorLookupInterface;
use Nythros\Framework\Combat\RandomSourceInterface;
use Nythros\Framework\Combat\TeamMembershipInterface;
use Nythros\Framework\Combat\VisionBroadcasterInterface;

/**
 * 战斗层共享测试 fakes：确定性随机源 / 调用记录广播器 / 调用记录 Actor 查找表，供 CombatServiceTest 与 MonsterActorTest 复用。
 * Shared combat-tier test fakes: a deterministic random source / a call-recording broadcaster / a call-recording actor lookup, reused by CombatServiceTest and MonsterActorTest.
 */

/**
 * FixedRandomSource - 确定性随机源：返回固定值或按序消耗的值队列（越界值钳制到 [min,max]）。
 * FixedRandomSource - a deterministic random source: returns a fixed value or a queue consumed in order (out-of-range values are clamped to [min,max]).
 */
final class FixedRandomSource implements RandomSourceInterface
{
    /** @var list<int>|int 固定值或值队列 A fixed value or a value queue. */
    private array|int $values;

    public function __construct(int|array $values = 100)
    {
        $this->values = $values;
    }

    public function randomInt(int $min, int $max): int
    {
        $value = is_array($this->values) ? (array_shift($this->values) ?? $min) : $this->values;

        return max($min, min($max, $value));
    }
}

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

/**
 * RecordingActorLookup - 按配置表返回 Actor 的查找表；removeActor 记录摘除调用（怪物死亡清理断言）。
 * RecordingActorLookup - an actor lookup backed by a configuration table; removeActor records the removal calls (monster-death cleanup assertions).
 */
final class RecordingActorLookup implements ActorLookupInterface
{
    /** @var array<string, ActorInterface> entityId => Actor 配置表 Configuration table. */
    public array $actors = [];

    /** @var list<string> removeActor 调用记录（修复 MINOR-2 的死亡清理断言） removeActor call records (MINOR-2 death-cleanup assertions). */
    public array $removedActorIds = [];

    public function getActor(string $entityId): ?ActorInterface
    {
        return $this->actors[$entityId] ?? null;
    }

    public function removeActor(string $entityId): void
    {
        $this->removedActorIds[] = $entityId;
        unset($this->actors[$entityId]);
    }
}

/**
 * FixedTeamMembership - 按配置表返回 uid → 队伍 id 的归属查询 fake（掉落同队拾取判定测试用）。
 * FixedTeamMembership - a membership-lookup fake backed by a uid → team-id table (for drop same-team pickup tests).
 */
final class FixedTeamMembership implements TeamMembershipInterface
{
    /** @param array<string, string> $teams uid => teamId 映射 The uid => teamId mapping. */
    public function __construct(private readonly array $teams = [])
    {
    }

    public function teamOf(string $uid): ?string
    {
        return $this->teams[$uid] ?? null;
    }
}
