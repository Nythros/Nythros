<?php

declare(strict_types=1);

namespace Nythros\Framework\Cluster;

/**
 * 转移票据的进程内存储（ADR-025）：单进程形态（单测/纯消息模式）用——与 InMemoryTokenStore 同范式。
 * The in-process ticket store (ADR-025): for the single-process shape (unit tests / message-only mode) —
 * the same convention as InMemoryTokenStore.
 */
final class InMemoryPlayerTransferStore implements PlayerTransferStoreInterface
{
    /** @var array<string, array<string, mixed>> uid => 快照 uid => snapshot. */
    private array $tickets = [];

    public function export(string $uid, array $snapshot): void
    {
        $this->tickets[$uid] = $snapshot;
    }

    public function consume(string $uid): ?array
    {
        $snapshot = $this->tickets[$uid] ?? null;
        unset($this->tickets[$uid]);

        return is_array($snapshot) ? $snapshot : null;
    }
}
