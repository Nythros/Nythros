<?php

declare(strict_types=1);

namespace Nythros\Framework\Cluster;

/**
 * 跨 map 实体迁移的快照票据存储契约（ADR-025 方案 C：客户端驱动换线 + 转移票据）。
 * The snapshot-ticket store contract for cross-map entity migration (ADR-025's option C: client-driven
 * zoning + transfer tickets).
 *
 * 语义：按 uid 单票据（同 uid 二次导出覆盖旧票）；消费为**原子单次**——目的端 attach 时取走即删，
 * 消费失败/超时（TTL）自然回落「全新入场」（故障方向「变保守」，与 P9 fail-open 同哲学）。
 * Semantics: one ticket per uid (a second export overwrites the old one); consumption is **atomic
 * single-take** — the destination takes-and-deletes at attach, and a failed/timed-out consume falls back
 * to a "fresh entry" naturally (the failure direction is "more conservative", the same philosophy as P9's fail-open).
 */
interface PlayerTransferStoreInterface
{
    /**
     * 导出实体状态快照（源端 detach 时调用；覆盖同 uid 旧票）。
     * Exports the entity-state snapshot (invoked by the source at detach; overwrites the same uid's old ticket).
     *
     * @param array<string, mixed> $snapshot 快照契约见 ADR-025 §3.2（fromMapId/position/hp/inventory）。
     *   The snapshot contract lives in ADR-025 §3.2 (fromMapId/position/hp/inventory).
     */
    public function export(string $uid, array $snapshot): void;

    /**
     * 原子消费快照票据（目的端 attach 时调用；取走即删，无票返回 null）。
     * Atomically consumes the snapshot ticket (invoked by the destination at attach; take-and-delete, null when no ticket).
     *
     * @return array<string, mixed>|null
     */
    public function consume(string $uid): ?array;
}
