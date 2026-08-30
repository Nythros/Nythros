<?php

declare(strict_types=1);

namespace Nythros\World;

use Nythros\Contracts\AOIProviderInterface;
use Nythros\Contracts\EntityManagerInterface;
use Nythros\Contracts\EventEnvelope;

/**
 * AOI 差分信封收集器：World 与 RoomInstance 共用的视野差分→双向信封装配逻辑。
 * AOI diff envelope collector: the vision-diff → bidirectional-envelope assembly logic shared by World and RoomInstance.
 * @internal 引擎内部实现，非公开 API。Engine-internal implementation, not part of the public API.
 */
final class AoiDiffEnvelopes
{
    /**
     * 对本帧已移动（moved-dirty）的实体刷新空间索引，收集视野进入/离开信封（仅 AOI 型容器调用）。
     * Refreshes the spatial index for entities that moved this frame (moved-dirty), collecting vision
     * enter/leave envelopes (only called by AOI-type containers).
     *
     * 双向事件：updateEntity 的 entered/left 是「实体 A 的视野新增/失去邻居 B」（A 视角），每个邻居成对生成
     * 两个方向的信封（邻居视角 + 自身视角），事件数量翻倍属预期。对称性去重：静止方走同格 fast path（空 diff），
     * 不会为同一对实体重复产生自身视角信封。
     * Bidirectional events: updateEntity's entered/left is "entity A's view gained/lost neighbor B" (A's perspective);
     * each neighbor produces a pair of directional envelopes (neighbor view + self view), doubling event volume as
     * expected. Symmetry dedup: a stationary party takes the same-cell fast path (empty diff), so the pair never
     * produces duplicate self-view envelopes.
     *
     * @param EntityManagerInterface $entityManager 容器自有实体管理器 The container's own entity manager.
     * @param AOIProviderInterface $aoi 容器自有 AOI 提供者 The container's own AOI provider.
     * @param float $frameClock 帧首采样的统一时间戳（全部信封共用，时钟每帧只调一次） The frame-start sampled timestamp shared by all envelopes (one clock call per frame).
     * @return array{entered: list<EventEnvelope>, left: list<EventEnvelope>} 先全部 entered 后全部 left 的信封集 Envelope sets, all entered first then all left.
     */
    public static function collect(
        EntityManagerInterface $entityManager,
        AOIProviderInterface $aoi,
        float $frameClock,
    ): array {
        /** @var list<EventEnvelope> $enterEnvelopes 本帧全部视野进入信封（先邻居视角后自身视角，成对入列） all visibility-enter envelopes of this frame (neighbor-view then self-view, queued in pairs) */
        $enterEnvelopes = [];
        /** @var list<EventEnvelope> $leaveEnvelopes 本帧全部视野离开信封（先邻居视角后自身视角，成对入列） all visibility-leave envelopes of this frame (neighbor-view then self-view, queued in pairs) */
        $leaveEnvelopes = [];

        foreach ($entityManager->drainMoved() as $entity) {
            $diff = $aoi->updateEntity($entity);

            foreach ($diff['entered'] as $neighbor) {
                $enterEnvelopes[] = new EventEnvelope(
                    source: $entity->getId(),
                    type: EventEnvelope::TYPE_AOI_ENTER,
                    timestamp: $frameClock,
                    targetScope: $neighbor->getId(),
                    reliable: false,
                    droppable: true,
                    payload: ['position' => $entity->getPosition()],
                );
                $enterEnvelopes[] = new EventEnvelope(
                    source: $neighbor->getId(),
                    type: EventEnvelope::TYPE_AOI_ENTER,
                    timestamp: $frameClock,
                    targetScope: $entity->getId(),
                    reliable: false,
                    droppable: true,
                    payload: ['position' => $neighbor->getPosition()],
                );
            }

            foreach ($diff['left'] as $neighbor) {
                $leaveEnvelopes[] = new EventEnvelope(
                    source: $entity->getId(),
                    type: EventEnvelope::TYPE_AOI_LEAVE,
                    timestamp: $frameClock,
                    targetScope: $neighbor->getId(),
                    reliable: false,
                    droppable: true,
                    payload: ['position' => $entity->getPosition()],
                );
                $leaveEnvelopes[] = new EventEnvelope(
                    source: $neighbor->getId(),
                    type: EventEnvelope::TYPE_AOI_LEAVE,
                    timestamp: $frameClock,
                    targetScope: $entity->getId(),
                    reliable: false,
                    droppable: true,
                    payload: ['position' => $neighbor->getPosition()],
                );
            }
        }

        return ['entered' => $enterEnvelopes, 'left' => $leaveEnvelopes];
    }
}
