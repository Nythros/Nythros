# 10 · 集群扩展：从一台机器到一组进程

> **这一阶打通**：多角色进程、服务注册发现、跨进程 token 消费、跨图迁移的心智模型，以及**把前 9 章组装成品**的验收方式。
> **新用 API**（全公开）：`Nythros\Cluster\{ServiceRegistryInterface, ServiceInstance}`（接口与值对象公开；`RedisServiceRegistry` 属引擎装配类，用法与 demo 同口径）、`Nythros\Framework\Deploy\DeployConfig`、`Nythros\Framework\Cluster\PlayerTransferStoreInterface`（`InMemoryPlayerTransferStore`/`RedisPlayerTransferStore` 成对，0.x 装配现实）。
> **Redis**：必须。这一章以**概念 + 部署实操**为主——完整多角色装配代码量大，demo 已经写完并验收过，你的任务是看懂它并跑起来，再按自己玩法裁剪。

## 1. 心智转变：一个进程的世界观如何裂变成一组

前 9 章你所有状态都在一进程内。集群化的最小切口是三个「跨进程握手」：

```
① token：登录进程签发（map/chat/team 多 scope）→ 各角色进程消费自己的 scope
   —— 01 章的 InMemoryTokenStore 换 RedisTokenStore，其余逻辑一字不改（这就是第 01 章坚持用接口的原因）
② 发现：每个服务进程启动即 register + 5s heartbeat（meta 可带 playerCount）
   → 需要路由的一方 discover 拿存活实例表（stopping 状态自动过滤）
③ 归属：玩家绑定哪张图哪个频道（LocationStore 快照）+ 迁移票据（transfer snapshot）
```

接口就在你脚下：`ServiceRegistryInterface` 六件套——
`register / heartbeat / discover / unregister / resolve(type, uid) / bind / unbind`。

## 2. 部署实操：直接用 demo 的拓扑跑通

```bash
# monorepo（或你 clone 下来的仓库）内——注意这是路线 B 的 demo，不是骨架
docker compose up -d                          # Redis + MySQL
composer install
php bin/server start                          # 按 packages/demo/config/deploy.yaml 起 social×3 + maps
php packages/demo/bin/verify-phase5.php       # 末行 RESULT: PASS
```

`verify-phase5.php` 验收的正是本章全部语义：登录（gateway）→ 进图凭证（多 scope token 跨进程消费）→
战斗直连（Map 消费 map scope）→ 聊天/组队（chat/team 角色）→ 掉线重连 → 滚动更新 → token 单向性。
**跑通它 = 你把前 9 章的所有零件在生产拓扑下看过了一遍。**

## 3. 读 demo 装配的路线（按本章三个切口）

| 切口 | 去哪读 |
|---|---|
| token 跨进程 | `packages/demo/bin/run-worker.php`（装配 `RedisTokenStore`）；消费侧 `MapServer::handleAuthMessage` L1378 |
| 注册/心跳/发现 | `MapServer` L85-92（心跳 5s、TTL 15s、meta 带 playerCount）；社交侧寻路 `SocialService`（构造吃 `ServiceRegistryInterface`） |
| 迁移 | `MapServer::exportTransferSnapshot`/`consumeTransferSnapshot`（L1573/L1678）；端到端 `packages/demo/bin/verify-transfer.php` |
| 拓扑唯一事实源 | `packages/demo/config/deploy.yaml` + `DeployConfig` 解析校验（端口/serviceId 唯一性在解析期拒绝） |

## 4. 把骨架迁上集群（你自己的项目怎么接）

1. **拆进程**：骨架 `GameServer` 已是单频道 worker 形态，天然可水平复制——`config/servers.php`
   加条目即多频道；起 gateway/chat 角色则引入 `SocialService`（04/05 章伏笔在此回收）。
2. **换存储**：`InMemoryTokenStore`→`RedisTokenStore`、`InMemoryPlayerTransferStore`→Redis 版（接口不变）。
3. **接线**：worker 启动 `register`、周期 `heartbeat`；gateway 分配进图目标时 `discover` 选实例。
4. **验证**：先跑 demo 的 `verify-phase5.php` 当「参考答案」，再逐条改造成打你的服务。
   生产清单（TLS/Redis 认证/滚动更新/备份）逐条在[部署指南](../deployment.md)；扩缩容/排空语义在
   [MMORPG 大地图](../mmorpg-mode.md) §5。

## 5. 毕业验收（全部 10 章的综合考）

在**你的**工程里做到：两个频道、一个 gateway，alice 登录后 `map:enter` 被分配到 ch-1、bob 到 ch-2，
然后 alice 发迁移请求进 ch-2 与 bob 同屏互见——涉及 token 多 scope、注册发现、位置快照、
转移票据、AOI 视野五条链路的贯通。demo 的 `verify-mmorpg.php`/`verify-transfer.php` 就是这条
验收题的参考答案。

## 6. 教程终点之后

- 你手上的工程 ≈ 骨架 + 10 阶；demo 里剩下的都是「数值与产品决策」（任务链、PVP 闸门、威胁表、
  反作弊窗口调参……）——机制层你已全部见过。
- 反馈通道：文档歧义/示例跑不通，Nythros 仓库开 issue（文档站首页有链接）。玩得开心。
