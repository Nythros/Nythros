<?php

declare(strict_types=1);

/**
 * P11 玩法数据外置 · drops 表：掉落条目数据化（每条目独立 roll，命中后数量在 [minCount, maxCount] 内独立 roll）。
 * itemId 必须已在物品表注册（引用完整性 fail-fast）；feature 标注行仅对应特性启用时装配。
 * P11 drops table: drop entries as data (each entry rolls independently; a hit rolls its count inside
 * [minCount, maxCount]). itemIds must be registered in the item table (referential-integrity fail-fast);
 * feature-tagged rows assemble only when that feature is enabled.
 */
return [
    // 每条目的不掉落权重段（0 = 声明的条目权重即全部命中段）
    // The per-entry no-drop weight segment (0 = a declared entry's weight is all hit segment)
    'noDropWeight' => 0,
    'entries' => [
        ['itemId' => 'bone', 'weight' => 3],
        ['itemId' => 'potion', 'weight' => 1],
        // 铁剑（仅 NYTHROS_ECONOMY=1）：与经济批物品注册条件联动
        // The iron sword (NYTHROS_ECONOMY=1 only): tied to the economy batch's item-registration condition
        ['itemId' => 'sword', 'weight' => 1, 'feature' => 'economy'],
    ],
];
