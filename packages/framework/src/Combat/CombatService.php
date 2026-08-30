<?php

declare(strict_types=1);

namespace Nythros\Framework\Combat;

use Nythros\Contracts\ShapeInterface;
use Nythros\Contracts\WorldInterface;
use Nythros\Framework\Actor\PlayerActor;
use Nythros\Framework\BaseMonster;
use Nythros\Framework\Damageable;
use Nythros\Framework\Event\EventDispatcherInterface;
use Nythros\Framework\Game\Mmorpg\MmorpgConfig;
use Nythros\Framework\Inventory;
use Nythros\Framework\Plugin\Item\ItemRepository;
use Nythros\Framework\Plugin\Skill\SkillRepository;

/**
 * 战斗服务：普攻/技能伤害结算、死亡掉落生成与拾取结算（纯业务，可单测）。
 * Combat service: normal-attack/skill damage settlement, death-drop spawning and pickup settlement (pure business logic, unit-testable).
 *
 * 冷却/距离/存活前置校验在调用方（MapServer.handleAttack/handleSkillCast、MonsterActor.onAttack）完成；
 * 死亡结算由基类 takeDamage 模板方法闭环（CombatService 只做 isDead 判断与广播，不跨类触碰 onDeath）。
 * Cooldown/range/alive preconditions are validated by the callers (MapServer.handleAttack/handleSkillCast, MonsterActor.onAttack);
 * death settlement is closed inside the base takeDamage template method (CombatService only checks isDead and broadcasts, never touching onDeath across classes).
 */
final class CombatService
{
    /** 普攻基础伤害（随机浮动前的基准值） Base damage of a normal attack (the baseline before random variance). */
    private const BASE_ATTACK_DAMAGE = 10;

    /** 伤害浮动下界（百分比） Damage variance lower bound (percent). */
    private const DAMAGE_FLOAT_MIN = 80;

    /** 伤害浮动上界（百分比） Damage variance upper bound (percent). */
    private const DAMAGE_FLOAT_MAX = 120;

    /** 击杀埋点事件名（R3 玩法批 D4 缺口补埋：任务等横切消费方监听）。 The kill-instrumentation event name (the R3 gameplay batch's D4-gap instrumentation; cross-cutting consumers such as quests listen). */
    public const EVENT_KILL = 'combat.kill';

    /** 拾取埋点事件名。 The pickup-instrumentation event name. */
    public const EVENT_PICKUP = 'combat.pickup';

    /** 掉落物 id 序号：保证同进程内 spawnDrops 生成的 drop id 唯一 Drop id sequence: keeps spawned drop ids unique within the process. */
    private int $dropSequence = 0;

    /** @var array<string, true> 在场掉落登记表（dropId => true）：过期回收扫描的遍历域，拾取/回收时摘除。 Live-drop registry (dropId => true): the sweep domain of expiry reclamation, removed on pickup/reclaim. */
    private array $liveDrops = [];

    /**
     * 掉落攒批窗口缓冲（ADR-024 §D-D）：null = 非窗口期；list = 窗口开启中——窗口内 spawnDrops 只做
     * EM/AOI 索引登记，出生通知（逐条 drop:spawned 与 entity_enter 补发）并入关窗时的单条 drop:spawned_batch。
     * Drop-batch window buffer (ADR-024 §D-D): null = outside any window; a list = window open — inside the
     * window spawnDrops only registers indexes, and birth notices (per-drop drop:spawned and entity_enter
     * back-fill) merge into one drop:spawned_batch emitted when the window closes.
     *
     * @var list<array{dropId: string, itemId: string, x: int, y: int}>|null
     */
    private ?array $dropBatch = null;

    /**
     * 死亡攒批窗口缓冲（ADR-024 §9 V5）：与掉落窗口同构、同开同关——窗口内 broadcastDeath 只登记
     * （id/位置/种类在登记时点捕获：死亡自清理会先于关窗摘除实体，关窗时已无从解析），逐条 entity_dead
     * 取消并入关窗时的单条 entity_dead_batch（并行等长标量列表 ids/positions/types）。
     * Death-batch window buffer (ADR-024 §9 V5): isomorphic to the drop window, opened and closed with it —
     * inside the window broadcastDeath only buffers (id/position/kind are captured at buffering time: the death
     * self-cleanup removes the entity before the window closes, when it would be unresolvable), per-target
     * entity_dead frames are cancelled into a single entity_dead_batch on close (parallel equal-length scalar
     * lists ids/positions/types).
     *
     * @var list<array{id: string, x: int, y: int, type: string}>|null
     */
    private ?array $deathBatch = null;

