<?php

declare(strict_types=1);

/**
 * P11 玩法数据外置 · gameplay 表：出生/复活点 + 初始怪物表。
 * 经 ConfigRepository 装载（NYTHROS_CONFIG_DIR 指向本目录时生效），GameplayTables::schemas()['gameplay'] 校验；
 * 坏表启动即拒（错误带行号），热载改坏走回滚（旧配置保留）。
 * 缺省值与外置前 MapChannelFactory 硬编码逐字段一致（零破坏口径）；文件缺席时由 GameplayTables::defaultTable 兜底。
 * P11 gameplay table: the spawn/revive point plus the initial monster table. Loaded through ConfigRepository
 * (active when NYTHROS_CONFIG_DIR points at this directory) and validated by GameplayTables::schemas()['gameplay'];
 * a rejected table fails startup fast (errors carry line numbers) and a bad hot-reload rolls back (old config kept).
 * Defaults are field-for-field identical to the pre-externalization MapChannelFactory hardcoding (zero breakage);
 * GameplayTables::defaultTable backstops when the file is absent.
 */
return [
    // 出生/复活点：mmorpg 安全区（NYTHROS_MMORPG_SAFE_ZONE）圆心须与其同源（attachMmorpg fail-fast 校验）
    // Spawn/revive point: an mmorpg safe zone's center (NYTHROS_MMORPG_SAFE_ZONE) must align (fail-fast in attachMmorpg)
    'spawnPoint' => ['x' => 0, 'y' => 0],
    // 玩家初始血量基线（P18 数据外置）：auth 挂载时 initVitals 一次性注入
    // The player's initial vitals baseline (the P18 externalization): injected once via initVitals at auth mount.
    'player' => ['maxHp' => 100],
    // 初始怪物：onWorkerStart 逐行 spawn；respawnMs 缺省 null = MmorpgConfig.respawnMs 全局值
    // Initial monsters: row-by-row spawn in onWorkerStart; respawnMs defaults to null = the global MmorpgConfig.respawnMs
    'monsters' => [
        ['id' => 'monster-1', 'typeId' => 'slime', 'maxHp' => 100, 'anchor' => ['x' => 15, 'y' => 15], 'patrolRadius' => 4],
        ['id' => 'monster-2', 'typeId' => 'wolf', 'maxHp' => 150, 'anchor' => ['x' => -6, 'y' => -6], 'patrolRadius' => 4],
    ],
];
