<?php

declare(strict_types=1);

namespace Nythros\Framework\Game\Horde;

/**
 * 出生保护配置（R4 horde 类型模块试点）：auth 挂载后的无敌窗口帧数（50ms 基准 tick 折算）。
 * Spawn-protection config (the R4 horde type-module pilot): the invulnerable window in frames after auth mount (converted on the 50ms base tick).
 */
final class SpawnProtectionConfig
{
    public function __construct(
        public readonly int $frames = 60,
    ) {
    }
}
