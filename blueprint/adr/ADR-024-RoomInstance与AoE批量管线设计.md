# ADR-024：RoomInstance 与 AoE 批量管线设计

- 状态：已裁决（2026-08-25）
- 关联：ADR-020（三层产品/R2 引擎补强）、ADR-021（自研单栈）、ADR-023（可见性白名单）
- 起因：architecture.md §5 将 RoomInstance 与 AoE 批量命中管线列为 R2 优先项。horde（≤6 人/千级怪/AoE/掉落风暴/50ms）与射击房间（15-30ms 可配）两锚点反推引擎原语契约；mmorpg layering 本期不做但结构不得堵死。

## 1. 现状核实（2026-08-25 Read/Grep 实测）

- World（final）聚合 EM/ActorSystem/AOI/EventBus/Scheduler，固定帧序：actor updateAll → drainMoved+AOI 差分（双向 entered/left 信封）→ scheduler runFrame；不 flush（上层帧末触发）。**mapId/channelId/serviceId 均为 demo MapServer 属性，WorldInterface 本身不含地图语义**。
- SimpleEntityManager.add 即 markMoved；drainMoved 为 O(N) 全表扫描；EntityInterface 仅 move(dx,dy) 相对入口。
- GridAOI（cellSize 九宫格，updateEntity 返回 {entered,left}）/UniversalAOI（query=全表、恒空差分）。
- TickScheduler=TaskQueue+TimerWheel（tickMs 构造期固定）；RegionScheduler 预算按「帧」截断；SimpleEventBus 有界队列 FIFO flush，无合并能力。
- demo MapServer 以 WorkermanTimer 50ms 驱动 clock.tick()+world.update()。
- CombatService.spawnDrops 逐 drop：EM add + AOI update + 逐邻居 entity_enter + 逐 drop drop:spawned 广播。

## 2. 裁决

### D-A RoomInstance 本体定位：独立聚合，实现 WorldInterface

**裁决**：Contracts 新增 `RoomInstanceInterface extends WorldInterface`（附加生命周期/成员契约）+ `RoomInstance` 实现（@internal）；`RoomInstanceManager`（@internal）负责创建、归属校验与到期驱动。World（持久大世界单例）与 Room（短生命周期小世界，多实例）平级为「空间容器」：共享子系统实现类，不共享实例。

理由：① WorldInterface 仅含 update + 五子系统访问器 + getType，无地图语义——房间实现它后，framework CombatService/MonsterActor/DropEntity 全套战斗代码以 WorldInterface 门面零改动运行于房间内（复利最大化）；② 生命周期与成员进出是房间独有能力，收进 RoomInstanceInterface 不污染 World；③ layering 不堵死：layer 本质 =「长生命周期、大成员量、GridAOI 的 Room 变体」，未来引 Scope 公共父接口或复用本接口调长生命周期策略即可，本期不为 layering 写代码。

被否：World 增加 room 模式——单 World 绑一套 EM/AOI/Scheduler，多房间共表使大世界 AOI 差分扫描被千级怪 moved 污染，生命周期状态机入侵 World.update 主路径。

**成员模型**：房间独立 EntityManager + 独立 AOI + 独立 ActorSystem + 独立 TickScheduler；**EventBus 共享宿主**（信封统一队列、统一帧末 flush，targetScope 寻址不变）。成员是完整注册而非宿主子集引用（同一 PHP 对象，注册表归属迁移）；实体同一时刻至多属于一个容器，由 RoomInstanceManager 归属表强制校验。

**小房间 AOI 策略**：RoomConfig 注入 `aoiFactory`（每房间独立实例工厂），装配层自选 GridAOI（horde 千级怪）或 UniversalAOI（≤6 人射击房）；「≤64 成员可用全量广播」之类阈值是装配层知识，引擎不定常数。

### D-B 独立 tick 频率：宿主心跳 + 到期驱动 + 追帧跳帧

**裁决**：全局 Clock 50ms 基准不动。`RoomInstanceManager::tick(now)` 为唯一驱动入口：线性扫描房间表 nextDueAt，到期者依次 update() 并 nextDueAt += periodMs；落后超过 maxCatchUpTicks（缺省 4）周期则跳帧对齐当前时刻（防死亡螺旋）。本周期累计耗时达 budgetMs（缺省宿主周期 60%）即止，剩余房间顺延计 deferred——预算思想与 RegionScheduler 同源，但房间是另一条时间轴（RegionScheduler.runFrame 绑定「帧」语义），独立实现于 manager，不改 RegionScheduler。

