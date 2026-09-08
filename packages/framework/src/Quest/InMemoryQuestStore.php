<?php

declare(strict_types=1);

namespace Nythros\Framework\Quest;

/**
 * 内存任务进度存储：QuestStoreInterface 的进程内实现（单测与无外部存储部署用）。
 * The in-memory quest-progress store: the in-process QuestStoreInterface implementation (for unit tests and
 * deployments without external storage).
 */
final class InMemoryQuestStore implements QuestStoreInterface, QuestBatchStoreInterface
{
    /** @var array<string, array<string, QuestProgress>> uid => questId => 进度 uid => questId => progress. */
    private array $records = [];

    public function save(QuestProgress $progress): void
    {
        $this->records[$progress->uid][$progress->questId] = $progress;
    }

    public function saveMany(array $progresses): void
    {
        // 内存后端无往返概念，批量 = 逐条覆盖写（仅为与 Redis 后端的接口能力对齐）
        // An in-memory backend has no round-trip notion, so batch is just per-record overwrite (kept interface-
        // compatible with the Redis backend)
        foreach ($progresses as $progress) {
            $this->save($progress);
        }
    }

    public function get(string $uid, string $questId): ?QuestProgress
    {
        return $this->records[$uid][$questId] ?? null;
    }

    public function all(string $uid): array
    {
        return array_values($this->records[$uid] ?? []);
    }

    public function delete(string $uid, string $questId): void
    {
        unset($this->records[$uid][$questId]);
    }
}
