<?php

declare(strict_types=1);

namespace Nythros\Framework\Combat;

use Nythros\Contracts\EntityInterface;
use Nythros\Contracts\WorldInterface;
use Nythros\Framework\Actor\PlayerActor;
use Nythros\Framework\BaseMonster;
use Nythros\Framework\Damageable;
use Nythros\Framework\Game\Mmorpg\ThreatTable;

/**
 * 怪物 Actor：BaseMonster 的 AI 钩子实现——巡逻感知/追击/攻击 + 死亡掉落与自清理。
 * Monster actor: BaseMonster AI hook implementations — patrol perception/chase/attack plus death drops and self-cleanup.
 *
 * 依赖面（铁律 1）：只依赖 contracts 接口 + framework 基类 + demo 自建接口，不 import 引擎 @internal 实现。
 * Dependency surface (iron rule 1): only contracts interfaces + the framework base class + demo-owned interfaces; engine @internal implementations are never imported.
 */
final class MonsterActor extends BaseMonster
{
    /** 攻击冷却（帧数）：一次攻击后需等待该帧数才能再次攻击。Attack cooldown in frames: the frames to wait after one attack before attacking again. */
    private const ATTACK_COOLDOWN_FRAMES = 8;

    /** 当前攻击冷却剩余帧数 Remaining attack-cooldown frames. */
    private int $attackCooldown = 0;

    /**
     * @param string $monsterId 怪物唯一标识 Monster unique id.
     * @param int $maxHp 最大生命值 Maximum hit points.
     * @param WorldInterface $world 世界门面（AOI 感知/EntityManager 定位/actorSystem 自清理） World facade (AOI perception/entity lookup/actorSystem self-cleanup).
     * @param CombatService $combat 战斗服务（攻击结算与死亡掉落） Combat service (attack settlement and death drops).
     * @param DropTable $dropTable 掉落表（onDeath 时 roll） Drop table (rolled on death).
     * @param ActorLookupInterface $actorLookup 目标 Actor 解析（修复 MAJOR-4） Target actor lookup (fixes MAJOR-4).
     * @param EntityTypeIndex $typeIndex 实体类型判定（区分玩家/怪物/掉落，修复 MAJOR-4） Entity kind discrimination (player/monster/drop, fixes MAJOR-4).
     * @param RandomSourceInterface $random 随机源（巡逻随机移动与掉落 roll） Random source (patrol random movement and drop rolls).
     * @param VisionBroadcasterInterface $broadcaster 视野广播（移动后广播 entity_moved，修复 debt MINOR-6） View broadcaster (broadcasts entity_moved after movement, fixes debt MINOR-6).
     * @param array{x: int, y: int} $patrolAnchor 出生点锚（巡逻移动的界心） Spawn anchor (the patrol-movement center).
     * @param int $patrolRadius 巡逻半径（世界单位）：任意位移（巡逻与追击）的预览落点超出出生点 ±radius 即拒绝。
     *   缺省 10 与攻击范围（3×3 AOI，cellSize 10 → ±10 单位）同量级：怪物始终贴近出生点且恒在出生玩家的
     *   九宫格视野内，避免「漂出攻击视野」导致攻击全部 out_of_range、死亡/掉落广播无人可收（R1 e2e 实测缺陷）。
     *   Patrol radius (world units): any displacement (patrol AND chase) whose tentative landing falls beyond the spawn
     *   anchor ± radius is rejected. The default 10 matches the attack range (3x3 AOI, cellSize 10 → ±10 units): monsters
     *   stay near their spawn and remain inside the spawner players' 3x3 view, instead of drifting out of attack vision so
     *   attacks all go out_of_range and death/drop broadcasts reach nobody (an R1 e2e defect).
     * @param ?ThreatTable $threatTable 威胁表（R4 mmorpg 类型模块试点；缺省 null = 不启用威胁/仇恨——
     *   受击不记威胁、攻击目标不切换，行为与接入前逐字节等价） Threat table (the R4 mmorpg type-module pilot;
     *   default null = threat/hate off — no threat recorded on hit, no target switching, byte-for-byte equivalent
     *   to the pre-integration behavior).
     * @param string $typeId 怪物类型 id（任务击杀匹配键；缺省 '' = 未指定——demo 装配层 spawnMonster 恒传入） Monster
     *   type id (the quest kill-matching key; default '' = unspecified — the demo assembly's spawnMonster always passes it).
     * @param array{x: int, y: int, radius: int}|null $safeZone 出生安全区（P7c，圆心 + 半径；缺省 null = 未声明零门禁）：
     *   区内玩家对怪物 AI 不可见。 The spawn safe zone (the P7c, center + radius; default null = undeclared, no gates):
     *   players inside are invisible to monster AI.
     * @param int $attackRange 攻击距离（P8c，世界单位；缺省 0 = 视野口径）——正值时在视野命中之上叠加欧氏距离上限。
     *   The attack range (the P8c, world units; default 0 = the view convention) — a positive value stacks a
     *   Euclidean cap on top of the view hit.
     */
    public function __construct(
        string $monsterId,
        int $maxHp,
        private readonly WorldInterface $world,
        private readonly CombatService $combat,
        private readonly DropTable $dropTable,
        private readonly ActorLookupInterface $actorLookup,
        private readonly EntityTypeIndex $typeIndex,
        private readonly RandomSourceInterface $random,
        private readonly VisionBroadcasterInterface $broadcaster,
        private readonly array $patrolAnchor = ['x' => 0, 'y' => 0],
        private readonly int $patrolRadius = 10,
        private readonly ?ThreatTable $threatTable = null,
        string $typeId = '',
        // 出生安全区（P7c，MmorpgConfig 透传）：区内玩家对怪物 AI 不可见——感知跳过、攻击无效化、
        // 威胁/嘲讽写入忽略、仇恨列表清理剔除；null = 未声明（零门禁，行为与接入前逐字节等价）。
        // The spawn safe zone (the P7c, passed through MmorpgConfig): players inside are invisible to monster AI —
        // perception skips, attacks are invalidated, threat/taunt writes ignored, hate-list purge removes them;
        // null = undeclared (no gates, byte-for-byte equivalent to the pre-integration behavior).
        private readonly ?array $safeZone = null,
        private readonly int $attackRange = 0,
    ) {
        parent::__construct($monsterId, $maxHp, $typeId);
    }

