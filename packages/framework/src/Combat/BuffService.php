<?php

declare(strict_types=1);

namespace Nythros\Framework\Combat;

use Nythros\Framework\BasePlayer;
use Nythros\Framework\Event\EventDispatcherInterface;
use Nythros\Framework\Plugin\Buff\BuffDefinition;
use Nythros\Framework\Plugin\Buff\BuffInstance;
use Nythros\Framework\Plugin\Buff\BuffRepository;

/**
 * Buff 服务（R3 玩法批正式化）：施加/叠加裁决、到期 tick 与效果结算（属性修正/DOT）的状态机。
 * Buff service (formalized in the R3 gameplay batch): the state machine for application/stacking adjudication,
 * expiry ticking and effect settlement (attribute modifiers / DOT).
 *
 * 驱动宿主裁决（D8）：选「TickScheduler 定时任务」而非 ActorSystem onTick——
 * ① 与既有掉落过期回收（CombatService::purgeExpiredDrops 定时路径）同构：服务自治、now 注入可测；
 * ② 不侵入四基类继承树：onTick 路径要求宿主注册进 ActorSystem 且逐 actor 推进，而 buff 宿主是任意
 *   BasePlayer（含未进 ActorSystem 的宿主），服务侧统一 tick 更简单；
 * ③ 组装层已有周期定时器先例（PerfSampler/archive 兜底/房间宿主心跳），接线零新模式。
 * Driver-host ruling (D8): a TickScheduler periodic task over ActorSystem onTick — ① isomorphic to the existing
 * drop-expiry reclamation (CombatService::purgeExpiredDrops, a periodic path with injectable now for testability);
 * ② never intrudes into the four-base-class inheritance tree: the onTick path requires hosts registered in the
 * ActorSystem and per-actor advancement, while buff hosts are arbitrary BasePlayers (including ones outside the
 * ActorSystem), so a unified service-side tick is simpler; ③ the assembly layer already has periodic-timer precedents
 * (PerfSampler / archive backstop / room host heartbeat) — wiring introduces no new pattern.
 *
 * 叠加边界矩阵（rules 清单第 5 条口径）：
 * - 首次施加：stacks=1，登记每层属性修正，广播 buff:applied；
 * - refresh 规则重复施加：仅刷新到期时刻（层数与修正不变）；
 * - stack 规则重复施加：层数 +1（封顶 maxStacks）并刷新到期时刻，新增层追加一份属性修正；
 * - mutexGroup 冲突：同组既有其他实例先被顶替摘除（含修正回退），再走正常施加；
 * - 到期（expiresAt <= now）：摘除全部层的修正，广播 buff:expired；DOT 不再结算过期后的尾拍。
 * Stacking boundary matrix (rules-checklist item 5):
 * - first application: stacks=1, one per-stack modifier registered, buff:applied broadcast;
 * - refresh rule on re-application: only the expiry is refreshed (stacks and modifiers unchanged);
 * - stack rule on re-application: stacks+1 (capped at maxStacks) with the expiry refreshed; the added stack appends
 *   one more modifier copy;
 * - mutexGroup conflict: an existing same-group instance is displaced first (modifiers rolled back), then the normal
 *   application proceeds;
 * - expiry (expiresAt <= now): all stack modifiers are rolled back and buff:expired broadcast; DOT never settles a
 *   tail tick after expiry.
 */
final class BuffService
{
    /** 施加事件名（EventDispatcher 埋点）。 The applied-event name (an EventDispatcher instrumentation point). */
    public const EVENT_APPLIED = 'buff.applied';

    /** 到期/驱散事件名。 The expired/dispelled-event name. */
    public const EVENT_EXPIRED = 'buff.expired';

    /** @var array<string, array<string, BuffInstance>> hostKey => buffId => 运行时实例 hostKey => buffId => runtime instance. */
    private array $instances = [];

    public function __construct(
        private readonly BuffRepository $definitions,
        private readonly ?VisionBroadcasterInterface $broadcaster = null,
        private readonly ?EventDispatcherInterface $events = null,
    ) {
    }