    /**
     * @param WorldInterface $world 世界门面（EntityManager/AOI 存取） World facade (entity/AOI access).
     * @param VisionBroadcasterInterface $broadcaster 视野/定向广播接口（MapServer 实现） Vision/directed broadcast interface (implemented by MapServer).
     * @param SkillRepository $skills 技能注册表 Skill repository.
     * @param ItemRepository $items 物品注册表 Item repository.
     * @param RandomSourceInterface $random 随机源 Random source.
     * @param null|ActorLookupInterface $actorLookup 实体 id → Damageable 解析表（AoE 命中结算依赖；
     *   缺省 null = 未装配，castSkillAoE 抛 LogicException） Entity-id → Damageable resolution table (the AoE hit
     *   settlement depends on it; default null = not assembled, castSkillAoE throws a LogicException).
     * @param ?EntityTypeIndex $typeIndex 实体类型索引（D5 债务关闭：spawnDrops 登记 KIND_DROP、拾取/过期回收
     *   摘除，与玩家/怪物登记口径统一；缺省 null = 不登记） Entity-type index (closing the D5 debt: spawnDrops
     *   registers KIND_DROP and pickup/expiry-reclaim remove it, unified with the player/monster registrations;
     *   default null = no registration).
     * @param ?TeamMembershipInterface $teams 队伍归属查询（掉落同队共享拾取权判定；缺省 null = 仅 uid 归属判定）
     *   Team-membership lookup (the same-team pickup predicate of drop ownership; default null = uid-only ownership).
     * @param int $dropLifetimeSeconds 掉落物存活秒数（过期定时回收；0 = 永不过期） Drop lifetime in seconds (periodic expiry reclamation; 0 = never expires).
     * @param ?EventDispatcherInterface $events 应用级事件派发器（R3 玩法批 D4 缺口补埋：击杀/拾取路径发布
     *   combat.kill / combat.pickup 业务事件；缺省 null = 不派发，既有直接调用零影响）
     *   The application-level event dispatcher (the R3 gameplay batch's D4-gap instrumentation: the kill/pickup
     *   paths publish combat.kill / combat.pickup business events; default null = no dispatch, zero impact on
     *   existing direct calls).
     * @param string $killCredit 击杀归属裁决（P13 AoE 多源归属）：last_hit = 最后一击来源（接入前语义，
     *   缺省）；damage_leader = 伤害账本最高者（平局取先达）。构造期白名单校验 fail-fast。
     *   The kill-credit ruling (the P13 AoE multi-source attribution): last_hit = the last-hit source (the
     *   pre-integration semantics, the default); damage_leader = the damage-ledger leader (ties take the first
     *   reached). Constructor-time whitelist validation fails fast.
     * @param \Closure|null $pvpGate PVP 对抗门（P13 对抗治理，AoE 命中管线消费）：签名
     *   fn(Damageable $attacker, Damageable $target): (string|null)——返回非 null 拒绝码（如
     *   pvp_disabled/in_safe_zone）的目标被静默跳过——不出现在命中结算与 combat:aoe 列表；缺省 null = 无门
     *   （接入前语义）。普攻/单体技能的路由级门由调用方（MapServer）在结算前置位。
     *   The PVP combat gate (the P13 governance, consumed by the AoE hit pipeline): the signature is
     *   fn(Damageable $attacker, Damageable $target): (string|null) — a target whose gate returns a non-null
     *   rejection code (e.g. pvp_disabled/in_safe_zone) is silently skipped — never entering the hit settlement
     *   nor the combat:aoe list; default null = no gate (the pre-integration semantics). Route-level gates for
     *   normal attacks and single-target skills are the caller's duty (MapServer), applied pre-settlement.
     */
    public function __construct(
        private readonly WorldInterface $world,
        private readonly VisionBroadcasterInterface $broadcaster,
        private readonly SkillRepository $skills,
        private readonly ItemRepository $items,
        private readonly RandomSourceInterface $random,
        private readonly ?ActorLookupInterface $actorLookup = null,
        private readonly ?EntityTypeIndex $typeIndex = null,
        private readonly ?TeamMembershipInterface $teams = null,
        private readonly int $dropLifetimeSeconds = 300,
        private readonly ?EventDispatcherInterface $events = null,
        private readonly string $killCredit = MmorpgConfig::KILL_CREDIT_LAST_HIT,
        private readonly ?\Closure $pvpGate = null,
    ) {
        if (!in_array($this->killCredit, [MmorpgConfig::KILL_CREDIT_LAST_HIT, MmorpgConfig::KILL_CREDIT_DAMAGE_LEADER], true)) {
            throw new \InvalidArgumentException('CombatService killCredit 必须为 last_hit|damage_leader 之一 / CombatService requires killCredit to be last_hit or damage_leader');
        }
    }

    /**
     * 普攻结算（双向：玩家→怪物 / 怪物→玩家）：伤害 = 基础 × random 浮动 → target->takeDamage →
     * 广播 combat:hit；目标死亡时广播 entity_dead（BaseMonster 的 onDeath 已自行广播，此处跳过防重复）。
     * Normal-attack settlement (bidirectional: player→monster / monster→player): damage = base × random variance
     * → target->takeDamage → broadcast combat:hit; on target death broadcast entity_dead (BaseMonster's onDeath
     * already broadcasts, so it is skipped here to avoid duplicates).
     */
    public function attack(Damageable $attacker, Damageable $target): void
    {
        $damage = $this->rollDamage(self::BASE_ATTACK_DAMAGE);
        $this->noteAttackerOn($attacker, $target, $damage);
        $target->takeDamage($damage);

        $attackerId = $this->combatantId($attacker);
        $this->broadcaster->broadcastToVision($attackerId, 'combat:hit', [
            'attackerId' => $attackerId,
            'targetId' => $this->combatantId($target),
            'damage' => $damage,
            'hp' => $target->hp(),
        ]);

        if ($target->isDead()) {
            $this->notifyKill($attacker, $target);
            if (!($target instanceof BaseMonster)) {
                $this->broadcastDeath($target);
            }
        }
    }