    /**
     * 巡逻钩子：AOI 感知视野内玩家（typeIndex 判定）→ 有则 CHASE + setTarget；无则随机移动一格并广播 entity_moved。
     * 巡逻移动受「出生点有界」约束：预览位移超出出生点 ±patrolRadius 时不移动（世界语义：怪物活动在出生点附近，
     * 防止无限随机漂移——玩家找不到怪、刷怪点失效；也稳定端到端验收对怪物位置的假设）。
     * Patrol hook: perceives a player inside the AOI view (judged via typeIndex) → CHASE + setTarget when found;
     * otherwise a one-cell random move broadcast as entity_moved. Patrol movement is bounded to the spawn anchor:
     * a tentative displacement landing beyond anchor ± patrolRadius is rejected (game semantics: monsters roam near
     * their spawn point, preventing infinite drift — players could never find them and spawn points would lose
     * purpose; this also stabilizes e2e acceptance relying on monster positions).
     */
    protected function onPatrol(): void
    {
        $entity = $this->entity;
        if ($entity === null) {
            return;
        }

        $playerId = $this->perceivePlayer();
        if ($playerId !== null) {
            $this->setTarget($playerId);
            $this->enterState(self::STATE_CHASE);

            return;
        }

        $dx = $this->random->randomInt(-1, 1);
        $dy = $this->random->randomInt(-1, 1);
        $pos = $entity->getPosition();
        if ($dx !== 0 || $dy !== 0) {
            // 有界巡逻：预览落点超出出生点范围则默认放弃本次移动（保持原位，不广播）。
            // 例外（界外自愈）：当前已越出活动域时，放行「朝锚回归」的单步移动——否则历史越界
            // （如旧版本无界追击遗留）的怪物连回归路径都被拒绝，永久滞留在视野外。
            // Bounded patrol: a tentative landing beyond the spawn radius is rejected by default (stay put, no broadcast).
            // Exception (out-of-bound self-heal): when already outside the roam domain, a single step that walks back
            // toward the anchor is allowed — otherwise a monster stranded out of bounds (e.g. left over from the former
            // unbounded chase) can never even walk home and stays outside vision forever.
            if ($this->exceedsAnchor($pos, $dx, $dy)
                && !($this->beyondAnchor($pos) && $this->stepsTowardAnchor($pos, $dx, $dy))) {
                return;
            }
            $entity->move($dx, $dy);
            $this->broadcastMove($entity);
        }
    }

