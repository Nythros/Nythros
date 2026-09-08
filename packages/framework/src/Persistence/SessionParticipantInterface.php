<?php

declare(strict_types=1);

namespace Nythros\Framework\Persistence;

/**
 * 会话状态参与者契约（统一会话生命周期钩子）：持「玩家在线期间的进程内会话态」的能力块
 * （任务进度缓存、宠物栏、成就位图、好友在线表…）实现本接口，即可挂进玩家会话的
 * open/close 生命周期——MapServer attach/detach 统一驱动,新能力块接入不再往主循环插调用。
 * Session-state participant contract (the unified session lifecycle hook): capability blocks holding in-process
 * per-player session state (the quest-progress cache, pet bar, achievement bitmap, friends presence table…)
 * implement this interface to join the player session's open/close lifecycle — MapServer drives it uniformly at
 * attach/detach, so new capability blocks never splice calls into the game loop.
 *
 * 纪律（与写回缓冲配套）：
 * - onSessionOpen 在 attach 读路径上执行（预热属每连接同步点,允许一次批量后端读）；
 *   onSessionOpen runs on the attach read path (preheating is a per-connection sync point where one batched
 *   backend read is allowed);
 * - onSessionClose 在 detach 清理链执行（先 flush 后释放,失败留脏不丢——参照 CachedQuestStore::evict）；
 * - onSessionClose runs in the detach cleanup chain: flush then release; failures stay dirty, never lost;
 * - 两个钩子都必须幂等（同 uid 重连/换频道会重复 open,close 后可能再来 close）。
 * - both hooks must be idempotent (reconnects/channel hops re-open; a late second close may arrive).
 *
 * 注册方式：MapServer::attachGameplay 自动登记实现了本接口的 QuestService；其余能力块用
 * MapServer::addSessionParticipant 显式注册（装配层一行）。
 * Registration: MapServer::attachGameplay auto-registers a QuestService implementing this interface; other
 * blocks register explicitly via MapServer::addSessionParticipant (one assembly line each).
 */
interface SessionParticipantInterface
{
    /**
     * 会话开启（attach 完成后调用）：预热该 uid 的会话态。幂等。
     * Session open (after attach): preloads the uid's session state. Idempotent.
     */
    public function onSessionOpen(string $uid): void;

    /**
     * 会话结束（detach 清理链调用）：回写未冲刷的脏数据并释放内存。幂等。
     * Session close (detach cleanup chain): writes back unflushed state and releases memory. Idempotent.
     */
    public function onSessionClose(string $uid): void;
}
