# 04 · 聊天：从「视野里的人」到「服务器里的人」

> **这一阶打通**：世界/频道/私聊三种消息路由，以及「聊天为什么最终住在社交层」的架构认知。
> **新用 API**：`RealtimeServer::sendToEntity`（基类 final，02 章已解锁）、`Message::create`——本阶刻意只用骨架现有能力。
> **Redis**：不需要。

## 1. 先立靶子：骨架进程能做到的聊天长什么样

demo 的聊天在**社交层**（chat 角色进程），五个 scope（world/channel/team/guild/private）。
而骨架是单 Map 进程——它没有「全服在线表」，但**有本频道全部连接**。所以本阶做一个诚实的最小版：

| scope | 骨架实现 | 完整实现（demo）在哪 |
|---|---|---|
| world | 退化为**本频道广播**（遍历在线实体全发） | SocialService：跨频道经 Redis 注册表扇出 |
| channel | 与 world 同（单进程只有一个频道） | 按进图时 joinGroup 的频道分组 |
| private | uid → entityId → `sendToEntity` 定向 | SocialService + ConnectionHub |
| team / guild | 留给[第 05 章](05-team-guild.md) | team 角色进程 + Redis 存储 |

这不是绕路——**协议语义与 demo 完全一致**（帧形照抄 `SocialService::handleChat`），
第 10 章拆多进程时你的路由只需把「遍历在线」换成「交给 SocialService」，客户端零改动。

## 2. 路由：一条 chat:send 进，chat:message 出

帧约定（与 demo 权威口径一致）：

```text
上行  chat:send   {scope: "world"|"channel"|"private", content: string, [toUid|channelId]}
下行  chat:message{scope, fromUid, content, [timestamp]}        ← 成功无专门回执，收到即送达
下行  chat:error  {code, message}                                ← 400 参数缺 / 404 对象不在
```

`GameServer::handleAuthenticated` 加 case：

```php
case 'chat:send': {
    $fromId = $this->registry->getEntityId($conn->getId());
    $from = $fromId === null ? null : ($this->actors[$fromId] ?? null);
    $scope = (string) ($message->payload['scope'] ?? '');
    $content = trim((string) ($message->payload['content'] ?? ''));
    if ($from === null || $content === '') {
        $this->send($conn, Message::create('chat:error', ['code' => 400, 'message' => 'scope/content 必填'], $message->requestId));

        return;
    }
    $fromUid = method_exists($from, 'uid') ? (string) $from->uid() : (string) $fromId;
    $frame = ['scope' => $scope, 'fromUid' => $fromUid, 'content' => mb_substr($content, 0, 200)];

    if ($scope === 'private') {
        $toUid = (string) ($message->payload['toUid'] ?? '');
        $toId = $this->findEntityIdByUid($toUid);            // 遍历本频道在线表
        if ($toId === null) {
            $this->send($conn, Message::create('chat:error', ['code' => 404, 'message' => '目标不在线'], $message->requestId));

            return;
        }
        $this->sendToEntity($toId, 'chat:message', $frame + ['timestamp' => time()]);
        $this->sendToEntity((string) $fromId, 'chat:message', $frame + ['timestamp' => time()]); // 自己也要收到（客户端以接收为准渲染）

        return;
    }

    // world/channel：本频道全体（含自己）。注意与 combat:hit 不同——聊天不越视野，背对背也能收到。
    foreach ($this->actors as $entityId => $actor) {
        $this->sendToEntity((string) $entityId, 'chat:message', $frame + ['timestamp' => time()]);
    }

    return;
}
```

```php
/** uid → entityId 的进程内反查（BasePlayer::uid() 由 attachConnection 登记） */
private function findEntityIdByUid(string $uid): ?string
{
    foreach ($this->actors as $entityId => $actor) {
        if (method_exists($actor, 'uid') && $actor->uid() === $uid) {
            return (string) $entityId;
        }
    }

    return null;
}
```

## 3. 验收

```bash
php bin/launch.php
php client.php alice 18081   # 终端 A
php client.php bob 18081     # 终端 B
```

给 `client.php` 加一个最小发送（auth_ok 后）：

```php
$connection->send($serializer->encodeBatch([
    Message::create('chat:send', ['scope' => 'world', 'content' => 'hello nythros']),
]));
```

预期：A、B 两个终端都打印出 `[client] <- chat:message {"scope":"world","fromUid":"alice",...}`；
把 scope 换 `private`、加 `"toUid":"bob"` → 只有 B 收到（外加 A 自己那一份回执语义）。
把 alice 连到 18081、bob 连 18082（不同进程）发 private → 404：这一直觉实验正是下一章的动机。

## 4. 完整形态：SocialService（对照与预告）

骨架版三件做不到的事：**跨频道**（每进程一张表）、**跨角色**（聊天不该占用地图帧预算）、
**离线消息**。demo 的答案：chat 角色进程 + `Nythros\Framework\Social\SocialService`（公开类，构造即
11 参全家桶：`ConnectionHubInterface + TokenManagerInterface + ServiceRegistryInterface +
AuthenticatorInterface + LocationStore + GuildStore + TeamStore + Serializer + mapIds + endpoints`），
下行经 `HubTransportInterface` 适配器落真实 WebSocket（demo 的 `WorkermanHubTransport`）。
完整语义/装配逐条在[社交指南](../social-guide.md)。下一阶先在骨架进程里把**组队状态机**立起来：
[05 组队与帮派](05-team-guild.md)。