    /**
     * 追击钩子：目标丢失（无目标/目标 Actor 不存在）→ 威胁表模式先尝试从仇恨列表切换目标
     * （R4 mmorpg：目标离场/死亡时 aggro 回落），仍无目标才回 PATROL；目标进入攻击范围 → ATTACK；
     * 否则朝目标方向移动一格。
     * Chase hook: a lost target (no target / missing target actor) → under the threat-table mode first tries to
     * switch from the hate list (the R4 mmorpg aggro fallback on target departure/death), only falling back to
     * PATROL when still targetless; the target inside attack range → ATTACK; otherwise move one cell toward the target.
     */
    protected function onChase(): void
    {
        $targetId = $this->targetId();
        // 目标丢失判定（P4b 收口）：Actor 不存在 **或目标已死** 都算丢失——玩家死亡仅打 awaitingRevive
        // 标记、Actor 持久存活，若只看 Actor 存在，怪物会永远追尸体、被巡逻域边界卡死（不动不攻击、
        // 无法触发 aggro 切换；E2E step6 实测暴露）。
        // Target-lost judgment (the P4b close-out): a missing actor **or a dead target** counts as lost — player
        // death only sets the awaitingRevive marker with the actor persisting, so checking actor existence alone
        // would let the monster chase a corpse forever, stuck against the patrol-domain boundary (no movement, no
        // attacks, aggro never re-evaluated; surfaced by the E2E's step6).
        $target = $targetId === null ? null : $this->actorLookup->getActor($targetId);
        if ($this->entity === null || $targetId === null || !($target instanceof Damageable) || $target->isDead()) {
            if ($this->threatTable !== null) {
                $this->applyAggroSwitch();
                if ($this->targetId() !== null) {
                    return; // 已从仇恨列表切换目标，继续追击 A target was switched from the hate list; keep chasing.
                }
            }
            $this->setTarget(null);
            $this->enterState(self::STATE_PATROL);

            return;
        }

        if ($this->isTargetInRange($targetId)) {
            $this->enterState(self::STATE_ATTACK);

            return;
        }

        $this->moveTowardTarget($targetId);
    }

