<?php

declare(strict_types=1);

namespace Nythros\Demo\Gameplay;

use Nythros\Demo\GameplayTables;

/**
 * 地图玩法配置（P11 玩法数据外置）：出生/复活点 + 初始怪物表，从 MapChannelFactory 硬编码抽到
 * gameplay 配置表的消费形态。字段合法性由 GameplayTables::schemas() 保证，本类只做行 → 值对象装配。
 * The map gameplay config (the P11 data externalization): the spawn/revive point plus the initial monster table,
 * the consumption shape of what used to be MapChannelFactory's hardcoded data moved into the gameplay config table.
 * Field legality is guaranteed by GameplayTables::schemas(); this class only assembles rows into value objects.
 *
 * @internal starter-kit 装配层值对象（demo 包不对外承诺 API） starter-kit assembly value object (no API promise outside the demo package).
 */
final readonly class GameplayConfig
{
    /**
     * @param array{x: int, y: int} $spawnPoint 出生/复活点（安全区圆心须与其同源，MapServer::attachMmorpg 校验）
     *   The spawn/revive point (a safe zone's center must align; validated in MapServer::attachMmorpg).
     * @param list<MonsterSpawn> $monsters 初始怪物表（onWorkerStart 内逐行 spawn） The initial monster table (row-by-row spawn inside onWorkerStart).
     */
    public function __construct(
        public array $spawnPoint,
        public array $monsters,
        public int $playerMaxHp = 100,
    ) {
    }

    /**
     * 从（已通过 schema 校验的）gameplay 表装配。
     * Assembles from a (schema-validated) gameplay table.
     *
     * @param array<string, mixed> $table gameplay 表 The gameplay table.
     */
    public static function fromTable(array $table): self
    {
        $monsters = [];
        foreach ($table['monsters'] ?? [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $monsters[] = MonsterSpawn::fromRow($row);
        }

        $spawnPoint = $table['spawnPoint'] ?? [];
        $player = is_array($table['player'] ?? null) ? $table['player'] : [];

        return new self(
            ['x' => (int) ($spawnPoint['x'] ?? 0), 'y' => (int) ($spawnPoint['y'] ?? 0)],
            $monsters,
            (int) ($player['maxHp'] ?? 100),
        );
    }

    /**
     * 现状缺省（零破坏口径）：与外置前 MapChannelFactory 硬编码值逐字段一致——出生点 (0,0)，
     * monster-1 slime 100 血锚 (15,15) 巡逻 4，monster-2 wolf 150 血锚 (-6,-6) 巡逻 4。
     * The status-quo default (the zero-breakage baseline): field-for-field identical to MapChannelFactory's
     * pre-externalization hardcoded values — spawn (0,0); monster-1 slime 100 hp anchored (15,15) patrolling 4;
     * monster-2 wolf 150 hp anchored (-6,-6) patrolling 4.
     */
    public static function defaults(): self
    {
        return self::fromTable(GameplayTables::defaultTable('gameplay'));
    }

    /**
     * 以显式初始血量派生（auth 挂载与热载应用共用）。
     * Derives with an explicit initial vitals baseline (shared by the auth mount and the hot-reload apply).
     */
    public function withPlayerMaxHp(int $playerMaxHp): self
    {
        return new self($this->spawnPoint, $this->monsters, $playerMaxHp);
    }

    /**
     * 怪物表按 id 索引（热载 diff 用）。
     * The monster table indexed by id (for hot-reload diffs).
     *
     * @return array<string, MonsterSpawn>
     */
    public function monstersById(): array
    {
        $indexed = [];
        foreach ($this->monsters as $monster) {
            $indexed[$monster->id] = $monster;
        }

        return $indexed;
    }
}
