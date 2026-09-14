<?php

declare(strict_types=1);

namespace Nythros\Testing;

use Nythros\Framework\Social\LocationStoreInterface;

/**
 * FakeLocationStore - 按配置表返回 isOffline/getLocation；记录写调用。
 * FakeLocationStore - isOffline/getLocation return from configuration tables; write calls are recorded.
 */
final class FakeLocationStore implements LocationStoreInterface
{
    /** @var list<string> markOffline 调用记录 markOffline call records. */
    public array $markOfflines = [];

    /** @var list<string> clearOffline 调用记录 clearOffline call records. */
    public array $clearOfflines = [];

    /** @var list<array{uid: string, mapId: string, channelId: string, x: ?float, y: ?float}> saveLocation 调用记录 saveLocation call records. */
    public array $saves = [];

    /** @var array<string, bool> uid => isOffline 配置表 isOffline configuration per uid. */
    public array $offline = [];

    /** @var array<string, array{mapId: string, channelId: string, x: ?float, y: ?float, updatedAt: float}> uid => 位置配置表 location configuration per uid. */
    public array $locations = [];

    public function markOffline(string $uid): void
    {
        $this->markOfflines[] = $uid;
    }

    public function isOffline(string $uid): bool
    {
        return $this->offline[$uid] ?? false;
    }

    public function saveLocation(string $uid, string $mapId, string $channelId, ?float $x = null, ?float $y = null): void
    {
        $this->saves[] = ['uid' => $uid, 'mapId' => $mapId, 'channelId' => $channelId, 'x' => $x, 'y' => $y];
    }

    public function getLocation(string $uid): ?array
    {
        return $this->locations[$uid] ?? null;
    }

    public function clearOffline(string $uid): void
    {
        $this->clearOfflines[] = $uid;
    }
}