    /**
     * 技能结算：查 SkillRepository → 伤害 = 普攻 × damageMultiplier × random 浮动 → 同 attack 结算。
     * Skill settlement: look up the SkillRepository → damage = base attack × damageMultiplier × random variance → settles like attack.
     *
     * @param string $skillId 技能 id（未注册时静默返回，调用方已前置校验） The skill id (unregistered ids return silently; callers pre-validate).
     */
    public function castSkill(Damageable $caster, string $skillId, Damageable $target): void
    {
        $skill = $this->skills->get($skillId);
        if ($skill === null) {
            return;
        }

        $damage = (int) round($this->rollDamage(self::BASE_ATTACK_DAMAGE) * $skill->damageMultiplier);
        $this->noteAttackerOn($caster, $target, $damage);
        $target->takeDamage($damage);

        $casterId = $this->combatantId($caster);
        $this->broadcaster->broadcastToVision($casterId, 'skill:cast', [
            'casterId' => $casterId,
            'skillId' => $skillId,
            'targetId' => $this->combatantId($target),
        ]);
        $this->broadcaster->broadcastToVision($casterId, 'combat:hit', [
            'attackerId' => $casterId,
            'targetId' => $this->combatantId($target),
            'damage' => $damage,
            'hp' => $target->hp(),
        ]);

        if ($target->isDead()) {
            $this->notifyKill($caster, $target);
            if (!($target instanceof BaseMonster)) {
                $this->broadcastDeath($target);
            }
        }
    }

    /**
     * AoE 批量命中管线（ADR-024 §D-C）：1 次 queryShape（引擎原语，形状查询归引擎）→ N 次 takeDamage
     * （伤害结算/命中校验归 framework：无 Actor/非战斗体/已死实体过滤）→ 1 次 combat:aoe 合并广播
     * （攒批出帧，杜绝逐目标 N 次 combat:hit 广播）。死亡结算复用既有 takeDamage 模板路径（onDeath 幂等触发），
     * 连锁死亡经死亡攒批窗口合并为单条 entity_dead_batch（见 broadcastDeath，ADR-024 §9 V5）——怪物受害者经
     * onDeath 自行广播、非怪物受害者（如被他人 AoE 击杀的房内玩家）由结算补 broadcastDeath（与 attack/castSkill
     * 同口径），两类均入批；连锁掉落经掉落攒批窗口合并为单条 drop:spawned_batch（见 spawnDropsBatch）。
     * AoE batch-hit pipeline (ADR-024 §D-C): one queryShape (the engine primitive owns shape queries) → N takeDamage
     * calls (damage settlement/hit validation stay in the framework: actors missing, non-combatants and dead entities
     * are filtered) → one merged combat:aoe broadcast (batched frame out; per-target combat:hit floods eliminated).
     * Death settlement reuses the existing takeDamage template path (onDeath fires idempotently); chained deaths merge
     * into a single entity_dead_batch through the death-batch window (see broadcastDeath, ADR-024 §9 V5) — monster
     * victims broadcast via their own onDeath while non-monster victims (e.g. an in-room player killed by another
     * player's AoE) are back-broadcast by the settlement (matching attack/castSkill), both batching; chained drops
     * merge into a single drop:spawned_batch through the drop-batch window (see spawnDropsBatch).
     *
     * @param Damageable $caster 施法者 The caster.
     * @param string $skillId 技能 id（未注册时静默返回，与 castSkill 口径一致） The skill id (unregistered ids return silently, matching castSkill).
     * @param ShapeInterface $shape 命中形状（Circle/Rectangle/Sector 值对象） The hit shape (Circle/Rectangle/Sector value objects).
     * @return list<array{targetId: string, damage: int, hp: int}> 命中结算结果（供调用方观测/断言） Hit-settlement results (for caller observation/assertions).
     */
    public function castSkillAoE(Damageable $caster, string $skillId, ShapeInterface $shape): array
    {
        $skill = $this->skills->get($skillId);
        if ($skill === null) {
            return [];
        }
        if ($this->actorLookup === null) {
            throw new \LogicException('castSkillAoE 需要组装层注入 ActorLookupInterface / castSkillAoE requires an assembled ActorLookupInterface');
        }

        $casterId = $this->combatantId($caster);

        // 开启掉落/死亡攒批窗口：AoE 连锁死亡（takeDamage → onDeath → spawnDrops/broadcastDeath）在窗口内只登记
        // 索引不出帧；try/finally 保证关窗/复位——结算链路抛非 Argument/Logic 异常时清缓冲关窗，避免窗口泄漏后
        // 后续出生/死亡通知被静默吞入永不广播的缓冲（R2 审查 MINOR-3）
        // Open the drop/death batch windows: chained AoE deaths (takeDamage → onDeath → spawnDrops/broadcastDeath)
        // only register indexes inside them; try/finally guarantees closing/resetting — when the settlement chain throws
        // a non-Argument/Logic exception the buffers are cleared and the windows closed, keeping a leaked window from
        // silently swallowing later birth/death notices into a never-broadcast buffer (R2 review MINOR-3)
        $this->dropBatch = [];
        $this->deathBatch = [];

        try {
            $hits = [];
            foreach ($this->world->getAOI()->queryShape($shape) as $entity) {
                if ($entity->getId() === $casterId) {
                    continue; // 施法者不受自身 AoE 伤害 The caster never takes its own AoE damage.
                }

                $target = $this->actorLookup->getActor($entity->getId());
                // 命中校验归 framework（ADR-024 §5 边界矩阵）：非战斗体/已死实体跳过
                // Hit validation stays in the framework (ADR-024 §5 boundary matrix): non-combatants and dead entities are skipped
                if (!$target instanceof Damageable || $target->isDead()) {
                    continue;
                }

                // PVP 对抗门（P13）：被门挡下的目标静默跳过——不结算、不出现在 combat:aoe 命中列表
                // （拒绝不广播：AoE 是范围结算，逐目标定向错误帧会退回洪泛，与合并帧语义相悖）
                // The PVP combat gate (the P13): gate-rejected targets are silently skipped — no settlement,
                // absent from the combat:aoe hit list (rejections are not broadcast: AoE is an area settlement,
                // and per-target directed error frames would regress into the flooding the merged frame exists to prevent)
                if ($this->pvpGate !== null && ($this->pvpGate)($caster, $target) !== null) {
                    continue;
                }

                $damage = (int) round($this->rollDamage(self::BASE_ATTACK_DAMAGE) * $skill->damageMultiplier);
                $this->noteAttackerOn($caster, $target, $damage);
                $target->takeDamage($damage);
                if ($target->isDead()) {
                    $this->notifyKill($caster, $target);
                    if (!($target instanceof BaseMonster)) {
                        // 非怪物目标（如被他人 AoE 击杀的房内玩家）：BaseMonster 经 onDeath 自行广播，
                        // 其余战斗体由结算补广播——与 attack/castSkill 同口径；死亡窗口开启时自动入批
                        // Non-monster targets (e.g. an in-room player killed by another player's AoE): BaseMonster
                        // broadcasts via its own onDeath, other combatants are broadcast by the settlement — matching
                        // attack/castSkill; inside the death-batch window this buffers automatically
                        $this->broadcastDeath($target);
                    }
                }
                $hits[] = ['targetId' => $entity->getId(), 'damage' => $damage, 'hp' => $target->hp()];
            }

            // 关窗：连锁死亡合并为一条 entity_dead_batch、连锁掉落合并为一条 drop:spawned_batch
            // （死亡帧先于掉落批量帧入队，同帧 FIFO 保序——ADR-024 §4 时序口径）；空批各自静默
            // Close the windows: chained deaths merge into one entity_dead_batch and chained drops into one
            // drop:spawned_batch (death frames enqueue before the drop batch frame, same-frame FIFO ordering —
            // ADR-024 §4's timing contract); empty batches stay silent each on their own
            $this->flushDeathBatch($casterId);
            $this->flushDropBatch($casterId);
        } finally {
            // 正常路径 flush 已复位，此处幂等；异常路径兜底清缓冲关窗（半途数据不广播残缺帧）
            // The happy path already reset via the flushes (idempotent here); the exceptional path clears the
            // buffers and closes as a backstop (halfway data never broadcasts as a partial frame)
            $this->dropBatch = null;
            $this->deathBatch = null;
        }

        // 合并结果帧一次广播；空集仍发 cast 回执（ADR-024 §5 边界矩阵：AoE 空集=仍发 cast 回执）
        // One merged result-frame broadcast; an empty hit set still emits the cast receipt (ADR-024 §5 boundary matrix)
        $this->broadcaster->broadcastToVision($casterId, 'combat:aoe', [
            'casterId' => $casterId,
            'skillId' => $skillId,
            'targetIds' => array_column($hits, 'targetId'),
            'damages' => array_column($hits, 'damage'),
            'hps' => array_column($hits, 'hp'),
        ]);

        return $hits;
    }