对接点：starter-kit 以 WorkermanTimer 注册单一宿主心跳（hostTickMs 取活动房间最小周期，如 15ms）回调 `manager->tick(clock->now())`；引擎侧 manager 纯 PHP 无事件循环依赖（假时钟可测）。

被否：每房间一个 WorkermanTimer——数百房间即数百系统定时器且失去统一预算点；子步进——50ms 基准下无法均匀表达 15-30ms；多快 Region——region 是任务分区不是时间轴。

### D-C AoE 批量命中：形状查询归引擎，结算归 framework，攒批归管线层

**裁决**：① 形状查询是引擎原语：Contracts 新增 ShapeInterface + Circle/Rectangle/Sector 三值对象（contains/bounds 纯函数）；AOIProviderInterface 新增 `queryShape`——GridAOI 按 bounds 覆盖格粗筛 + 谓词精判（O(覆盖格实体) 非 O(全房间)），UniversalAOI 全表过滤。② 伤害结算/命中校验（无敌帧/闪避/公式）留 framework Combat：castSkillAoE = 1 次 queryShape + N 次 takeDamage + 1 次合并广播。③ 事件合并在管线层（framework 攒批出帧），EventBus 不加 coalesce——可合并性与合并键是业务语义，总线层做会把业务知识漏进引擎；真正风暴源是「N 次 broadcastToVision 各出一帧」，攒成单帧即消除。

性能口径：千级怪房间一次 AoE = O(覆盖格实体) 查询 + O(N) 必要结算 + 1 信封；返回引用列表零拷贝；AoE 不位移实体故不产生 moved 流量（击退非目标）。

被否：EventBus coalesce——见③；形状查询下沉 framework——失去 AOI 索引红利，退化为全表扫描。

### D-D 掉落风暴：spawnDropsBatch 攒批出帧

**裁决**：framework CombatService 新增 spawnDropsBatch：循环内仅 EM add + AOI updateEntity（索引登记必须逐个），收集后**一次** broadcastToVision(`drop:spawned_batch`, {drops:[…]})；entity_enter 补发与 batch 帧信息等价而取消（与 UniversalAOI 口径一致：出生通知由 spawned 帧承担）；starter-kit 协议词表增 batch 帧。一波 500 死亡 × 0.5 掉率：250 次广播 → 1 次。

ArchivePipeline 交互：掉落为易失实体不落库；批量死亡引发的玩家 hp/背包变更为既有脏标记路径，periodicFlush 本就 saveBatch——零改动，仅补集成测试验证。

被否：EventEnvelope 增加 batch 种类——payload 本就是 array，列表负载无需新信封类型。

### D-E 既有机制一致性

1. **补契约 `EntityInterface::setPosition(int,int)`**：绝对重定位唯一入口，实现必须置位 moved（与 move 同路径）。房间成员进出/传送必然跨绝对坐标，delta 反推样板会在 framework/starter-kit 反复出现——契约缺口（清单 §2）。BaseEntity/DropEntity 同步实现。
2. **drainMoved O(N) 评估**：1000 怪 ≈ 0.1ms/帧量级，50ms 帧占 ~0.2% 可接受；15-30ms 多房间叠加时占比上升。本期不改 SimpleEntityManager，T8 benchmark 锁基线；O(moved) 化随 §5 路线图 SoA 批量布局另批落地。
3. **ADR-023 白名单增量**：新公开面 = 新 Contracts 接口（自动公开）+ 形状三值对象（framework Combat 构造入参，比照 Position 先例入白名单）；RoomInstance/RoomInstanceManager/RoomConfig 等 @internal，framework 经 RoomManagerInterface 消费（比照 ServiceRegistryInterface 模式）。

## 3. 核心接口签名草图

