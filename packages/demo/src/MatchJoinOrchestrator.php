<?php

declare(strict_types=1);

namespace Nythros\Demo;

use Nythros\Framework\Matching\MatchJoinHandlerInterface;

/**
 * 匹配入房编排委托（starter-kit 侧 MatchJoinHandlerInterface 实现，R3 玩法批组装边界落点）：
 * 把 MatchingService 撮合成功的候选者经 RoomHub::admitPlayer 走 transfer 全链编排
 * （世界摘除/entity_leave 广播/原子入房/容器标记）——这些宿主能力全部留在 starter-kit（唯一组装点铁律），
 * framework 的 MatchingService 只依赖本契约。
 * The match-join orchestration delegate (starter-kit's MatchJoinHandlerInterface implementation, the R3 gameplay
 * batch's assembly-boundary landing): candidates that matched successfully walk RoomHub::admitPlayer's full transfer
 * chain (world removal / entity_leave broadcast / atomic admission / container marking) — these host capabilities all
 * stay in starter-kit (the single-assembly-point rule); the framework-side MatchingService depends on this contract only.
 *
 * 失败语义：admitPlayer 抛出的状态机/参数异常在此转为 false（撮合侧把该候选者重新入队），连接不断。
 * Failure semantics: state-machine/argument exceptions from admitPlayer become false here (the matcher re-queues
 * that candidate) while the connection stays open.
 */
final class MatchJoinOrchestrator implements MatchJoinHandlerInterface
{
    public function __construct(
        private readonly RoomHub $hub,
    ) {
    }

    public function joinRoom(string $roomId, string $entityId): bool
    {
        try {
            $this->hub->admitPlayer($entityId, $roomId);

            return true;
        } catch (\InvalidArgumentException|\LogicException $e) {
            // 满员/状态不可入/实体缺失：转 false 由撮合侧重排（比照 RoomHub 状态机异常口径，连接不断）；
            // 日志归因供装配/E2E 排障
            // Full room / non-admissible state / missing entity: false so the matcher re-queues (mirroring RoomHub's
            // state-machine exception convention, connection kept); the log attributes failures for assembly/e2e debugging
            error_log(sprintf('[MatchJoinOrchestrator] joinRoom failed: %s (entity=%s room=%s)', $e->getMessage(), $entityId, $roomId));

            return false;
        }
    }
}