    /**
     * 死亡掉落：在 monsterId 实体位置为每个掉落生成 DropEntity——itemId 经 items 校验（非法跳过）→
     * entityManager->add + aoi->updateEntity → 对 entered 差分补发 entity_enter（附 itemId，ADR-017 §8.7）→ 广播 drop:spawned。
     * 击杀归属绑定（R3 经济批模块 2）：killerUid 非空时 DropEntity 绑定 ownerUid/ownerTeamId（队伍经 teams 查询），
     * 拾取校验归属；killerUid 为 null（如 AoE 连锁死亡未及记录来源）生成无归属掉落，自由拾取。
     * 过期登记：dropLifetimeSeconds > 0 时写入 expiresAt，由 purgeExpiredDrops 定时回收。
     * D5 债务关闭（ADR-017 §8.3/§8.6 矛盾裁决：以登记为准）：spawnDrops 向 typeIndex 登记 KIND_DROP，
     * 与玩家/怪物登记口径统一；拾取/过期回收同步摘除。
     * Death drops: at the monsterId entity position, spawn one DropEntity per drop — itemId validated via items (invalid ids are skipped)
     * → entityManager->add + aoi->updateEntity → back-fill entity_enter (carrying itemId, ADR-017 §8.7) over the entered delta → broadcast drop:spawned.
     * Kill-ownership binding (economy-batch module 2): a non-null killerUid binds ownerUid/ownerTeamId onto the
     * DropEntity (team resolved via teams) and pickup validates ownership; a null killerUid (e.g. an AoE chained
     * death with no recorded source) spawns unowned drops, free for anyone. Expiry registration: when
     * dropLifetimeSeconds > 0, expiresAt is written and purgeExpiredDrops reclaims periodically.
     * D5 debt closed (the ADR-017 §8.3/§8.6 contradiction ruled in favor of registration): spawnDrops registers
     * KIND_DROP into the typeIndex, unified with the player/monster registrations; pickup/expiry-reclaim remove it.
     *
     * 攒批窗口内（castSkillAoE/spawnDropsBatch 开启）：索引登记照常，逐条出生通知取消并入批量帧。
     * Inside a batch window (opened by castSkillAoE/spawnDropsBatch): index registration proceeds as usual while
     * per-drop birth notices are cancelled into the merged batch frame.
     *
     * @param string $monsterId 掉落来源怪物 id The drop-source monster id.
     * @param array{x: int, y: int} $position 掉落位置 Drop position.
     * @param list<array{itemId: string, count: int}> $drops 掉落结果（dropTable roll 的输出） Drop results (the dropTable roll output).
     * @param ?string $killerUid 击杀者 uid（归属绑定；null = 无归属自由拾取） The killer's uid (ownership binding; null = unowned free pickup).
     * @param ?int $lifetimeSecondsOverride 存活秒数覆盖（P13 死亡掉落归属窗口：策略窗口 ≠ 怪物掉落全局寿命；
     *   null = 用构造期 dropLifetimeSeconds）。 The lifetime-seconds override (the P13 death-drop ownership window:
     *   the policy's window differs from the monster-drop global lifetime; null = the constructor's dropLifetimeSeconds).
     */
    public function spawnDrops(string $monsterId, array $position, array $drops, ?string $killerUid = null, ?int $lifetimeSecondsOverride = null): void
    {
        $ownerTeamId = $killerUid !== null ? $this->teams?->teamOf($killerUid) : null;
        $lifetimeSeconds = $lifetimeSecondsOverride ?? $this->dropLifetimeSeconds;
        $expiresAt = $lifetimeSeconds > 0 ? microtime(true) + $lifetimeSeconds : null;

        foreach ($drops as $drop) {
            $itemId = $drop['itemId'];
            if ($this->items->get($itemId) === null) {
                continue; // 未注册 itemId：跳过不生成 Illegal item id: skipped, nothing is spawned.
            }

            $dropId = sprintf('drop-%s-%d', $monsterId, ++$this->dropSequence);
            $dropEntity = new DropEntity($dropId, $position['x'], $position['y'], $itemId, $drop['count'], $killerUid, $ownerTeamId, $expiresAt);
            $this->world->getEntityManager()->add($dropEntity);
            $this->liveDrops[$dropId] = true;
            $this->typeIndex?->set($dropId, EntityTypeIndex::KIND_DROP);
            // 登记进视野提供者：UniversalAOI（全量世界）恒返回空差分——没有「进入视野」差分，
            // 出生通知由 drop:spawned 承担（全量可见）
            // Register into the view provider: UniversalAOI (full world) always returns an empty delta —
            // no "entered view" delta exists; the birth notice is carried by drop:spawned (full visibility)
            $diff = $this->world->getAOI()->updateEntity($dropEntity);

            // 攒批窗口内：只登记缓冲，逐条 drop:spawned 与 entity_enter 补发取消（与批量帧信息等价，ADR-024 §D-D）
            // Inside a batch window: buffer only — per-drop drop:spawned and entity_enter back-fill are cancelled
            // (informationally equivalent to the batch frame, ADR-024 §D-D)
            if ($this->dropBatch !== null) {
                $this->dropBatch[] = ['dropId' => $dropId, 'itemId' => $itemId, 'x' => $position['x'], 'y' => $position['y']];

                continue;
            }

            // spawn 后补发 entered 差分：视野内旧邻居收到「掉落进入视野」entity_enter（附 itemId，ADR-017 §8.7：
            // 掉落物 spawn 后立即对视野内邻居走 entity_enter，与 drop:spawned 信息等价；entered 为空时由 drop:spawned 承担出生通知）
            // Back-fill the entered delta after spawn: neighbors already in view receive the entity_enter
            // "drop entered view" frame carrying itemId (ADR-017 §8.7: a spawned drop immediately walks the
            // entity_enter path for neighbors in view, informationally equivalent to drop:spawned; an empty
            // entered is covered by drop:spawned alone)
            foreach ($diff['entered'] as $neighbor) {
                $this->broadcaster->sendToEntity($neighbor->getId(), 'entity_enter', [
                    'id' => $dropId,
                    'position' => $dropEntity->getPosition(),
                    'itemId' => $itemId,
                ]);
            }

            $this->broadcaster->broadcastToVision($dropId, 'drop:spawned', [
                'dropId' => $dropId,
                'itemId' => $itemId,
                'x' => $position['x'],
                'y' => $position['y'],
            ]);
        }
    }

