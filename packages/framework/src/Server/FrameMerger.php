<?php

declare(strict_types=1);

namespace Nythros\Framework\Server;

use Nythros\Network\ConnectionInterface;
use Nythros\Protocol\BatchSerializerInterface;
use Nythros\Protocol\Message;

/**
 * 出站帧合并器：在连接分组缓冲之上提供同帧去重、优先级过滤与单帧字节配额。
 * 帧按策略表分类——状态帧（STATE）同帧内同一实体同类型只保留最新负载（原位替换，帧序稳定），
 * 事件帧（EVENT）始终追加；drain 时应用慢客户端软过滤（丢低优先级）与字节配额，
 * 再把该连接本帧全部帧编码为「一包多帧」的单个批量包（每连接每帧恰好一次网络写入）。
 * Outbound frame merger: adds same-frame dedup, priority filtering and a per-frame byte quota on top of
 * per-connection frame buffering. Frames are classified by a policy table — STATE frames keep only the latest
 * payload per entity/type within a frame (replaced in place, stable ordering), EVENT frames always append;
 * drain applies the slow-client soft filter (shedding low priority) and the byte quota, then encodes the
 * connection's whole frame set into a single "many frames in one packet" batch (one network write per
 * connection per frame).
 */
final class FrameMerger
{
    /** 状态帧：同帧内同 key 替换（保留最新负载与首次入队位置）。 STATE frames: replaced on the same key within a frame (latest payload, first-enqueue position). */
    public const KIND_STATE = 'state';

    /** 事件帧：始终追加，从不替换（进视野/离开/伤害等一条都不能少）。 EVENT frames: always appended, never replaced (enter/leave/hit must not be lost). */
    public const KIND_EVENT = 'event';

    /** 高优先级：慢客户端过滤与配额超限时最后被丢弃。 High priority: shed last under slow-client filtering and quota pressure. */
    public const PRIORITY_HIGH = 'high';

    /** 低优先级：慢客户端过滤与配额超限时优先被丢弃（有周期视野快照重同步兜底）。 Low priority: shed first under slow-client filtering and quota pressure (covered by the periodic vision-snapshot resync). */
    public const PRIORITY_LOW = 'low';

    /** @var array{kind: string, priority: string} 未列入策略表的帧默认策略（事件型 + 高优先级）。 Default policy for frames not in the table (event + high). */
    private const DEFAULT_POLICY = ['kind' => self::KIND_EVENT, 'priority' => self::PRIORITY_HIGH];

    /**
     * 帧策略表：type => [kind, priority]。状态帧以 payload['id']（或显式 dedupKey）为替换键。
     * Frame policy table: type => [kind, priority]. STATE frames are keyed by payload['id'] (or an explicit dedupKey).
     *
     * @var array<string, array{kind: string, priority: string}>
     */
    private const FRAME_POLICY = [
        'entity_moved' => ['kind' => self::KIND_STATE, 'priority' => self::PRIORITY_LOW],
        'player:stats' => ['kind' => self::KIND_STATE, 'priority' => self::PRIORITY_LOW],
    ];

    /** @var array<string, list<array{type: string, payload: array<string|int, mixed>, priority: string}>> connectionId => 帧槽列表（按入队顺序） connectionId => frame-slot list (enqueue order). */
    private array $slots = [];

    /** @var array<string, array<string, int>> connectionId => dedupKey => 槽索引（状态帧替换定位） connectionId => dedupKey => slot index (STATE replacement target). */
    private array $stateSlots = [];

    /**
     * 构造合并器并注入批量序列化器（drain 时把该连接本帧全部帧编码为一个批量包）。
     * Creates the merger with a batch serializer (drain encodes the connection's whole frame set into one batch).
     *
     * @param BatchSerializerInterface $serializer 批量序列化器 Batch serializer.
     */
    public function __construct(private readonly BatchSerializerInterface $serializer)
    {
    }

