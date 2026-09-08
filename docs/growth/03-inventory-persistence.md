# 03 · 背包与持久化：打完怪，东西得留下

> **这一阶打通**：拾取进背包（Inventory）、断线/重连/停服后玩家数据不丢（归档管线）。
> **新用 API**：`Nythros\Framework\Inventory`、`Nythros\Framework\Persistence\ArchivePipeline`、`Nythros\Framework\Combat\DropEntity`、`Nythros\Persistence\{StorageInterface, InMemoryStorage}`（接口与框架服务均公开；两个存储实现类按 0.x 装配现实使用）。
> **Redis**：本章不需要（进程内存储起步）。**注意与 demo 现口径的分叉**：demo 缺省已升级为 export 持久化模式
> （背包 Redis 权威 + Stream 导出 + storage-exporter 落 MySQL，见 persistence-guide §2.1）；本章按 InMemory→MySQL
> 直写的教学阶梯走，学完本章再读 §5 的「进阶到 export 模式」即可对齐。

## 1. 拾取：路由只需要「解析 + 委托」

上一阶打完怪，掉落物已经躺在地上（`drop:spawned`）。本阶加 `pickup` 路由。
先看一个会救你命的事实：**`CombatService::pickup()` 内部完成全部广播**——成功时定向 `item:added`、
视野 `drop:removed`；超重/未注册等失败时定向 `combat:error`。你的路由只要做 demo 同样的三件事：
解析 → 距离校验 → 委托。

```php
use Nythros\Framework\Combat\DropEntity;
use Nythros\Framework\Inventory;

case 'pickup': {
    $entityId = $this->registry->getEntityId($conn->getId());
    $player = $entityId === null ? null : ($this->actors[$entityId] ?? null);
    $dropId = $message->payload['dropId'] ?? null;
    if ($player === null || !is_string($dropId) || $dropId === '') {
        $this->send($conn, Message::create('error', ['code' => 401, 'message' => 'unauthorized'], $message->requestId));

        return;
    }
    // 掉落物是实体（DropEntity 实现 EntityInterface），从 entityManager 取，不是 actor 表
    $drop = $this->entityManager->get($dropId);
    if (!$drop instanceof DropEntity) {
        $this->sendToEntity($entityId, 'combat:error', ['code' => 'invalid_target', 'message' => '掉落物不存在']);

        return;
    }
    // 距离校验照抄 demo 的 isNeighborIn（AOI query 九宫格判定），然后一切交给 CombatService：
    $inv = $this->inventories[$entityId] ??= new Inventory();
    if ($this->combat->pickup($player, $drop, $inv) && $player->uid() !== null) {
        $this->archive?->markDirty($player->uid(), ['inventory' => $inv->all()]); // 帧内零 I/O，见 §2
    }

    return;
}
```

要点：`pickup()` 返回 false 的两种形态（物品未注册 / 被别人抢先）`CombatService` 已广播了原因帧，
路由不要再补发；背包的键用 **entityId**（每连接实例），归档的键用 **uid**（跨连接身份）——
demo 就是这个双层口径，第 4 小节登录回读会把两层接起来。

## 2. 归档：帧内只标脏，I/O 全在帧外

`ArchivePipeline` 的设计纪律：结算路径只 `markDirty`（内存登记最新态，同 id 覆盖写），
落库由 **30s 周期 / 主动 flushId / 停服** 三个兜底触发——这是它能待在 6ms 帧预算里的原因。

装配（`bin/map-worker.php`）：

```php
use Nythros\Framework\Persistence\ArchivePipeline;
use Nythros\Persistence\InMemoryStorage;

$archive = new ArchivePipeline(new InMemoryStorage(), 'players', $timer); // 传 timer = 自带 30s 周期 flush
$game->attachArchive($archive);
```

登录回读（`handleAuthMessage` 挂载玩家之后，跨连接续档的关键一步）：

```php
$snapshot = $this->archive?->load($uid);
if (is_array($snapshot) && isset($snapshot['inventory']) && is_array($snapshot['inventory'])) {
    $inv = $this->inventories[$entityId] = new Inventory();
    foreach ($snapshot['inventory'] as $itemId => $count) {
        $inv->add((string) $itemId, (int) $count);
    }
}
```

再加一条 demo 同款纪律：**主动登出是强制同步点**（`logout` 路由里 `flushId($uid)` 后断连），
断线兜底覆写 `onEntityCleanedUp` 钩子同样 `flushId`。

## 3. 验收

1. 登录 alice → 打怪 → `pickup{dropId}` → 收 `item:added`（服务端自动发的，不是你补的）；
2. 断开重连（新 entityId）→ 回读生效：再发个自定义 `bag` 路由确认背包还在；
3. `InMemoryStorage` 重启必丢（预期内）——把装配换成 `MySqlStorage`（`pdoFactory` + 幂等 `createSchema`，
   步骤见[持久化指南](../persistence-guide.md)）→ 重启后回读成功，这一步做完才算「数据不丢」。
   （直跑 demo 现栈对照时注意：缺省 export 模式的落库走 storage-exporter，或显式
   `NYTHROS_PERSIST_MODE=mysql` 回到本章直写口径。）

## 4. demo 对照与常见坑

- 完整参考：`MapServer::handlePickup`（markDirty 标脏）与登出 `handleLogout`（scheduleFlushId 合并窗）；
  装配双模式选择与恢复开关见 `MapChannelFactory`（`NYTHROS_PERSIST_MODE` / `NYTHROS_ARCHIVE_RESTORE`
  语义按模式不同——export 缺省开、读 Redis 背包权威；mysql 回退 `=1` 才开、读归档，persistence-guide §2.1/§4）。
- **坑 1**：在 pickup 路由里直接 `storage->save()`——同步 I/O 进帧，压测必爆。永远只 `markDirty`。
- **坑 2**：`markDirty` 传增量——管线语义是**最新全量覆盖写**，传 `$inv->all()` 全量。
- **坑 3**：把 `DropEntity` 当 Actor 从 `getActor()` 找——它是实体，走 `entityManager`。

## 5. 进阶：对齐 demo 缺省的 export 模式（选读）

本章的直写路线在高频拾取下会把 MySQL 往返拖进断连/停机同步点。demo 现缺省把「热状态权威」上移 Redis：
`markDirty` 语义不变（零 IO），冲刷点改为一条 pipeline 同窗写 Redis 背包 hash（`nythros:bag:{uid}`，attach 恢复首读）
+ 脏快照 `XADD` 导出 Stream，由独立 storage-exporter 进程消费落 MySQL——游戏 worker 零 PDO。
三步进阶：①装配换 `RedisExportPipeline`（同实现 `PersistPipelineInterface`，业务代码零改动）；
②`deploy.yaml` 加 `storage` 单元并起 `run-exporter.php`；③backlog/心跳告警接入（deployment §4）。
原理与取舍见 persistence-guide §2.1 与 best-practices §1。

下一阶：[04 聊天](04-chat.md)——从「一个人的世界」到「一群人的服务器」。