    /**
     * 批量掉落（掉落风暴，ADR-024 §D-D）：一波怪物死亡的掉落合并为单条 drop:spawned_batch 帧——
     * 循环内仅 EM add + AOI updateEntity（索引登记必须逐个），出生通知（逐条 drop:spawned 与 entity_enter 补发）
     * 与批量帧信息等价而取消；ArchivePipeline 零改动（掉落易失不落库）。一波 500 死亡 × 0.5 掉率：
     * 250 次广播 → 1 次。死亡帧先于批量帧入队（同帧 FIFO 保序，由调用方的死亡结算时序保证）。
     * Batch drops (drop storms, ADR-024 §D-D): one death wave's drops merge into a single drop:spawned_batch frame —
     * the loop only does EM add + AOI updateEntity (index registration must be per-drop) while birth notices (per-drop
     * drop:spawned and entity_enter back-fill) are cancelled as informationally equivalent to the batch frame;
     * ArchivePipeline needs zero changes (drops are volatile and never archived). A wave of 500 deaths × 0.5 drop rate:
     * 250 broadcasts → 1. Death frames enqueue before the batch frame (same-frame FIFO order, guaranteed by the caller's
     * death-settlement ordering).
     *
     * @param string $visionCenterId 批量帧的视野中心实体 id（通常为施法者/击杀发起者） The batch frame's vision-center entity id (usually the caster/killer).
     * @param list<array{monsterId: string, position: array{x: int, y: int}, drops: list<array{itemId: string, count: int}>}> $wave 一波死亡（每项一个怪物的掉落 roll 结果） One death wave (one monster's drop-roll result per entry).
     */
    public function spawnDropsBatch(string $visionCenterId, array $wave): void
    {
        if ($wave === []) {
            return;
        }

        // try/finally 保证关窗/复位（与 castSkillAoE 同口径，R2 审查 MINOR-3）
        // try/finally guarantees closing/resetting (same convention as castSkillAoE, R2 review MINOR-3)
        $this->dropBatch = [];
        try {
            foreach ($wave as $death) {
                $this->spawnDrops($death['monsterId'], $death['position'], $death['drops']);
            }
            $this->flushDropBatch($visionCenterId);
        } finally {
            $this->dropBatch = null;
        }
    }