    /**
     * 攻击钩子：攻击冷却判定 → 威胁表模式先做 aggro 切换（R4 mmorpg：受击方按仇恨列表选择最高威胁者，
     * 与当前目标不同即切换）→ actorLookup 解析目标 → 前置 instanceof Damageable && !isDead()（reviewer 细节 1）
     * → 出生保护跳过（R4 出生保护批：保护期内不结算、不消耗冷却，窗口结束后自动恢复攻击）→
     * combat->attack 反向攻击玩家；目标已死/无效则放弃追击回 PATROL。
     * Attack hook: cooldown check → under the threat-table mode an aggro switch first (the R4 mmorpg: the hit side
     * picks the highest threat from the hate list, switching when it differs from the current target) → resolve the
     * target via actorLookup → precondition instanceof Damageable && !isDead() (reviewer detail 1)
     * → spawn-protection skip (the R4 spawn-protection batch: no settlement and no cooldown consumed while protected,
     * attacks resume automatically once the window ends) → combat->attack against the player; a dead/invalid target
     * abandons the chase back to PATROL.
     */
    protected function onAttack(): void
    {
        if ($this->attackCooldown > 0) {
            $this->attackCooldown--;

            return;
        }

        $this->applyAggroSwitch();

        $targetId = $this->targetId();
        if ($targetId === null) {
            $this->setTarget(null);
            $this->enterState(self::STATE_PATROL);

            return;
        }

        $target = $this->actorLookup->getActor($targetId);
        if (!($target instanceof Damageable) || $target->isDead()) {
            // 目标已死/失效（P4b 收口）：先尝试从仇恨列表切换（死目标释放追击），无可切换目标才回 PATROL——
            // 否则怪物会追着尸体卡死（onChase 同款修复，攻击态的对称路径）。
            // A dead/invalid target (the P4b close-out): first try switching from the hate list (a dead target
            // releases the chase); only with no switchable target does it fall back to PATROL — otherwise the
            // monster would freeze on a corpse (the symmetric fix to onChase, here on the attack side).
            if ($this->threatTable !== null) {
                $this->applyAggroSwitch();
                if ($this->targetId() !== null) {
                    return; // 已切换目标，下个 tick 结算对切换目标的攻击 A switched target; the next tick settles the attack on it.
                }
            }
            $this->setTarget(null);
            $this->enterState(self::STATE_PATROL);

            return;
        }

        // 出生保护期：跳过本次攻击（不结算伤害也不置冷却），保持 ATTACK 态等待窗口结束
        // Spawn protection: skip this swing (no damage settled, no cooldown started), staying in ATTACK until the window ends
        if ($target instanceof PlayerActor && $target->isSpawnProtected()) {
            return;
        }

        // 攻击距离门（P8c）：attackRange > 0 且目标超出攻击距离——不结算本次攻击，回 CHASE 逼近
        //（下一 tick 的进入判定由距离门裁决）；0 = 缺省口径，攻击结算不做额外距离裁决（与接入前一致）。
        // The attack-range gate (the P8c): with attackRange > 0 and the target beyond the range — skip this
        // swing and fall back to CHASE to close in (the next tick's entry judgment is ruled by the gate);
        // 0 = the default convention, attack settlement adds no distance ruling (matching pre-integration).
        if ($this->attackRange > 0 && !$this->isTargetInRange($this->targetId() ?? '')) {
            $this->enterState(self::STATE_CHASE);

            return;
        }

        // 安全区门（P7c）：目标在安全区内不可攻击——与保护期同跳过口径（不结算、不置冷却）；
        // 怪物追进安全区边界后会在下个 tick 的目标失效/aggro 切换中放弃
        // The safe-zone gate (the P7c): a target inside the safe zone is unattackable — the same skip convention
        // as spawn protection (no settlement, no cooldown); a monster chasing into the zone boundary gives up via
        // the next tick's target invalidation / aggro switch.
        if ($target instanceof PlayerActor && $this->inSafeZone($this->world->getEntityManager()->get($this->targetId() ?? '')?->getPosition())) {
            return;
        }

        $this->combat->attack($this, $target);
        $this->attackCooldown = self::ATTACK_COOLDOWN_FRAMES;
    }

    /**
     * 死亡结算钩子：由 BaseMonster::takeDamage 模板方法在 hp 归零时幂等触发一次——
     * combat->spawnDrops（位置取绑定实体坐标；击杀归属绑定：以最后伤害来源解析击杀者 uid，
     * 无记录时回退追击目标）+ combat->broadcastDeath 广播 entity_dead + 死亡完整自清理
     * （AOI remove + entityManager remove + actorSystem remove + typeIndex remove + actorLookup removeActor，修复 MINOR-2 泄漏）。
     * Death settlement hook: triggered idempotently by the BaseMonster::takeDamage template method on hp zero —
     * combat->spawnDrops (position from the bound entity; kill-ownership binding resolves the killer's uid from the
     * last damage source, falling back to the chase target when unrecorded) + combat->broadcastDeath broadcasting
     * entity_dead + full death self-cleanup (AOI remove + entityManager remove + actorSystem remove + typeIndex
     * remove + actorLookup removeActor, fixing the MINOR-2 leak).
     */
    protected function onDeath(): void
    {
        $position = $this->entity?->getPosition() ?? ['x' => 0, 'y' => 0];
        $this->combat->spawnDrops($this->monsterId(), $position, $this->dropTable->roll($this->random), $this->resolveKillerUid());
        $this->combat->broadcastDeath($this);

        // 死亡完整清理：AOI/entityManager 摘掉怪物空间实体、actorSystem/$actors 摘掉 Actor、typeIndex 摘掉种类登记，
        // 保证死亡后实体与 Actor 五处无残留（entity_dead 已在上面广播，不再走 entity_leave 路径）。
        // Full death cleanup: remove the monster spatial entity from AOI/entityManager, the actor from actorSystem/$actors,
        // and the kind registration from typeIndex — no residue in any of the five registries (entity_dead is already
        // broadcast above, so no entity_leave path is needed).
        $monsterEntity = $this->entity;
        if ($monsterEntity !== null) {
            // 从视野提供者摘除（UniversalAOI::remove 为空操作）后删实体
            // Remove from the view provider (UniversalAOI::remove is a no-op) then delete the entity
            $this->world->getAOI()->remove($monsterEntity);
            $this->world->getEntityManager()->remove($this->monsterId());
        }
        $this->world->getActorSystem()->remove($this);
        $this->typeIndex->remove($this->monsterId());
        $this->actorLookup->removeActor($this->monsterId());
    }

