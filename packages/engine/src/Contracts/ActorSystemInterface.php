<?php

declare(strict_types=1);

namespace Nythros\Contracts;

/**
 * Actor 系统契约：管理 Actor 集合，并逐帧驱动全部已注册 Actor 更新。
 * Actor system contract: manages a collection of actors and drives per-frame updates for all registered actors.
 */
interface ActorSystemInterface
{
    /**
     * 注册 Actor；重复添加同一实例的行为由实现约定（通常忽略或替换）。
     * Register an actor; adding the same instance twice is implementation-defined (usually ignored or replaced).
     *
     * @param ActorInterface $actor 目标 Actor The target actor.
     */
    public function add(ActorInterface $actor): void;

    /**
     * 注销 Actor；未注册的实例应被静默忽略。
     * Unregister an actor; instances that are not registered should be silently ignored.
     *
     * @param ActorInterface $actor 目标 Actor The target actor.
     */
    public function remove(ActorInterface $actor): void;

    /**
     * 更新所有已注册 Actor 一帧；遍历顺序与异常处理由实现约定。
     * Update all registered actors for one frame; iteration order and exception handling are implementation-defined.
     */
    public function updateAll(): void;
}
