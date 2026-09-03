# 05 · 组队与帮派：把状态机交给存储，而不是 if-else

> **这一阶打通**：邀请→接受的组队状态机、帮派增删查、以及「社交状态为什么放 Redis 而非内存」。
> **新用 API**：`Nythros\Framework\Social\{TeamStoreInterface, GuildStoreInterface}`（接口公开；
> 参考实现 `RedisTeamStore` / `RedisFriendStore` / `GuildStore`，接口自实现即可换后端）。
> **Redis**：本阶起需要（团队/帮派是要跨频道、跨重启共享的持久态）。`docker compose up -d redis`。

## 1. 组队状态机：别自己写，用契约

`TeamStoreInterface` 把组队的全部规则收进六个方法（每个返回统一的 `array{ok, code?, teamId?, members?}` 形状）：

```text
findByUid(uid)                 → ?teamId
get(teamId)                    → ?array
invite(sender,target,maxSize,ttl,now) → array   // 无房则建房、有房则挂邀请
accept(uid,teamId,maxSize,ttl,now)    → array   // 接受入队（满员→拒绝）
reject(uid,teamId,ttl)  leave(...)  disband(...) → array
```

`now` 由调用方注入（可测试性设计），`ttl` 是无活动自动解散秒数——**状态机与时间源解耦，
你不需要懂它内部怎么实现就能测**。

装配（`bin/map-worker.php`）：

```php
use Nythros\Framework\Social\RedisTeamStore;

$teams = new RedisTeamStore($redis);   // $redis = new Redis(); $redis->connect('127.0.0.1', 6379);
$game->attachTeamStore($teams);
```

## 2. 路由：把帧转发给 store

```php
case 'team:invite': {
    $r = $this->teams->invite($uid, (string) $message->payload['toUid'], 5, 1800, microtime(true));
    $this->send($conn, Message::create($r['ok'] ? 'team:invited' : 'team:error', $r, $message->requestId));

    return;
}
case 'team:accept': {
    $r = $this->teams->accept($uid, (string) $message->payload['teamId'], 5, 1800, microtime(true));
    // 成功则广播给全队（拿 $r['members'] 逐个 sendToEntity），复用 04 章的下行路
    $this->send($conn, Message::create($r['ok'] ? 'team:joined' : 'team:error', $r, $message->requestId));
    return;
}
// team:reject / team:leave / team:disband 同构，直接转 store 对应方法
```

帧约定照抄 demo `SocialServer::handleTeam`：上行 `team:{verb}`，下行 `team:{past}` 或 `team:error{code}`。

## 3. 帮派

`GuildStoreInterface` 同构（create/disband/kick/promote/notice/apply/approve/join/leave），
参考 `packages/framework/src/Social/GuildStore.php`。路由与组队一模一样，只是换 store 与 verb 前缀 `guild:`。
帮派是**跨频道共享**的——这正是它必须在 Redis 而非进程内存的理由：玩家在 map-1 和 map-2 看到的是同一个帮派。

## 4. 验收

```bash
# 两个终端分别 alice/bob 登录同一频道
team:invite  toUid=bob   → alice 收 team:invited{teamId}
team:accept  teamId=...  → bob 收 team:joined，alice 收 team:joined 广播（双方都看到 2 人名单）
```

重启一个 map 进程（`php bin/map-worker.php --mapId=main --channelId=ch-1`）→ 队伍还在（Redis 持久），
但**在线位置**要重连刷新——这条区别 [10 章](10-scale-out.md)细讲。

## 5. demo 对照与常见坑

- 完整装配在 `packages/framework/src/Social/SocialService.php`（`handleTeam/handleGuild`），
  路由在 demo `SocialServer.php` L111 起；逐条语义见[社交指南](../social-guide.md)。
- **坑 1**：把邀请过期、满员判定写进自己的路由 if-else——那是 store 的职责，你只转发。
- **坑 2**：`invite` 后不发 `team:invited` 给对方（只回了发起者）——目标端收不到邀请。demo 双向投递。
- **坑 3**：进程内存里存队伍 → 换频道/重启就散。组队/帮派/好友从第一天就该进 Redis。

下一阶进入房间制：[06 匹配与房间](06-matching-rooms.md)。