    /**
     * 受击钩子（R4 mmorpg 威胁表接入）：威胁表启用时把攻击者记入仇恨列表（受击方记录攻击者威胁，
     * 供 aggro 选择切换目标）；未启用时零操作（行为与接入前逐字节等价）。
     * 距离门（P1 收口）：aggroRange 在装配层真正生效——按攻击者实体与受击方实体的欧氏距离过滤，
     * 超 aggroRange 不记威胁（攻击路由虽有 isNeighborIn 距离防线，AoE/直结算等旁路路径仍靠本门兜底）。
     * Hit hook (the R4 mmorpg threat-table integration): with a threat table the attacker enters the hate list
     * (the hit side records the attacker's threat, feeding the aggro target switch); without one this is a no-op
     * (byte-for-byte equivalent to the pre-integration behavior).
     * Distance gate (the P1 close-out): aggroRange takes effect at the assembly layer — the Euclidean distance
     * between the attacker's and the victim's entities filters the record (beyond aggroRange = no threat; the
     * attack route's isNeighborIn line is a first defense, but side paths such as AoE/direct settlement still rely
     * on this gate).
     */
    protected function onDamaged(?string $attackerId, int $amount): void
    {
        if ($this->threatTable === null || $attackerId === null) {
            return;
        }

        // 安全区门（P7c）：安全区内玩家的攻击不记威胁——否则玩家可从区内无反伤骚扰，安全语义破缺
        // The safe-zone gate (the P7c): attacks from players inside the safe zone record no threat — otherwise
        // players could harass from inside without retaliation, breaking the safety semantics.
        if ($this->inSafeZone($this->world->getEntityManager()->get($attackerId)?->getPosition())) {
            return;
        }

        $distance = $this->distanceToAttacker($attackerId);
        if ($distance === null) {
            return; // 攻击者实体不可解析（跨容器/已离场）→ 距离未知，不默认入仇恨列表 An unresolvable attacker (cross-container/gone) → unknown distance never enters the hate list.
        }
        $this->threatTable->addThreat($attackerId, (float) $amount, $distance);
    }

    /**
     * 位置是否落在出生安全区内（P7c）：safeZone 未声明恒 false（零门禁）。
     * Whether the position falls inside the spawn safe zone (the P7c): always false without a declared safeZone
     * (no gates).
     *
     * @param array{x: int, y: int}|null $position 待判定坐标（null = 实体不可解析，恒在区外） The position to judge
     *   (null = the entity is unresolvable, always outside).
     */
    private function inSafeZone(?array $position): bool
    {
        $zone = $this->safeZone;
        if ($zone === null || $position === null) {
            return false;
        }

        return hypot($position['x'] - $zone['x'], $position['y'] - $zone['y']) <= $zone['radius'];
    }

    /**
     * 受击方到攻击者的欧氏距离（世界单位）：双方经世界实体管理器定位；攻击者实体不可解析返回 null。
     * The Euclidean distance from the victim to the attacker (world units): both resolve through the world
     * entity manager; null when the attacker's entity is unresolvable.
     */
    private function distanceToAttacker(string $attackerId): ?float
    {
        $entity = $this->entity;
        $attackerEntity = $this->world->getEntityManager()->get($attackerId);
        if ($entity === null || $attackerEntity === null) {
            return null;
        }

        $mine = $entity->getPosition();
        $theirs = $attackerEntity->getPosition();
        $dx = (float) ($theirs['x'] - $mine['x']);
        $dy = (float) ($theirs['y'] - $mine['y']);

        return sqrt($dx * $dx + $dy * $dy);
    }

