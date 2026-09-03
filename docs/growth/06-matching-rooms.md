# 06 · 匹配与房间：撮合进一个「独立小世界」

> **这一阶打通**：排队撮合（MatchingService）、入房委托契约、以及 demo 房间制的核心模式——**一个房间一个战斗容器**。
> **新用 API**：`Nythros\Framework\Matching\{MatchingService, MatchCriteria, MatchTicket, MatchJoinHandlerInterface}`、`Nythros\Contracts\{RoomConfig, RoomInstanceInterface, RoomStateManagerInterface}`。
> **Redis**：不需要（撮合与房间都在进程内）。本章是骨架各章里概念密度最高的一章，先看懂图再动手。

## 1. 核心模式：房间 = 私有战斗容器

demo 的 `RoomHub` 每个房间持有**自己的一套** CombatService + EntityManager + AOI（房间即 `WorldInterface`
门面），玩家从大世界「transfer 全链」进房：世界摘除 → entity_leave → 原子入房 → 容器标记。
帧末统一 flush 仍走宿主 `MapServer::sendToEntity`（`RoomVisionBroadcaster` 只是把「视野」换成房间自有 AOI，
投递复用宿主）。语义细节全部在[房间制玩法](../room-mode.md)——本章给你最短的可跑接线。

## 2. 排队：MatchingService 三步接入

```php
use Nythros\Framework\Matching\{MatchCriteria, MatchingService};

// 装配（bin/map-worker.php）：第三个参数是房间 AOI 工厂闭包——由组装层提供（GridAOI 属引擎装配类）
$matching = new MatchingService($roomManager, $joinHandler, fn () => new GridAOI(10));
$matching->registerCriteria(new MatchCriteria(queueId: 'arena-3v3', teamSize: 2, minLevel: 1, maxLevel: 99));
```

路由侧薄薄一层（帧语义对齐 demo `matching:enqueue/cancel`）：

```php
case 'matching:enqueue': {
    $ok = $this->matching->enqueue((string) $message->payload['queueId'], $uid, $entityId, level: 1, now: microtime(true));
    $this->send($conn, Message::create($ok ? 'matching:queued' : 'matching:error', ['queueId' => $message->payload['queueId']], $message->requestId));

    return;
}
```

再把撮合心跳挂进帧循环（装配处 `$timer->add(0.5, fn () => $this->matching->tick(microtime(true)), persistent: true)`）。
`tick` 返回本轮撮合结果数组——撮合成功时 MatchingService 会调用你注入的 **`MatchJoinHandlerInterface`**。

## 3. 入房委托：46 行的教学级实现

MatchingService 不知道你的房间怎么开——它只调 `admit(candidate)`。demo 的实现
（`packages/demo/src/MatchJoinOrchestrator.php`，46 行）的结构照搬即可：

```php
final class GameJoinHandler implements MatchJoinHandlerInterface
{
    public function __construct(private readonly RoomHubLike $hub) {}

    public function joinRoom(string $roomId, string $entityId): bool
    {
        try {
            $this->hub->admitPlayer($roomId, $entityId); // transfer 全链：摘除→入房→容器标记
            return true;
        } catch (\InvalidArgumentException | \LogicException) {
            return false; // 满员/状态不可入/实体缺失 → 撮合侧重新入队，连接不断
        }
    }
}
```

> 撮合成功后 MatchingService 调 `joinRoom`；入队 `enqueue(queueId, uid, entityId, level, now)` 的
> `level` 就与这里的 `minLevel/maxLevel` 区间配对。

「抛异常 → 转 false → 重排队、断线不断」这条容错口径是章节精髓：**入房失败是排队的一部分，不是事故**。

## 4. 最小房间容器（可跑版）

不搬 654 行 RoomHub，先立三件套就够玩：

```php
final class MiniRoom implements RoomInstanceInterface   // 契约方法照 Contracts 定义实现
{
    public CombatService $combat;                        // 每房间独立实例（broadcaster 指向房间成员）
    public EntityManagerInterface $entities;
    /** @var array<string, string> entityId => uid */
    public array $members = [];

    public function admit(string $entityId): void { /* 从世界 entityManager 摘除 → 塞进本房间 entities */ }
}
```

`room:create / room:join / room:aoe / room:settle / room:close` 的路由闸门语义（谁可以发言/结算、
进程房间数触顶 507）demo `RoomHub` 已全部验证过，最小版先只做「创建者才能 settle/close」。

## 5. 验收

1. 两个客户端 `matching:enqueue{queueId:"arena-3v3"}` → 都收 `matching:queued`；
2. 撮合触发（maxWait 或人数凑齐）→ 双方收到进房帧，房间里互相 attack 生效、**房外玩家收不到**（容器隔离的直觉验证）；
3. 三人 enqueue 两人房 → 第三人一直 queued 到超时（`ticketOf` 可查）。

## 6. demo 对照与常见坑

- 权威参考：`RoomHub`（`packages/demo/src/RoomHub.php`，654 行）+ `RoomVisionBroadcaster` +
  `MapServer` 的 `room:*` 分发（L404）；帧表与状态机全在[房间制玩法](../room-mode.md)。
- **坑 1**：把房间战斗结算塞回大世界 CombatService（靠 entityId 过滤）——隔离语义全靠独立容器，别省。
- **坑 2**：`settle/close` 不做创建者校验 → 任何人结算别人的房间。demo 还处理了「无主房接管」防僵尸泄漏。
- 下一阶：[07 经济](07-economy.md)。