    /**
     * 入队一帧：按策略表分类——状态帧同 key 替换（保留原槽位、只换负载），事件帧追加。
     * Enqueues a frame: classified by the policy table — STATE frames replace on the same key (slot kept, payload swapped), EVENT frames append.
     *
     * @param ConnectionInterface $conn 目标连接 Target connection.
     * @param string $type 帧类型（协议 type，如 entity_moved / entity_enter） Frame type (protocol type, e.g. entity_moved / entity_enter).
     * @param array<string|int, mixed> $payload 帧负载 Frame payload.
     * @param null|string $dedupKey 状态帧替换键；缺省取 payload['id'] State-frame replacement key; defaults to payload['id'].
     */
    public function enqueue(ConnectionInterface $conn, string $type, array $payload, ?string $dedupKey = null): void
    {
        $connId = $conn->getId();
        $policy = self::FRAME_POLICY[$type] ?? self::DEFAULT_POLICY;

        if ($policy['kind'] === self::KIND_STATE) {
            $key = $dedupKey ?? ($payload['id'] ?? null);
            if (is_string($key) && $key !== '') {
                $slotIndex = $this->stateSlots[$connId][$key] ?? null;
                if ($slotIndex !== null) {
                    // 同帧同实体状态帧：保留原槽位，仅替换负载（帧序稳定，客户端只关心本帧最终值）
                    // Same-frame same-entity STATE frame: keep the slot, replace only the payload (stable frame order; the client only needs the final value)
                    $this->slots[$connId][$slotIndex]['payload'] = $payload;

                    return;
                }
                $this->stateSlots[$connId][$key] = count($this->slots[$connId] ?? []);
            }
        }

        $this->slots[$connId][] = [
            'type' => $type,
            'payload' => $payload,
            'priority' => $policy['priority'],
        ];
    }

    /**
     * 取出全部帧：按连接把本帧全部帧编码为单个批量包（每连接一个字节串）并清空缓冲；
     * 软过滤（低优先级丢弃）与单帧字节配额在此应用（超配额时剔除低优先级帧后重编码）。
     * Drains every frame: encodes each connection's whole frame set into a single batch packet (one byte string
     * per connection) and clears the buffer; the soft filter (low-priority shed) and the per-frame byte quota are
     * applied here (over quota, low-priority frames are shed and the batch is re-encoded).
     *
     * @param int $maxBytesPerConnection 每连接每帧字节配额（按批量包编码后字节统计） Per-connection per-frame byte quota (counted on the encoded batch).
     * @param array<string, true> $softFilterConnIds 本帧启用低优先级过滤的连接 id 集合 Connection ids whose low-priority frames are shed this frame.
     * @return array<string, list<string>> connectionId => 批量包字节（列表恒含 1 个元素，与 sendBatch 接口对齐） connectionId => batch bytes (list always holds one element, aligned with the sendBatch interface).
     */
    public function drain(int $maxBytesPerConnection, array $softFilterConnIds = []): array
    {
        $result = [];
        foreach ($this->slots as $connId => $frameSlots) {
            $filterLow = isset($softFilterConnIds[$connId]);
            $chosen = [];
            foreach ($frameSlots as $slot) {
                if ($filterLow && $slot['priority'] === self::PRIORITY_LOW) {
                    continue;
                }
                $chosen[] = $slot;
            }

            if ($chosen === []) {
                continue;
            }

            $encode = fn (array $slots): string => $this->serializer->encodeBatch(array_values(array_map(
                static fn (array $s): Message => Message::create($s['type'], $s['payload']),
                $slots,
            )));

            $blob = $encode($chosen);
            if (strlen($blob) > $maxBytesPerConnection) {
                // 超配额：剔除低优先级帧后重编码（周期快照兜底）；高优先级尽力发送（软配额而非硬截断）
                // Over quota: re-encode without low-priority frames (covered by the periodic snapshot); high-priority ones are sent best-effort (soft quota, not a hard cut)
                $kept = array_values(array_filter($chosen, static fn (array $s): bool => $s['priority'] !== self::PRIORITY_LOW));
                if ($kept === []) {
                    continue;
                }
                $blob = $encode($kept);
            }

            $result[$connId] = [$blob];
        }

        $this->slots = [];
        $this->stateSlots = [];

        return $result;
    }
}
