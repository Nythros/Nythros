<?php

declare(strict_types=1);

namespace Nythros\Framework\Quest;

/**
 * 任务进度写回缓冲（主循环 IO 剥离）：QuestStoreInterface 的内存装饰器——把「读穿透 + 写回」的持久化
 * 后端（如 RedisQuestStore）包在内存会话缓存后，使战斗/拾取热路径（combat.kill/combat.pickup →
 * QuestService::advance 的 get/save）零 IO；后端往返只在 flush 点发生。
 * Write-back buffer for quest progress (strips IO off the hot path): an in-memory QuestStoreInterface decorator
 * that wraps a read-through/persist backend (e.g. RedisQuestStore) behind a session cache, so the combat/pickup
 * hot path (combat.kill/combat.pickup → QuestService::advance's get/save) does zero IO; backend round-trips happen
 * only at flush points.
 *
 * 会话模型（与玩家在线状态同生命周期，非全局 LRU）：
 * Session model (shares the player's online lifecycle, not a global LRU):
 * - 预热 preload：入场时一次性把该 uid 的全部进度读进缓存（attach 读路径，非每击杀）；未预热的 uid 走
 *   读穿透 get/all 也会登记为已载入（首访即缓存，热路径只付一次后端往返）；
 *   preload: at attach the uid's whole progress set is read into the cache once (an attach read path, not per
 *   kill); an uid that was never preloaded still reads through get/all and becomes loaded (first touch caches it,
 *   so the hot path pays at most one backend round-trip);
 * - 写回 save/delete：只改内存并标脏（per-(uid,questId) 记最新值或删除标记），绝不触碰后端；
 *   write-back save/delete: mutates memory and marks the (uid,questId) dirty (latest value or a delete flag),
 *   never touching the backend;
 * - 冲刷 flush：仅把脏记录逐条回写后端（成功清脏，失败留脏待下次兜底）；evict = flush 该 uid 后移出缓存
 *   （断连/登出的会话收尾），flushAll 供 30s 定时兜底。
 *   flush: writes only the dirty records back to the backend (success clears dirty, failure keeps it for the next
 *   fallback); evict flushes one uid then drops it from the cache (the disconnect/logout session close-out), and
 *   flushAll serves the 30s periodic backstop.
 *
 * 一致性边界：单进程权威（一 uid 只被一个 map worker 持有，见 uid@connectionId 与转移票据语义），缓存即
 * 该 uid 进度的唯一真相直到冲刷；跨进程重启的持久性由后端承担、由「崩溃前未冲刷的脏记录」界定丢失窗口
 * （与 ArchivePipeline 的有界丢失裁决 4 同口径）。未预热带 TTL 的读缓存——纯靠会话生命周期界定内存。
 * Consistency boundary: single-process authority (one uid is held by one map worker — see uid@connectionId and the
 * transfer-ticket semantics), so the cache is the sole truth for that uid's progress until flushed; durability
 * across process restarts stays on the backend, bounded by the not-yet-flushed dirty window (the same
 * bounded-loss ruling as ArchivePipeline's ruling 4). No TTL on the read cache — memory is bounded purely by the
 * session lifecycle.
 */
final class CachedQuestStore implements QuestStoreInterface
{
    /**
     * @param QuestStoreInterface $backend 真实持久化后端（RedisQuestStore/InMemoryQuestStore） The real persistence backend
     * @param bool $readThrough 读穿透开关：true 时 get/all 命中未载 uid 会即时读后端（默认，兼容性最好）；
     *   false 时要求先 preload，未载 uid 读返回空——用于「attach 必预热」的装配以杜绝热路径首访往返。
     *   Read-through switch: when true, get/all on an unloaded uid immediately reads the backend (the default, most
     *   compatible); when false, a preload is required and reads on an unloaded uid return empty — for assemblies
     *   that guarantee "attach always preloads", eliminating the hot path's first-touch round-trip.
     */
    public function __construct(
        private readonly QuestStoreInterface $backend,
        private readonly bool $readThrough = true,
    ) {
    }

    /** @var array<string, array<string, QuestProgress>> uid => questId => 缓存进度 uid => questId => cached progress. */
    private array $cache = [];