    /**
     * 关闭掉落攒批窗口：缓冲非空时以一条 drop:spawned_batch 合并帧广播（并行标量列表负载——二进制协议
     * LIST 元素仅支持标量/POS，见 BinaryBatchSerializer）；空批静默（无可通告内容）。
     * Closes the drop-batch window: broadcasts one merged drop:spawned_batch frame when the buffer is non-empty
     * (parallel scalar-list payloads — the binary protocol's LIST elements support scalars/POS only, see
     * BinaryBatchSerializer); an empty batch stays silent (nothing to announce).
     *
     * @param string $visionCenterId 批量帧的视野中心实体 id The batch frame's vision-center entity id.
     */
    private function flushDropBatch(string $visionCenterId): void
    {
        $buffer = $this->dropBatch;
        $this->dropBatch = null;
        if ($buffer === null || $buffer === []) {
            return;
        }

        $this->broadcaster->broadcastToVision($visionCenterId, 'drop:spawned_batch', [
            'dropIds' => array_column($buffer, 'dropId'),
            'itemIds' => array_column($buffer, 'itemId'),
            'positions' => array_map(
                static fn (array $drop): array => ['x' => $drop['x'], 'y' => $drop['y']],
                $buffer,
            ),
        ]);
    }

    /**
     * 拾取结算：itemId 经 items 校验 → 归属校验（击杀者本人/同队可拾，非归属者拒绝并定向 combat:error）→
     * 经 world 摘除 DropEntity（AOI remove + EntityManager remove + 在场登记/typeIndex 摘除）→
     * inventory->add → 广播 drop:removed（视野）+ item:added（定向拾取者）。
     * Pickup settlement: itemId validated via items → ownership validated (the killer or a same-team member may
     * pick; others are rejected with a directed combat:error) → the DropEntity is removed through the world (AOI
     * remove + EntityManager remove + live-registry/typeIndex cleanup) → inventory->add → broadcast drop:removed
     * (view) + item:added (directed to the pickup actor).
     *
     * @return bool true = 拾取成功；false = 未注册 itemId 或归属校验拒绝 true when picked up; false on an illegal item id or an ownership rejection.
     */
    public function pickup(Damageable $player, DropEntity $drop, Inventory $inventory): bool
    {
        if ($this->items->get($drop->itemId) === null) {
            return false; // 未注册 itemId：不拾取 Illegal item id: nothing is picked up.
        }

        if (!$this->isPickupAllowed($player, $drop)) {
            $playerId = $this->combatantId($player);
            if ($playerId !== '') {
                $this->broadcaster->sendToEntity($playerId, 'combat:error', [
                    'code' => 'not_owner',
                    'message' => '掉落物归属其他冒险者',
                ]);
            }

            return false;
        }

        // 从视野提供者摘除（UniversalAOI::remove 为空操作：索引只存在于 GridAOI 侧）后删实体
        // Remove from the view provider (UniversalAOI::remove is a no-op — the index exists only for GridAOI)
        // then delete the entity
        $this->world->getAOI()->remove($drop);
        $this->world->getEntityManager()->remove($drop->getId());
        unset($this->liveDrops[$drop->getId()]);
        $this->typeIndex?->remove($drop->getId());
        $inventory->add($drop->itemId, $drop->count);

        $playerId = $this->combatantId($player);
        $this->broadcaster->broadcastToVision($playerId, 'drop:removed', ['dropId' => $drop->getId()]);
        $this->broadcaster->sendToEntity($playerId, 'item:added', [
            'itemId' => $drop->itemId,
            'count' => $drop->count,
        ]);

        // 拾取埋点（D4 缺口补埋）：uid 可解析时发布 combat.pickup（任务收集进度源的消费事件）。
        // Pickup instrumentation (the D4-gap back-fill): publishes combat.pickup when the uid resolves
        // (the consumption event of the quest collect progress source).
        if ($this->events !== null && $player instanceof PlayerActor && $player->uid() !== null) {
            $this->events->dispatch(self::EVENT_PICKUP, [
                'uid' => $player->uid(),
                'itemId' => $drop->itemId,
                'count' => $drop->count,
            ]);
        }

        return true;
    }

