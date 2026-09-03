# 公开 API 一览（API Reference）

> **本文件由脚本生成，请勿手工编辑**：`php tools/generate-api-docs.php`。
> 收录口径与 `tools/check-internal.php` 公开面门禁一致：interface 全部、`Nythros\Contracts` 命名空间全部、
> 未标 `@internal` 的类/枚举（ADR-023/024）。`@internal` 实现类不构成 API 承诺，业务层只依赖 Contracts 接口。
> 指南（用法与教程）见 [docs/ 索引](https://github.com/nythros/nythros/tree/master#文档索引)；本文件只做「有什么、叫什么、签名单什么」的索引。
> 摘要中的 P 编号（P9/P11/P15…）是阶段验收记录的追溯锚点，对应 [blueprint/](https://github.com/nythros/nythros/tree/master/blueprint) 目录的编号验收文档。
163 个公开符号（engine + framework）。


## nythros/engine

### `Nythros\Actor`

#### `BaseActor`
Actor 基类：管理 Actor 生命周期的基础状态——绑定的实体与逐帧更新入口。 · implements `Nythros\Contracts\ActorInterface` · abstract

| 方法 | 说明 |
|---|---|
| `bindEntity(Nythros\Contracts\EntityInterface $entity): void` | 将 Actor 绑定到实体；重复绑定会覆盖之前的实体。 |
| `update(): void` | Actor 每帧逻辑入口：由 ActorSystem 逐帧调用，子类实现具体行为。 |

### `Nythros\Cluster`

#### `ServiceInstance`
服务实例描述：discover 返回的存活实例单元（id + 元数据）。

| 方法 | 说明 |
|---|---|
| `__construct(string $id, array $meta)` | 构造服务实例描述。 |

#### `Nythros\Cluster\ServiceRegistryInterface`
服务注册表契约：服务实例注册/心跳/发现 + uid 寻址（bind/unbind/resolve）。

| 方法 | 说明 |
|---|---|
| `bind(string $serviceType, string $uid, string $serviceId, int $ttlSeconds = 21600): void` | 绑定 uid → 实例（覆盖写 = 同 uid 后登录者覆盖先登录者；会话期 TTL 与 token TTL 解耦，默认 21600s）。 |
| `discover(string $serviceType): array` | 发现存活实例：弱一致快照，至多 TTL 延迟；心跳键缺失即不可见。 |
| `heartbeat(string $serviceType, string $serviceId, array $meta = [...]): void` | 心跳续期；meta 与既有值原子合并（playerCount 上报）。 |
| `register(string $serviceType, string $serviceId, array $meta = [...]): void` | 注册服务实例；重复注册 = 覆盖 meta + 续心跳（自愈路径）。 |
| `resolve(string $serviceType, string $uid): ?string` | uid 寻址：返回该 uid 绑定的存活实例 id；无映射/实例已死返回 null。 |
| `unbind(string $serviceType, string $uid, string $serviceId): void` | 解除 uid → 实例绑定（仅当当前映射值等于 serviceId 才删，跨实例误删不可能）。 |
| `unregister(string $serviceType, string $serviceId): void` | 注销服务实例。 |

### `Nythros\Contracts`

#### `Nythros\Contracts\AOIProviderInterface`
AOI（兴趣区域）提供者契约：维护实体空间索引，支持位置更新、移除与近邻查询。

| 方法 | 说明 |
|---|---|
| `query(Nythros\Contracts\EntityInterface $entity): array` | 查询目标实体 AOI 范围内的可见实体集合：九宫格（当前格 + 周围 8 格）；是否包含自身由实现约定，GridAOI 含自身。 |
| `queryShape(Nythros\Contracts\ShapeInterface $shape): array` | 形状查询（AoE 批量命中管线原语）：返回形状覆盖范围内的实体。 |
| `remove(Nythros\Contracts\EntityInterface $entity): void` | 从 AOI 索引中移除实体；未登记的实体应被静默忽略。 |
| `updateEntity(Nythros\Contracts\EntityInterface $entity): array` | 登记或更新实体的空间位置，使其与 AOI 索引保持同步，并返回视野变化差分。 |

#### `Nythros\Contracts\ActorInterface`
Actor 契约：主动行为单元，由 Actor 系统每帧驱动一次更新。

| 方法 | 说明 |
|---|---|
| `update(): void` | 执行一帧 Actor 逻辑；由 Actor 系统在每帧对已注册 Actor 调用一次。 |

#### `Nythros\Contracts\ActorSystemInterface`
Actor 系统契约：管理 Actor 集合，并逐帧驱动全部已注册 Actor 更新。

| 方法 | 说明 |
|---|---|
| `add(Nythros\Contracts\ActorInterface $actor): void` | 注册 Actor；重复添加同一实例的行为由实现约定（通常忽略或替换）。 |
| `remove(Nythros\Contracts\ActorInterface $actor): void` | 注销 Actor；未注册的实例应被静默忽略。 |
| `updateAll(): void` | 更新所有已注册 Actor 一帧；遍历顺序与异常处理由实现约定。 |

#### `Nythros\Contracts\ClockInterface`
时钟契约：逻辑帧的时间基准，通过 tick 推进时间并暴露当前时间。

| 方法 | 说明 |
|---|---|
| `deltaTime(): float` | 获取最近一次 tick 的帧间隔（秒）；未 tick 过时为 0。 |
| `now(): float` | 获取最近一次 tick 采样的时间；未 tick 过时的返回值由实现约定（通常为 0）。 |
| `tick(): void` | 推进一次时钟节拍：采样最新时间并计算本帧间隔。 |

#### `Nythros\Contracts\EntityInterface`
实体契约：世界中可定位的对象，具备唯一 id、整数坐标与相对移动能力。

| 方法 | 说明 |
|---|---|
| `consumeMoved(): bool` | 读取并清除「本帧已移动」标志：置位返回 true 并复位，未置位返回 false。 |
| `getId(): string` | 获取实体唯一标识。 |
| `getPosition(): array` | 获取实体当前坐标。 |
| `markMoved(): void` | 置位「本帧已移动」标志（实体首次加入世界、传送等外部强制标记入口）。 |
| `move(int $dx, int $dy): void` | 相对移动实体：dx/dy 为相对当前位置的增量，而非绝对坐标。 |
| `setPosition(int $x, int $y): void` | 绝对重定位至 (x,y)：传送/房间进出等跨绝对坐标场景的唯一绝对定位入口。 |

#### `Nythros\Contracts\EntityManagerInterface`
实体管理器契约：按 id 维护实体注册表，提供增删、查找与全量列举。

| 方法 | 说明 |
|---|---|
| `add(Nythros\Contracts\EntityInterface $entity): void` | 注册实体；id 冲突时的行为由实现约定（通常覆盖或抛出异常）。 |
| `all(): array` | 获取全部已注册实体。 |
| `drainMoved(): array` | 取走并清空「本帧已移动」实体集合：返回自上次 drain 后发生位置变更（含首次登记）的全部实体， |
| `get(string $id): ?Nythros\Contracts\EntityInterface` | 按 id 查找实体，未找到返回 null。 |
| `remove(string $id): void` | 按 id 移除实体；id 不存在时应静默忽略。 |
| `walk(): iterable` | 遍历全部已注册实体（零拷贝）：直接走内部表迭代，不做 array_values 强制复制。 |

#### `Nythros\Contracts\EventBusInterface`
事件总线契约：事件发布与订阅的解耦通道，实现方负责把事件同步分发给全部订阅者。

| 方法 | 说明 |
|---|---|
| `flush(): void` | 处理所有待派发（pending）的事件信封；对同步派发实现可为空操作。 |
| `publish(string $event, array $payload = [...]): void` | 发布一个事件，携带可选载荷；发布时所有已订阅该事件的监听器都会收到通知。 |
| `publishEnvelope(Nythros\Contracts\EventEnvelope $envelope): void` | 发布一个事件信封；派发时机由实现约定——可入队待 flush 处理，也可同步分发。 |
| `subscribe(string $event, callable $listener): void` | 订阅一个事件；同一监听器重复订阅的行为由实现约定（通常去重或后注册覆盖）。 |

#### `EventEnvelope`
事件信封：结构化事件描述，携带来源、类型、时间戳、目标域、可靠性与丢弃策略及负载。

| 方法 | 说明 |
|---|---|
| `__construct(string $source, string $type, float $timestamp, ?string $targetScope, bool $reliable, bool $droppable, array $payload)` | 构造事件信封。 |

#### `Nythros\Contracts\PerfSnapshotProviderInterface`
性能快照供给者契约：向采样管线暴露进程内性能指标（计数器/直方图/累计值）的只读与消费式读取。

| 方法 | 说明 |
|---|---|
| `collect(): array` | 消费式读取：取走并清零全部累计数据（采样窗口语义：每次调用后各表归零重新累计）。 |
| `peek(): array` | 只读快照：读取当前全部累计但不清零（跨周期累计观测，如长时间漂移分析）。 |

#### `RoomConfig`
房间配置值对象：只读，描述一个房间实例的寻址、节奏、容量与 AOI 装配策略。

| 方法 | 说明 |
|---|---|
| `__construct(string $roomId, int $periodMs, int $maxMembers, $aoiFactory, int $maxCatchUpTicks = 4)` | 构造房间配置。 |

#### `Nythros\Contracts\RoomInstanceInterface`
房间实例契约：短生命周期小世界，扩展 WorldInterface 附加生命周期与成员进出能力。 · implements `Nythros\Contracts\WorldInterface`

| 方法 | 说明 |
|---|---|
| `close(): void` | 关闭：Settled→Closed，清空成员与索引，终态不可逆。非 Settled 态关闭抛 LogicException。 |
| `getConfig(): Nythros\Contracts\RoomConfig` | 只读房间配置（roomId/periodMs/maxMembers/aoiFactory/maxCatchUpTicks）：单一访问器整体返回 |
| `getRoomId(): string` | 获取房间唯一标识。 |
| `getState(): Nythros\Contracts\RoomState` | 获取当前生命周期状态。 |
| `join(Nythros\Contracts\EntityInterface $entity, ?Nythros\Contracts\ActorInterface $actor = NULL): bool` | 成员进入：EM 登记（即 markMoved 首帧进 AOI 索引）+ 可选 Actor 注册； |
| `leave(string $entityId): bool` | 成员离开：摘 EM + AOI + ActorSystem；非成员返回 false。 |
| `settle(): void` | 结算：Running→Settled（从未开房的 Created 空房允许静默结算）；停收成员， |

#### `Nythros\Contracts\RoomManagerInterface`
房间管理器契约：房间创建、归属校验与到期驱动的唯一编排入口。

| 方法 | 说明 |
|---|---|
| `all(): array` | 返回全部房间（登记顺序）。 |
| `create(Nythros\Contracts\RoomConfig $config): Nythros\Contracts\RoomInstanceInterface` | 创建并登记一个房间实例；roomId 重复抛 InvalidArgumentException。 |
| `destroy(string $roomId): void` | 异常路径销毁：内部强制 settle→close→移除并清除归属表；未知 roomId 静默。 |
| `evictFromAny(string $entityId): bool` | 跨容器断连清理（ADR-024 §9 V3）：按归属表定位实体所在房间并复用 leave 全链 |
| `get(string $roomId): ?Nythros\Contracts\RoomInstanceInterface` | 按 id 查询房间；不存在返回 null。 |
| `tick(float $now): array` | 宿主心跳驱动：线性扫描房间表 nextDueAt，到期房间依次 update()，本周期预算耗尽即止。 |
| `transfer(?string $fromRoomId, string $toRoomId, Nythros\Contracts\EntityInterface $entity, ?Nythros\Contracts\ActorInterface $actor = NULL): bool` | 跨容器成员迁移编排（含归属表校验，杜绝双房）：leave 源房 + join 目标房原子语义， |

#### `Nythros\Contracts\RoomState`
房间生命周期状态：Created（已创建未开房）→ Running（运行中，可加入成员）→ Settled（已结算，停收成员）→ Closed（终态，成员与索引清空）。 · implements `UnitEnum`
- Cases: `Created`, `Running`, `Settled`, `Closed`

| 方法 | 说明 |
|---|---|
| `static` `cases(): array` |  |

#### `Nythros\Contracts\SchedulerInterface`
调度器契约：按优先级收集可延期任务，每帧集中执行一帧的任务队列。

| 方法 | 说明 |
|---|---|
| `addTask(callable $task, int $priority = 0): void` | 提交一个任务，附带优先级；优先级语义（数值越大越先执行）由实现约定。 |
| `addTaskToRegion(string $region, callable $task, int $priority = 0): void` | 向指定分区提交任务；不支持分区的实现降级为 addTask（忽略 region 参数，不得抛异常）。 |
| `runFrame(): void` | 执行本帧排定的全部任务（按优先级顺序）。 |

#### `Nythros\Contracts\ShapeInterface`
形状契约：AoE 批量命中管线的引擎原语——纯函数式的点包含判定与包围盒粗筛依据。

| 方法 | 说明 |
|---|---|
| `bounds(): array` | 包围盒：必须完整覆盖 contains=true 的格范围（AOI 粗筛依据，允许保守外扩、不允许遗漏）。 |
| `contains(int $x, int $y): bool` | 点是否在形状内（整数坐标、浮点判定、边界含入）。 |

#### `Nythros\Contracts\TimerInterface`
定时器契约：基于秒的定时回调调度，支持持久（重复触发）与单次定时器。

| 方法 | 说明 |
|---|---|
| `add(float $intervalSeconds, callable $callback, bool $persistent = true): int` | 添加定时器，到期时调用回调；持久定时器到期后自动重新调度，直至被 cancel。 |
| `cancel(int $timerId): void` | 取消指定 id 的定时器；id 不存在时应静默忽略。 |

#### `Nythros\Contracts\WorldInterface`
世界门面契约：聚合实体管理、Actor 系统、AOI、事件总线与调度，驱动单帧世界更新。

| 方法 | 说明 |
|---|---|
| `getAOI(): Nythros\Contracts\AOIProviderInterface` | 获取 AOI 兴趣区域提供者：GridAOI（九宫格视野）或 UniversalAOI（全量广播 = 全世界即视野，无空间索引），恒非空。 |
| `getActorSystem(): Nythros\Contracts\ActorSystemInterface` | 获取 Actor 系统。 |
| `getEntityManager(): Nythros\Contracts\EntityManagerInterface` | 获取实体管理器。 |
| `getEventBus(): Nythros\Contracts\EventBusInterface` | 获取事件总线。 |
| `getScheduler(): Nythros\Contracts\SchedulerInterface` | 获取帧调度器。 |
| `getType(): Nythros\Contracts\WorldType` | 获取本世界的类型（AOI / 全量广播）。 |
| `update(): void` | 推进一帧世界状态（actor 更新 + AOI 同步 + 调度）。 |

#### `Nythros\Contracts\WorldType`
世界类型枚举：区分「AOI 局域广播」与「全量广播」两类 World 的同步语义。 · implements `UnitEnum`
- Cases: `AOI`, `FULL_BROADCAST`

| 方法 | 说明 |
|---|---|
| `static` `cases(): array` |  |

### `Nythros\Entity`

#### `CircleShape`
圆形形状值对象：圆心加半径，contains 用整数平方距离精确判定（无浮点误差、边界含入）。 · implements `Nythros\Contracts\ShapeInterface`

| 方法 | 说明 |
|---|---|
| `__construct(int $cx, int $cy, int $r)` | 构造圆形形状。 |
| `bounds(): array` | 包围盒：圆的外接正方形。 |
| `contains(int $x, int $y): bool` | 点是否在圆内（含圆周）：平方距离比较，全程整数运算、结果精确。 |

#### `Position`
二维位置值对象：只读坐标加平移运算，平移返回新实例而非原地修改。

| 方法 | 说明 |
|---|---|
| `__construct(int $x, int $y)` | 构造一个二维位置。 |
| `move(int $dx, int $dy): Nythros\Entity\Position` | 按增量平移并返回新 Position；原实例保持不变（不可变语义）。 |

#### `RectangleShape`
矩形形状值对象：锚点为最小角（minX/minY），w/h 为正向宽高，四边含入。 · implements `Nythros\Contracts\ShapeInterface`

| 方法 | 说明 |
|---|---|
| `__construct(int $x, int $y, int $w, int $h)` | 构造矩形形状。 |
| `bounds(): array` | 包围盒：矩形本身。 |
| `contains(int $px, int $py): bool` | 点是否在矩形内（含四边）：逐轴闭区间比较。 |

#### `SectorShape`
扇形形状值对象：angleDeg 为朝向中心线方向、fovDeg 为全张角（半张角 = fovDeg/2）， · implements `Nythros\Contracts\ShapeInterface`

| 方法 | 说明 |
|---|---|
| `__construct(int $cx, int $cy, int $r, float $angleDeg, float $fovDeg)` | 构造扇形形状。 |
| `bounds(): array` | 包围盒：取整圆外接框——保守粗筛保证不漏任何 contains=true 的格，精判交给 contains。 |
| `contains(int $x, int $y): bool` | 点是否在扇形内（边界含入）：距离不超半径且与朝向的角差不超过半张角； |

### `Nythros\Network`

#### `ConnectionClosedException`
连接已关闭异常：对已关闭连接执行发送等操作时抛出。 · extends `RuntimeException` · implements `Stringable`, `Throwable`

#### `Nythros\Network\ConnectionInterface`
网络连接抽象：屏蔽底层实现，提供发送/关闭/认证/缓冲回压的统一视图。

| 方法 | 说明 |
|---|---|
| `close(): void` | 关闭连接。 |
| `getId(): string` | 获取连接唯一标识符。 |
| `getLastMessageTime(): float` | 获取最近一次收到消息的时间戳。 |
| `getRemoteAddress(): string` | 获取远端地址。 |
| `getSendBufferQueueSize(): int` | 获取底层发送队列中尚未写入内核的字节数（慢客户端软/硬阈值检测用；0 表示无积压）。 |
| `isAuthenticated(): bool` | 判断连接是否已通过认证。 |
| `isClosed(): bool` | 判断连接是否已关闭。 |
| `isInternal(): bool` | 判断连接是否为内部服务连接。 |
| `markAuthenticated(): void` | 将连接标记为已通过认证。 |
| `markInternal(): void` | 将连接标记为内部服务连接（服务间 RPC transport，rpc:hello 握手登记后调用；限流豁免依据，MINOR-3）。 |
| `onBufferDrain(callable $handler): void` | 注册发送缓冲区排空时的回调。 |
| `onBufferFull(callable $handler): void` | 注册发送缓冲区写满时的回调。 |
| `send(string $payload): void` | 向连接发送负载。 |
| `sendBatch(array $payloads): void` | 按顺序批量发送多条负载；空数组为空操作，语义与 send 一致。 |

#### `Nythros\Network\RateLimiterInterface`
限流器抽象：按连接维度消费令牌，用于防刷/流量整形。

| 方法 | 说明 |
|---|---|
| `consume(string $connectionId, int $tokens = 1): bool` | 为指定连接消费令牌。 |
| `forget(string $connectionId): void` | 断连时释放指定连接的令牌桶。 |

#### `Nythros\Network\ServerInterface`
服务器抽象：统一各传输实现的启动/停止与事件挂载入口。

| 方法 | 说明 |
|---|---|
| `onClose(callable $handler): void` | (ConnectionInterface $conn) |
| `onConnect(callable $handler): void` | (ConnectionInterface $conn) |
| `onMessage(callable $handler): void` | (ConnectionInterface $conn, string $data) data 为已解帧负载，解码由上层 Serializer 完成 |
| `onWorkerStart(callable $handler): void` | 启动周期任务挂载点（Clock/Timer） |
| `onWorkerStop(callable $handler): void` | 注册 Worker 退出回调（追加式：优雅退出时按注册顺序依次执行，供 unregister 等清理钩子挂载）。 |
| `start(): void` | 启动服务器（阻塞运行事件循环）。 |
| `stop(): void` | 停止服务器。 |

### `Nythros\Persistence`

#### `Nythros\Persistence\RepositoryInterface`
仓储契约：面向单类聚合的存取门面（find/persist/remove/findBy）。

| 方法 | 说明 |
|---|---|
| `find(string $id): ?array` | 按主键查找；不存在返回 null。 |
| `findBy(string $field, $value): array` | 按字段值查找全部匹配记录；无匹配返回空数组。 |
| `persist(string $id, array $state): void` | 写入或覆盖记录状态。 |
| `remove(string $id): void` | 移除记录；不存在视为成功（幂等）。 |

#### `Nythros\Persistence\StorageInterface`
存储契约：按集合分区的键值持久化原语（异步归档与同步双写的共同底层）。

| 方法 | 说明 |
|---|---|
| `delete(string $collection, string $id): bool` | 删除单条记录；不存在视为成功（幂等）。 |
| `load(string $collection, string $id): ?array` | 读取单条记录；不存在返回 null。 |
| `save(string $collection, string $id, array $data): bool` | 保存单条记录；失败返回 false（不抛异常）。 |
| `saveBatch(string $collection, array $records): array` | 批量保存；返回失败 id 列表（供归档重试与日志归因）。 |

### `Nythros\Protocol`

#### `Nythros\Protocol\BatchSerializerInterface`
批量序列化器契约：在单帧序列化（SerializerInterface）之上增加「一包多帧」的批量编码/解码。 · implements `Nythros\Protocol\SerializerInterface`

| 方法 | 说明 |
|---|---|
| `decodeBatch(string $bytes): array` | 解码批量包字节为消息列表（空包返回空列表）。 |
| `encodeBatch(array $messages): string` | 将多条消息编码为一个批量包字节串。 |

#### `DecodeException`
解码异常：字节串不是合法协议包时抛出。 · extends `Nythros\Protocol\ProtocolException` · implements `Throwable`, `Stringable`

#### `Frame`
帧（Frame）：协议消息的原始字节承载对象。 · implements `Nythros\Protocol\FrameInterface`

| 方法 | 说明 |
|---|---|
| `__construct(string $bytes)` | 构造帧。 |
| `bytes(): string` | 返回帧的原始字节内容。 |

#### `Nythros\Protocol\FrameInterface`
帧接口：任何可提供原始字节的协议包载体都必须实现它。

| 方法 | 说明 |
|---|---|
| `bytes(): string` | 返回帧的原始字节内容。 |

#### `Message`
协议消息：序列化前/反序列化后的内存表示。

| 方法 | 说明 |
|---|---|
| `__construct(string $type, ?string $requestId, float $timestamp, array $payload)` | 构造协议消息。 |
| `static` `create(string $type, array $payload = [...], ?string $requestId = NULL, ?float $timestamp = NULL): self` | 便捷工厂：缺省 timestamp 用 microtime(true)。 |

#### `ProtocolException`
协议异常：协议层编解码失败时抛出。 · extends `RuntimeException` · implements `Stringable`, `Throwable`

#### `ProtocolVocabulary`
协议词汇表：维护「帧类型 ↔ 编码」与「负载字段名 ↔ 编码」的双向映射，供二进制序列化器使用。

| 方法 | 说明 |
|---|---|
| `__construct(array $typeCodes, array $keyCodes)` | 构造词汇表并构建反向映射。 |
| `keyCode(string $key): ?int` | 负载字段名 → 编码；未知字段返回 null。 |
| `keyName(int $code): ?string` | 编码 → 负载字段名；未知编码返回 null。 |
| `typeCode(string $type): ?int` | 帧类型名 → 编码；未知类型返回 null（调用方决定抛错或兜底）。 |
| `typeName(int $code): ?string` | 编码 → 帧类型名；未知编码返回 null。 |

#### `Nythros\Protocol\SerializerInterface`
序列化器接口：负责 Message 与帧字节之间的双向转换。

| 方法 | 说明 |
|---|---|
| `decode(Nythros\Protocol\FrameInterface $frame): Nythros\Protocol\Message` | 将帧字节解码为消息。 |
| `encode(Nythros\Protocol\Message $message): Nythros\Protocol\FrameInterface` | 将消息编码为帧。 |

### `Nythros\Security`

#### `AuthenticationException`
认证异常：凭证无效或认证失败时抛出。 · extends `RuntimeException` · implements `Stringable`, `Throwable`

#### `Nythros\Security\AuthenticatorInterface`
认证器接口：校验凭证并产出身份。

| 方法 | 说明 |
|---|---|
| `authenticate(array $credentials): Nythros\Security\IdentityInterface` | 校验凭证并返回身份；凭证无效时抛出异常。 |

#### `Nythros\Security\IdentityInterface`
身份接口：认证成功后获得的用户身份。

| 方法 | 说明 |
|---|---|
| `getUserId(): string` | 返回用户唯一标识。 |
| `getUsername(): string` | 返回用户名。 |

#### `Nythros\Security\TokenManagerInterface`
Token 管理器接口：签发（多 scope）、一次性消费与只读查看。

| 方法 | 说明 |
|---|---|
| `consume(string $token, string $scope): Nythros\Security\TokenStatus` | 五态判定，一次性消费（带 scope）。 |
| `issue(string $uid, string $mapId, array $scopes = [...], int $ttlSeconds = 30): string` | 签发 token：短 TTL（默认 30s）；返回 64 字符 hex token。 |
| `peek(string $token): ?Nythros\Security\TokenRecord` | 只读查看（不消费）：格式非法或不存在/已消费/已过期返回 null。 |

#### `Nythros\Security\TokenStatus`
Token 五态枚举：consume 的一次性判定结果（决策 F：token 多授权）。 · implements `UnitEnum`
- Cases: `Valid`, `Expired`, `Replayed`, `Invalid`, `Unauthorized`

| 方法 | 说明 |
|---|---|
| `static` `cases(): array` |  |

#### `Nythros\Security\TokenStoreInterface`
Token 存储接口：定义 token 的持久化与五态判定契约。

| 方法 | 说明 |
|---|---|
| `consume(string $token, string $scope): Nythros\Security\TokenStatus` | 原子消费五态：Valid（该 scope 首次消费成功）/ Expired（存在但超时）/ Replayed（该 scope 已消费）/ Invalid（不存在或格式非法）/ Unauthorized（scope 未授权，不消费）。 |
| `peek(string $token): ?Nythros\Security\TokenRecord` | 只读查看（不消费）：主键存在且未过期 → 返回含 scopes 的记录；主键缺失/畸形/已过期 → null。 |
| `remove(string $token): void` | 移除 token：仅删除主记录/主键（不写总墓碑）。per-scope 墓碑各自 TTL 自然消亡—— |
| `save(string $token, Nythros\Security\TokenRecord $record, int $ttlSeconds): void` | 保存 Token 记录。 |

## nythros/framework

### `Nythros\Framework`

#### `BaseMonster`
怪物基类：AI 状态机骨架 + 最小战斗面；takeDamage 模板方法内闭环死亡结算。 · extends `Nythros\Actor\BaseActor` · implements `Nythros\Contracts\ActorInterface`, `Nythros\Framework\Damageable` · abstract

| 方法 | 说明 |
|---|---|
| `__construct(string $monsterId, int $maxHp, string $typeId = '')` | matching / visual identity; default '' = unspecified). |
| `aiState(): string` |  |
| `damageContributors(): array` | 伤害账本快照（按累计伤害降序；平局按先达序——arsort 保持键序稳定性由插入序保证）。 |
| `damageLeader(): ?string` | 伤害账本最高者（击杀归属 damage_leader 裁决；空账本返回 null；平局取先达）。 |
| `enterState(string $state): void` | 状态迁移：白名单校验，非法状态抛 InvalidArgumentException；DEAD 为终态，不再迁出。 |
| `heal(int $amount): void` | 治疗：恢复生命值，钳制在 maxHp 内；已死不复活。 |
| `hp(): int` |  |
| `isDead(): bool` |  |
| `lastAttacker(): ?string` | 最近一次伤害来源实体 id；未被命中过返回 null。 |
| `maxHp(): int` |  |
| `monsterId(): string` |  |
| `noteAttacker(string $attackerId): void` | 记录伤害来源（击杀归属绑定）：每次有效扣血前由结算方调用，死亡时以最后来源为击杀者。 |
| `noteDamage(string $attackerId, int $amount): void` | 记入伤害账本（P13 多源归属）：每次有效扣血前由结算方按伤害量累加（非负钳制，0 伤害不入账）。 |
| `setTarget(?string $targetId): void` | 设置/清除追击目标。 |
| `setTickDivisor(int $divisor): void` | 设置分频（governor 每 base tick 重算指派）；非法值（<1）钳制为 1。 |
| `takeDamage(int $amount): void` | 模板方法：扣血钳制归零；归零时迁移 DEAD 并幂等触发一次 onDeath。 |
| `targetId(): ?string` |  |
| `tickDivisor(): int` |  |
| `typeId(): string` | 怪物类型 id（如 'wolf'）：任务击杀进度源的匹配键；未指定时为空串。 |
| `update(): void` | 模板方法：按 aiState 分发钩子；DEAD 每帧只走 onDead，onDeath 仅在死亡瞬间触发一次。 |

#### `BaseNPC`
NPC 基类：静态实体，无主动行为；交互由玩家触发 onInteract。 · extends `Nythros\Actor\BaseActor` · implements `Nythros\Contracts\ActorInterface` · abstract

| 方法 | 说明 |
|---|---|
| `__construct(string $npcId)` |  |
| `npcId(): string` |  |
| `onInteract(Nythros\Framework\BasePlayer $player): void` | 交互入口：由玩家触发，子类实现对话/商店等交互内容。 |
| `update(): void` | 模板方法：静态实体默认空操作，交由子类 onIdle 钩子。 |

#### `BasePlayer`
玩家基类：承载连接/身份与最小战斗面，模板方法 takeDamage 内闭环死亡结算。 · extends `Nythros\Actor\BaseActor` · implements `Nythros\Contracts\ActorInterface`, `Nythros\Framework\Damageable` · abstract

| 方法 | 说明 |
|---|---|
| `addAttributeModifier(string $attribute, int $delta): void` | 叠加一条属性临时修正（增量可正可负）：聚合表累加后把 hp 收敛进新合成上限 |
| `attachConnection(string $connectionId, string $uid): void` | 绑定连接与玩家 uid。 |
| `attachEquipment(Nythros\Framework\Inventory\Equipment\Equipment $equipment): void` | 挂载装备栏（属性聚合入口）：挂载即把 hp 收敛进合成上限。 |
| `attributeModifierSum(string $attribute): int` | 查询某属性的临时修正当前和（未登记返回 0）。 |
| `clampHpToMax(): void` | 把当前 hp 收敛进合成上限（装备变更后的不变量维护点）。 |
| `connectionId(): ?string` | 当前连接标识；未绑定时为 null。 |
| `detachConnection(): void` | 解除连接绑定。 |
| `detachEquipment(): void` | 摘除装备栏：加成清零后同样收敛 hp（卸下减益装备可能压低上限）。 |
| `equipment(): ?Nythros\Framework\Inventory\Equipment\Equipment` | 当前装备栏；未挂载为 null。 |
| `heal(int $amount): void` | 治疗：恢复生命值，钳制在合成上限内；已死不复活。 |
| `hp(): int` |  |
| `initVitals(int $maxHp): void` | 初始化生命基线（P18 玩法数据外置，auth 挂载时一次性调用）：覆盖基础 maxHp 并回满—— |
| `isDead(): bool` |  |
| `maxHp(): int` | 合成最大生命值：基础 maxHp + 装备 maxHp 加成 + 属性临时修正和（D6 聚合口径 + R3 玩法批临时修正）。 |
| `removeAttributeModifier(string $attribute, int $delta): void` | 回退一条属性临时修正（按施加时的同一增量对称回退）：归零键摘除，防止表无限膨胀。 |
| `setTickDivisor(int $divisor): void` | 设置分频（governor 每 base tick 重算指派）；非法值（<1）钳制为 1。 |
| `takeDamage(int $amount): void` | 模板方法：扣血钳制归零；从存活→死亡的那次伤害触发一次 onDeath。 |
| `tickDivisor(): int` |  |
| `uid(): ?string` | 玩家唯一标识；未绑定时为 null。 |
| `update(): void` | 模板方法：每帧统一入口，交由子类 onTick 钩子实现具体帧逻辑。 |

#### `Nythros\Framework\Damageable`
可损伤面：玩家与怪物共同实现的最小战斗契约，使战斗服务（CombatService）的 attack

| 方法 | 说明 |
|---|---|
| `heal(int $amount): void` | 治疗：恢复生命值，不越过上限。 |
| `hp(): int` | 当前生命值。 |
| `isDead(): bool` | 是否已死亡（生命值归零）。 |
| `maxHp(): int` | 最大生命值上限。 |
| `takeDamage(int $amount): void` | 模板方法：扣血钳制归零，归零时幂等触发死亡结算（见 BasePlayer/BaseMonster 实现）。 |

#### `Inventory`
玩家背包：itemId => count 的计数表。

| 方法 | 说明 |
|---|---|
| `add(string $itemId, int $count): void` | 入包：同 itemId 数量累加。 |
| `all(): array` | 返回全部物品（itemId => count）。 |
| `count(string $itemId): int` | 查询某物品数量；未持有返回 0。 |
| `remove(string $itemId, int $count): void` | 出包：数量不足时整组移除（不会出现负数）。 |

### `Nythros\Framework\Actor`

#### `PlayerActor`
玩家 Actor：继承 BasePlayer，承载玩家身份与最小战斗面；钩子实现冷却递减、属性同步与死亡标记。 · extends `Nythros\Framework\BasePlayer` · implements `Nythros\Framework\Damageable`, `Nythros\Contracts\ActorInterface`

| 方法 | 说明 |
|---|---|
| `__construct(string $entityId, ?Nythros\Framework\Combat\VisionBroadcasterInterface $broadcaster = NULL)` |  |
| `attackCooldown(): int` | 当前攻击冷却剩余帧数。 |
| `enableSpawnProtection(?int $frames = NULL): void` | 激活出生保护窗口（auth 挂载时由装配层调用）：从下一帧起倒数 frames 帧（缺省 SPAWN_PROTECTION_FRAMES， |
| `entityId(): string` | 返回玩家实体 id（与 getPlayerId 等价，供 CombatService 解析 id）。 |
| `getPlayerId(): string` | 返回玩家实体 id（MapServerTest 依赖）。 |
| `importHp(int $hp): void` | 导入血量（P15 跨 map 迁移快照重建）：clamp 进 [1, 合成 maxHp]——不迁移死亡态（快照 hp ≤0 视为 |
| `isAttackReady(): bool` | 是否可发起攻击（冷却已归零）。 |
| `isAwaitingRevive(): bool` | 是否处于待复活状态。 |
| `isSpawnProtected(): bool` | 是否处于出生保护期（怪物感知/攻击跳过依据）。 |
| `revive(): void` | 复活（P5a 接入，消费 awaitingRevive 标记）：清待复活标记并回满血——demo 玩家死亡仅状态标记， |
| `startAttackCooldown(): void` | 开始攻击冷却（攻击成功后由调用方触发）。 |

### `Nythros\Framework\Auction`

#### `AuctionService`
交易行服务：挂单（扣货托管）/购买（Lua 原子结算+邮件交付）/撤单（邮件退回）。

| 方法 | 说明 |
|---|---|
| `__construct(Nythros\Framework\Auction\AuctionStore $store, Nythros\Framework\Auction\CurrencyLedger $ledger, Nythros\Framework\Mail\MailService $mail, ?Closure $idFactory = NULL)` | 构造交易行服务。 |
| `buy(string $buyerUid, string $auctionId, int $price): array` | 购买：Lua 原子结算（校验+删单+买家扣款+卖家入账）→ 发货邮件；邮件失败走补偿 |
| `cancel(string $sellerUid, string $auctionId): bool` | 撤单：Lua 原子归属校验+删单 → 退回邮件（附件=原货物）；邮件失败恢复挂单后原样抛出。 |
| `sell(string $sellerUid, Nythros\Framework\Inventory $inventory, string $itemId, int $count, int $price): string` | 挂单：从背包扣货托管 → 登记挂单。扣货成功但登记失败时回滚背包（托管未落库，货必须回包）。 |

#### `AuctionStore`
交易行挂单存储（Redis 持久，无 TTL；购买/撤单走 Redis Lua 原子语义）。

| 方法 | 说明 |
|---|---|
| `__construct(Redis\|Closure $redis, string $prefix = 'nythros:ec:')` | 构造交易行存储。 |
| `cancel(string $auctionId, string $sellerUid): bool` | 撤单（Lua 原子归属校验+删单）：true = 已撤（含残缺挂单的直接删除路径——货物信息不可信， |
| `create(string $auctionId, string $sellerUid, string $itemId, int $count, int $price): bool` | 登记挂单（托管落库）；auctionId 已存在时返回 false（幂等防护）。 |
| `get(string $auctionId): ?array` | 读取挂单。 |
| `purchase(string $auctionId, string $buyerUid, int $price): array` | 购买结算（Lua 原子）：成功返回 ok=true + 删单前快照（seller/itemId/count，供发货邮件构造）； |

#### `CurrencyLedger`
货币账本（D2 缺口最小语义：余额/托管/结算的余额面）。

| 方法 | 说明 |
|---|---|
| `__construct(Redis\|Closure $redis, string $prefix = 'nythros:ec:')` | 构造货币账本。 |
| `balance(string $uid): int` | 查询余额；无记录（从未入账）返回 0。 |
| `deposit(string $uid, int $amount): void` | 入账（正整数）；INCRBY 天然建键。 |
| `withdraw(string $uid, int $amount): bool` | 出账：余额充足时扣减返回 true；不足时不产生任何变更返回 false。 |

### `Nythros\Framework\Auth`

#### `Identity`
身份对象：不可变的 userId + username 组合，demo 阶段两者取同值。 · implements `Nythros\Security\IdentityInterface`

| 方法 | 说明 |
|---|---|
| `__construct(string $userId, string $username)` | 构造身份对象。 |
| `getUserId(): string` | 返回用户唯一标识。 |
| `getUsername(): string` | 返回用户名。 |

#### `ThrottledAuthenticator`
防爆破认证装饰器：按 username 统计连续失败，达到阈值后锁定一段时间，成功即清零。 · implements `Nythros\Security\AuthenticatorInterface`

| 方法 | 说明 |
|---|---|
| `__construct(Nythros\Security\AuthenticatorInterface $inner, int $maxAttempts = 5, int $lockoutSeconds = 60, ?Closure $clock = NULL)` | 构造防爆破装饰器。 |
| `authenticate(array $credentials): Nythros\Security\IdentityInterface` |  |

### `Nythros\Framework\Cluster`

#### `InMemoryPlayerTransferStore`
转移票据的进程内存储（ADR-025）：单进程形态（单测/纯消息模式）用——与 InMemoryTokenStore 同范式。 · implements `Nythros\Framework\Cluster\PlayerTransferStoreInterface`

| 方法 | 说明 |
|---|---|
| `consume(string $uid): ?array` |  |
| `export(string $uid, array $snapshot): void` |  |

#### `Nythros\Framework\Cluster\PlayerTransferStoreInterface`
跨 map 实体迁移的快照票据存储契约（ADR-025 方案 C：客户端驱动换线 + 转移票据）。

| 方法 | 说明 |
|---|---|
| `consume(string $uid): ?array` | 原子消费快照票据（目的端 attach 时调用；取走即删，无票返回 null）。 |
| `export(string $uid, array $snapshot): void` | 导出实体状态快照（源端 detach 时调用；覆盖同 uid 旧票）。 |

#### `RedisPlayerTransferStore`
转移票据的 Redis 存储（ADR-025）：SETEX 覆盖导出 + Lua GET+DEL 原子消费。 · implements `Nythros\Framework\Cluster\PlayerTransferStoreInterface`

| 方法 | 说明 |
|---|---|
| `__construct(Redis\|Closure $redis, string $prefix = 'nythros:', int $ttlSeconds = 30)` |  |
| `consume(string $uid): ?array` |  |
| `export(string $uid, array $snapshot): void` |  |

### `Nythros\Framework\Combat`

#### `Nythros\Framework\Combat\ActorLookupInterface`
按 entityId 查 Actor：MonsterActor 解析目标 PlayerActor 用；MapServer 以 $actors 表实现（玩家+怪物都登记）。

| 方法 | 说明 |
|---|---|
| `getActor(string $entityId): ?Nythros\Contracts\ActorInterface` | 按实体 id 查询已登记的 Actor；未登记返回 null。 |
| `removeActor(string $entityId): void` | 按实体 id 摘除已登记的 Actor（怪物死亡自清理用）；未登记时静默忽略。 |

#### `BuffService`
Buff 服务（R3 玩法批正式化）：施加/叠加裁决、到期 tick 与效果结算（属性修正/DOT）的状态机。

| 方法 | 说明 |
|---|---|
| `__construct(Nythros\Framework\Plugin\Buff\BuffRepository $definitions, ?Nythros\Framework\Combat\VisionBroadcasterInterface $broadcaster = NULL, ?Nythros\Framework\Event\EventDispatcherInterface $events = NULL)` |  |
| `apply(string $hostKey, Nythros\Framework\BasePlayer $host, string $buffId, float $now): bool` | 施加 buff：定义校验 → 互斥组顶替 → 叠加规则裁决（首次/refresh/stack）→ 属性修正登记 → 广播与事件。 |
| `instanceOf(string $hostKey, string $buffId): ?Nythros\Framework\Plugin\Buff\BuffInstance` | 查询宿主的某 buff 实例；不存在返回 null。 |
| `instancesOf(string $hostKey): array` | 查询宿主全部在身实例（buffId => BuffInstance）。 |
| `purgeHost(string $hostKey): void` | 宿主清理（断连路径调用）：摘除该宿主全部实例（无广播——连接已断，帧无人可收）。 |
| `remove(string $hostKey, Nythros\Framework\BasePlayer $host, string $buffId): bool` | 主动驱散：实例存在即摘除（修正回退 + 广播）；不存在静默 false。 |
| `tick(float $now, ?callable $hostResolver = NULL): void` | 到期 tick（定时任务路径，组装层周期调用）：遍历全部实例——到期者摘除（先于 DOT 判定： |

#### `CombatService`
战斗服务：普攻/技能伤害结算、死亡掉落生成与拾取结算（纯业务，可单测）。

| 方法 | 说明 |
|---|---|
| `__construct(Nythros\Contracts\WorldInterface $world, Nythros\Framework\Combat\VisionBroadcasterInterface $broadcaster, Nythros\Framework\Plugin\Skill\SkillRepository $skills, Nythros\Framework\Plugin\Item\ItemRepository $items, Nythros\Framework\Combat\RandomSourceInterface $random, ?Nythros\Framework\Combat\ActorLookupInterface $actorLookup = NULL, ?Nythros\Framework\Combat\EntityTypeIndex $typeIndex = NULL, ?Nythros\Framework\Combat\TeamMembershipInterface $teams = NULL, int $dropLifetimeSeconds = 300, ?Nythros\Framework\Event\EventDispatcherInterface $events = NULL, string $killCredit = 'last_hit', ?Closure $pvpGate = NULL)` | 缺省 null = 未装配，castSkillAoE 抛 LogicException） Entity-id → Damageable resolution table (the AoE hit |
| `attack(Nythros\Framework\Damageable $attacker, Nythros\Framework\Damageable $target): void` | 普攻结算（双向：玩家→怪物 / 怪物→玩家）：伤害 = 基础 × random 浮动 → target->takeDamage → |
| `broadcastDeath(Nythros\Framework\Damageable $target): void` | 广播实体死亡帧 entity_dead{id}（视野）。供攻击结算与 MonsterActor.onDeath 共用。 |
| `castSkill(Nythros\Framework\Damageable $caster, string $skillId, Nythros\Framework\Damageable $target): void` | 技能结算：查 SkillRepository → 伤害 = 普攻 × damageMultiplier × random 浮动 → 同 attack 结算。 |
| `castSkillAoE(Nythros\Framework\Damageable $caster, string $skillId, Nythros\Contracts\ShapeInterface $shape): array` | AoE 批量命中管线（ADR-024 §D-C）：1 次 queryShape（引擎原语，形状查询归引擎）→ N 次 takeDamage |
| `pickup(Nythros\Framework\Damageable $player, Nythros\Framework\Combat\DropEntity $drop, Nythros\Framework\Inventory $inventory): bool` | 拾取结算：itemId 经 items 校验 → 归属校验（击杀者本人/同队可拾，非归属者拒绝并定向 combat:error）→ |
| `purgeExpiredDrops(float $now): int` | 过期回收扫描（定时回收路径，装配层周期调用）：遍历在场掉落登记表，摘除已过期掉落 |
| `spawnDrops(string $monsterId, array $position, array $drops, ?string $killerUid = NULL, ?int $lifetimeSecondsOverride = NULL): void` | 死亡掉落：在 monsterId 实体位置为每个掉落生成 DropEntity——itemId 经 items 校验（非法跳过）→ |
| `spawnDropsBatch(string $visionCenterId, array $wave): void` | 批量掉落（掉落风暴，ADR-024 §D-D）：一波怪物死亡的掉落合并为单条 drop:spawned_batch 帧—— |

#### `DropEntity`
掉落物实体：实现 EntityInterface，携带 itemId/count 与自持整数坐标。 · implements `Nythros\Contracts\EntityInterface`

| 方法 | 说明 |
|---|---|
| `__construct(string $id, int $x, int $y, string $itemId, int $count, ?string $ownerUid = NULL, ?string $ownerTeamId = NULL, ?float $expiresAt = NULL)` |  |
| `consumeMoved(): bool` |  |
| `getId(): string` |  |
| `getPosition(): array` |  |
| `isExpired(float $now): bool` | 是否已过期（供定时回收扫描判定）；永不过期型恒 false。 |
| `markMoved(): void` |  |
| `move(int $dx, int $dy): void` |  |
| `setPosition(int $x, int $y): void` | 绝对重定位至 (x,y)：与 move() 同路径置位 moved 标志（坐标未变亦置位）。 |

#### `DropEntry`
掉落表条目值对象：单条目的权重与数量区间（掉落正式化，R3 经济批模块 2）。

| 方法 | 说明 |
|---|---|
| `__construct(string $itemId, int $weight, int $minCount = 1, int $maxCount = 1)` |  |

#### `DropTable`
掉落表（正式化版）：每条目独立 roll 是否掉落，命中后数量在 [minCount, maxCount] 区间内独立 roll。

| 方法 | 说明 |
|---|---|
| `__construct(array $entries, int $noDropWeight = 0)` |  |
| `static` `fromRows(array $rows, int $noDropWeight = 0): self` | 数据表构造（P11 掉落表外置）：从行声明数组构建——每行 {itemId, weight, minCount?, maxCount?}， |
| `roll(Nythros\Framework\Combat\RandomSourceInterface $random): array` | 多条目独立 roll：逐条目在 [1, weight + noDropWeight] 上掷点，落入前 noDropWeight 段（不掉落段）则跳过， |

#### `EntityTypeIndex`
实体类型索引：entityId → kind（player/monster/drop）的类型登记表。

| 方法 | 说明 |
|---|---|
| `kindOf(string $entityId): ?string` | 查询实体类型；未登记返回 null。 |
| `remove(string $entityId): void` | 摘除实体类型登记（cleanup/死亡/拾取处同步删除）；未登记时静默忽略。 |
| `set(string $entityId, string $kind): void` | 登记实体类型（auth → player、spawnMonster → monster、spawnDrops → drop）。 |

#### `Nythros\Framework\Combat\RandomSourceInterface`
随机源：伤害浮动/掉落 roll 用，可注入确定实现做单测。

| 方法 | 说明 |
|---|---|
| `randomInt(int $min, int $max): int` | 返回 [min, max] 闭区间内的随机整数。 |

#### `SeededRandomSource`
可播种随机源（P14 E2E 工程化）：基于 PHP 内置 Mt19937 引擎（Random\Randomizer + Random\Engine\Mt19937）—— · implements `Nythros\Framework\Combat\RandomSourceInterface`

| 方法 | 说明 |
|---|---|
| `__construct(int $seed)` |  |
| `randomInt(int $min, int $max): int` |  |

#### `SkillCooldownTable`
技能冷却表（R3 玩法批收编）：按「施法者键 × 技能 id」维度管理技能独立冷却（秒制，与普攻攻击冷却的

| 方法 | 说明 |
|---|---|
| `isReady(string $casterKey, string $skillId, float $now): bool` | 是否就绪：无记录或 now ≥ 就绪时刻即就绪。 |
| `remaining(string $casterKey, string $skillId, float $now): float` | 剩余冷却秒数（已就绪返回 0.0）。 |
| `reset(string $casterKey): void` | 清空某施法者的全部冷却记录（断连清理路径）。 |
| `start(string $casterKey, string $skillId, float $cooldownSeconds, float $now): void` | 置冷：记录 casterKey×skillId 的就绪时刻 = now + cooldownSeconds（非正冷却视为瞬时就绪，仍覆盖旧记录）。 |

#### `SystemRandomSource`
系统随机源：基于 random_int 的真实随机实现，生产组装用；测试注入确定实现。 · implements `Nythros\Framework\Combat\RandomSourceInterface`

| 方法 | 说明 |
|---|---|
| `randomInt(int $min, int $max): int` |  |

#### `Nythros\Framework\Combat\TeamMembershipInterface`
队伍归属查询契约（掉落归属绑定的同队判定依赖，R3 经济批模块 2）。

| 方法 | 说明 |
|---|---|
| `teamOf(string $uid): ?string` | uid → 所在队伍 id；未组队返回 null。 |

#### `Nythros\Framework\Combat\VisionBroadcasterInterface`
视野/定向广播接口：战斗结算依赖它出帧，由 MapServer 实现（持有 FrameMerger 帧合并器 + connections + registry）。

| 方法 | 说明 |
|---|---|
| `broadcastToVision(string $centerEntityId, string $type, array $payload): void` | 向 centerEntityId 视野内的全部连接广播一帧（帧末 flush）。 |
| `sendToEntity(string $entityId, string $type, array $payload): void` | 定向发送一帧给某 entityId 对应连接（拾取者/攻击发起者回执）。 |

### `Nythros\Framework\Config`

#### `Config`
应用级配置：PHP 数组文件加载（零 yaml 依赖）与点号路径读取。

| 方法 | 说明 |
|---|---|
| `__construct(array $items)` |  |
| `all(): array` | 全部配置项。 |
| `static` `fromPhpFile(string $path): self` | 从 PHP 文件加载配置：文件须返回 array。 |
| `get(string $key, $default = NULL): mixed` | 读取配置：支持点号路径（a.b.c），未命中返回默认值。 |
| `has(string $key): bool` | 键是否存在（含点为 null 的值）。 |

#### `ConfigRepository`
配置热载仓库：多 PHP 文件注册、mtime 轮询检测与内存快照原子替换（R3 配置热载基线）。

| 方法 | 说明 |
|---|---|
| `__construct(Nythros\Framework\Event\EventDispatcherInterface $events, ?callable $mtimeReader = NULL)` | Mtime-reader injection (fake clock/mtime for tests); signature fn(string $path): int\|false. |
| `check(): bool` | 轮询入口：检测 mtime 变化并原子替换；返回是否发生了重载。 |
| `config(): ?Nythros\Framework\Config\Config` | 当前配置快照（未注册任何文件时为 null）。 |
| `get(string $key, $default = NULL): mixed` | 点号路径读取（口径与 Config 一致；未注册任何文件时返回默认值）。 |
| `has(string $key): bool` | 键是否存在（含值为 null 的键；未注册任何文件时恒 false）。 |
| `registerDirectory(string $dir, ?array $schemas = NULL): void` | 注册目录内全部 *.php 配置文件（按文件名排序保证确定性），键取文件名去扩展名； |
| `registerFile(string $key, string $path, ?Nythros\Framework\Config\ConfigSchema $schema = NULL): void` | 注册单文件并立即加载（启动期失败快速抛出，不进静默降级）；重复键抛异常。 |
| `startPolling(Nythros\Contracts\TimerInterface $timer, float $intervalSeconds): void` | 定时轮询装配：以持久定时器周期触发 check()（fork 后各进程独立驱动）。 |

#### `ConfigSchema`
声明式配置表校验器（P11 玩法数据外置的地基）：以类型/区间/枚举/形状规则描述一张配置表的合法形态，

| 方法 | 说明 |
|---|---|
| `static` `bool(): self` | 布尔规则。 |
| `static` `enum(string $...values): self` | 字符串枚举规则（白名单集合）。 |
| `errors($value, string $path = ''): array` | 纯校验：返回结构化错误列表（path => 定位点、message => 违规原因），空列表 = 合法。 |
| `static` `float(float $min = -1.7976931348623157E+308, float $max = 1.7976931348623157E+308): self` | 浮点规则（可附区间；整数值自动归一为 float）。 |
| `static` `int(int $min = -9223372036854775807-1, int $max = 9223372036854775807): self` | 整数规则（可附区间）。 |
| `static` `listOf(self $itemSchema, ?int $minItems = NULL, ?int $maxItems = NULL): self` | 顺序列表规则（元素逐个按 itemSchema 校验；要求 array_is_list）。 |
| `normalized($value, string $path = ''): mixed` | 归一化产出：校验通过后返回回填默认值的副本（config 消费方拿到的即该形态）；未先通过 errors() 校验 |
| `nullable(): self` | 接受 null（校验通过且原样透传，不参与默认值回填）。 |
| `optional($default): self` | 放开为可选字段：缺省时以声明的默认值回填（仅 shape 内有意义）。 |
| `static` `renderErrors(array $errors, string $key, string $file): string` | 错误渲染（带行号定位）：按错误路径在源文件里定位行号，定位不到则不带行号输出。 |
| `static` `shape(array $fields, bool $allowUnknownFields = false): self` | 形状规则（关联数组：字段名 => 字段规则；未知字段恒拒绝，除非 allowUnknownFields）。 |
| `static` `string(int $minLength = 0, ?string $pattern = NULL): self` | 字符串规则（可附最小长度与正则约束）。 |

#### `ConfigSourceLines`
PHP 数组配置文件的「路径 → 行号」映射（P11 schema 校验的行号定位器）：用 tokenizer 扫描源码，

| 方法 | 说明 |
|---|---|
| `static` `build(string $source): self` | 从源码构建映射。 |
| `count(): int` | 已解析路径数（测试断言用）。 |
| `static` `forFile(string $path): ?self` | 从文件构建映射；文件不可读返回 null（行号定位是尽力而为，不阻塞主流程）。 |
| `lineFor(string $path): ?int` | 精确路径定位行号；未命中时逐段向上回退（monsters.2.anchor.x → monsters.2.anchor → monsters.2 → monsters）， |

### `Nythros\Framework\Container`

#### `Container`
轻量服务容器：实例表 + 延迟工厂表；工厂首次 get 时装配并缓存，未命中抛异常。 · implements `Nythros\Framework\Container\ContainerInterface`

| 方法 | 说明 |
|---|---|
| `factory(string $id, callable $fn): void` |  |
| `get(string $id): mixed` |  |
| `has(string $id): bool` |  |
| `remove(string $id): void` |  |
| `set(string $id, $value): void` |  |

#### `Nythros\Framework\Container\ContainerInterface`
轻量服务容器契约：实例/工厂注册与按 id 解析。

| 方法 | 说明 |
|---|---|
| `factory(string $id, callable $fn): void` | 注册延迟工厂：首次 get 时装配。 |
| `get(string $id): mixed` | 解析服务；未命中抛异常。 |
| `has(string $id): bool` | 服务是否已注册（实例或工厂皆算）。 |
| `remove(string $id): void` | 卸载注册项：同时清理实例与工厂表项，未命中静默忽略。 |
| `set(string $id, $value): void` | 注册实例。 |

### `Nythros\Framework\Deploy`

#### `DeployConfig`
deploy.yaml 配置模型与解析器（ADR-013 决策 C：deploy.yaml 是服务拓扑唯一事实源）。

| 方法 | 说明 |
|---|---|
| `static` `buildCommand(Nythros\Framework\Deploy\DeployWorker $worker, string $workerScript, array $redis, array $mysql = [...]): array` | 构建 worker 的完整启动命令（纯函数：同一 service 声明无论归属哪个 process 块，命令完全一致—— |
| `mapIds(): array` | 合法 mapId 白名单：按拓扑声明顺序去重收集全部 map 服务的 mapId（供 launch 启动摘要打印， |
| `mysql(): array` | MySQL 归档连接参数（host/port/user/password/dbname）。 |
| `static` `parseYaml(string $yaml): self` | 解析 deploy.yaml 文本为配置模型；结构非法时抛 InvalidArgumentException（消息带行号归因）。 |
| `processes(): array` | 部署单元拓扑：process 名 => 服务实例列表（保持 yaml 声明顺序）。 |
| `redis(): array` | Redis 连接参数。 |
| `workers(): array` | 展开为 worker 列表：按 process 声明顺序、每 process 内 service 声明顺序、count 实例数依次展开。 |

#### `DeployService`
deploy.yaml 中的单个服务声明（一个进程块内的一条 "- type: ..." 条目）。

| 方法 | 说明 |
|---|---|
| `__construct(string $type, int $port, int $count = 1, ?string $mapId = NULL, ?string $channelId = NULL, ?string $worldType = NULL, ?string $pidFile = NULL)` | 构造服务声明。 |
| `serviceId(): ?string` | 服务实例标识：map 为 {mapId}#{channelId} 编码（ADR 5.1）；其他类型返回 null（注册逻辑 id 由各服务内部持有， |

#### `DeployWorker`
展开后的单个 worker：一个启动进程的完整描述（所属部署单元 + 服务声明 + count 内实例序号）。

| 方法 | 说明 |
|---|---|
| `__construct(string $process, Nythros\Framework\Deploy\DeployService $service, int $instance = 1)` | 构造 worker 描述。 |

### `Nythros\Framework\Event`

#### `EventDispatcher`
同步即时事件派发器：按事件名维护监听器列表，dispatch 立即逐条调用。 · implements `Nythros\Framework\Event\EventDispatcherInterface`

| 方法 | 说明 |
|---|---|
| `dispatch(string $event, array $payload = [...]): void` |  |
| `listen(string $event, callable $listener): void` |  |
| `removeListener(string $event, callable $listener): void` |  |

#### `Nythros\Framework\Event\EventDispatcherInterface`
应用级事件派发契约：同步即时派发，与引擎 EventBus 职责分层、并行存在。

| 方法 | 说明 |
|---|---|
| `dispatch(string $event, array $payload = [...]): void` | 同步即时派发事件，携带可选负载。 |
| `listen(string $event, callable $listener): void` | 注册事件监听器。 |
| `removeListener(string $event, callable $listener): void` | 按 event 精确移除首个匹配监听器；未命中静默忽略。 |

### `Nythros\Framework\Game\Horde`

#### `DropStormConfig`
掉落风暴配置（R4 horde 类型模块试点，ADR-024 §D-D）：一波死亡的掉落寿命与攒批口径参数。

| 方法 | 说明 |
|---|---|
| `__construct(int $dropLifetimeSeconds = 300)` |  |

#### `HordeConfig`
horde 玩法参数化配置（R4 类型模块试点，ADR-020 §4）：波次刷怪定义、房间容量与 tick 周期、

| 方法 | 说明 |
|---|---|
| `__construct(array $waves, int $periodMs = 50, int $maxMembers = 512, int $aoeMaxRadius = 300, Nythros\Framework\Game\Horde\DropStormConfig $dropStorm = \Nythros\Framework\Game\Horde\DropStormConfig::__set_state(array(
   'dropLifetimeSeconds' => 300,
)), Nythros\Framework\Game\Horde\SpawnProtectionConfig $spawnProtection = \Nythros\Framework\Game\Horde\SpawnProtectionConfig::__set_state(array(
   'frames' => 60,
)), Nythros\Framework\Game\Horde\SettlementRules $settlement = \Nythros\Framework\Game\Horde\SettlementRules::__set_state(array(
   'minKillRatio' => 100,
)))` | 构造期拒绝，见下） Wave spawn definitions (one grid layout plus combat parameters per wave; at least one — |
| `static` `default(): self` | 缺省配置：与 RoomHub 迁移前常量逐值一致（网格 x∈[24,62] y 起点 -24 步距 2、怪 maxHp=12、 |

#### `HordePlugin`
Horde 插件（R4 类型模块试点，ADR-020 §4「命名空间 + PluginRegistry 插件形态」）： · implements `Nythros\Framework\Plugin\PluginInterface`

| 方法 | 说明 |
|---|---|
| `__construct(?Nythros\Framework\Game\Horde\HordeConfig $config = NULL)` |  |
| `disable(): void` |  |
| `enable(): void` |  |
| `name(): string` |  |
| `register(Nythros\Framework\Container\ContainerInterface $container, Nythros\Framework\Event\EventDispatcherInterface $dispatcher): void` | 加载：向 Container 注册 horde 配置（幂等；构造期未显式给定时注册缺省配置）。 |
| `uninstall(Nythros\Framework\Container\ContainerInterface $container, Nythros\Framework\Event\EventDispatcherInterface $dispatcher): void` |  |

#### `SettlementRules`
房间结算规则（R4 horde 类型模块试点）：以刷怪/击杀计数纯函数判定结算结论——

| 方法 | 说明 |
|---|---|
| `__construct(int $minKillRatio = 100)` |  |
| `isCleared(int $spawnedCount, int $killedCount): bool` | 判定一波刷怪是否达成结算条件：刷怪数为 0 恒不可结算（空房不产出结算结论）； |

#### `SpawnProtectionConfig`
出生保护配置（R4 horde 类型模块试点）：auth 挂载后的无敌窗口帧数（50ms 基准 tick 折算）。

| 方法 | 说明 |
|---|---|
| `__construct(int $frames = 60)` |  |

#### `WaveDefinition`
波次刷怪定义（R4 horde 类型模块试点）：一波怪的网格布局与战斗参数——

| 方法 | 说明 |
|---|---|
| `__construct(int $count, int $monsterMaxHp, int $gridStartX, int $gridStartY, int $columns, int $step)` |  |
| `positionAt(int $index): array` | 波内序号 → 刷怪坐标（行优先网格；纯函数，供装配层与测试复用）。 |

### `Nythros\Framework\Game\Mmorpg`

#### `CellDensityGovernor`
格子密度 governor（P9a，区域降频的负载策略层）：每 base tick 采样一次玩家位置 → 计算各格子密度 →

| 方法 | 说明 |
|---|---|
| `__construct(int $cellSize, Nythros\Framework\Game\Mmorpg\HotCellPolicy $policy, ?Closure $clock = NULL)` |  |
| `divisorFor(int $x, int $y): int` | 坐标的实体分频：取自身格及 neighborRadius 邻接格的最热档（格界梯度平滑——邻格更热则按热档， |
| `sample(array $positions): void` | 每 base tick 采样一次：以玩家位置重算各格密度并推进热区等级。升温即时生效；降温须在当前等级 |

#### `DeathDropPolicy`
死亡掉落策略（P13 死亡与对抗治理，参数草案裁决值见 blueprint/26 §一）：

| 方法 | 说明 |
|---|---|
| `__construct(int $dropRatioPercent, int $ownerWindowSeconds, int $maxDropsPerDeath, array $boundItemIds = [...])` | (killer/team-exclusive duration, >=1). |
| `static` `default(): self` | 草案缺省：30% 逐单位掉率、60s 归属窗口、单次死亡最多 8 种、无绑定物品。 |

#### `HotCellPolicy`
热区策略（P9a，只读值对象）：格子密度 → 实体 tick 分频的档位表。tiers 按密度升序声明，

| 方法 | 说明 |
|---|---|
| `__construct(array $tiers, int $hysteresisSeconds = 5, int $neighborRadius = 0)` | 须存在且仅一个、位于末位）。 The tier table by ascending density (untilPlayers=0 = the unbounded |

#### `MmorpgConfig`
mmorpg 玩法参数化配置（R4 类型模块试点，ADR-020 §4）：威胁/仇恨参数组（aggroRange 进入仇恨列表距离、

| 方法 | 说明 |
|---|---|
| `__construct(int $aggroRange = 10, float $threatDecayPerSec = 0.0, float $tauntMultiplier = 1.0, int $maxThreat = 0, int $respawnMs = 5000, int $spawnDensity = 1, int $playerRespawnMs = 0, ?array $safeZone = NULL, int $attackRange = 0, ?Nythros\Framework\Game\Mmorpg\HotCellPolicy $hotCell = NULL, array $questChains = [...], ?Nythros\Framework\Game\Mmorpg\DeathDropPolicy $deathDrop = NULL, bool $pvpEnabled = false, string $killCredit = 'last_hit')` | The distance to enter the hate list (world units): attackers beyond it from the hit monster gain no threat. |
| `static` `default(): self` | 缺省配置：威胁不衰减（threatDecayPerSec=0）、无上限（maxThreat=0）、嘲讽倍率 1.0、aggroRange 10 |

#### `MmorpgPlugin`
Mmorpg 插件（R4 类型模块试点，ADR-020 §4「命名空间 + PluginRegistry 插件形态」）： · implements `Nythros\Framework\Plugin\PluginInterface`

| 方法 | 说明 |
|---|---|
| `__construct(?Nythros\Framework\Game\Mmorpg\MmorpgConfig $config = NULL)` |  |
| `disable(): void` |  |
| `enable(): void` |  |
| `name(): string` |  |
| `register(Nythros\Framework\Container\ContainerInterface $container, Nythros\Framework\Event\EventDispatcherInterface $dispatcher): void` | 加载：向 Container 注册 mmorpg 配置（幂等；构造期未显式给定时注册缺省配置）。 |
| `uninstall(Nythros\Framework\Container\ContainerInterface $container, Nythros\Framework\Event\EventDispatcherInterface $dispatcher): void` |  |

#### `Respawner`
世界怪物重生器（R4 mmorpg 类型模块试点）：死亡登记 → 定时重生回锚点的纯调度组件——

| 方法 | 说明 |
|---|---|
| `__construct(int $respawnMs)` |  |
| `clear(string $monsterId): void` | 摘除登记（重生执行后调用；未登记静默忽略）。 |
| `due(float $now): array` | 到期查询：返回重生时刻已到的怪物 id 列表（不改变状态——消费方逐 id clear 后执行重生）。 |
| `pending(): bool` | 是否仍有待重生登记。 |
| `registerDeath(string $monsterId, float $now, ?int $overrideMs = NULL): void` | 死亡登记：怪物死亡时记录重生时刻（now + respawnMs / 1000）；重复登记覆盖（幂等）。 |

#### `ThreatRules`
威胁/仇恨规则（R4 mmorpg 类型模块试点）：纯函数集合——aggro 选择（最高威胁者）、衰减计算

| 方法 | 说明 |
|---|---|
| `__construct(int $aggroRange = 10, float $threatDecayPerSec = 0.0, float $tauntMultiplier = 1.0, int $maxThreat = 0)` |  |
| `applyTaunt(float $amount): float` | 嘲讽倍率应用：amount × tauntMultiplier（嘲讽技能的威胁提升量）。 |
| `capThreat(float $threat): float` | 威胁上限钳制：maxThreat > 0 时钳制到上限，否则原样返回。 |
| `decay(float $threat, float $dt): float` | 衰减计算：threat - decay × dt，钳制非负（衰减到零即出仇恨列表，由调用方摘除）。 |
| `inAggroRange(float $distance): bool` | 距离是否在仇恨列表范围内（≤ aggroRange）。 |
| `selectTarget(array $threats): ?string` | aggro 选择：最高威胁者；空表返回 null；平局取先记录者（数组保持插入顺序）。 |

#### `ThreatTable`
威胁表状态组件（R4 mmorpg 类型模块试点）：per-actor 威胁记录——addThreat 累加（可选距离判定，

| 方法 | 说明 |
|---|---|
| `__construct(Nythros\Framework\Game\Mmorpg\ThreatRules $rules)` |  |
| `addThreat(string $actorId, float $amount, ?float $distance = NULL): void` | 记录/累加威胁：可选距离判定（distance 非 null 且超 aggroRange 时忽略——攻击者不在仇恨列表范围内 |
| `all(): array` | 全部威胁记录快照（actorId => 威胁值）。 |
| `applyTaunt(string $actorId, float $amount): void` | 嘲讽提升：把该 actor 的威胁提升到 amount × tauntMultiplier 与当前值的较大者（嘲讽语义： |
| `clear(): void` | 清空全部威胁记录。 |
| `decay(float $dt): void` | 按规则衰减全部威胁（dt 秒）；衰减到零的 actor 自动摘除（出仇恨列表）。 |
| `remove(string $actorId): void` | 摘除某 actor 的威胁记录（目标死亡/离场时由消费方调用）。 |
| `selectTarget(): ?string` | 选择攻击目标（aggro 语义命名，与 topThreat 同判据——最高威胁者）。 |
| `threatOf(string $actorId): float` | 查询某 actor 的当前威胁值（未记录返回 0）。 |
| `topThreat(): ?string` | 最高威胁者（只读查询，不改变状态）；空表返回 null。 |

### `Nythros\Framework\Gm`

#### `Nythros\Framework\Gm\GmBroadcasterInterface`
GM 全服广播能力契约（组装层实现接口、framework 消费——VisionBroadcasterInterface 倒置先例）：

| 方法 | 说明 |
|---|---|
| `broadcast(string $message): void` | 向本服务全部在线客户端广播一条 GM 消息。 |

#### `GmCommandBus`
GM 命令总线：命令注册 / 权限检查 / 分发的最小内核。

| 方法 | 说明 |
|---|---|
| `__construct(Nythros\Framework\Gm\GmPermissionInterface $permissions)` |  |
| `dispatch(string $uid, string $commandName, array $payload): Nythros\Framework\Gm\GmResult` | 分发一次 GM 命令（永不抛出）。 |
| `register(Nythros\Framework\Gm\GmCommandInterface $command): void` | 注册命令；同名重复注册抛异常（与 PluginRegistry::load 同口径）。 |

#### `Nythros\Framework\Gm\GmCommandInterface`
GM 命令契约：最小内核的命令单元——名字 + 执行。

| 方法 | 说明 |
|---|---|
| `execute(array $payload): Nythros\Framework\Gm\GmResult` | 执行命令：返回结构化结果；抛出的异常由 CommandBus 捕获转 error 结果。 |
| `name(): string` | 命令名（分发键，如 status/broadcast/kick）。 The command name (the dispatch key, e.g. status/broadcast/kick). |

#### `Nythros\Framework\Gm\GmDrainHandlerInterface`
drain 命令的能力契约（P16 动态扩缩容）：由服务实现（MapServer）——标记 draining 后

| 方法 | 说明 |
|---|---|
| `drain(): bool` | 进入 draining：注册心跳 meta 置 status=draining + 本地守卫激活（新 auth 拒绝）。 |
| `isDraining(): bool` | 是否处于 draining（观测口）。 |

#### `Nythros\Framework\Gm\GmKickerInterface`
GM 踢人能力契约（组装层实现接口、framework 消费）：按 uid 断开其全部在线连接，

| 方法 | 说明 |
|---|---|
| `kick(string $uid): int` | 踢指定 uid 下线，返回实际断开的连接数（不在线返回 0）。 |

#### `Nythros\Framework\Gm\GmPermissionInterface`
GM 权限检查契约：uid 是否有权执行某命令（framework 只定义接口，身份实现留在组装层——

| 方法 | 说明 |
|---|---|
| `allows(string $uid, string $command): bool` | 权限判定：允许执行返回 true。 |

#### `GmResult`
GM 命令结果：四态结构化回执（ok / unknown_command / permission_denied / error）。

| 方法 | 说明 |
|---|---|
| `static` `error(string $message): self` |  |
| `static` `ok(string $message = 'ok', array $data = [...]): self` |  |
| `static` `permissionDenied(string $name): self` |  |
| `static` `unknownCommand(string $name): self` |  |

#### `Nythros\Framework\Gm\GmStatusProviderInterface`
GM 服务状态源契约（组装层实现接口、framework 消费）：status 命令的数据来源，

| 方法 | 说明 |
|---|---|
| `status(): array` | 采集本服务当前状态快照。 |

### `Nythros\Framework\Gm\Command`

#### `BroadcastCommand`
broadcast 命令：经 GmBroadcasterInterface 门面向全服广播一条文本。 · implements `Nythros\Framework\Gm\GmCommandInterface`

| 方法 | 说明 |
|---|---|
| `__construct(Nythros\Framework\Gm\GmBroadcasterInterface $broadcaster)` |  |
| `execute(array $payload): Nythros\Framework\Gm\GmResult` |  |
| `name(): string` |  |

#### `DrainCommand`
drain 命令（P16 动态扩缩容）：标记本服务 draining——目录服务停止路由新会话，存量连接不受影响， · implements `Nythros\Framework\Gm\GmCommandInterface`

| 方法 | 说明 |
|---|---|
| `__construct(Nythros\Framework\Gm\GmDrainHandlerInterface $handler)` |  |
| `execute(array $payload): Nythros\Framework\Gm\GmResult` |  |
| `name(): string` |  |

#### `KickCommand`
kick 命令：经 GmKickerInterface 门面按 uid 踢下线。 · implements `Nythros\Framework\Gm\GmCommandInterface`

| 方法 | 说明 |
|---|---|
| `__construct(Nythros\Framework\Gm\GmKickerInterface $kicker)` |  |
| `execute(array $payload): Nythros\Framework\Gm\GmResult` |  |
| `name(): string` |  |

#### `StatusCommand`
status 命令：回服务状态快照（数据来自注入的 GmStatusProviderInterface）。 · implements `Nythros\Framework\Gm\GmCommandInterface`

| 方法 | 说明 |
|---|---|
| `__construct(Nythros\Framework\Gm\GmStatusProviderInterface $provider)` |  |
| `execute(array $payload): Nythros\Framework\Gm\GmResult` |  |
| `name(): string` |  |

### `Nythros\Framework\Inventory\Equipment`

#### `Equipment`
装备栏：按槽位管理已穿戴装备的容器（每玩家一份，由 BasePlayer 挂载消费）。

| 方法 | 说明 |
|---|---|
| `attributeBonus(string $attribute): int` | 单项属性加成：全部已穿戴装备该属性增量之和（BasePlayer maxHp 聚合的消费口径）。 |
| `attributeBonuses(): array` | 全部属性加成聚合表（属性名 => 增量和）。 |
| `equip(Nythros\Framework\Plugin\Item\ItemDefinition $item): ?string` | 穿戴：校验通过后写入槽位；同槽位已有装备时顶替。 |
| `equipped(): array` | 全量已穿戴表（槽位值 => 物品定义）。 |
| `itemIdIn(string $slot): ?string` | 查询槽位当前穿戴的物品 id；空槽位/非法槽位均返回 null（查询路径不抛）。 |
| `unequip(string $slot): ?string` | 卸下：槽位有装备时摘除并返回物品 id。 |

#### `Nythros\Framework\Inventory\Equipment\EquipmentSlot`
装备槽位枚举：背包装备模型的合法槽位注册表（R3 经济批裁决：Equipment 子命名空间承载槽位定义）。 · implements `UnitEnum`, `BackedEnum`
- Cases: `WEAPON`, `ARMOR`, `ACCESSORY`

| 方法 | 说明 |
|---|---|
| `static` `cases(): array` |  |
| `static` `from(string\|int $value): static` |  |
| `static` `tryFrom(string\|int $value): ?static` |  |

### `Nythros\Framework\Leaderboard`

#### `Nythros\Framework\Leaderboard\LeaderboardStoreInterface`
排行榜存储契约（Redis ZSet 承载）：写入口径两式——业务上报（report，单 uid 实时覆盖）

| 方法 | 说明 |
|---|---|
| `aggregate(string $board, array $scores): void` | 定时聚合：批量 upsert（uid => score 映射一次合并写入，聚合任务口径）。 |
| `rankOf(string $board, string $uid): ?array` | 单 uid 排名（rank 从 1 起）；未上榜 null。 |
| `remove(string $board, string $uid): bool` | 移除条目（uid 退出榜单）。 |
| `report(string $board, string $uid, float $score): void` | 业务上报：单 uid 分数写入（同 uid 重复上报覆盖为最新分）。 |
| `size(string $board): int` | 榜单规模（聚合任务与运维观测用）。 |
| `top(string $board, int $n, int $offset = 0): array` | top N 查询（分数降序，rank 从 1 起，offset 分页）。 |

#### `RedisLeaderboardStore`
排行榜存储 Redis ZSet 实现（照 GuildStore 先例：\Redis|\Closure 构造 + 键前缀 + 格式白名单）。 · implements `Nythros\Framework\Leaderboard\LeaderboardStoreInterface`

| 方法 | 说明 |
|---|---|
| `__construct(Redis\|Closure $redis, string $prefix = 'nythros:lb:')` | 构造排行榜存储。 |
| `aggregate(string $board, array $scores): void` |  |
| `rankOf(string $board, string $uid): ?array` |  |
| `remove(string $board, string $uid): bool` |  |
| `report(string $board, string $uid, float $score): void` |  |
| `size(string $board): int` |  |
| `top(string $board, int $n, int $offset = 0): array` |  |

### `Nythros\Framework\Mail`

#### `Nythros\Framework\Mail\MailNotifierInterface`
新邮件在线通知端口：ConnectionHubInterface::sendToUid 的等价抽象。

| 方法 | 说明 |
|---|---|
| `notifyNewMail(string $uid, string $mailId): void` | 通知 uid 有新邮件到达（离线时静默丢弃——邮件本身已持久化，登录后可拉取）。 |

#### `MailService`
邮件服务：发送/列表/附件领取/删除（纯业务，可单测）。

| 方法 | 说明 |
|---|---|
| `__construct(Nythros\Framework\Mail\MailStoreInterface $store, ?Nythros\Framework\Mail\MailNotifierInterface $notifier = NULL, ?Closure $idFactory = NULL)` | 构造邮件服务。 |
| `claimAttachments(string $uid, string $mailId): array` | 领取邮件附件（幂等）：not_found = 邮件不存在；already_claimed = 已领取过（幂等命中， |
| `delete(string $uid, string $mailId): bool` | 删除邮件。 |
| `list(string $uid): array` | 读取收件箱全部邮件。 |
| `send(string $toUid, string $fromUid, string $title, string $body, array $attachments = [...]): string` | 发送邮件：生成 mailId → 存储 → 在线通知（通知失败不回滚——邮件已持久化，登录后可拉取）。 |

#### `Nythros\Framework\Mail\MailStoreInterface`
邮件存储契约（Redis 持久，无 TTL）。

| 方法 | 说明 |
|---|---|
| `claimGate(string $uid, string $mailId): bool` | 领取幂等闸门（Lua 原子 SISMEMBER+SADD）：true = 首次领取（闸门已抢到）；false = 已领取过。 |
| `delete(string $uid, string $mailId): bool` | 删除邮件（同时清理领取闸门残留）。 |
| `get(string $uid, string $mailId): ?array` | 读取单封邮件。 |
| `insert(string $toUid, string $mailId, string $fromUid, string $title, string $body, array $attachments): void` | 写入一封邮件（mailId 已由调用方生成并保证唯一；重复写入覆盖旧值）。 |
| `listByUid(string $uid): array` | 读取收件箱全部邮件（按 sentAt 升序）。 |
| `releaseClaimGate(string $uid, string $mailId): void` | 释放领取闸门（补偿路径：抢到闸门后邮件被并发删除等失败回滚）。 |

#### `RedisMailStore`
邮件存储 Redis 实现（照 GuildStore 先例：\Redis|\Closure 构造 + 键前缀 + 格式白名单，无 TTL 持久）。 · implements `Nythros\Framework\Mail\MailStoreInterface`

| 方法 | 说明 |
|---|---|
| `__construct(Redis\|Closure $redis, string $prefix = 'nythros:ml:')` | 构造邮件存储。 |
| `claimGate(string $uid, string $mailId): bool` |  |
| `delete(string $uid, string $mailId): bool` |  |
| `get(string $uid, string $mailId): ?array` |  |
| `insert(string $toUid, string $mailId, string $fromUid, string $title, string $body, array $attachments): void` |  |
| `listByUid(string $uid): array` |  |
| `releaseClaimGate(string $uid, string $mailId): void` |  |

### `Nythros\Framework\Make`

#### `MakeActor`
make:actor — 生成业务 Actor 骨架（kind → 基类 + 钩子集映射驱动模板渲染）。 · extends `Nythros\Framework\Make\MakeCommand`

| 方法 | 说明 |
|---|---|
| `run(array $args): string` | 执行 make:actor：校验参数 → 渲染 kind 对应模板 → 写入 --out/{类名}.php。 |

#### `MakeCommand`
make:* 命令公共基类：位置参数 + --key=value 选项解析、模板读取、目标写入。 · abstract

#### `MakeEvent`
make:event — 生成事件常量/载荷类骨架（EventDispatcher 派发用）。 · extends `Nythros\Framework\Make\MakeCommand`

| 方法 | 说明 |
|---|---|
| `run(array $args): string` | 执行 make:event：校验参数 → 渲染事件类 → 写入 --out/{事件名}.php。 |

#### `MakeMap`
make:map — 生成地图配置条目并追加到地图配置（config/maps.php）。 · extends `Nythros\Framework\Make\MakeCommand`

| 方法 | 说明 |
|---|---|
| `run(array $args): string` | 执行 make:map：校验参数 → 渲染地图条目 → 追加到 --out 指定的配置。 |

#### `MakeSkill`
make:skill — 生成技能定义条目并追加到技能配置（config/skills.php）。 · extends `Nythros\Framework\Make\MakeCommand`

| 方法 | 说明 |
|---|---|
| `run(array $args): string` | 执行 make:skill：校验参数 → 渲染技能条目 → 追加到 --out 指定的配置。 |

### `Nythros\Framework\Matching`

#### `MatchCriteria`
撮合条件值对象：一个队列（房间类型）的准入与开房参数。

| 方法 | 说明 |
|---|---|
| `__construct(string $queueId, int $teamSize, int $minLevel, int $maxLevel, int $roomPeriodMs = 50, int $roomMaxMembers = 512)` |  |
| `admits(int $level): bool` | 候选者等级是否满足准入区间（含边界）。 |

#### `Nythros\Framework\Matching\MatchJoinHandlerInterface`
匹配入房编排委托契约（framework → assembly layer 依赖倒置）：撮合成功后把一名候选者编排进指定房间。

| 方法 | 说明 |
|---|---|
| `joinRoom(string $roomId, string $entityId): bool` | 把 entityId 对应的玩家编排进 roomId；false = 编排失败（满员/状态不可入/实体缺失等）， |

#### `MatchTicket`
排队票值对象：候选者在匹配队列中的登记记录。

| 方法 | 说明 |
|---|---|
| `__construct(string $uid, string $entityId, int $level, string $queueId, float $enqueuedAt)` |  |

### `Nythros\Framework\Persistence`

#### `ArchivePipeline`
归档管线（组装层通用件）：业务状态异步归档——标脏 → 断连/登出立即 flush → 30s 定时兜底批量 saveBatch（ADR-013 10.5，裁决 4/6）。

| 方法 | 说明 |
|---|---|
| `__construct(Nythros\Persistence\StorageInterface $storage, string $collection, ?Nythros\Contracts\TimerInterface $timer = NULL, ?callable $clock = NULL)` | 组装归档管线。 |
| `flush(): void` | 批量冲刷全部脏记录（saveBatch）：成功记录出脏；失败 id 计一次尝试，未达上限留待重试， |
| `flushId(string $id): void` | 断连/登出立即冲刷：立即 save 该记录（强制同步点，不受 30s 门控影响）；save 失败时计一次 |
| `load(string $id): ?array` | 读路径（P18 工程债收尾：关闭「归档只写」的半闭环）：按 id 读取最近一次归档的记录—— |
| `markDirty(string $id, array $data): void` | 标脏：登记最新状态（同 id 覆盖写）并清零失败计数；零 I/O，不阻塞帧预算（裁决 4）。 |
| `periodicFlush(): void` | 定时兜底回调（30s 持久定时器）：时钟门控——距上次兜底冲刷不足 30s 直接返回；否则推进 |

### `Nythros\Framework\Plugin`

#### `Nythros\Framework\Plugin\PluginInterface`
插件契约：定义加载/启用/停用/卸载四态生命周期，由 PluginRegistry 驱动。

| 方法 | 说明 |
|---|---|
| `disable(): void` | 停用：暂停运行时行为（保留注册）。 |
| `enable(): void` | 启用：激活运行时行为。 |
| `name(): string` | 插件唯一名，如 'skill' / 'item' / 'buff'。 |
| `register(Nythros\Framework\Container\ContainerInterface $container, Nythros\Framework\Event\EventDispatcherInterface $dispatcher): void` | 加载：向 Container 注册本插件能力（仓库/服务）并订阅事件；幂等，可重复调用。 |
| `uninstall(Nythros\Framework\Container\ContainerInterface $container, Nythros\Framework\Event\EventDispatcherInterface $dispatcher): void` | 卸载：清理注册与订阅、回收资源，具备完整运行时卸载语义。 |

#### `PluginRegistry`
插件注册表：按唯一名管理插件生命周期（load/enable/disable/uninstall），并支持按名查询。

| 方法 | 说明 |
|---|---|
| `all(): array` | 返回全部已加载插件（name => plugin）。 |
| `disable(string $name): void` | 停用已加载插件（保留注册）。 |
| `enable(string $name): void` | 启用已加载插件。 |
| `get(string $name): ?Nythros\Framework\Plugin\PluginInterface` | 按名查询插件；未加载返回 null。 |
| `load(Nythros\Framework\Plugin\PluginInterface $plugin, Nythros\Framework\Container\ContainerInterface $container, Nythros\Framework\Event\EventDispatcherInterface $dispatcher): void` | 加载插件：调用 $plugin->register 装配后登记进注册表；同名插件重复加载抛异常。 |
| `uninstall(string $name, Nythros\Framework\Container\ContainerInterface $container, Nythros\Framework\Event\EventDispatcherInterface $dispatcher): void` | 卸载已加载插件：调 $plugin->uninstall 清理注册与订阅后从注册表摘除。 |

### `Nythros\Framework\Plugin\Buff`

#### `BuffDefinition`
Buff 定义：纯数据值对象。R3 玩法批正式化：effects 从占位描述升级为结构化约定键，

| 方法 | 说明 |
|---|---|
| `__construct(string $id, string $name, float $durationSeconds, array $effects, string $stackRule = 'refresh', int $maxStacks = 1, ?string $mutexGroup = NULL)` | Mutex-group id: buffs sharing a group are mutually exclusive on one host; a new application displaces the |
| `attributeModifiers(): array` | 属性修正表（effects.attributes；缺失返回空表）。 |
| `dot(): ?array` | DOT 配置（effects.dot；缺失返回 null）。 |

#### `BuffInstance`
Buff 运行时实例：BuffService 状态机的可变状态单元（宿主键维度登记）。

| 方法 | 说明 |
|---|---|
| `__construct(string $buffId, string $hostKey, int $stacks = 1, float $expiresAt = 0.0, ?float $nextDotAt = NULL)` |  |

#### `BuffPlugin`
Buff 插件：向 Container 注册 BuffRepository，并订阅 'buff.applied' 作为退订机制的示范。 · implements `Nythros\Framework\Plugin\PluginInterface`

| 方法 | 说明 |
|---|---|
| `disable(): void` |  |
| `enable(): void` |  |
| `name(): string` |  |
| `register(Nythros\Framework\Container\ContainerInterface $container, Nythros\Framework\Event\EventDispatcherInterface $dispatcher): void` |  |
| `uninstall(Nythros\Framework\Container\ContainerInterface $container, Nythros\Framework\Event\EventDispatcherInterface $dispatcher): void` |  |

#### `BuffRepository`
Buff 注册表：按 id 管理 buff 定义，供 demo 效果结算查询（demo 阶段仅注册/查询）。

| 方法 | 说明 |
|---|---|
| `all(): array` | 返回全部 buff 定义（id => BuffDefinition）。 |
| `get(string $id): ?Nythros\Framework\Plugin\Buff\BuffDefinition` | 按 id 查询 buff 定义；未注册返回 null。 |
| `register(Nythros\Framework\Plugin\Buff\BuffDefinition $buff): void` | 注册 buff 定义；同 id 后注册覆盖先注册。 |

### `Nythros\Framework\Plugin\Item`

#### `ItemDefinition`
物品定义：纯数据值对象；type 取值见本类常量（consumable/material/currency/equipment）。

| 方法 | 说明 |
|---|---|
| `__construct(string $id, string $name, string $type, ?string $slot = NULL, array $attributes = [...])` |  |

#### `ItemPlugin`
Item 插件：向 Container 注册 ItemRepository，并订阅 'item.dropped' 作为退订机制的示范。 · implements `Nythros\Framework\Plugin\PluginInterface`

| 方法 | 说明 |
|---|---|
| `disable(): void` |  |
| `enable(): void` |  |
| `name(): string` |  |
| `register(Nythros\Framework\Container\ContainerInterface $container, Nythros\Framework\Event\EventDispatcherInterface $dispatcher): void` |  |
| `uninstall(Nythros\Framework\Container\ContainerInterface $container, Nythros\Framework\Event\EventDispatcherInterface $dispatcher): void` |  |

#### `ItemRepository`
物品注册表：按 id 管理物品定义，供 demo 掉落/拾取校验与查询。

| 方法 | 说明 |
|---|---|
| `all(): array` | 返回全部物品定义（id => ItemDefinition）。 |
| `get(string $id): ?Nythros\Framework\Plugin\Item\ItemDefinition` | 按 id 查询物品定义；未注册返回 null。 |
| `register(Nythros\Framework\Plugin\Item\ItemDefinition $item): void` | 注册物品定义；同 id 后注册覆盖先注册。 |

### `Nythros\Framework\Plugin\Skill`

#### `SkillDefinition`
技能定义：纯数据值对象，统一公式占位（demo 阶段不引入每技能独立类）。

| 方法 | 说明 |
|---|---|
| `__construct(string $id, string $name, float $damageMultiplier, float $cooldownSeconds, int $range, ?array $aoe = NULL, int $mpCost = 0, ?string $itemCostId = NULL, int $itemCostCount = 0, float $tauntThreat = 0.0)` | （shape=circle 时 radius 半径；shape=rect 时 width/height 宽高；null = 单体技能） AoE shape parameters |

#### `SkillPlugin`
Skill 插件：向 Container 注册 SkillRepository，并订阅 'skill.cast' 作为退订机制的示范。 · implements `Nythros\Framework\Plugin\PluginInterface`

| 方法 | 说明 |
|---|---|
| `disable(): void` |  |
| `enable(): void` |  |
| `name(): string` |  |
| `register(Nythros\Framework\Container\ContainerInterface $container, Nythros\Framework\Event\EventDispatcherInterface $dispatcher): void` |  |
| `uninstall(Nythros\Framework\Container\ContainerInterface $container, Nythros\Framework\Event\EventDispatcherInterface $dispatcher): void` |  |

#### `SkillRepository`
技能注册表：按 id 管理技能定义，供 demo 战斗结算查询。

| 方法 | 说明 |
|---|---|
| `all(): array` | 返回全部技能定义（id => SkillDefinition）。 |
| `get(string $id): ?Nythros\Framework\Plugin\Skill\SkillDefinition` | 按 id 查询技能定义；未注册返回 null。 |
| `ids(): array` | 全部已注册技能 id。 |
| `register(Nythros\Framework\Plugin\Skill\SkillDefinition $skill): void` | 注册技能定义；同 id 后注册覆盖先注册。 |
| `remove(string $id): bool` | 按 id 摘除技能定义（P11 技能表热载删除行用）；未注册返回 false。 |

### `Nythros\Framework\Quest`

#### `InMemoryQuestStore`
内存任务进度存储：QuestStoreInterface 的进程内实现（单测与无外部存储部署用）。 · implements `Nythros\Framework\Quest\QuestStoreInterface`

| 方法 | 说明 |
|---|---|
| `all(string $uid): array` |  |
| `delete(string $uid, string $questId): void` |  |
| `get(string $uid, string $questId): ?Nythros\Framework\Quest\QuestProgress` |  |
| `save(Nythros\Framework\Quest\QuestProgress $progress): void` |  |

#### `QuestChain`
任务链配置值对象（R4 mmorpg 类型模块试点 → Quest 子系统）：链式任务聚合——按顺序排列的任务 id 列表，

| 方法 | 说明 |
|---|---|
| `__construct(string $id, array $questIds)` | The ordered quest-id list (at least one — an empty list is rejected at construction, see below). |

#### `QuestChainRules`
任务链规则（R4 mmorpg 类型模块试点 → Quest 子系统）：链式解锁/顺序推进的纯函数集合——

| 方法 | 说明 |
|---|---|
| `static` `chainOf(array $chains, string $questId): ?Nythros\Framework\Quest\QuestChain` | 查询包含某任务的链；不属于任何链返回 null（无链任务恒解锁）。 |
| `static` `isChainComplete(Nythros\Framework\Quest\QuestChain $chain, array $completedQuestIds): bool` | 链是否全部完成（每个链上任务都在完成集中）。 |
| `static` `isUnlocked(Nythros\Framework\Quest\QuestChain $chain, array $completedQuestIds, string $questId): bool` | 链上某任务是否已解锁：任务须属于该链，且其全部前序任务已完成（首任务恒解锁）。 |
| `static` `nextQuestId(Nythros\Framework\Quest\QuestChain $chain, array $completedQuestIds): ?string` | 链上下一个待推进任务：第一个未完成的任务；链全完成返回 null。 |

#### `QuestDefinition`
任务定义值对象：进度源类型 × 目标 × 所需数量 × 奖励表。

| 方法 | 说明 |
|---|---|
| `__construct(string $id, string $name, string $source, string $targetId, int $requiredCount, array $rewards = [...])` |  |

#### `QuestProgress`
任务进度值对象：某 uid 对某任务的累计进度与状态标记（存储单元）。

| 方法 | 说明 |
|---|---|
| `__construct(string $uid, string $questId, int $count = 0, bool $completed = false, bool $rewarded = false)` |  |

#### `QuestRepository`
任务定义注册表：按 id 管理任务定义（比照 SkillRepository 风格）。

| 方法 | 说明 |
|---|---|
| `all(): array` | 返回全部任务定义（id => QuestDefinition）。 |
| `get(string $id): ?Nythros\Framework\Quest\QuestDefinition` | 按 id 查询任务定义；未注册返回 null。 |
| `register(Nythros\Framework\Quest\QuestDefinition $quest): void` | 注册任务定义；同 id 后注册覆盖先注册。 |

#### `QuestService`
任务服务（R3 玩法批）：三类进度源（击杀/收集/对话）的进度状态机与奖励发放。

| 方法 | 说明 |
|---|---|
| `__construct(Nythros\Framework\Quest\QuestStoreInterface $store, Nythros\Framework\Quest\QuestRepository $quests, array $chains = [...])` | [] = chainless, every quest always unlocked). |
| `allProgress(string $uid): array` | 某 uid 的全部任务进度。 |
| `attachDispatcher(Nythros\Framework\Event\EventDispatcherInterface $dispatcher): void` | 事件埋点接线：监听 combat.kill / combat.pickup 并驱动对应进度源（组装层在装配后调用一次）。 |
| `claimReward(string $uid, string $questId, Nythros\Framework\Inventory $inventory): bool` | 领奖：completed 且未领奖时把奖励表逐项入包并置 rewarded；否则 false（幂等）。 |
| `definitions(): Nythros\Framework\Quest\QuestRepository` | 任务定义注册表（组装层注册定义用）。 |
| `progressOf(string $uid, string $questId): ?Nythros\Framework\Quest\QuestProgress` | 查询某 uid 某任务的进度；无记录返回 null。 |
| `reportCollect(string $uid, string $itemId, int $count): void` | 收集进度上报：source=collect 且 targetId 匹配的任务按入包数量累计。 |
| `reportKill(string $uid, string $monsterTypeId): void` | 击杀进度上报：source=kill 且 targetId 匹配的任务计数 +1。 |
| `reportTalk(string $uid, string $npcId): void` | 对话进度上报：source=talk 且 targetId 匹配的任务计数 +1（同一 NPC 重复对话照常累计， |

#### `Nythros\Framework\Quest\QuestStoreInterface`
任务进度存储契约：进度状态机的持久化边界（实现方负责序列化，Redis/MySQL 等后端按部署裁决）。

| 方法 | 说明 |
|---|---|
| `all(string $uid): array` | 某 uid 的全部任务进度。 |
| `delete(string $uid, string $questId): void` | 删除某 uid 某任务的进度记录；不存在静默。 |
| `get(string $uid, string $questId): ?Nythros\Framework\Quest\QuestProgress` | 查询某 uid 某任务的进度；无记录返回 null。 |
| `save(Nythros\Framework\Quest\QuestProgress $progress): void` | 保存（整体覆盖语义：以传入进度为准）。 |

#### `RedisQuestStore`
任务进度存储 Redis 实现（照 GuildStore/FriendStore 先例：\Redis|\Closure 构造 + 键前缀 + 格式白名单， · implements `Nythros\Framework\Quest\QuestStoreInterface`

| 方法 | 说明 |
|---|---|
| `__construct(Redis\|Closure $redis, string $prefix = 'nythros:gw:')` | 构造任务进度存储。 |
| `all(string $uid): array` |  |
| `delete(string $uid, string $questId): void` |  |
| `get(string $uid, string $questId): ?Nythros\Framework\Quest\QuestProgress` |  |
| `save(Nythros\Framework\Quest\QuestProgress $progress): void` |  |

### `Nythros\Framework\Server`

#### `ConnectionRegistry`
连接-实体注册表：维护 connectionId <-> entityId 双向映射，保证两侧查询与删除 O(1) 且无脏数据；

| 方法 | 说明 |
|---|---|
| `attach(string $connectionId, string $entityId): void` | 挂载映射：重复挂载先清旧映射再写入，保证双向表始终一致。 |
| `detachByConnection(string $connectionId): ?string` | 按连接摘除映射：双向删除后返回原实体 ID，未挂载返回 null。 |
| `detachByEntity(string $entityId): ?string` | 按实体摘除映射：双向删除后返回原连接 ID，未挂载返回 null。 |
| `getConnectionId(string $entityId): ?string` | 按实体 ID 查连接 ID，未挂载返回 null。 |
| `getContainer(string $connectionId): ?object` | 按连接查当前容器；null = 无容器记录（回落宿主世界，SocialServer 恒 null 合法）。 |
| `getEntityId(string $connectionId): ?string` | 按连接 ID 查实体 ID，未挂载返回 null。 |
| `has(string $connectionId): bool` | 判断连接是否已挂载实体映射（即是否已认证）。 |
| `moveToContainer(string $connectionId, ?object $container): void` | 标记连接的当前容器：$container 为容器引用（World \| RoomInstance 等空间宿主）， |
| `resolveContainerContext(string $connectionId, Nythros\Contracts\WorldInterface $host): array` | 解析连接的容器上下文（连接 → 容器 → 容器内 EntityManager/AOI 的路由解析入口，ADR-024 §9 V6）： |

#### `FrameMerger`
出站帧合并器：在连接分组缓冲之上提供同帧去重、优先级过滤与单帧字节配额。

| 方法 | 说明 |
|---|---|
| `__construct(Nythros\Protocol\BatchSerializerInterface $serializer)` | 构造合并器并注入批量序列化器（drain 时把该连接本帧全部帧编码为一个批量包）。 |
| `drain(int $maxBytesPerConnection, array $softFilterConnIds = [...]): array` | 取出全部帧：按连接把本帧全部帧编码为单个批量包（每连接一个字节串）并清空缓冲； |
| `enqueue(Nythros\Network\ConnectionInterface $conn, string $type, array $payload, ?string $dedupKey = NULL): void` | 入队一帧：按策略表分类——状态帧同 key 替换（保留原槽位、只换负载），事件帧追加。 |

#### `MovementValidator`
移动校验器（R3 反作弊基线）：O(1) 热路径的 move 指令合法性门控，纯 framework——

| 方法 | 说明 |
|---|---|
| `__construct(int $maxStepAxis = 2, float $maxStepDistance = 2.5, int $maxCommandsPerWindow = 30, float $windowSeconds = 1.0, float $maxWindowDistance = 10.0)` |  |
| `forget(string $entityId): void` | 丢弃某实体的时间窗状态（断连清理路径）：窗口行按 entityId 无界增长且无 TTL，接线层在断连清理 |
| `validate(string $entityId, int $dx, int $dy, int $fromX, int $fromY, float $now): ?string` | 校验一次 move 指令（O(1)）：单步上限 → 频率门控 → 瞬移检测，任一失败即短路返回原因。 |

#### `RealtimeServer`
实时服务器运行时（抽象基类）：把「基于 World 的实时游戏服务器」的通用骨架收拢到这里， · abstract

| 方法 | 说明 |
|---|---|
| `__construct(Nythros\Network\ServerInterface $server, Nythros\Protocol\BatchSerializerInterface $serializer, Nythros\Contracts\WorldInterface $world, Nythros\Framework\Server\ConnectionRegistry $registry, int $sendBufferSoftLimitBytes = 2097152, int $sendBufferHardLimitBytes = 10485760, int $maxFrameBytesPerConnection = 524288)` |  |
| `dispatch(Nythros\Network\ConnectionInterface $conn, string $data): void` | 消息分发兜底：捕获一切异常记日志，并尽力回一个 500 error 帧（发送失败只记日志不抛出）。 |
| `register(): void` | 注册事件处理器（不触发 runAll）：单进程多服务组装时先 register 再统一启动。 |
| `sendToEntity(string $entityId, string $type, array $payload): void` | 定向发送：经 registry 反查 entityId 对应连接并入 outbox（帧末批量发送）。 |
| `setCrossContainerCleanup(?callable $cleanup): void` | 注入跨容器断连清理回调（ADR-024 §9 V3）：closeConnection 模板在世界 EM 查空时兜底调用， |
| `setMovementValidator(?Nythros\Framework\Server\MovementValidator $validator): void` | 注入移动校验器（R3 反作弊基线）：handleMove 模板在坐标变更前调用其 O(1) 校验，失败回 |
| `start(): void` | 启动服务器：注册处理器后进入阻塞事件循环。 Starts the server: registers handlers, then enters the blocking event loop. |

### `Nythros\Framework\Social`

#### `Nythros\Framework\Social\ConnectionHubInterface`
社交连接层契约：uid↔连接登记、分组索引、会话存取与下行投递的最小面（ADR-021：取代 GatewayClientInterface，

| 方法 | 说明 |
|---|---|
| `bindUid(string $clientId, string $uid): void` | 将 clientId 与 uid 绑定（单点登录的在线态依据；一 uid 多连接多对多）。 |
| `closeClient(string $clientId): void` | 关闭指定连接（踢下线；传输层负责真正断开并触发 onClose 清理）。 |
| `getClientIdByUid(string $uid): array` | 获取与 uid 绑定的全部 clientId。 |
| `getSession(string $clientId): ?array` | 读取连接的会话数据。 |
| `isUidOnline(string $uid): bool` | 判断 uid 是否在线（存在绑定连接）。 |
| `joinGroup(string $clientId, string $group): void` | 将连接加入分组。 |
| `leaveGroup(string $clientId, string $group): void` | 将连接移出分组。 |
| `sendToAll(string $message, ?string $excludeClientId = NULL): void` | 向所有客户端广播（可排除指定连接，如发送者本人）。 |
| `sendToClient(string $clientId, string $message): void` | 向指定连接直接发送（未绑定的认证失败回执等场景）。 |
| `sendToGroup(string $group, string $message, ?string $excludeClientId = NULL): void` | 向分组广播（可排除指定连接）。 |
| `sendToUid(string $uid, string $message): void` | 向 uid 定向发送（全部绑定连接各一份；离线自动丢弃）。 |
| `setSession(string $clientId, array $session): void` | 整量覆盖会话（丢弃旧字段）。 |
| `updateSession(string $clientId, array $session): void` | 与会话合并（未提及字段保留）。 |

#### `Nythros\Framework\Social\FriendStoreInterface`
好友关系存储契约（无 TTL，持久；好友关系双向——A→B 与 B→A 一致）。

| 方法 | 说明 |
|---|---|
| `accept(string $applicantUid, string $acceptorUid): array` | 同意申请：applicantUid 向 acceptorUid 的待处理申请 → 双向写好友关系并清除申请。 |
| `apply(string $fromUid, string $toUid): array` | 申请好友：fromUid → toUid 写入待处理申请；已是好友/重复申请/自邀拒绝。 |
| `list(string $uid): array` | 好友列表。 |
| `reject(string $applicantUid, string $rejectorUid): array` | 拒绝申请：移除 applicantUid → rejectorUid 的待处理申请。 |
| `remove(string $uid, string $targetUid): array` | 删除好友：双向一致移除 uid ↔ targetUid 的好友关系。 |

#### `GuildStore`
帮派存储（Redis 持久，无 TTL）：最小 join/leave 面 + R3 正式化面（建会/解散/踢人/职位/公告/审批/人数上限）。 · implements `Nythros\Framework\Social\GuildStoreInterface`

| 方法 | 说明 |
|---|---|
| `__construct(Redis\|Closure $redis, string $prefix = 'nythros:gw:')` | 构造帮派存储。 |
| `apply(string $uid, string $guildId): array` |  |
| `approve(string $approverUid, string $applicantUid, string $guildId, bool $accept): array` |  |
| `create(string $uid, string $guildId, ?string $name, int $maxMembers): array` |  |
| `disband(string $operatorUid, string $guildId): array` |  |
| `findByUid(string $uid): ?string` |  |
| `get(string $guildId): ?array` |  |
| `join(string $uid, string $guildId): bool` |  |
| `kick(string $operatorUid, string $targetUid, string $guildId): array` |  |
| `leave(string $uid, string $guildId): bool` |  |
| `members(string $guildId): array` |  |
| `promote(string $operatorUid, string $targetUid, string $guildId, string $role): array` |  |
| `roleOf(string $uid, string $guildId): ?string` |  |
| `setNotice(string $operatorUid, string $guildId, string $notice): array` |  |

#### `Nythros\Framework\Social\GuildStoreInterface`
帮派存储契约（无 TTL，持久）：最小 join/leave 面（ADR-015 §1.9）+ R3 正式化面

| 方法 | 说明 |
|---|---|
| `apply(string $uid, string $guildId): array` | 申请入会：写入待审批列表；已有帮派/已是成员/重复申请/满员拒绝。 |
| `approve(string $approverUid, string $applicantUid, string $guildId, bool $accept): array` | 审批（会长/官员）：accept=true 把申请人收为成员（受人数上限约束）；false 移除申请。 |
| `create(string $uid, string $guildId, ?string $name, int $maxMembers): array` | 建会：creator 成为会长；guildId 已存在或 creator 已有帮派时拒绝。 |
| `disband(string $operatorUid, string $guildId): array` | 解散帮派（仅会长）：删除帮派数据与全部成员索引，返回原成员列表供分组清场。 |
| `findByUid(string $uid): ?string` | uid → 所在帮派 guildId。 |
| `get(string $guildId): ?array` | 读取帮派详情（auth 恢复下发用）。 |
| `join(string $uid, string $guildId): bool` | 加入帮派（ADR-015 §1.9 最小面，保留）：members 追加（幂等）+ 写 uid-guild 索引； |
| `kick(string $operatorUid, string $targetUid, string $guildId): array` | 踢人（会长/官员，且目标阶位必须低于操作者）。 |
| `leave(string $uid, string $guildId): bool` | 退出帮派。 |
| `members(string $guildId): array` | 成员与职位列表。 |
| `promote(string $operatorUid, string $targetUid, string $guildId, string $role): array` | 任命（仅会长）：把目标改为 officer 或 member（不可指向自己或会长）。 |
| `roleOf(string $uid, string $guildId): ?string` | uid 在指定帮派的职位；非成员 null。 |
| `setNotice(string $operatorUid, string $guildId, string $notice): array` | 公告（会长/官员）：写帮派公告字段。 |

#### `Nythros\Framework\Social\HubTransportInterface`
连接层传输端口：hub 的下行投递与踢线动作落到具体传输实现（由接入层绑定，如 Workerman 连接），

| 方法 | 说明 |
|---|---|
| `close(string $clientId): void` | 关闭指定连接（触发接入层的 onClose 清理路径）；连接已不存在时静默忽略。 |
| `sendToConnection(string $clientId, string $message): void` | 向指定连接写入帧字节；连接已不存在时静默丢弃。 |

#### `InMemoryConnectionHub`
进程内连接注册表：uid↔connections 多对多表 + group→conns 索引 + 连接会话存取（ADR-021 自研单栈的连接层实现）。 · implements `Nythros\Framework\Social\ConnectionHubInterface`

| 方法 | 说明 |
|---|---|
| `__construct(Nythros\Framework\Social\HubTransportInterface $transport)` | 构造连接注册表。 |
| `attachConnection(string $clientId): void` | 连接建立登记（onConnect 调用）：进入存活连接表，sendToAll 广播全集由此而来。 |
| `bindUid(string $clientId, string $uid): void` |  |
| `closeClient(string $clientId): void` |  |
| `detachConnection(string $clientId): void` | 连接关闭清理（onClose 一次性调用）：摘存活登记、uid 绑定、全部所属分组与会话——对齐 gateway-worker 自动解绑的行为承诺。 |
| `getClientIdByUid(string $uid): array` |  |
| `getSession(string $clientId): ?array` |  |
| `isUidOnline(string $uid): bool` |  |
| `joinGroup(string $clientId, string $group): void` |  |
| `leaveGroup(string $clientId, string $group): void` |  |
| `sendToAll(string $message, ?string $excludeClientId = NULL): void` |  |
| `sendToClient(string $clientId, string $message): void` |  |
| `sendToGroup(string $group, string $message, ?string $excludeClientId = NULL): void` |  |
| `sendToUid(string $uid, string $message): void` |  |
| `setSession(string $clientId, array $session): void` |  |
| `updateSession(string $clientId, array $session): void` |  |

#### `LocationStore`
位置快照与掉线标记存储（Redis 持久，跨进程）。 · implements `Nythros\Framework\Social\LocationStoreInterface`

| 方法 | 说明 |
|---|---|
| `__construct(Redis\|Closure $redis, string $prefix = 'nythros:gw:')` | 构造位置存储。 |
| `clearOffline(string $uid): void` | 清除掉线标记（DEL offline:{uid}）。 |
| `getLocation(string $uid): ?array` | 读位置快照（GET location:{uid} → 解码 → 逐字段校验）。 |
| `isOffline(string $uid): bool` | 掉线判定（EXISTS offline:{uid}）。 |
| `markOffline(string $uid): void` | 写掉线标记（SETEX 300s '1'）。 |
| `saveLocation(string $uid, string $mapId, string $channelId, ?float $x = NULL, ?float $y = NULL): void` | 写位置快照（SETEX 300s JSON，覆盖写）。 |

#### `Nythros\Framework\Social\LocationStoreInterface`
位置快照与掉线标记存储契约。

| 方法 | 说明 |
|---|---|
| `clearOffline(string $uid): void` | 清除掉线标记（DEL offline:{uid}）。 |
| `getLocation(string $uid): ?array` | 读位置快照。 |
| `isOffline(string $uid): bool` | 掉线判定（EXISTS offline:{uid}）。 |
| `markOffline(string $uid): void` | 写掉线标记（SETEX 300s）。 |
| `saveLocation(string $uid, string $mapId, string $channelId, ?float $x = NULL, ?float $y = NULL): void` | 写位置快照（SETEX 300s JSON，覆盖写）。 |

#### `RedisFriendStore`
好友关系存储 Redis 实现（照 GuildStore 先例：\Redis|\Closure 构造 + 键前缀 + 格式白名单，无 TTL 持久）。 · implements `Nythros\Framework\Social\FriendStoreInterface`

| 方法 | 说明 |
|---|---|
| `__construct(Redis\|Closure $redis, string $prefix = 'nythros:gw:')` | 构造好友存储。 |
| `accept(string $applicantUid, string $acceptorUid): array` |  |
| `apply(string $fromUid, string $toUid): array` |  |
| `list(string $uid): array` |  |
| `reject(string $applicantUid, string $rejectorUid): array` |  |
| `remove(string $uid, string $targetUid): array` |  |

#### `RedisTeamStore`
组队状态机 Redis Lua 实现（跨进程「一 uid 一队」不变量，ADR-015 §1.6 修复版）。 · implements `Nythros\Framework\Social\TeamStoreInterface`

| 方法 | 说明 |
|---|---|
| `__construct(Redis\|Closure $redis, string $prefix = 'nythros:gw:')` | 构造 Redis 组队存储。 |
| `accept(string $uid, string $teamId, int $maxSize, int $teamTtl, float $now): array` |  |
| `disband(string $uid, string $teamId, int $teamTtl): array` |  |
| `findByUid(string $uid): ?string` |  |
| `get(string $teamId): ?array` |  |
| `invite(string $senderUid, string $targetUid, int $maxSize, int $teamTtl, float $now): array` |  |
| `leave(string $uid, string $teamId, int $teamTtl): array` |  |
| `reject(string $uid, string $teamId, int $teamTtl, float $now): array` |  |

#### `SocialService`
社交业务核心：auth（完整握手 + token 消费登录）/ chat 五语义 / team 状态机 / map:enter / map:join

| 方法 | 说明 |
|---|---|
| `__construct(Nythros\Framework\Social\ConnectionHubInterface $hub, Nythros\Security\TokenManagerInterface $tokenManager, Nythros\Cluster\ServiceRegistryInterface $registry, Nythros\Security\AuthenticatorInterface $authenticator, Nythros\Framework\Social\LocationStoreInterface $location, Nythros\Framework\Social\GuildStoreInterface $guild, Nythros\Framework\Social\TeamStoreInterface $team, Nythros\Protocol\SerializerInterface $serializer, array $mapIds, array $endpointAddresses = [...], ?Nythros\Framework\Social\FriendStoreInterface $friend = NULL, ?int $minClientVersion = NULL)` | 组装社交业务依赖。 |
| `handleAuth(string $clientId, Nythros\Protocol\Message $msg): void` | 认证登录（ADR-015 §1.4 完整流程）：authenticate → mapId 白名单 → 踢旧连新 → 恢复判定 → |
| `handleChat(string $clientId, string $uid, Nythros\Protocol\Message $msg): void` | 聊天五语义（ADR-015 §1.5）：world/channel/team/guild/private，错误一律 chat:error 回发起方。 |
| `handleClose(string $uid): void` | 连接关闭：写掉线标记（ADR-015 §1.8）。 |
| `handleFriend(string $clientId, string $uid, Nythros\Protocol\Message $msg): void` | 好友五语义（R3 社交批）：friend:apply/accept/reject/remove/list，委托 FriendStore，返回码映射 friend:error。 |
| `handleGuild(string $clientId, string $uid, Nythros\Protocol\Message $msg): void` | 帮派语义（ADR-015 §1.9 最小面 + R3 正式化面）：guild:join/leave 沿用最小实现； |
| `handleMapEnter(string $clientId, string $uid, Nythros\Protocol\Message $msg): void` | map:enter 进图/重连凭证续签（ADR-015 §1.7）：mapId 白名单 → 选频道 → issue(['map']) → map:entered。 |
| `handleMapJoin(string $clientId, string $uid, Nythros\Protocol\Message $msg): void` | map:join 进图/切图上报（ADR-015 §1.7）：白名单 → 退旧频道组 → 写位置快照 → joinGroup → map:joined。 |
| `handleTeam(string $clientId, string $uid, Nythros\Protocol\Message $msg): void` | 组队状态机（ADR-015 §1.6）：invite/accept/reject/leave/disband，委托 TeamStore，返回码映射 team:error。 |
| `handleTokenAuth(string $clientId, Nythros\Protocol\Message $msg, string $scope): void` | token 消费登录（ADR-021 §3.2 多 scope 兑现）：chat/team 角色对 gateway 完整握手签发的多 scope token |
| `hub(): Nythros\Framework\Social\ConnectionHubInterface` | 暴露社交连接层门面（运行时入口读会话/清理时用）。 |

#### `Nythros\Framework\Social\TeamStoreInterface`
组队状态机存储契约（ADR-015 §1.6）：边界判定 + 读改写原子化，返回码枚举。

| 方法 | 说明 |
|---|---|
| `accept(string $uid, string $teamId, int $maxSize, int $teamTtl, float $now): array` | 接受邀请：已在队 6（先于队伍不存在）；队伍不存在 7；无本人有效邀请 4/5；满员 3；否则入队。 |
| `disband(string $uid, string $teamId, int $teamTtl): array` | 解散：队伍不存在 7；非队长 1；否则解散。 |
| `findByUid(string $uid): ?string` | uid → 所在队伍 teamId。 |
| `get(string $teamId): ?array` | 读取队伍详情（auth 恢复下发 auth_ok.team 用）。 |
| `invite(string $senderUid, string $targetUid, int $maxSize, int $teamTtl, float $now): array` | 邀请：无队 sender 自动建队（Lua 内 INCR seq + 判队原子）；自邀 9；目标已在队 2； |
| `leave(string $uid, string $teamId, int $teamTtl): array` | 退队：非成员（含队伍不存在）8；队长离开 = 解散；成员离开 = 移除。 |
| `reject(string $uid, string $teamId, int $teamTtl, float $now): array` | 拒绝邀请：队伍不存在/无本人有效邀请 4；邀请非本人 5；否则删条目。 |