    /** @var array<string, true> 已载入 uid 集合（preload 或首次读穿透后置位） Loaded-uid set (set by preload or first read-through). */
    private array $loaded = [];

    /** @var array<string, array<string, QuestProgress|null>> uid => questId => 待回写（null = 删除标记） uid => questId => pending write-back (null = a delete flag). */
    private array $dirty = [];

    /**
     * 预热：整批读入某 uid 的全部进度并登记为已载入（不标脏）。幂等——已载入则零往返。
     * Preloads a uid's whole progress set into the cache and marks it loaded (without dirtying it). Idempotent —
     * already-loaded uids cost zero round-trips.
     */
    public function preload(string $uid): void
    {
        if (isset($this->loaded[$uid])) {
            return;
        }

        foreach ($this->backend->all($uid) as $progress) {
            $this->cache[$uid][$progress->questId] = $progress;
        }
        $this->loaded[$uid] = true;
    }

    public function save(QuestProgress $progress): void
    {
        $this->ensureLoaded($progress->uid);

        $this->cache[$progress->uid][$progress->questId] = $progress;
        $this->dirty[$progress->uid][$progress->questId] = $progress;
    }

    public function get(string $uid, string $questId): ?QuestProgress
    {
        $this->ensureLoaded($uid);

        return $this->cache[$uid][$questId] ?? null;
    }

    /**
     * @return list<QuestProgress>
     */
    public function all(string $uid): array
    {
        $this->ensureLoaded($uid);

        return array_values($this->cache[$uid] ?? []);
    }

    public function delete(string $uid, string $questId): void
    {
        $this->ensureLoaded($uid);

        unset($this->cache[$uid][$questId]);
        $this->dirty[$uid][$questId] = null;
    }

    /**
     * 冲刷全部脏记录：按 uid 批量回写后端（成功清脏、失败留脏待下次兜底），返回是否全部成功。
     * Flushes every dirty record via batched write-back (success clears dirty, failure keeps it for the next
     * fallback); returns whether all succeeded.
     */
    public function flushAll(): bool
    {
        return $this->flushUids(array_keys($this->dirty));
    }

    /**
     * 冲刷并淘汰某 uid（断连/登出会话收尾）：先回写其脏记录（失败的留在 dirty 交 30s 兜底，不丢），
     * 再把该 uid 的缓存/载入态移出——释放内存。会话已结束，后续同 uid 若重连会重新预热。
     * Flushes and evicts one uid (the disconnect/logout session close-out): first writes back its dirty records
     * (failures stay in dirty for the 30s backstop and are never lost), then drops the uid's cache/loaded state to
     * free memory. The session has ended; a later re-login of the same uid preloads afresh.
     */
    public function evict(string $uid): void
    {
        $this->flushUids([$uid]);
        unset($this->cache[$uid], $this->loaded[$uid]);
    }

    /**
     * 缓存中是否仍存有该 uid 未冲刷的脏记录（观测/测试用）。
     * Whether unflushed dirty records for the uid remain in the cache (an observation/test seam).
     */
    public function hasDirty(string $uid): bool
    {
        return isset($this->dirty[$uid]) && $this->dirty[$uid] !== [];
    }

