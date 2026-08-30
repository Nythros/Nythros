<?php

declare(strict_types=1);

namespace Nythros\Framework\Social;

/**
 * 位置快照与掉线标记存储契约。
 * Location snapshot and offline-marker store contract.
 */
interface LocationStoreInterface
{
    /**
     * 写掉线标记（SETEX 300s）。
     * Write the offline marker (SETEX 300s).
     */
    public function markOffline(string $uid): void;

    /**
     * 掉线判定（EXISTS offline:{uid}）。
     * Offline verdict (EXISTS offline:{uid}).
     */
    public function isOffline(string $uid): bool;

    /**
     * 写位置快照（SETEX 300s JSON，覆盖写）。
     * Write the location snapshot (SETEX 300s JSON, overwrite).
     */
    public function saveLocation(string $uid, string $mapId, string $channelId, ?float $x = null, ?float $y = null): void;

    /**
     * 读位置快照。
     * Read the location snapshot.
     *
     * @return ?array{mapId: string, channelId: string, x: ?float, y: ?float, updatedAt: float} 快照数据；不可见时 null Snapshot; null when unavailable.
     */
    public function getLocation(string $uid): ?array;

    /**
     * 清除掉线标记（DEL offline:{uid}）。
     * Clear the offline marker (DEL offline:{uid}).
     */
    public function clearOffline(string $uid): void;
}