    /**
     * 嘲讽结算（P4b 接入，关闭 P1 预留）：把嘲讽威胁量写入目标威胁表（tauntMultiplier 倍率裁决归
     * ThreatTable::applyTaunt）。威胁表未启用（mmorpg 关）或攻击者 id 非法时零操作。
     * Taunt settlement (the P4b wiring, closing the P1 reservation): writes the taunt threat amount into the
     * target's threat table (the tauntMultiplier adjudication stays inside ThreatTable::applyTaunt). A no-op
     * without a threat table (mmorpg off) or with an invalid attacker id.
     */
    public function applyTaunt(string $taunterId, float $amount): void
    {
        if ($amount <= 0.0 || $this->threatTable === null) {
            return;
        }
        // 安全区门（P7c）：安全区内玩家的嘲讽不写入——区内玩家对怪物 AI 不可见，威胁写入即破缺
        // The safe-zone gate (the P7c): a taunt from a player inside the safe zone is ignored — players inside
        // are invisible to monster AI, and a threat write would break that.
        if ($this->inSafeZone($this->world->getEntityManager()->get($taunterId)?->getPosition())) {
            return;
        }
        $this->threatTable->applyTaunt($taunterId, $amount);
    }

    /**
     * 威胁衰减（R4 mmorpg）：由装配层定时驱动（世界 tick 每帧调用，dt = 帧时长）；
     * 威胁表未启用时零操作。
     * Threat decay (the R4 mmorpg): driven by the assembly layer's timer (invoked every world tick, dt = the frame
     * duration); a no-op without a threat table.
     */
    public function decayThreats(float $dt): void
    {
        $this->threatTable?->decay($dt);
    }

    /**
     * 目标离场钩子（R4 CHASE 卡滞修复）：目标实体离开本容器（transfer 进房等跨容器编排）时由装配层通知。
     * 目标 Actor 仍在共享 $actors 表（跨容器可解析），但已不在本容器 EM——CHASE 的 moveTowardTarget 查空
     * 原地 no-op、ATTACK 甚至可跨容器继续结算伤害；匹配即清目标回 PATROL，非当前目标幂等无操作。
     * Target-left hook (the R4 CHASE-stall fix): invoked by the assembly layer when the target entity leaves this
     * container (cross-container orchestration such as transferring into a room). The target actor remains in the
     * shared actors table (resolvable across containers) but is gone from this container's EM — CHASE's
     * moveTowardTarget would no-op in place forever, and ATTACK could even keep settling damage cross-container;
     * on a match the target clears back to PATROL, and other targets are an idempotent no-op.
     */
    public function onTargetLeft(string $entityId): void
    {
        if ($this->targetId() !== $entityId) {
            return;
        }

        $this->setTarget(null);
        if ($this->aiState() === self::STATE_CHASE || $this->aiState() === self::STATE_ATTACK) {
            $this->enterState(self::STATE_PATROL);
        }
    }

    /**
     * 解析击杀者 uid（掉落归属绑定）：优先最后伤害来源，无记录回退追击目标；
     * 来源 Actor 非玩家或未绑定 uid 时返回 null（生成无归属掉落）。
     * Resolves the killer's uid (drop-ownership binding): the last damage source first, falling back to the chase
     * target when unrecorded; returns null when the source actor is not a player or carries no bound uid
     * (an unowned drop is spawned).
     */
    private function resolveKillerUid(): ?string
    {
        $sourceId = $this->lastAttacker() ?? $this->targetId();
        if ($sourceId === null) {
            return null;
        }

        $source = $this->actorLookup->getActor($sourceId);

        return $source instanceof PlayerActor ? $source->uid() : null;
    }

    /**
     * 感知视野内第一个玩家实体 id（经 typeIndex 判定种类，非 instanceof）。
     * 出生保护跳过（R4 出生保护批）：保护期内玩家对怪物 AI 不可见——不感知、不追击，
     * 防止怪压到出生格边界等待窗口结束（保护语义是「出生不被打扰」而非仅「免伤」）。
     * Perceives the id of the first player entity in view (kind judged via typeIndex, not instanceof).
     * Spawn-protection skip (the R4 spawn-protection batch): a protected player is invisible to monster AI —
     * never perceived, never chased — so monsters do not press against the spawn cell waiting out the window
     * (protection means "undisturbed spawn", not merely "damage immunity").
     */
    private function perceivePlayer(): ?string
    {
        $entity = $this->entity;
        if ($entity === null) {
            return null;
        }

        // 视野查询统一为 AOI：GridAOI 给九宫格邻居，UniversalAOI（全量世界）给全实体表——全量可见 = 全部可感知
        // The view query is always the AOI: GridAOI yields 3x3 neighbors, UniversalAOI (full world) yields the
        // whole entity table — under full visibility everything is perceivable
        foreach ($this->world->getAOI()->query($entity) as $neighbor) {
            if ($neighbor->getId() === $this->monsterId()) {
                continue;
            }
            if ($this->typeIndex->kindOf($neighbor->getId()) !== EntityTypeIndex::KIND_PLAYER) {
                continue;
            }
            $actor = $this->actorLookup->getActor($neighbor->getId());
            if ($actor instanceof PlayerActor && $actor->isSpawnProtected()) {
                continue;
            }
            // 安全区门（P7c）：区内玩家不可感知——与出生保护同语义（「出生不被打扰」的常驻版）
            // The safe-zone gate (the P7c): players inside are imperceivable — the standing twin of the spawn
            // protection's "undisturbed spawn" semantics.
            if ($this->inSafeZone($neighbor->getPosition())) {
                continue;
            }

            return $neighbor->getId();
        }

        return null;
    }