```php
// ── Nythros\Contracts\EntityInterface（增补）──
/** 绝对重定位至 (x,y)：传送/房间进出唯一绝对定位入口；实现须与 move() 同路径置位 moved。 */
public function setPosition(int $x, int $y): void;

// ── Nythros\Contracts\ShapeInterface ──
interface ShapeInterface
{
    /** 点是否在形状内（整数坐标、浮点判定、边界含入）。 */
    public function contains(int $x, int $y): bool;
    /** @return array{minX:int,minY:int,maxX:int,maxY:int} 包围盒：必须精确覆盖 contains=true 的格范围（AOI 粗筛依据） */
    public function bounds(): array;
}
// CircleShape(cx,cy,r)、RectangleShape(x,y,w,h)、SectorShape(cx,cy,r,angleDeg,fovDeg)：final readonly 值对象，纯函数。

// ── Nythros\Contracts\AOIProviderInterface（增补）──
/** 形状查询：返回形状覆盖内实体（含自身若在内；按 id 去重；顺序不保证）。GridAOI=bounds 格粗筛+精判；UniversalAOI=全表过滤。 */
public function queryShape(ShapeInterface $shape): array;

// ── Nythros\Contracts\RoomState ──
enum RoomState { case Created; case Running; case Settled; case Closed; }

// ── Nythros\Contracts\RoomConfig（readonly 值对象）──
final class RoomConfig
{
    /** @param callable(): AOIProviderInterface $aoiFactory 每房间独立 AOI 工厂 */
    public function __construct(
        public readonly string $roomId,
        public readonly int $periodMs,      // 房间 tick 周期（15–50）
        public readonly int $maxMembers,    // 成员上限（玩家+怪物合计）
        public readonly mixed $aoiFactory,
        public readonly int $maxCatchUpTicks = 4,
    ) {}
}

// ── Nythros\Contracts\RoomInstanceInterface ──
interface RoomInstanceInterface extends WorldInterface
{
    public function getRoomId(): string;
    public function getState(): RoomState;
    /** 成员进入：EM 登记（即 markMoved 首帧进 AOI）+ 可选 Actor 注册；Running 态方可加入；已是成员返回 false。 */
    public function join(EntityInterface $entity, ?ActorInterface $actor = null): bool;
    /** 成员离开：摘 EM + AOI + ActorSystem；非成员返回 false。 */
    public function leave(string $entityId): bool;
    /** running→settled：停收成员，向存活成员发 room_closed 信封，保留只读查询。 */
    public function settle(): void;
    /** settled→closed：清空成员与索引，终态不可逆。 */
    public function close(): void;
}

// ── Nythros\Contracts\RoomManagerInterface ──
interface RoomManagerInterface
{
    public function create(RoomConfig $config): RoomInstanceInterface;
    public function get(string $roomId): ?RoomInstanceInterface;
    /** @return list<RoomInstanceInterface> */
    public function all(): array;
    /**
     * 宿主心跳驱动：到期房间依次 update()，本周期预算耗尽即止。
     * @return array{updated:int, deferred:int} updated=本周期实际更新数；deferred=预算顺延数（未到期不计）
     */
    public function tick(float $now): array;
    /** 跨容器成员迁移编排（含归属表校验，杜绝双房）：$fromRoomId=null 表示从大世界进入。 */
    public function transfer(?string $fromRoomId, string $toRoomId, EntityInterface $entity, ?ActorInterface $actor = null): bool;
    /** 异常路径销毁：内部强制 settle→close→移除。 */
    public function destroy(string $roomId): void;
}
```

## 4. 与 World/Scheduler/EventBus/AOI 集成点图

- WorkermanTimer(hostTickMs)【starter-kit】→ RoomInstanceManager::tick(now)：到期筛选（nextDueAt）→ 预算截断 → RoomInstance::update()。
- RoomInstance::update() 复刻 World 固定帧序：房间自有 SimpleActorSystem.updateAll → 房间自有 EM.drainMoved + 房间自有 AOI.updateEntity（双向 entered/left 信封）→ 房间自有 TickScheduler(periodMs).runFrame；信封发布到**共享宿主 EventBus**，仍由上层帧末统一 flush。
- framework CombatService.castSkillAoE：WorldInterface::getAOI()->queryShape(shape)【引擎原语】→ N × takeDamage【framework 结算】→ 1 × broadcastToVision(combat:aoe)【攒批出帧】。
- spawnDropsBatch：N × (EM add + AOI updateEntity) + 1 × broadcast(drop:spawned_batch)；死亡帧先于 batch 帧入队（同帧 FIFO 保序）。
- 宿主 World 与房间互不感知：大世界实体经 manager.transfer 进房 = 大世界 EM/AOI remove + 房间 join。

## 5. 边界条件矩阵（对照 rules/architecture-design.md 清单）

