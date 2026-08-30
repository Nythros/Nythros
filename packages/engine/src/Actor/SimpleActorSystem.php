<?php

declare(strict_types=1);

namespace Nythros\Actor;

use Nythros\Contracts\ActorInterface;
use Nythros\Contracts\ActorSystemInterface;

/**
 * 简单 Actor 系统：以数组维护 Actor 集合，逐帧驱动全部已注册 Actor 更新。
 * Simple actor system: keeps the actor collection in an array and drives per-frame updates for all registered actors.
 * @internal 引擎内部实现，非公开 API。Engine-internal implementation, not part of the public API.
 */
final class SimpleActorSystem implements ActorSystemInterface
{
    /** @var list<ActorInterface> 已注册的 Actor 集合（按添加顺序） Collection of registered actors (in insertion order). */
    private array $actors = [];

    /**
     * 追加注册一个 Actor；重复添加同一实例会被驱动更新两次。
     * Appends an actor; adding the same instance twice will drive its update twice.
     *
     * @param ActorInterface $actor 要注册的 Actor The actor to register.
     */
    public function add(ActorInterface $actor): void
    {
        $this->actors[] = $actor;
    }

    /**
     * 注销一个 Actor；从未注册过的实例会被静默忽略。
     * Unregisters an actor; instances that were never registered are silently ignored.
     *
     * @param ActorInterface $actor 要注销的 Actor The actor to unregister.
     */
    public function remove(ActorInterface $actor): void
    {
        // 过滤掉目标实例后重建连续索引，避免留下 null 空洞 filter out the target instance and re-index the array to avoid gaps
        $this->actors = array_values(array_filter(
            $this->actors,
            static fn (ActorInterface $existing): bool => $existing !== $actor,
        ));
    }

    /**
     * 驱动全部已注册 Actor 执行一帧更新，按注册顺序遍历。
     * Drives one frame of updates for all registered actors, iterating in registration order.
     */
    public function updateAll(): void
    {
        foreach ($this->actors as $actor) {
            $actor->update();
        }
    }
}
