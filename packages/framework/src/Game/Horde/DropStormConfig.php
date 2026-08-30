<?php

declare(strict_types=1);

namespace Nythros\Framework\Game\Horde;

/**
 * 掉落风暴配置（R4 horde 类型模块试点，ADR-024 §D-D）：一波死亡的掉落寿命与攒批口径参数。
 * Drop-storm config (the R4 horde type-module pilot, ADR-024 §D-D): drop lifetime and batching parameters for a death wave.
 */
final class DropStormConfig
{
    /**
     * @param int $dropLifetimeSeconds 掉落物存活秒数（过期定时回收；0 = 永不过期） Drop lifetime in seconds (periodic expiry reclamation; 0 = never expires).
     */
    public function __construct(
        public readonly int $dropLifetimeSeconds = 300,
    ) {
    }
}