    /**
     * 施加 buff：定义校验 → 互斥组顶替 → 叠加规则裁决（首次/refresh/stack）→ 属性修正登记 → 广播与事件。
     * Applies a buff: definition validation → mutex-group displacement → stacking-rule adjudication
     * (first/refresh/stack) → modifier registration → broadcast and event.
     *
     * @param string $hostKey 宿主键（玩家 entityId；显式传入使本服务不依赖具体 Actor 类） The host key (the player's
     *   entityId; passed explicitly so this service never depends on a concrete actor class).
     * @param BasePlayer $host 宿主（属性修正挂载点） The host (the attribute-modifier mount point).
     * @param string $buffId Buff 定义 id（未注册/时长非正返回 false） The definition id (false when unregistered or non-positive duration).
     * @param float $now 当前时刻（microtime 秒，注入保证可测） The current instant (microtime seconds, injected for testability).
     */
    public function apply(string $hostKey, BasePlayer $host, string $buffId, float $now): bool
    {
        $definition = $this->definitions->get($buffId);
        if ($definition === null || $definition->durationSeconds <= 0) {
            return false;
        }

        // 互斥组顶替：同组既有其他实例先摘除（修正回退 + expired 广播），再施加新实例
        // Mutex-group displacement: an existing same-group instance is removed first (modifier rollback plus the
        // expired broadcast) before the new instance applies
        if ($definition->mutexGroup !== null) {
            foreach ($this->instances[$hostKey] ?? [] as $existing) {
                $existingDefinition = $this->definitions->get($existing->buffId);
                if ($existing->buffId !== $buffId && $existingDefinition !== null && $existingDefinition->mutexGroup === $definition->mutexGroup) {
                    $this->detach($host, $existing);
                }
            }
        }

        $instance = $this->instances[$hostKey][$buffId] ?? null;
        if ($instance === null) {
            $instance = new BuffInstance($buffId, $hostKey, 1, $now + $definition->durationSeconds, $this->initialDotAt($definition, $now));
            $this->instances[$hostKey][$buffId] = $instance;
            $this->applyStackModifiers($host, $definition, 1);
            $this->broadcastApplied($instance, $definition);

            return true;
        }

        if ($definition->stackRule === BuffDefinition::STACK_STACK) {
            $maxStacks = max(1, $definition->maxStacks);
            if ($instance->stacks < $maxStacks) {
                $instance->stacks++;
                $this->applyStackModifiers($host, $definition, 1);
            }
        }
        $instance->expiresAt = $now + $definition->durationSeconds;

        $this->broadcastApplied($instance, $definition);

        return true;
    }

    /**
     * 主动驱散：实例存在即摘除（修正回退 + 广播）；不存在静默 false。
     * Active dispel: removes the instance when present (modifier rollback plus broadcast); silently false otherwise.
     */
    public function remove(string $hostKey, BasePlayer $host, string $buffId): bool
    {
        $instance = $this->instances[$hostKey][$buffId] ?? null;
        if ($instance === null) {
            return false;
        }
        $this->detach($host, $instance);

        return true;
    }

    /**
     * 到期 tick（定时任务路径，组装层周期调用）：遍历全部实例——到期者摘除（先于 DOT 判定：
     * 过期后不结算尾拍），DOT 到拍者结算自伤 combat:hit 并推进下一拍。
     * Expiry tick (the periodic-task path, invoked by the assembly layer's timer): walks every instance — expired
     * ones detach first (before any DOT judgment: no tail tick settles past expiry), due DOT ones settle their
     * self-damage combat:hit and advance to the next beat.
     *
     * @param float $now 当前时刻（microtime 秒） The current instant (microtime seconds).
     * @param callable(string): ?BasePlayer|null $hostResolver 宿主解析器（hostKey → BasePlayer；
     *   缺失返回 null 跳过该宿主）——DOT 结算需要宿主 takeDamage The host resolver (hostKey → BasePlayer; null skips
     *   that host) — DOT settlement needs the host's takeDamage.
     */
    public function tick(float $now, ?callable $hostResolver = null): void
    {
        foreach ($this->instances as $hostKey => $hostInstances) {
            $host = $hostResolver !== null ? $hostResolver($hostKey) : null;
            foreach ($hostInstances as $buffId => $instance) {
                if ($instance->expiresAt <= $now) {
                    if ($host !== null) {
                        $this->detach($host, $instance);
                    } else {
                        // 宿主不可解析（已断连且未 purgeHost）：仅摘表，修正回退随宿主销毁自然失效
                        // An unresolvable host (disconnected without purgeHost): drop the table entry only — the
                        // modifier rollback becomes moot with the host destroyed
                        unset($this->instances[$hostKey][$buffId]);
                    }

                    continue;
                }

                if ($instance->nextDotAt !== null && $instance->nextDotAt <= $now && $host !== null) {
                    $definition = $this->definitions->get($buffId);
                    $dot = $definition?->dot();
                    if ($dot !== null && $dot['damage'] > 0) {
                        $this->settleDot($host, $hostKey, $dot['damage']);
                        $instance->nextDotAt = $now + $dot['intervalSeconds'];
                    }
                }
            }
        }
    }

