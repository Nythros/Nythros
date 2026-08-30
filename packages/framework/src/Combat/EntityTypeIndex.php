<?php

declare(strict_types=1);

namespace Nythros\Framework\Combat;

/**
 * 实体类型索引：entityId → kind（player/monster/drop）的类型登记表。
 * 玩家/怪物共用 final 的 BaseEntity，感知侧无法从实体对象本身 instanceof 判定种类，必须经本表区分。
 * Entity type index: an entityId → kind (player/monster/drop) registration table.
 * Players and monsters share the final BaseEntity, so perception cannot distinguish kinds via instanceof on the entity object itself — this table is the discriminator.
 */
final class EntityTypeIndex
{
    public const KIND_PLAYER = 'player';

    public const KIND_MONSTER = 'monster';

    public const KIND_DROP = 'drop';

    /** @var array<string, string> entityId => kind（player|monster|drop） entityId => kind (player|monster|drop). */
    private array $kinds = [];

    /**
     * 登记实体类型（auth → player、spawnMonster → monster、spawnDrops → drop）。
     * Registers an entity kind (auth → player, spawnMonster → monster, spawnDrops → drop).
     *
     * @param string $entityId 实体 id The entity id.
     * @param string $kind 类型（本类 KIND_* 常量） The kind (this class's KIND_* constants).
     */
    public function set(string $entityId, string $kind): void
    {
        $this->kinds[$entityId] = $kind;
    }

    /**
     * 摘除实体类型登记（cleanup/死亡/拾取处同步删除）；未登记时静默忽略。
     * Removes the kind registration (kept in sync at cleanup/death/pickup sites); silently ignored when never registered.
     *
     * @param string $entityId 实体 id The entity id.
     */
    public function remove(string $entityId): void
    {
        unset($this->kinds[$entityId]);
    }

    /**
     * 查询实体类型；未登记返回 null。
     * Looks up the entity kind; null when not registered.
     *
     * @param string $entityId 实体 id The entity id.
     */
    public function kindOf(string $entityId): ?string
    {
        return $this->kinds[$entityId] ?? null;
    }
}
