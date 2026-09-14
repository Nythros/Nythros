<?php

declare(strict_types=1);

namespace Nythros\Testing;

use Nythros\Contracts\ActorInterface;
use Nythros\Framework\Combat\ActorLookupInterface;

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
