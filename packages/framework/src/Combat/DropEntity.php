<?php

declare(strict_types=1);

namespace Nythros\Framework\Combat;

use Nythros\Contracts\EntityInterface;

/**
 * 掉落物实体：实现 EntityInterface，携带 itemId/count 与自持整数坐标。
 * 击杀归属绑定（R3 经济批模块 2）：ownerUid/ownerTeamId 记录击杀者身份，拾取校验归属
 * （本人或同队可拾，非归属者拒绝）；ownerUid 为 null 表示无归属（自由拾取）。
 * 过期回收：expiresAt 为过期时刻（microtime 秒），null = 永不过期；isExpired 供定时回收扫描判定。
 * Drop entity: implements EntityInterface, carrying itemId/count with its own integer coordinates.
 * Kill-ownership binding (economy-batch module 2): ownerUid/ownerTeamId record the killer's identity and pickup
 * validates ownership (the owner or a same-team member may pick; others are rejected); a null ownerUid means
 * unowned (free pickup). Expiry: expiresAt is the expiry instant (microtime seconds), null = never expires;
 * isExpired drives the periodic reclamation sweep.
 */
final class DropEntity implements EntityInterface
{
    /** 「本帧已移动」标志：move 置位、consumeMoved 取走清除，供 AOI moved-dirty 增量刷新感知。"Moved this frame" flag: set by move, taken and cleared by consumeMoved; drives the AOI moved-dirty incremental refresh. */
    private bool $moved = false;

    /**
     * @param string $id 掉落物唯一 id Unique drop id.
     * @param int $x X 坐标 X coordinate.
     * @param int $y Y 坐标 Y coordinate.
     * @param string $itemId 物品 id Item id.
     * @param int $count 数量 Quantity.
     * @param ?string $ownerUid 击杀者 uid（归属绑定；null = 无归属自由拾取） The killer's uid (ownership binding; null = unowned free pickup).
     * @param ?string $ownerTeamId 击杀者所属队伍 id（同队共享拾取权） The killer's team id (same-team members share pickup rights).
     * @param ?float $expiresAt 过期时刻（microtime 秒；null = 永不过期） The expiry instant (microtime seconds; null = never expires).
     */
    public function __construct(
        private readonly string $id,
        private int $x,
        private int $y,
        public readonly string $itemId,
        public readonly int $count,
        public readonly ?string $ownerUid = null,
        public readonly ?string $ownerTeamId = null,
        public readonly ?float $expiresAt = null,
    ) {
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getPosition(): array
    {
        return ['x' => $this->x, 'y' => $this->y];
    }

    public function move(int $dx, int $dy): void
    {
        $this->x += $dx;
        $this->y += $dy;
        $this->moved = true;
    }

    /**
     * 绝对重定位至 (x,y)：与 move() 同路径置位 moved 标志（坐标未变亦置位）。
     * Absolutely repositions to (x, y): sets the moved flag through the same path as move()
     * (unchanged coordinates still set it).
     */
    public function setPosition(int $x, int $y): void
    {
        $this->x = $x;
        $this->y = $y;
        $this->moved = true;
    }

    public function markMoved(): void
    {
        $this->moved = true;
    }

    public function consumeMoved(): bool
    {
        $moved = $this->moved;
        $this->moved = false;

        return $moved;
    }

    /**
     * 是否已过期（供定时回收扫描判定）；永不过期型恒 false。
     * Whether the drop has expired (for the periodic reclamation sweep); never-expiring drops are always false.
     */
    public function isExpired(float $now): bool
    {
        return $this->expiresAt !== null && $now >= $this->expiresAt;
    }
}
