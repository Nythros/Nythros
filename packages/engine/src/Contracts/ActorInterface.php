<?php

declare(strict_types=1);

namespace Nythros\Contracts;

/**
 * Actor 契约：主动行为单元，由 Actor 系统每帧驱动一次更新。
 * Actor contract: an active behavior unit driven by the actor system once per frame.
 */
interface ActorInterface
{
    /**
     * 执行一帧 Actor 逻辑；由 Actor 系统在每帧对已注册 Actor 调用一次。
     * Run one frame of actor logic; invoked once per frame by the actor system for each registered actor.
     */
    public function update(): void;
}