    /**
     * 查询宿主的某 buff 实例；不存在返回 null。
     * Looks up one of the host's buff instances; null when absent.
     */
    public function instanceOf(string $hostKey, string $buffId): ?BuffInstance
    {
        return $this->instances[$hostKey][$buffId] ?? null;
    }

    /**
     * 查询宿主全部在身实例（buffId => BuffInstance）。
     * All of the host's live instances (buffId => BuffInstance).
     *
     * @return array<string, BuffInstance>
     */
    public function instancesOf(string $hostKey): array
    {
        return $this->instances[$hostKey] ?? [];
    }

    /**
     * 宿主清理（断连路径调用）：摘除该宿主全部实例（无广播——连接已断，帧无人可收）。
     * Host cleanup (the disconnect path): drops every instance of the host (no broadcast — the connection is gone,
     * no receiver exists).
     */
    public function purgeHost(string $hostKey): void
    {
        unset($this->instances[$hostKey]);
    }

    /**
     * 摘除实例：回退全部层属性修正 + 广播 buff:expired + 事件派发 + 表摘除。
     * Detaches an instance: rolls back all stack modifiers, broadcasts buff:expired, dispatches the event and drops the table entry.
     */
    private function detach(BasePlayer $host, BuffInstance $instance): void
    {
        $definition = $this->definitions->get($instance->buffId);
        if ($definition !== null) {
            $this->applyStackModifiers($host, $definition, -$instance->stacks);
        }
        unset($this->instances[$instance->hostKey][$instance->buffId]);
        if ($this->instances[$instance->hostKey] === []) {
            unset($this->instances[$instance->hostKey]);
        }

        $this->broadcaster?->sendToEntity($instance->hostKey, 'buff:expired', [
            'targetId' => $instance->hostKey,
            'buffId' => $instance->buffId,
        ]);
        $this->events?->dispatch(self::EVENT_EXPIRED, [
            'targetId' => $instance->hostKey,
            'buffId' => $instance->buffId,
        ]);
    }

    /**
     * 按 delta 层数对称登记/回退属性修正（delta=1 叠层追加、delta=-stacks 到期全量回退）。
     * Registers/rolls back attribute modifiers symmetrically by delta stacks (delta=1 appends on stack-up;
     * delta=-stacks rolls everything back on expiry).
     */
    private function applyStackModifiers(BasePlayer $host, BuffDefinition $definition, int $deltaStacks): void
    {
        foreach ($definition->attributeModifiers() as $attribute => $increment) {
            if ($deltaStacks > 0) {
                $host->addAttributeModifier((string) $attribute, $increment * $deltaStacks);
            } else {
                $host->removeAttributeModifier((string) $attribute, $increment * -$deltaStacks);
            }
        }
    }

    /**
     * DOT 首拍时刻：有 DOT 配置时为 now + interval，否则 null。
     * The first DOT beat: now + interval when configured, null otherwise.
     */
    private function initialDotAt(BuffDefinition $definition, float $now): ?float
    {
        $dot = $definition->dot();
        if ($dot === null || $dot['intervalSeconds'] <= 0) {
            return null;
        }

        return $now + $dot['intervalSeconds'];
    }

    /**
     * DOT 结算：takeDamage 自伤 + combat:hit 自伤帧（attackerId=targetId=宿主，复用既有词表帧型）。
     * DOT settlement: takeDamage self-damage plus a combat:hit self-damage frame (attackerId=targetId=host,
     * reusing the existing frame type of the vocabulary).
     */
    private function settleDot(BasePlayer $host, string $hostKey, int $damage): void
    {
        $host->takeDamage($damage);
        $this->broadcaster?->sendToEntity($hostKey, 'combat:hit', [
            'attackerId' => $hostKey,
            'targetId' => $hostKey,
            'damage' => $damage,
            'hp' => $host->hp(),
        ]);
    }

    /**
     * 施加/刷新广播 buff:applied{targetId, buffId, stacks, durationSeconds} + 事件派发。
     * Broadcasts buff:applied{targetId, buffId, stacks, durationSeconds} on apply/refresh plus the event dispatch.
     */
    private function broadcastApplied(BuffInstance $instance, BuffDefinition $definition): void
    {
        $payload = [
            'targetId' => $instance->hostKey,
            'buffId' => $instance->buffId,
            'stacks' => $instance->stacks,
            'durationSeconds' => $definition->durationSeconds,
        ];
        $this->broadcaster?->sendToEntity($instance->hostKey, 'buff:applied', $payload);
        $this->events?->dispatch(self::EVENT_APPLIED, $payload);
    }
}