    /**
     * 目标是否在攻击范围内：九宫格 AOI 查询包含目标即视为在范围内。
     * Whether the target is inside attack range: the 3x3 AOI query containing the target counts as in range.
     */
    private function isTargetInRange(string $targetId): bool
    {
        $entity = $this->entity;
        if ($entity === null) {
            return false;
        }

        // 统一走视野查询：GridAOI 查九宫格，UniversalAOI（全量世界）查全表——目标在视野内即命中
        // （全量可见下目标存在即在范围内，无距离概念）
        // Unified view query: GridAOI scans the 3x3, UniversalAOI (full world) scans the whole table —
        // a target inside the view hits (under full visibility an existing target is in range; no distance)
        $inView = false;
        foreach ($this->world->getAOI()->query($entity) as $neighbor) {
            if ($neighbor->getId() === $targetId) {
                $inView = true;

                break;
            }
        }
        if (!$inView) {
            return false;
        }

        // 攻击距离门（P8c）：attackRange > 0 时在视野命中之上叠加欧氏距离上限——把格级粗粒度的视野裁决
        // 细化为精确距离裁决；0 = 缺省口径（视野命中即命中，与接入前逐字节等价）。
        // The attack-range gate (the P8c): with attackRange > 0 a Euclidean cap stacks on the view hit —
        // refining the cell-granularity view verdict into an exact distance ruling; 0 = the default convention
        // (a view hit is a hit).
        if ($this->attackRange > 0) {
            $targetEntity = $this->world->getEntityManager()->get($targetId);
            if ($targetEntity === null) {
                return false;
            }
            $mine = $entity->getPosition();
            $theirs = $targetEntity->getPosition();
            if (hypot($theirs['x'] - $mine['x'], $theirs['y'] - $mine['y']) > $this->attackRange) {
                return false;
            }
        }

        return true;
    }

    /**
     * 朝目标方向移动一格（x/y 各取符号方向，简化的格点逼近），移动后广播 entity_moved。
     * Moves one cell toward the target (sign direction per axis, a simplified grid approximation), broadcasting entity_moved after the move.
     */
    private function moveTowardTarget(string $targetId): void
    {
        $entity = $this->entity;
        if ($entity === null) {
            return;
        }

        $targetEntity = $this->world->getEntityManager()->get($targetId);
        if ($targetEntity === null) {
            return;
        }

        $self = $entity->getPosition();
        $other = $targetEntity->getPosition();
        $dx = $other['x'] <=> $self['x'];
        $dy = $other['y'] <=> $self['y'];
        if ($dx !== 0 || $dy !== 0) {
            // 追击位移同样受出生锚活动域约束：预览落点越界则放弃本步移动（怪物守家，
            // 不被引诱出出生视野域——否则追击可无限走远，死亡/掉落广播无人可收）。
            // Chase displacement is bounded by the same spawn-anchor domain: a tentative landing beyond it abandons the
            // step (monsters hold their ground and are never lured out of their spawn vision — an unbounded chase could
            // walk arbitrarily far, leaving death/drop broadcasts with no receiver).
            if ($this->exceedsAnchor($self, (int) $dx, (int) $dy)) {
                return;
            }
            $entity->move($dx, $dy);
            $this->broadcastMove($entity);
        }
    }

