<?php

declare(strict_types=1);

namespace Nythros\Framework\Tests;

use Nythros\Contracts\EntityInterface;
use Nythros\Framework\Combat\DropEntity;
use PHPUnit\Framework\TestCase;

/**
 * DropEntityTest - 覆盖掉落物实体的身份/坐标/移动与物品数据暴露。
 * Tests covering the drop entity's identity, coordinates, movement and item-data exposure.
 */
final class DropEntityTest extends TestCase
{
    public function testConstructExposesIdItemAndCount(): void
    {
        $drop = new DropEntity('drop-1', 3, -2, 'gold', 5);

        self::assertSame('drop-1', $drop->getId());
        self::assertSame('gold', $drop->itemId);
        self::assertSame(5, $drop->count);
    }

    public function testGetPositionReturnsXYArray(): void
    {
        $drop = new DropEntity('drop-1', 3, -2, 'gold', 1);

        self::assertSame(['x' => 3, 'y' => -2], $drop->getPosition());
    }

    public function testMoveTranslatesCoordinates(): void
    {
        $drop = new DropEntity('drop-1', 3, -2, 'gold', 1);

        $drop->move(2, -1);

        self::assertSame(['x' => 5, 'y' => -3], $drop->getPosition());
    }

    public function testImplementsEntityInterface(): void
    {
        $drop = new DropEntity('drop-1', 0, 0, 'gold', 1);

        self::assertInstanceOf(EntityInterface::class, $drop);
    }

    public function testSetPositionRepositionsAbsolutely(): void
    {
        $drop = new DropEntity('drop-1', 3, -2, 'gold', 1);

        $drop->setPosition(-9, 4);

        self::assertSame(['x' => -9, 'y' => 4], $drop->getPosition());
    }

    public function testSetPositionMarksMoved(): void
    {
        $drop = new DropEntity('drop-1', 3, -2, 'gold', 1);

        $drop->setPosition(3, -2);

        // 与 move() 同路径：绝对重定位必须置位 moved（坐标未变亦然） same path as move(): absolute repositioning must set moved (even with unchanged coordinates)
        self::assertTrue($drop->consumeMoved());
        self::assertFalse($drop->consumeMoved());
    }

    public function testOwnershipDefaultsToUnowned(): void
    {
        $drop = new DropEntity('drop-1', 0, 0, 'gold', 1);

        self::assertNull($drop->ownerUid, '缺省无归属（自由拾取）。Unowned by default (free pickup).');
        self::assertNull($drop->ownerTeamId);
        self::assertNull($drop->expiresAt, '缺省永不过期。Never expires by default.');
    }

    public function testKillOwnershipBindingIsExposed(): void
    {
        $drop = new DropEntity('drop-1', 0, 0, 'gold', 1, '1001', 'team-7');

        self::assertSame('1001', $drop->ownerUid);
        self::assertSame('team-7', $drop->ownerTeamId);
    }

    public function testIsExpiredJudgesAgainstTheInjectedNow(): void
    {
        $expiresAt = 1000.0;
        $drop = new DropEntity('drop-1', 0, 0, 'gold', 1, null, null, $expiresAt);

        self::assertFalse($drop->isExpired(999.9), '未到过期时刻。Before the expiry instant.');
        self::assertTrue($drop->isExpired(1000.0), '恰在过期时刻即过期。Expired exactly at the instant.');
        self::assertTrue($drop->isExpired(1001.0));
    }

    public function testNeverExpiringDropsAreNeverExpired(): void
    {
        $drop = new DropEntity('drop-1', 0, 0, 'gold', 1);

        self::assertFalse($drop->isExpired(PHP_FLOAT_MAX));
    }
}