    /**
     * 过期回收扫描（定时回收路径，装配层周期调用）：遍历在场掉落登记表，摘除已过期掉落
     * （AOI remove + EntityManager remove + 登记/typeIndex 清理）并广播 drop:removed；返回回收数量。
     * Expired-drop reclamation sweep (the periodic path, invoked by the assembly layer's timer): walks the live-drop
     * registry, removes expired drops (AOI remove + EntityManager remove + registry/typeIndex cleanup) and broadcasts
     * drop:removed; returns how many were reclaimed.
     *
     * @param float $now 当前时刻（microtime 秒，由调用方注入保证可测） The current instant (microtime seconds, injected by the caller for testability).
     */
    public function purgeExpiredDrops(float $now): int
    {
        $purged = 0;
        foreach (array_keys($this->liveDrops) as $dropId) {
            $entity = $this->world->getEntityManager()->get($dropId);
            // 登记与实体表失配（外部摘除）：同步清理登记防泄漏
            // A registry/entity-table mismatch (removed externally): clean the registration to prevent leaks
            if (!$entity instanceof DropEntity) {
                unset($this->liveDrops[$dropId]);
                $this->typeIndex?->remove($dropId);

                continue;
            }
            if (!$entity->isExpired($now)) {
                continue;
            }

            $this->world->getAOI()->remove($entity);
            $this->world->getEntityManager()->remove($dropId);
            unset($this->liveDrops[$dropId]);
            $this->typeIndex?->remove($dropId);
            $this->broadcaster->broadcastToVision($dropId, 'drop:removed', ['dropId' => $dropId]);
            $purged++;
        }

        return $purged;
    }

    /**
     * 归属校验：无归属掉落自由拾取；有归属时仅击杀者本人或同队成员可拾。
     * Ownership check: unowned drops are free to pick; owned drops allow only the killer or a same-team member.
     */
    private function isPickupAllowed(Damageable $player, DropEntity $drop): bool
    {
        if ($drop->ownerUid === null) {
            return true;
        }

        $pickerUid = $player instanceof PlayerActor ? $player->uid() : null;
        if ($pickerUid !== null && $pickerUid === $drop->ownerUid) {
            return true;
        }

        if ($drop->ownerTeamId !== null && $pickerUid !== null) {
            $pickerTeamId = $this->teams?->teamOf($pickerUid);
            if ($pickerTeamId !== null && $pickerTeamId === $drop->ownerTeamId) {
                return true;
            }
        }

        return false;
    }

    /**
     * 击杀归属记录：目标为 BaseMonster 时在扣血前登记伤害来源与伤害量——lastAttacker 维持「最后来源」
     * 语义（接入前击杀归属），伤害账本（noteDamage）按攻击者聚合供多源归属裁决（P13）。
     * Kill-attribution note: when the target is a BaseMonster, the damage source and amount are recorded before
     * the hit — lastAttacker keeps the "last source" semantics (the pre-integration kill attribution) while the
     * damage ledger (noteDamage) aggregates per attacker for the multi-source attribution ruling (the P13).
     */
    private function noteAttackerOn(Damageable $attacker, Damageable $target, int $damage): void
    {
        if (!$target instanceof BaseMonster) {
            return;
        }
        $attackerId = $this->combatantId($attacker);
        if ($attackerId !== '') {
            $target->noteAttacker($attackerId);
            $target->noteDamage($attackerId, $damage);
        }
    }

    /**
     * 广播实体死亡帧 entity_dead{id}（视野）。供攻击结算与 MonsterActor.onDeath 共用。
     * 死亡攒批窗口内（castSkillAoE 开启，ADR-024 §9 V5）：逐条 entity_dead 取消——id/位置/种类在登记时点
     * 捕获（死亡自清理先于关窗摘除实体），并入关窗时的单条 entity_dead_batch；窗口外保持逐条单帧
     * （单体击杀路径不变：一杀一帧无洪泛，批量帧对单体路径零收益）。
     * Broadcasts the entity-death frame entity_dead{id} (view). Shared by attack settlement and MonsterActor.onDeath.
     * Inside the death-batch window (opened by castSkillAoE, ADR-024 §9 V5): per-target entity_dead frames are
     * cancelled — id/position/kind are captured at buffering time (the death self-cleanup removes the entity before
     * the window closes) and merge into a single entity_dead_batch on close; outside the window the per-frame path
     * stays as-is (single-target kills keep one frame per kill with no flooding; batching buys nothing there).
     */
    public function broadcastDeath(Damageable $target): void
    {
        $id = $this->combatantId($target);
        if ($id === '') {
            return;
        }

        if ($this->deathBatch !== null) {
            $position = $this->world->getEntityManager()->get($id)?->getPosition() ?? ['x' => 0, 'y' => 0];
            $this->deathBatch[] = ['id' => $id, 'x' => $position['x'], 'y' => $position['y'], 'type' => $this->deathType($target)];

            return;
        }

        $this->broadcaster->broadcastToVision($id, 'entity_dead', ['id' => $id]);
    }

