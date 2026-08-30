<?php

declare(strict_types=1);

namespace Nythros\Framework\Gm;

/**
 * drain 命令的能力契约（P16 动态扩缩容）：由服务实现（MapServer）——标记 draining 后
 * 目录服务（gateway selectChannel）不再把新会话路由到本实例，存量连接不受影响；
 * 在场玩家归零后即可停机摘除（扩缩容的 scale-in 语义）。
 * The drain command's capability contract (the P16 dynamic scaling): implemented by the service (MapServer) —
 * once marked draining, the directory (gateway selectChannel) stops routing new sessions to this instance while
 * existing connections are unaffected; once the player count reaches zero the worker can be stopped and removed
 * (the scale-in semantics).
 */
interface GmDrainHandlerInterface
{
    /**
     * 进入 draining：注册心跳 meta 置 status=draining + 本地守卫激活（新 auth 拒绝）。
     * Enters draining: the registry heartbeat meta flips status=draining and the local guard activates (new auths rejected).
     *
     * @return bool 是否实际生效（重复 drain 幂等返回 false；未接集群时 false） Whether it took effect (idempotent repeats return false; false without a cluster).
     */
    public function drain(): bool;

    /**
     * 是否处于 draining（观测口）。
     * Whether draining (an observation port).
     */
    public function isDraining(): bool;
}