    /**
     * 冲刷指定 uid 集合的脏记录：save 类脏记录若后端支持批量则并成一次 saveMany 往返，否则逐条；
     * delete 类脏记录逐条回写（存储契约无批量删）。成功出脏、失败留脏（下次兜底重试）。
     * Flushes the dirty records of the given uids: save-flagged records collapse into one saveMany round-trip when
     * the backend supports batching (else per-record), while delete-flagged records write back per-record (the store
     * contract has no batch delete). Successes clear dirty, failures stay for the next fallback.
     *
     * @param list<string> $uids 待冲刷 uid（数字 uid 的 int 数组键已在调用点 (string) 规整）
     */
    private function flushUids(array $uids): bool
    {
        $ok = true;

        // 分桶：save 类（携带最新进度）与 delete 类（null 标记），逐 uid 保序
        // Bucket: save-flagged (carry the latest progress) vs delete-flagged (null), keeping per-uid order
        $saves = [];
        /** @var list<array{0: string, 1: string}> $deletes */
        $deletes = [];
        foreach ($uids as $uid) {
            $uid = (string) $uid;
            foreach ($this->dirty[$uid] ?? [] as $questId => $progress) {
                if ($progress === null) {
                    $deletes[] = [$uid, $questId];
                } else {
                    $saves[$uid][] = $questId;
                }
            }
        }

        // save 桶：收集实际进度对象（delete 已分离），批量优先
        // The save bucket: gather the progress objects (deletes already split out), batch-first
        $records = [];
        foreach ($saves as $uid => $questIds) {
            foreach ($questIds as $questId) {
                $progress = $this->dirty[$uid][$questId];
                if ($progress !== null) {
                    $records[] = $progress;
                }
            }
        }
        if ($records !== []) {
            if ($this->backend instanceof QuestBatchStoreInterface) {
                $ok = $this->writeBackBatch($this->backend, $records);
            } else {
                foreach ($records as $progress) {
                    if (!$this->writeBackSave($progress)) {
                        $ok = false;
                    }
                }
            }
        }

        // delete 桶：逐条（契约无批量删），成功出脏
        // The delete bucket: per-record (no batch-delete in the contract), successes clear dirty
        foreach ($deletes as [$uid, $questId]) {
            if ($this->writeBackDelete($uid, $questId)) {
                unset($this->dirty[$uid][$questId]);
            } else {
                $ok = false;
            }
        }

        foreach ($uids as $uid) {
            if (($this->dirty[(string) $uid] ?? []) === []) {
                unset($this->dirty[(string) $uid]);
            }
        }

        return $ok;
    }

    /**
     * 批量回写：一次 saveMany 落库全部 save 脏记录（按 uid 归组 hMSet / 跨 uid pipeline = 1 往返）；
     * 整批成功才清全部脏，抛错则整批留脏（下次兜底重试——不做单条部分成功的细粒度裁决，批量语义 = 全有或全留）。
     * Batch write-back: one saveMany persists every save-flagged record (per-uid hMSet / cross-uid pipeline = 1
     * round-trip); dirty clears only if the whole batch succeeds — a throw keeps the entire batch dirty for the next
     * fallback (no per-record partial-success adjudication; the batch is all-clear-or-all-keep).
     *
     * @param QuestBatchStoreInterface $backend 调用点已 instanceof 收窄的批量后端（本方法内无法收窄 $this->backend）
     * @param list<QuestProgress> $records 待批量回写的 save 脏记录
     */
    private function writeBackBatch(QuestBatchStoreInterface $backend, array $records): bool
    {
        if ($records === []) {
            return true;
        }

        try {
            $backend->saveMany($records);
        } catch (\Throwable $e) {
            error_log(sprintf('[CachedQuestStore] batch write-back failed (%d records): %s', count($records), $e->getMessage()));

            return false;
        }

        foreach ($records as $progress) {
            unset($this->dirty[$progress->uid][$progress->questId]);
        }

        return true;
    }

    /** 逐条回写单条 save 脏记录（非批量后端回落路径）；成功出脏返回 true。 Writes back one save-flagged record (the non-batching-backend fallback); true on success (clears dirty). */
    private function writeBackSave(QuestProgress $progress): bool
    {
        try {
            $this->backend->save($progress);
        } catch (\Throwable $e) {
            error_log(sprintf('[CachedQuestStore] write-back failed uid=%s quest=%s: %s', $progress->uid, $progress->questId, $e->getMessage()));

            return false;
        }

        unset($this->dirty[$progress->uid][$progress->questId]);

        return true;
    }

    /** 逐条回写单条 delete 脏记录；成功返回 true。 Writes back one delete-flagged record; true on success. */
    private function writeBackDelete(string $uid, string $questId): bool
    {
        try {
            $this->backend->delete($uid, $questId);

            return true;
        } catch (\Throwable $e) {
            error_log(sprintf('[CachedQuestStore] delete write-back failed uid=%s quest=%s: %s', $uid, $questId, $e->getMessage()));

            return false;
        }
    }

    /** 确保 uid 已载入：已载入或关闭读穿透则跳过；否则整批读入后端一次。 Ensures a uid is loaded: skips when loaded or read-through is off; otherwise reads the backend in one shot. */
    private function ensureLoaded(string $uid): void
    {
        if (isset($this->loaded[$uid]) || !$this->readThrough) {
            return;
        }

        $this->preload($uid);
    }
}