| 清单项 | 核对结论 |
|---|---|
| 数据流对称性 | join：房间既有成员收 member_enter(batch) + 进入者收房间快照，双向；leave/settle/close 同理双向（留守方 + 当事方）；AoE：施法者回执 + 受击方/视野广播双向 |
| 契约完整性 | queryShape 入 AOIProviderInterface；setPosition 入 EntityInterface；RoomInstanceInterface/RoomManagerInterface 新增；SchedulerInterface 不动（房间持独立 TickScheduler 实例） |
| 返回值语义 | queryShape=「形状覆盖实体集（含自身若在内、去重、无序）」；join/leave 返回 false=重复/非成员；tick 返回 {updated,deferred} 供观测 |
| 时序/延迟 | 房间更新延迟 ≤1 宿主心跳；追帧跳帧上限 maxCatchUpTicks；死亡帧先于 drop:spawned_batch（同帧入队序）；flush 仍帧末统一 |
| 边界条件 | 空房间 tick=跳过 actor/AOI 仅推进轮次；重复 join=false；close 时仍有成员=settle 已通知+强制清空；同帧 join+move=join 即 markMoved 首帧进索引；AoE 空集=仍发 cast 回执；命中已死实体=framework isDead 过滤；预算耗尽=顺延+deferred；心跳停摆恢复=跳帧对齐；双房=transfer 归属表拒绝 |

## 6. 实施拆解（给 build，有序；★=测试先行）

1. ★T1 EntityInterface::setPosition 契约 + BaseEntity/DropEntity 实现 + 存量测试回归。
2. ★T2 ShapeInterface + 三值对象（contains/bounds 表驱动测试：圆心/边界/扇形跨 0° 角）。
3. ★T3 AOIProviderInterface::queryShape + GridAOI（bounds 格交集+精判）+ UniversalAOI（全表过滤）双实现对齐测试。
4. ★T4 RoomState/RoomConfig/RoomInstanceInterface + RoomInstance 实现（生命周期状态机、join/leave、固定帧序；依赖 T1）。
5. ★T5 RoomManagerInterface + RoomInstanceManager（到期驱动/追帧跳帧/预算截断/transfer 归属表；假时钟测试）。
6. T6 framework：CombatService::castSkillAoE + spawnDropsBatch（攒批广播）+ starter-kit 协议词表 batch 帧（依赖 T3/T4）。
7. T7 starter-kit 接线：宿主心跳（WorkermanTimer→manager::tick）+ horde 演示房间端到端。
8. T8 benchmark：千级怪房间单次 AoE 帧耗时、drainMoved 基线、多房间心跳扫描成本（门禁数据）。
9. T9 ADR-023 白名单修订（形状三值对象）+ 新类 bulk @internal 标注 + grep 门禁覆盖。

依赖链：T1→T4→T5→T7；T2→T3→T6。T9 随 R2 bulk 标注批合入。

## 7. 非目标（本期不做）

layering（仅结构预留）；SoA 批量布局（另批）；击退/位移型 AoE；EventBus coalesce；跨进程房间与分布式匹配；房间快照恢复/断线重连回放；MOBA lockstep 帧同步；房间内独立 EventBus。

## 8. 后果

- engine 公开面净增：3 接口 + 1 enum + 1 值对象 + 3 形状值对象 + EntityInterface/AOIProviderInterface 各一方法（对既有实现为受控破坏性变更，monorepo 内实现全数同步）。
- framework 战斗代码经 WorldInterface 门面天然双栖（大世界/房间），Horde 类型模块（R4）可直接以 RoomManagerInterface 开房。
- 性能天花板由 T8 benchmark 数据守门；drainMoved O(N) 与心跳线性扫描为已知可接受成本，超阈值时触发 SoA/堆优化另案。

## 9. 修订记录 v2（2026-08-25，吸收 R2-b 实施偏差）

R2-b 实施暴露七处设计缺口，逐条裁决如下。正文（§1–§8）不改，冲突处以本节为准。

### V1 aoiFactory 签名缺口 —— 签名修订，即刻生效

**现象**：§3 草图 `callable(): AOIProviderInterface` 零参签名无法让 UniversalAOI 包裹房间自有 EM（EM 在工厂调用前才创建）；实施以「零参闭包仍兼容」绕过。

**裁决**：正式契约修订为 **`callable(EntityManagerInterface): AOIProviderInterface`**——工厂接收房间自有 EM（UniversalAOI 必须包裹它）；零参闭包仍合法（多传实参被语言忽略），GridAOI 类无 EM 依赖工厂照写零参。PHP 属性无法静态声明 callable 签名，维持 `mixed $aoiFactory` + docblock 契约 + RoomInstance 构造期 is_callable/产出类型运行时校验（实施现状即此形态，本条为文档追认）。RoomConfig 类注释已同步，§3 草图以此为准：

