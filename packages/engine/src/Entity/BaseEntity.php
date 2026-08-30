<?php

declare(strict_types=1);

namespace Nythros\Entity;

use Nythros\Contracts\EntityInterface;

/**
 * 实体基类：持有不可变 id 与可变位置，实现 EntityInterface 的查询与移动语义。
 * Base entity class: holds an immutable id and a mutable position, implementing the query and movement semantics of EntityInterface.
 * @internal 引擎内部实现，非公开 API。Engine-internal implementation, not part of the public API.
 */
final class BaseEntity implements EntityInterface
{
    /** 「本帧已移动」标志：move 置位、consumeMoved 取走清除，供 AOI moved-dirty 增量刷新感知。"Moved this frame" flag: set by move, taken and cleared by consumeMoved; drives the AOI moved-dirty incremental refresh. */
    private bool $moved = false;

    /**
     * 构造实体。
     * Creates an entity.
     *
     * @param string $id 实体唯一 id（不可变） Unique entity id (immutable).
     * @param Position $position 初始位置 Initial position.
     */
    public function __construct(
        private readonly string $id,
        private Position $position,
    ) {
    }

    /**
     * 获取实体 id。
     * Gets the entity id.
     *
     * @return string 实体唯一 id The unique entity id.
     */
    public function getId(): string
    {
        return $this->id;
    }

    /**
     * 获取当前位置；以数组形式返回，便于跨包传输与 AOI 格子索引计算。
     * Gets the current position; returned as an array for easy cross-package transport and AOI cell indexing.
     *
     * @return array{x: int, y: int} 坐标数组 Coordinate array.
     */
    public function getPosition(): array
    {
        return ['x' => $this->position->x, 'y' => $this->position->y];
    }

    /**
     * 按增量移动实体；新位置由 Position::move 返回的新实例接管。
     * 位置变更唯一入口（传送/强制位置变更同样走这里），移动即置位 moved 标志。
     * Moves the entity by the given deltas; the new position is taken over from the instance returned by Position::move.
     * The single entry point for position changes (teleports / forced repositioning included); moving sets the moved flag.
     *
     * @param int $dx X 轴增量 X-axis delta.
     * @param int $dy Y 轴增量 Y-axis delta.
     */
    public function move(int $dx, int $dy): void
    {
        $this->position = $this->position->move($dx, $dy);
        $this->moved = true;
    }

    /**
     * 绝对重定位至 (x,y)：以新 Position 实例接管，与 move() 同路径置位 moved 标志（坐标未变亦置位）。
     * Absolutely repositions to (x, y): takes over from a new Position instance and sets the moved flag
     * through the same path as move() (unchanged coordinates still set it).
     *
     * @param int $x 目标 X 轴坐标 Target X-axis coordinate.
     * @param int $y 目标 Y 轴坐标 Target Y-axis coordinate.
     */
    public function setPosition(int $x, int $y): void
    {
        $this->position = new Position($x, $y);
        $this->moved = true;
    }

    /**
     * 置位「本帧已移动」标志（外部强制标记入口，如实体管理器登记）。
     * Sets the "moved this frame" flag (external force-mark entry, e.g. entity-manager registration).
     */
    public function markMoved(): void
    {
        $this->moved = true;
    }

    /**
     * 读取并清除「本帧已移动」标志：置位返回 true 并复位，未置位返回 false。
     * Reads and clears the "moved this frame" flag: returns true and resets when set, false otherwise.
     */
    public function consumeMoved(): bool
    {
        $moved = $this->moved;
        $this->moved = false;

        return $moved;
    }
}
