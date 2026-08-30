<?php

declare(strict_types=1);

/**
 * P11 玩法数据外置 · skills 表：技能声明数据化（增删一行即生效——启动装配 + 热载重放双路径）。
 * feature 标注的行仅在该特性启用（对应 NYTHROS_* env = 1）时装配；未标注的行恒生效。
 * P11 skills table: skill declarations as data (adding/removing a row takes effect — both the startup assembly
 * and the hot-reload replay paths). Feature-tagged rows assemble only when that feature is enabled (the matching
 * NYTHROS_* env = 1); untagged rows always apply.
 */
return [
    // 基础战斗技能（恒生效）
    // Base combat skills (always applied)
    ['id' => 'fireball', 'name' => '火球术', 'damageMultiplier' => 1.5, 'cooldownSeconds' => 2.0, 'range' => 3, 'aoe' => ['shape' => 'circle', 'radius' => 70], 'mpCost' => 10],
    ['id' => 'ice_bolt', 'name' => '冰锥术', 'damageMultiplier' => 1.2, 'cooldownSeconds' => 1.5, 'range' => 3],
    // 嘲讽系（仅 NYTHROS_MMORPG=1）：tauntThreat 写入怪物威胁表
    // Taunt family (NYTHROS_MMORPG=1 only): tauntThreat lands in the monster threat table
    ['id' => 'taunt', 'name' => '嘲讽', 'damageMultiplier' => 0.6, 'cooldownSeconds' => 6.0, 'range' => 3, 'tauntThreat' => 1000.0, 'feature' => 'mmorpg'],
    ['id' => 'taunt_aoe', 'name' => '嘲讽风暴', 'damageMultiplier' => 0.3, 'cooldownSeconds' => 8.0, 'range' => 10, 'aoe' => ['shape' => 'circle', 'radius' => 10], 'tauntThreat' => 1000.0, 'feature' => 'mmorpg'],
    ['id' => 'slash_rect', 'name' => '矩形斩击', 'damageMultiplier' => 0.8, 'cooldownSeconds' => 5.0, 'range' => 6, 'aoe' => ['shape' => 'rect', 'width' => 6, 'height' => 4], 'feature' => 'mmorpg'],
];