```php
/** @param callable(EntityManagerInterface): AOIProviderInterface $aoiFactory 每房间独立 AOI 工厂 */
public readonly mixed $aoiFactory,
```

被否：延迟双阶段装配（先建 EM 再二次注入 AOI）——RoomInstance 构造序复杂化，且两段构造留下半装配窗口。

### V2 RoomInstanceInterface 未暴露 periodMs/maxMembers —— 增补单一 getConfig()

**现象**：接口未暴露周期/容量，管理器靠 create 时在私有房间表留存 RoomConfig 解决（实测确认），外部消费者无从读取。

**裁决**：增补，但形式为**单一只读访问器 `getConfig(): RoomConfig`**，不做 getPeriodMs/getMaxMembers 逐字段访问器。理由：① RoomConfig 本就是 Contracts 公开 readonly 值对象，整体返回零泄漏；② 逐字段访问器随配置项线性膨胀（现有 5 字段），getConfig 一劳永逸；③ R4 Horde 匹配/观测面板需要容量与周期，不应依赖 manager 私有房间表。管理器内部 tick 路径继续用留存 config，不走访问器（性能热路径不受影响）。

```php
// ── RoomInstanceInterface 增补 ──
/** 只读房间配置（roomId/periodMs/maxMembers/aoiFactory/maxCatchUpTicks）。 */
public function getConfig(): RoomConfig;
```

被否：getPeriodMs/getMaxMembers 双访问器——每加一个配置项就要扩一次接口；manager 暴露 getConfig(roomId)——查询语义碎且逼消费者持 manager 引用。排期：R3 顺手批（engine 接口微扩，monorepo 内 RoomInstance 同步实现）。

### V3 transfer 后断连泄漏 —— 需补「跨容器 disconnect 编排」，落点 framework 模板层，R3

**现象**：RealtimeServer::closeConnection 清理模板绑定世界 EM，玩家进房后 `$this->entityManager->get($entityId)` 为 null 即整段跳过——房内成员残留（幽灵成员：邻居收不到 entity_leave、怪物持续索敌、内存滞留）直至房间 close/destroy。实测代码印证。

**裁决**：**不是可接受的已知限制，必须补**——horde（R4）6 人房必现玩家掉线，幽灵成员会直接污染试点验收。落点分两层：

- **framework RealtimeServer 扩展**（通用性归模板）：closeConnection 在世界 EM 查空后调用可选注入的跨容器清理回调（缺省 null 行为不变，向后兼容）；模板吸收「查世界 → 查不到 → 问容器编排」的通用序列。
- **engine RoomManagerInterface 增补便捷方法**（方向性草图，细节归 plan）：`evictFromAny(string $entityId): bool`——查归属表定位所在房 → 复用 leave 全链（摘 EM/AOI/ActorSystem + entity_leave 广播）。
- **starter-kit 装配桥接**：注入回调 `(fn($id) => $manager->evictFromAny($id))` 并在清理后触发既有持久化冲刷钩子。

被否：starter-kit 各子类覆写 onEntityCleanedUp 自行清理——每个宿主重写一遍样板，违背模板吸收通用性原则；R2-b 立即热修——刚收尾批次不再开洞，且需 manager 便捷方法配套，超出偏差修复范围。**排期：R3**（与 V6 组成「跨容器编排批」，horde 试点前必须闭环）。

### V4 直入刷怪不受 maxMembers 约束 —— 语义文档化为「仅约束 join 路径」

**现象**：EM.add 直入（服务端刷怪/掉落）绕过 join 容量计数，maxMembers 名不副实。

**裁决**：**文档化，不改总容量语义**。maxMembers 正式定义为「**仅约束 join() 路径的受管成员**（玩家、经 transfer 进出的实体）；经 EM.add 直入的实体（服务端刷怪、掉落）不受限，其规模由业务侧配额自控」。理由：maxMembers 本质是**准入控制**（防玩家涌入/匹配超卖）而非资源硬限额；刷怪配额本就是策划业务逻辑，引擎代管反而错位。写入 RoomConfig docblock 与本文档。

被否：改为总容量（EM.add 计数）——需 EM 感知房间容量或装饰器包裹 EM，把房间知识漏进引擎通用件，职责越界。排期：不修（文档即刻生效，RoomConfig 注释随 R3 微扩批同步）。

### V5 AoE 击杀 entity_dead 逐目标广播 —— 独立攒批 entity_dead_batch，R4 horde 试点

**现象**：R2-b 仅合并 combat:aoe 与掉落帧；循环内 takeDamage → onDeath → broadcastDeath 仍逐目标出 entity_dead（一次 AoE 杀 50 怪 = 50 帧）。