    /**
     * 关闭死亡攒批窗口：缓冲非空时以一条 entity_dead_batch 合并帧广播（并行等长标量列表 ids/positions/types，
     * 协议约束 V7 列式编码）；空批静默（无可通告内容）。
     * Closes the death-batch window: broadcasts one merged entity_dead_batch frame when the buffer is non-empty
     * (parallel equal-length scalar lists ids/positions/types — the V7 protocol constraint's columnar encoding);
     * an empty batch stays silent (nothing to announce).
     *
     * @param string $visionCenterId 批量帧的视野中心实体 id The batch frame's vision-center entity id.
     */
    private function flushDeathBatch(string $visionCenterId): void
    {
        $buffer = $this->deathBatch;
        $this->deathBatch = null;
        if ($buffer === null || $buffer === []) {
            return;
        }

        $this->broadcaster->broadcastToVision($visionCenterId, 'entity_dead_batch', [
            'ids' => array_column($buffer, 'id'),
            'positions' => array_map(
                static fn (array $death): array => ['x' => $death['x'], 'y' => $death['y']],
                $buffer,
            ),
            'types' => array_column($buffer, 'type'),
        ]);
    }

    /**
     * 解析死者种类标量（entity_dead_batch 的 types 列）：怪物/玩家二元判定，其余战斗体归 unknown。
     * Resolves the dead combatant's kind scalar (the types column of entity_dead_batch): a monster/player binary
     * verdict, anything else falls back to unknown.
     */
    private function deathType(Damageable $target): string
    {
        if ($target instanceof BaseMonster) {
            return 'monster';
        }
        if ($target instanceof PlayerActor) {
            return 'player';
        }

        return 'unknown';
    }

    /**
     * 击杀埋点（D4 缺口补埋）：目标死亡时发布 combat.kill——killerUid 经击杀归属裁决（killCredit）解析：
     * last_hit = 本次伤害来源（接入前语义）；damage_leader = 伤害账本最高者（P13 多源归属，账本空回落
     * lastAttacker），来源实体 id 经 actorLookup 解析回 PlayerActor uid（非玩家来源为 null）。
     * monsterId 为怪物实例 id（重生登记等消费方用 victimId 同源），monsterTypeId 供任务击杀进度源按类型
     * 匹配（P2 收口）；contributors 为伤害账本快照（P13 多源统计，非怪物目标为空列表——应用级事件负载，
     * 不占协议词表）。
     * Kill instrumentation (the D4-gap back-fill): publishes combat.kill on target death — killerUid resolves
     * through the kill-credit ruling (killCredit): last_hit = the current damage source (the pre-integration
     * semantics); damage_leader = the damage-ledger leader (the P13 multi-source attribution, falling back to
     * lastAttacker on an empty ledger), with the source entity id resolved back to the PlayerActor uid via the
     * actorLookup (null for non-player sources). monsterId is the monster instance id (consumers such as the
     * respawn registration use victimId as the same source), monsterTypeId feeds the quest kill-progress
     * matching by type (the P2 close-out), and contributors is the damage-ledger snapshot (the P13 multi-source
     * statistics; an empty list for non-monster targets — an application-event payload, costing no protocol vocabulary).
     */
    private function notifyKill(Damageable $attacker, Damageable $target): void
    {
        if ($this->events === null) {
            return;
        }
        $this->events->dispatch(self::EVENT_KILL, [
            'killerUid' => $this->resolveKillCredit($attacker, $target),
            'victimId' => $this->combatantId($target),
            'monsterId' => $target instanceof BaseMonster ? $target->monsterId() : null,
            'monsterTypeId' => $target instanceof BaseMonster ? $target->typeId() : null,
            'contributors' => $target instanceof BaseMonster ? $target->damageContributors() : [],
        ]);
    }

    /**
     * 击杀归属裁决（P13）：damage_leader 模式下从目标伤害账本取最高者并解析回 uid；解析不出（账本空/
     * 来源非玩家/actorLookup 未装配）回落攻击方本身——与 last_hit 模式同出口。
     * The kill-credit ruling (the P13): in damage_leader mode the ledger leader is taken from the target and
     * resolved back to a uid; an unresolvable source (empty ledger / non-player source / no actorLookup) falls
     * back to the attacker itself — the same exit as last_hit mode.
     */
    private function resolveKillCredit(Damageable $attacker, Damageable $target): ?string
    {
        if ($this->killCredit === MmorpgConfig::KILL_CREDIT_DAMAGE_LEADER && $target instanceof BaseMonster) {
            $creditId = $target->damageLeader() ?? $target->lastAttacker();
            if ($creditId !== null) {
                if ($creditId === $this->combatantId($attacker)) {
                    return $attacker instanceof PlayerActor ? $attacker->uid() : null;
                }
                $credited = $this->actorLookup?->getActor($creditId);
                if ($credited instanceof PlayerActor) {
                    return $credited->uid();
                }
            }
        }

        return $attacker instanceof PlayerActor ? $attacker->uid() : null;
    }

    /**
     * 基础伤害 × 随机浮动百分比（randomInt(80,120) / 100）。
     * Base damage × random variance percent (randomInt(80,120) / 100).
     */
    private function rollDamage(int $base): int
    {
        return (int) round($base * $this->random->randomInt(self::DAMAGE_FLOAT_MIN, self::DAMAGE_FLOAT_MAX) / 100);
    }

    /**
     * 从 Damageable 解析实体 id：BaseMonster 用 monsterId，PlayerActor 用 getPlayerId，其余返回空串。
     * Resolves the entity id from a Damageable: monsterId for BaseMonster, getPlayerId for PlayerActor, empty string otherwise.
     */
    private function combatantId(Damageable $combatant): string
    {
        if ($combatant instanceof BaseMonster) {
            return $combatant->monsterId();
        }
        if ($combatant instanceof PlayerActor) {
            return $combatant->getPlayerId();
        }

        return '';
    }
}