    /**
     * 预览落点是否越出出生锚活动域（巡逻与追击共用的位移闸门）。
     * Whether the tentative landing falls outside the spawn-anchor roam domain (the shared gate for patrol and chase).
     *
     * @param array{x: int, y: int} $pos 当前位置 Current position.
     */
    private function exceedsAnchor(array $pos, int $dx, int $dy): bool
    {
        return abs($pos['x'] + $dx - $this->patrolAnchor['x']) > $this->patrolRadius
            || abs($pos['y'] + $dy - $this->patrolAnchor['y']) > $this->patrolRadius;
    }

    /**
     * 当前位置是否已越出出生锚活动域（界外自愈判定的前提）。
     * Whether the current position is already outside the spawn-anchor roam domain (the self-heal precondition).
     *
     * @param array{x: int, y: int} $pos 当前位置 Current position.
     */
    private function beyondAnchor(array $pos): bool
    {
        return abs($pos['x'] - $this->patrolAnchor['x']) > $this->patrolRadius
            || abs($pos['y'] - $this->patrolAnchor['y']) > $this->patrolRadius;
    }

    /**
     * 该步位移是否朝锚回归（到锚的曼哈顿距离严格减小）。
     * Whether the step walks back toward the anchor (strictly decreasing Manhattan distance to it).
     *
     * @param array{x: int, y: int} $pos 当前位置 Current position.
     */
    private function stepsTowardAnchor(array $pos, int $dx, int $dy): bool
    {
        $before = abs($pos['x'] - $this->patrolAnchor['x']) + abs($pos['y'] - $this->patrolAnchor['y']);
        $after = abs($pos['x'] + $dx - $this->patrolAnchor['x']) + abs($pos['y'] + $dy - $this->patrolAnchor['y']);

        return $after < $before;
    }

    /**
     * 广播移动帧 entity_moved{id, position}（视野）：payload 与 MapServer::handleMove 的玩家移动帧同构，
     * 客户端无需区分移动来源（无 move 回执，以广播为准）。
     * Broadcasts the entity_moved{id, position} frame (view): the payload is isomorphic to the player-move frame
     * in MapServer::handleMove, so clients need not distinguish the movement source (no move ack — the broadcast is authoritative).
     */
    private function broadcastMove(EntityInterface $entity): void
    {
        $this->broadcaster->broadcastToVision($entity->getId(), 'entity_moved', [
            'id' => $entity->getId(),
            'position' => $entity->getPosition(),
        ]);
    }

    /**
     * aggro 切换（R4 mmorpg 威胁表接入）：威胁表启用时——先清理已死/离场的威胁者（仇恨列表不残留尸体），
     * 再按仇恨列表选择最高威胁者：与当前目标不同即切换；**仇恨列表空时清空目标**（否则旧目标残留——
     * 调用方以 targetId 非空判断「已切换」，怪物会永久锁死在旧目标上，死目标/全员离场即卡死；
     * E2E step6 实测暴露）。
     * Aggro switch (the R4 mmorpg threat-table integration): with a threat table — first purge dead/departed
     * threat sources (no corpses linger in the hate list), then pick the highest threat and switch when it differs
     * from the current target; **an empty hate list clears the target** (otherwise the stale target lingers — the
     * callers judge "switched" by a non-null targetId, freezing the monster on the old target forever once it is
     * dead or everyone departed; surfaced by the E2E's step6).
     */
    private function applyAggroSwitch(): void
    {
        $table = $this->threatTable;
        if ($table === null) {
            return;
        }

        foreach ($table->all() as $actorId => $threat) {
            $actor = $this->actorLookup->getActor($actorId);
            if (!$actor instanceof Damageable || $actor->isDead()) {
                $table->remove($actorId);

                continue;
            }
            // 安全区清理（P7c）：区内玩家不参与 aggro 选择——否则怪物会把区内目标选为追击对象，
            // 追至安全区边界后卡在「攻击被跳过」态
            // The safe-zone purge (the P7c): players inside the zone never participate in aggro selection —
            // otherwise monsters would chase them into the zone boundary and stall on skipped attacks.
            if ($actor instanceof PlayerActor
                && $this->inSafeZone($this->world->getEntityManager()->get($actorId)?->getPosition())) {
                $table->remove($actorId);
            }
        }

        $aggro = $table->selectTarget();
        if ($aggro === null) {
            $this->setTarget(null);

            return;
        }
        if ($aggro !== $this->targetId()) {
            $this->setTarget($aggro);
        }
    }
}
