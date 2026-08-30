<?php

declare(strict_types=1);

namespace Nythros\Actor;

use Nythros\Contracts\ActorInterface;
use Nythros\Contracts\EntityInterface;

/**
 * Actor 基类：管理 Actor 生命周期的基础状态——绑定的实体与逐帧更新入口。
 * Base actor class: manages the fundamental lifecycle state of an actor — its bound entity and the per-frame update entry point.
 */
abstract class BaseActor implements ActorInterface
{
    /** @var ?EntityInterface Actor 当前绑定的实体；未绑定时为 null The entity currently bound to the actor; null when unbound. */
    protected ?EntityInterface $entity = null;

    /**
     * 将 Actor 绑定到实体；重复绑定会覆盖之前的实体。
     * Binds the actor to an entity; binding again overwrites the previous entity.
     *
     * @param EntityInterface $entity 要绑定的实体 The entity to bind.
     */
    public function bindEntity(EntityInterface $entity): void
    {
        $this->entity = $entity;
    }

    /**
     * Actor 每帧逻辑入口：由 ActorSystem 逐帧调用，子类实现具体行为。
     * The actor's per-frame logic entry point: invoked every frame by the actor system; subclasses implement the concrete behavior.
     */
    abstract public function update(): void;
}
