<?php

declare(strict_types=1);

namespace Nythros\Contracts;

/**
 * 世界门面契约：聚合实体管理、Actor 系统、AOI、事件总线与调度，驱动单帧世界更新。
 * World facade contract: aggregates entity management, actor system, AOI, event bus and scheduling to drive one frame of world update.
 */
interface WorldInterface
{
    /**
     * 推进一帧世界状态（actor 更新 + AOI 同步 + 调度）。
     * Advance one frame of world state (actor update + AOI sync + scheduling).
     */
    public function update(): void;

    /**
     * 获取实体管理器。
     * Get the entity manager.
     *
     * @return EntityManagerInterface 实体管理器 The entity manager instance.
     */
    public function getEntityManager(): EntityManagerInterface;

    /**
     * 获取 Actor 系统。
     * Get the actor system.
     *
     * @return ActorSystemInterface Actor 系统 The actor system instance.
     */
    public function getActorSystem(): ActorSystemInterface;

    /**
     * 获取 AOI 兴趣区域提供者：GridAOI（九宫格视野）或 UniversalAOI（全量广播 = 全世界即视野，无空间索引），恒非空。
     * Get the AOI (Area of Interest) provider: GridAOI (3x3 view) or UniversalAOI (full broadcast — no spatial
     * index; the whole world is the view). Never null.
     *
     * @return AOIProviderInterface AOI 提供者，恒非空（全量型 World 注入 UniversalAOI） The AOI provider; never
     *         null (full-broadcast Worlds inject a UniversalAOI).
     */
    public function getAOI(): AOIProviderInterface;

    /**
     * 获取事件总线。
     * Get the event bus.
     *
     * @return EventBusInterface 事件总线 The event bus instance.
     */
    public function getEventBus(): EventBusInterface;

    /**
     * 获取本世界的类型（AOI / 全量广播）。
     * Get the World's type (AOI / full broadcast).
     */
    public function getType(): WorldType;

    /**
     * 获取帧调度器。
     * Get the frame scheduler.
     *
     * @return SchedulerInterface 帧调度器 The frame scheduler instance.
     */
    public function getScheduler(): SchedulerInterface;
}
