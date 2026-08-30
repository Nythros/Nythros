<?php

declare(strict_types=1);

namespace Nythros\Framework\Combat;

use Nythros\Contracts\ActorInterface;

/**
 * 按 entityId 查 Actor：MonsterActor 解析目标 PlayerActor 用；MapServer 以 $actors 表实现（玩家+怪物都登记）。
 * Actor lookup by entityId: used by MonsterActor to resolve its target PlayerActor; MapServer implements it over the $actors table (players and monsters are both registered).
 */
interface ActorLookupInterface
{
    /**
     * 按实体 id 查询已登记的 Actor；未登记返回 null。
     * Looks up a registered actor by entity id; null when not registered.
     *
     * @param string $entityId 实体 id The entity id.
     */
    public function getActor(string $entityId): ?ActorInterface;

    /**
     * 按实体 id 摘除已登记的 Actor（怪物死亡自清理用）；未登记时静默忽略。
     * Removes the registered actor by entity id (used by monster-death self-cleanup); silently ignored when never registered.
     *
     * @param string $entityId 实体 id The entity id.
     */
    public function removeActor(string $entityId): void;
}
