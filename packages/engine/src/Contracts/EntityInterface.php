<?php

declare(strict_types=1);

namespace Nythros\Contracts;

/**
 * 实体契约：世界中可定位的对象，具备唯一 id、整数坐标与相对移动能力。
 * Entity contract: a locatable object in the world with a unique id, integer coordinates and the ability to move relative to its position.
 */
interface EntityInterface
{
    /**
     * 获取实体唯一标识。
     * Get the entity's unique identifier.
     *
     * @return string 实体 id The entity id.
     */
    public function getId(): string;

    /**
     * 获取实体当前坐标。
     * Get the entity's current coordinates.
     *
     * @return array{x: int, y: int} 坐标（x/y 均为整数） Entity coordinates (both x and y are integers).
     */
    public function getPosition(): array;

    /**
     * 相对移动实体：dx/dy 为相对当前位置的增量，而非绝对坐标。
     * Move the entity relative to its current position: dx/dy are deltas, not absolute coordinates.
     *
     * 实现约定：任何位置变更（含传送/强制位置变更）都必须经过本方法同一路径，并置位「本帧已移动」标志，
     * 供 AOI moved-dirty 增量刷新感知。
     * Implementation note: every position change (including teleports / forced repositioning) must go through
     * this single path and set the "moved this frame" flag, which drives the AOI moved-dirty incremental refresh.
     *
     * @param int $dx 水平增量 Horizontal delta.
     * @param int $dy 垂直增量 Vertical delta.
     */
    public function move(int $dx, int $dy): void;

    /**
     * 绝对重定位至 (x,y)：传送/房间进出等跨绝对坐标场景的唯一绝对定位入口。
     * Absolutely repositions to (x, y): the single absolute-positioning entry point for teleports,
     * room enter/leave and other cross-absolute-coordinate scenarios.
     *
     * 实现约定：与 move() 同路径置位「本帧已移动」标志（坐标未变亦置位），供 AOI moved-dirty 增量刷新感知。
     * Implementation note: implementations must set the "moved this frame" flag through the same path as
     * move() (unchanged coordinates included), driving the AOI moved-dirty incremental refresh.
     *
     * @param int $x 目标 X 轴坐标 Target X-axis coordinate.
     * @param int $y 目标 Y 轴坐标 Target Y-axis coordinate.
     */
    public function setPosition(int $x, int $y): void;

    /**
     * 置位「本帧已移动」标志（实体首次加入世界、传送等外部强制标记入口）。
     * Sets the "moved this frame" flag (external force-mark entry for first registration into a world, teleports, etc.).
     */
    public function markMoved(): void;

    /**
     * 读取并清除「本帧已移动」标志：置位返回 true 并复位，未置位返回 false。
     * Reads and clears the "moved this frame" flag: returns true and resets when set, false otherwise.
     */
    public function consumeMoved(): bool;
}
