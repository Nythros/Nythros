<?php

declare(strict_types=1);

// 服务拓扑唯一事实源（入门套件版）：PHP 数组配置，零解析器依赖，经 framework Config 读取。
// The single source of truth for the service topology (starter-kit edition): a PHP array config,
// zero parser deps, read through the framework's Config.
// 完整 YAML 版本（多字段/校验/错误归因）见 demo 的 DeployConfig（ADR-013 决策 C：配置驱动部署）。

return [
    'servers' => [
        // 主城：AOI 局域广播（九宫格视野，广播只发给视野内连接） Main town: AOI-local broadcast (3x3 view; broadcasts reach only connections inside the view)
        [
            'mapId' => 'main',
            'channelId' => 'ch-1',
            'port' => 18081,
            'worldType' => 'aoi',
            'npc' => [
                ['id' => 'npc-guide', 'typeId' => 'guide', 'x' => 5, 'y' => 5],
                ['id' => 'npc-dog', 'typeId' => 'dog', 'x' => -5, 'y' => 0],
            ],
        ],
        // 副本：全量广播（无空间索引，世界内所有实体对所有连接可见） Dungeon: full broadcast (no spatial index; every entity in the world is visible to every connection)
        [
            'mapId' => 'dungeon-A',
            'channelId' => 'pool-1',
            'port' => 18082,
            'worldType' => 'full',
            'npc' => [
                ['id' => 'boss-golem', 'typeId' => 'golem', 'x' => 0, 'y' => 0],
            ],
        ],
    ],
];
