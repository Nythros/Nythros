<?php

declare(strict_types=1);

namespace Nythros\Demo\Gameplay;

/**
 * 怪物出生规格（P11 玩法数据外置）：怪物表一行的值对象——锚点/造型/血量/巡逻域/逐怪重生延迟。
 * 由 GameplayTables::schemas() 的 gameplay 表结构校验后经 fromTable 装配，字段合法性由 schema 层保证。
 * A monster spawn spec (the P11 data externalization): the value object of one monster-table row — anchor, type,
 * max hp, patrol domain, and the per-monster respawn delay. Field legality is guaranteed by the schema layer via
 * GameplayTables::schemas() before fromTable assembles it.
 *
 * @internal starter-kit 装配层值对象（demo 包不对外承诺 API） starter-kit assembly value object (no API promise outside the demo package).
 */
final readonly class MonsterSpawn
{
    /**
     * @param string $id 怪物实体/Actor 共用 id Shared monster entity/actor id.
     * @param string $typeId 怪物类型 id（monster:spawned 造型标识 + 任务击杀匹配键） Monster type id (the
     *   monster:spawned visual identity and the quest kill-matching key).
     * @param int $maxHp 最大生命值 Maximum hit points.
     * @param array{x: int, y: int} $anchor 出生锚点（巡逻中心，重生回锚） Spawn anchor (patrol center; respawn returns here).
     * @param int|null $patrolRadius 巡逻半径（null = MonsterActor 缺省 10） Patrol radius (null = the MonsterActor default 10).
     * @param int|null $respawnMs 逐怪重生延迟（null = MmorpgConfig.respawnMs 全局值） Per-monster respawn delay
     *   (null = the global MmorpgConfig.respawnMs).
     */
    public function __construct(
        public string $id,
        public string $typeId,
        public int $maxHp,
        public array $anchor,
        public ?int $patrolRadius,
        public ?int $respawnMs,
    ) {
    }

    /**
     * 从（已通过 schema 校验的）怪物表行装配。
     * Assembles from a (schema-validated) monster-table row.
     *
     * @param array<string, mixed> $row 表行 The table row.
     */
    public static function fromRow(array $row): self
    {
        $anchor = $row['anchor'] ?? [];

        return new self(
            (string) ($row['id'] ?? ''),
            (string) ($row['typeId'] ?? ''),
            (int) ($row['maxHp'] ?? 0),
            ['x' => (int) ($anchor['x'] ?? 0), 'y' => (int) ($anchor['y'] ?? 0)],
            isset($row['patrolRadius']) ? (int) $row['patrolRadius'] : null,
            isset($row['respawnMs']) ? (int) $row['respawnMs'] : null,
        );
    }
}