**裁决**：**独立攒批为新帧 `entity_dead_batch`**（与 drop:spawned_batch 同构：并行标量列表 id[]/positions[]，见 V7 约束），单体击杀路径仍走 entity_dead 单帧。**排期：R4 horde 试点**——试点期用真实战斗数据验证死亡帧占比后再锁帧格式，攒批窗口复用既有 drop-batch 窗口机制（castSkillAoE 已开启）。

被否：死亡帧并入 combat:aoe payload——① 死亡语义与命中语义耦合，单体击杀（combat:hit 路径）无法复用同一帧型；② 视野中心不一致：queryShape 形状可远超施法者视野（超远程技能），entity_dead 以死者为中心广播、combat:aoe 以施法者为中心广播，合并必丢帧或错发。

### V6 进房后世界路由失效 —— 协议层「当前容器」概念，R3（与 V3 同批）

**现象**：move/attack/pickup 路由与 broadcastToVision/AOI 差分监听全绑世界实例，玩家 transfer 后指令静默失效（世界 EM 查无此人）。与 V3 同根：RealtimeServer 模板假设「实体恒在世界」。

**裁决**：**协议/连接层引入「当前容器」概念，不做消息级转发**。方向：ConnectionRegistry 双向映射扩展为 connId ↔ entityId ↔ 当前容器引用（World | RoomInstance）；RealtimeServer 路由入口统一「解析容器 → 按容器 EM/AOI 分发」；视野广播与 AOI 差分监听按实体所在容器取 AOI（房间信封已入共享宿主总线，targetScope 寻址可区分容器）。**排期：R3**——horde 试点要求「进房后可玩」，此项是 R4 硬前置，与 V3 断连编排同属跨容器主题，合并为一个批次最经济。

被否：路由转发（世界收到指令 → 发现实体不在 → 查 manager → 转发房间）——双重查找、失败模式复杂，且视野差分监听仍绑世界，转发救不了广播面。

### V7 二进制协议不支持嵌套结构 —— 即刻生效为协议约束

**现象**：BinaryBatchSerializer valueType 仅标量/LIST（元素限标量）/POS，无嵌套容器；批量帧被迫用并行标量列表编码（targetIds[]/damages[]/hps[]）。

**裁决**：**接受为正式协议约束，写入 ADR**：批量帧负载必须是「**并行等长标量列表**」（列式编码），客户端按下标对齐解析；禁止 LIST of LIST / 结构体数组等嵌套形态。配套约定：① 同帧各列表长度必须相等（下标 i 跨列表构成一条逻辑记录）；② 字段增删须同步 ProtocolVocabulary keyCode 映射与两端解析器，列表字段同增同删保对齐；③ 确需嵌套结构的负载（如未来背包格子）走 MsgpackSerializer 路径（已存在且支持嵌套），不扩二进制词表。

理由：列式编码是二进制紧凑性的来源（消除逐元素键重复）；游戏帧负载天然表格化，嵌套支持需引入 MAP/对象类型码，编解码复杂度陡增收益低，且有 msgpack 逃生通道。排期：不修（约束即刻生效，后续批量帧设计遵此）。

### 白名单核对（ADR-023）

本批七条裁决**无新增公开非接口类**：V2/V3/V6 为既有 Contracts 接口方法增补（依 ADR-023 D1「接口一律公开」自动合规），V7 为约束非符号。**ADR-023 白名单不需修订**；若 R3 跨容器编排批落地时引入新公开值对象（如容器引用类型），届时按 ADR-023 流程单独扩白名单。

### 编排责任补记（R3 审查 MAJOR 修复）

调用方编排责任：玩家跨容器转移（join/transfer）时，摘除源容器登记前须先向源容器视野邻居补发 entity_leave（对齐 RealtimeServer::closeConnection 先广播后摘除时序）；该责任由 starter-kit 组装层承担（reviewer R3 批 MAJOR 修复）。核实注（2026-08-25 Read/Grep）：closeConnection 先广播后摘除时序属实（RealtimeServer.php L521-528，广播先于摘 AOI/EM）；已于 R3 跨容器编排批 G1 修复闭环：RoomHub::handleJoin 摘除世界登记前经 MapServer::broadcastEntityLeave 补发 entity_leave（镜像 closeConnection 先广播后摘除时序），RoomHubTest::testJoinBroadcastsEntityLeaveToWorldNeighbors 双客户端断言锁定。
