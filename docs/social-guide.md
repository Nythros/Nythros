# Nythros 社交与经济系统开发手册（Social & Economy Guide）

> 面向读者：要在 Nythros 上开发好友、组队、公会、聊天、邮件、任务、拍卖行、背包/货币、排行榜功能的程序。
> 读完你能：分清社交控制线与地图实时线的数据边界、按角色接通多 scope token 鉴权、复用 SocialService
> 的聊天/组队/公会/好友帧语义、用 Quest/Mail/Auction/Leaderboard 模块搭玩法、为每个系统选对存储档位，
> 并按 `packages/demo/bin/verify-economy.php` 的蓝本跑通端到端验收。
> 登录换 token 的协议链路详见 [docs/architecture.md](architecture.md) §5.1；帧格式权威文档见
> [docs/protocol.md](protocol.md)。

## 1. 总览与数据边界

Nythros 的玩法系统跑在两条线上：

- **控制线（Social 三角色）**：登录、聊天、组队、公会、好友、进图调度、排行榜查询。入口是
  `packages/demo/src/SocialServer.php`——继承 framework 的 `RealtimeServer` 骨架，按
  `--service=gateway|chat|team` 三角色部署（`packages/demo/config/deploy.yaml`：gateway 18285 /
  chat 18286 / team 18287），各角色进程连接表独立（对称直连）。帧路由集中在
  `SocialServer::handleAuthenticated()`（chat:send / team:* / map:enter / map:join / guild:* / friend:* /
  leaderboard:top|rank），业务全部落在 `packages/framework/src/Social/SocialService.php`。
- **实时线（Map）**：移动/战斗/AOI/掉落/背包/邮件领取/任务帧——`packages/demo/src/MapServer.php`
  承载，走二进制批量协议（帧类型/负载字段经词表压缩，见 `packages/demo/src/Protocol/FrameType.php`）。

**四类状态落点**（判据引自 docs/architecture.md §2.3 的口诀：「要保留」→ 永久进 DB/Redis、临时带 TTL
进 Redis；「要跨在线共享」→ 分组进 Social 连接表；「帧级高频」→ Map）：

| 状态层 | 本手册中的例子 | 落点 |
|---|---|---|
| 持久状态 | 好友关系、帮派成员、任务进度、邮件、账本余额、挂单、榜单 | Redis（无 TTL 键族） |
| 临时共享状态 | 组队（TTL 600s）、位置快照/掉线标记（TTL 300s）、token（TTL 30s） | Redis（SETEX） |
| 分组状态 | 频道组/队伍组/帮派组的「谁在哪个分组」 | `InMemoryConnectionHub` 进程内索引 |
| 战斗状态 | 位置、血量、背包内容、装备栏 | Map 进程内（背包见 §7） |

**store 的 TTL 语义速查**（键前缀均为构造参数，可注入隔离）：

| store | 文件 | 键 | TTL |
|---|---|---|---|
| `LocationStore` | framework/src/Social/LocationStore.php | `nythros:gw:location:{uid}` / `nythros:gw:offline:{uid}` | 300s（SETEX） |
| `RedisTeamStore` | framework/src/Social/RedisTeamStore.php | `nythros:gw:team:{teamId}`（hash）、`nythros:gw:uid-team:{uid}`、`nythros:gw:team:seq`（无 TTL） | 队伍 TTL 每次写操作同步续期 |
| `GuildStore` | framework/src/Social/GuildStore.php | `nythros:gw:guild:{guildId}` / `nythros:gw:uid-guild:{uid}` | 无 TTL（持久） |
| `RedisFriendStore` | framework/src/Social/RedisFriendStore.php | `nythros:gw:friend:{uid}` / `nythros:gw:friend-req:{uid}` | 无 TTL（持久） |
| `RedisQuestStore` | framework/src/Quest/RedisQuestStore.php | `nythros:gw:quest:{uid}` | 无 TTL（持久） |
| `RedisMailStore` | framework/src/Mail/RedisMailStore.php | `nythros:ml:mailbox:{uid}` / `nythros:ml:claimed:{uid}` | 无 TTL（持久，易失风险见其类注释） |
| `AuctionStore` / `CurrencyLedger` | framework/src/Auction/ | `nythros:ec:auction:{id}` / `nythros:ec:balance:{uid}` | 无 TTL（持久，易失风险同上） |
| `RedisLeaderboardStore` | framework/src/Leaderboard/RedisLeaderboardStore.php | `nythros:lb:board:{boardId}`（zset） | 无 TTL（持久） |

TTL 的含义不是「存活期」而是「无操作即过期」：组队 TTL（`SocialService::TEAM_TTL = 600` 秒）在每次
队伍写操作时由 RedisTeamStore 的 Lua 同步续期到全体成员的 uid-team 键；位置快照/掉线标记 300s 内
没有新的 auth/map:join 覆盖写即失效——掉线恢复判定（§5）依赖这个窗口。

## 2. 鉴权与连接

**登录链路**（细节见 docs/architecture.md §5.1，此处只列与社交开发相关的步骤）。完整握手发生在
gateway 角色，实现在 `SocialService::handleAuth()`（framework/src/Social/SocialService.php）：

1. `AuthenticatorInterface::authenticate()` 校验凭证（demo 用
   `packages/demo/src/StaticAuthenticator.php`：内存账号表 `password_verify`，账号来自
   `NYTHROS_ACCOUNTS` 环境变量 `uid=password` 对，缺省 `1001=secret,1002=secret,1003=secret`，见
   `packages/demo/bin/run-worker.php`）。
2. `mapId` 白名单校验（`SocialService` 构造注入的 `$mapIds`）。
3. 单点登录：`ConnectionHubInterface::getClientIdByUid()` 踢旧连新。
4. 恢复判定：`LocationStoreInterface::isOffline()` 命中掉线标记 → 读位置快照优先回原频道。
5. `TokenManagerInterface::issue($uid, $mapId, ['map', 'chat', 'team'], 30)` 签发多 scope token。
6. `bindUid` + `setSession`（uid 与 loc）+ 写位置快照 + 恢复分组（team:*/guild:*/map:* 频道组）+
   `clearOffline`。
7. 下发 `auth_ok`：`{uid, token, map: {wsAddress, mapId, channelId}, team, guild, endpoints}`；
   `endpoints.chat` / `endpoints.team` 由 `SocialService` 构造参数 `$endpointAddresses` 注入
   （run-worker.php 读 `NYTHROS_CHAT_ADDRESS` / `NYTHROS_TEAM_ADDRESS`），未注入时 auth_ok 不含
   endpoints 字段。

**多 scope token**（engine/src/Security/TokenManager.php、RedisTokenStore.php）：

- gateway 签发时一次写入 `['map','chat','team']` 三 scope；每个 scope 独立消费（per-scope 墓碑，
  Redis Lua 原子），互不影响——`consume($token, $scope)` 五态判定：`TokenStatus::Valid / Expired /
  Replayed / Unauthorized / Invalid`。
- chat/team 角色不重复握手：客户端连 chat/team 地址后发 `auth{token}`，`SocialServer::handleAuthMessage()`
  检测到本角色声明了 `$tokenAuthScope`（chat 角色 'chat'、team 角色 'team'，gateway 传 null）即走
  `SocialService::handleTokenAuth()`：peek 预检 → `consume($token, $scope)` → `bindUid` +
  `setSession{uid}` → `auth_ok{uid}`（不签发新 token）。失败一律 `auth_failed` + 断开（reason 五态映射
  expired/replayed/unauthorized/invalid）。
- `map:enter` 续签走 `SocialService::handleMapEnter()`：只签 `['map']` scope 的一次性凭证，
  `map:entered` 下发，Map 侧消费 map scope 进图。

**bindUid/joinGroup 的分组约定**：组键是社交业务约定（engine 出局）——
`map:{mapId}:{channelId}` / `team:{teamId}` / `guild:{guildId}`（`ConnectionHubInterface` 类注释）。
连接生命周期由 `InMemoryConnectionHub::attachConnection()` / `detachConnection()` 维护：
`SocialServer` 构造时挂 `onConnect`/`onClose` 回调，断开一次性摘除 uid 绑定、全部分组与会话
（对齐 gateway-worker 自动解绑承诺）。`ConnectionHubInterface` 全能力面见
framework/src/Social/ConnectionHubInterface.php（bindUid / getClientIdByUid / closeClient /
sendToAll / sendToGroup / sendToUid / sendToClient / isUidOnline / getSession / setSession /
updateSession / joinGroup / leaveGroup）。

## 3. 聊天系统

聊天实现集中在 `SocialService::handleChat()`，入口帧 **chat:send**，payload 必带
`{scope, content}`（缺一 `chat:error 400`）。五种语义、五种投递通道（ADR-015 §1.5）：

| scope | 额外 payload | 投递 | 失败帧 |
|---|---|---|---|
| `world` | — | `sendToAll`（排除发送者） | — |
| `channel` | 可选 mapId/channelId | `sendToGroup('map:{mapId}:{channelId}')`，仅本频道（与 session loc 不符 → 404） | `chat:error 404 channel unknown` |
| `team` | — | `sendToGroup('team:{teamId}')`；session teamId 缺失时回退 `TeamStore::findByUid` | 无队 `chat:error 404 not in team` |
| `guild` | — | `sendToGroup('guild:{guildId}')`；session guildId 缺失时回退 `GuildStore::findByUid` | 无帮 `chat:error 404 not in guild` |
| `private` | `targetUid` | `sendToUid` 定向 | targetUid 缺失 400；目标离线 `chat:error 404 target offline` |

统一下行帧：**chat:message** `{scope, content, fromUid}`（world/channel/team/guild 均排除发送者，
private 只投目标）。所有失败帧 `chat:error {code, message}` 带原 requestId。

**如何加私聊屏蔽/系统广播**：

- 系统广播 = 服务端代码直接调 `$hub->sendToAll($this->serializer->encode(Message::create('chat:message',
  ['scope' => 'world', ...]))->bytes())` 的等价物；定向系统消息用 `sendToUid`（离线自动丢弃 =
  静默，这是 hub 的既定语义）。
- 私聊屏蔽没有内建开关——`chatPrivate` 只校验目标在线。要加屏蔽/敏感词，在 `handleChat` 入口
  （或 `chatPrivate`）前插过滤层即可：它是纯业务方法，`ConnectionHubInterface` 与
  `TeamStoreInterface` 都可注入替身单测。
- 注意：`sendToAll` 的全集 = 本进程存活连接表（`InMemoryConnectionHub::attachConnection` 登记）。
  三角色连接表进程内独立，world 广播只覆盖连在 chat 角色上的那批连接；跨进程广播需要
  `HubTransportInterface` 之外另行扩展（**待验证**：现有源码中未见跨进程聊天投递实现）。

## 4. 组队与公会

**组队状态机**（ADR-015 §1.6）：入口帧与语义（`SocialService::handleTeam`）：

- `team:invite {targetUid}`：目标离线 pre-check（`isUidOnline`）→ `TeamStore::invite`——无队 sender
  在 Lua 内自动建队（`team-{seq}`，INCR 序列），自邀 9 / 目标在队 2 / 非队长 1 / 满员 3；邀请条目
  30s 有效性、幂等刷新。成功后发起人 joinGroup + updateSession，被邀请人收
  `team:notify {type: 'invited', teamId, uid, fromUid}`，发起人收 `team:ok {teamId, action}`。
- `team:accept {teamId}` / `team:reject {teamId}`：入队拦截（已在队 6 先于队伍不存在 7）、满员 3；
  accept 成功全队收 `team:notify {type: 'joined'}`，reject 通知队长 `{type: 'rejected'}`。
- `team:leave {teamId}`：队长离开 = 解散（全队 `team:notify {type: 'disbanded'}` + 全员清分组）；
  成员离开 `{type: 'left'}`。
- `team:disband {teamId}`：仅队长（403 not_leader）。

所有失败映射 `team:error {code, message}`（返回码 0~9 → HTTP 码 + 语义串，映射表在
`SocialService::teamErrorHttpCode/Message`）。硬编码参数：`TEAM_TTL = 600`、`MAX_TEAM_SIZE = 5`
（SocialService 常量）；邀请条目 30s（RedisTeamStore Lua 内 `expiresAt = now + 30`）。

**掉线超时语义**：掉线不清队伍、不写离队——队伍随 TTL（600s 无写操作）自然蒸发，成员
uid-team 键同步过期。重连时 `handleAuth` ⑨ 恢复分组：`TeamStore::findByUid` 命中即重新
joinGroup `team:{teamId}` 并在 auth_ok.team 下发 `{teamId, leaderUid, members}`。

**公会**（`GuildStoreInterface`，最小面 + 正式化面）：`guild:join {guildId}` /
`guild:leave {guildId}` 沿用最小面（换帮拦截 403 already_in_guild）；正式化面
`guild:create {guildId, name?, maxMembers?}`（缺省上限 `DEFAULT_MAX_GUILD_SIZE = 100`）、
`guild:disband`（仅会长）、`guild:kick`（会长/官员，只能踢低于自己阶位）、
`guild:promote {role: officer|member}`（仅会长）、`guild:notice {notice}`（会长/官员，向
`guild:{guildId}` 组广播）、`guild:apply` / `guild:approve {targetUid, accept: bool}`（审批制入会，
无需列表帧）。权限矩阵表驱动（`GuildStore::PERMISSION_MATRIX` 为唯一事实源），返回码 0~9 映射
`guild:error`；通知帧 `guild:notify`（disbanded / kicked / promoted / notice / approved / rejected）；
解散/踢人/审批成功会同步被操作者的在线连接分组与 session（`leaveGroupAll`）。

**如何扩展（如申请审批流）**：帮派的审批流已经内建（apply/approve）；若要给好友或组队加同类流程，
仿照 `FriendStoreInterface` / `TeamStoreInterface` 的「整型返回码 + SocialService 侧 match 映射」先例
加一对帧语义与 store 方法即可；需跨进程不变量（如「一 uid 一队」）时把判定写进 Redis Lua
（照 `RedisTeamStore` 的三脚本先例），demo 规模下单机 Redis 非原子读改写即可接受（见
RedisFriendStore 类注释的口径声明）。

## 5. 好友与位置

**好友**（`FriendStoreInterface` + `RedisFriendStore`，无 TTL 持久，双向一致）——五语义
（`SocialService::handleFriend`，未装配 FriendStore 时一律 `friend:error 500`）：

- `friend:apply {targetUid}`（自邀 400 self_not_allowed / 已是好友 409 / 重复申请 409）
- `friend:accept {targetUid}`（对端视角：acceptor 的 targetUid 指向申请人；双向写 set + 清申请，
  互加场景反向残留申请一并清）
- `friend:reject {targetUid}` / `friend:remove {targetUid}`（双向一致移除）
- `friend:list` → `friend:ok {action: 'list', uids}`（uid 排序）

操作成功后向目标发 `friend:notify {type: applied|accepted|rejected|removed, fromUid}`——走
`sendToUid`，离线静默。返回码 `CODE_OK/CODE_SELF/CODE_ALREADY_FRIENDS/CODE_REQUEST_EXISTS/
CODE_REQUEST_NOT_FOUND/CODE_NOT_FRIENDS`。

**位置**（`LocationStoreInterface` + `LocationStore`，Redis 跨进程）：

- `saveLocation($uid, $mapId, $channelId, ?x, ?y)`：SETEX 300s JSON 快照（map:join 上报、auth 成功时写）。
- `getLocation`：读快照（恢复判定与 map:enter 的同图重入优先频道用）。
- `markOffline` / `isOffline` / `clearOffline`：掉线标记三件套——`SocialServer::onEntityCleanedUp`
  调 `SocialService::handleClose($uid)` 写标记（ADR-015 §1.8），下次 auth 时命中即走恢复分支，
  成功登录后 `clearOffline`。
- uid 格式白名单 `/^[A-Za-z0-9_-]{1,64}$/`（进入键构造的字段收敛注入面）；写入失败抛
  `InvalidArgumentException` / `RuntimeException`。

## 6. 邮件与任务

**邮件**（framework/src/Mail/）：

- `MailService`：`send($toUid, $fromUid, $title, $body, $attachments)`（返回 mailId，`mail-` 前缀；
  附件结构 `{itemId: string, count: int>0}`）、`list($uid)`、`claimAttachments($uid, $mailId)`、
  `delete($uid, $mailId)`。
- 附件领取幂等：`RedisMailStore::claimGate` 的 Lua 原子 SISMEMBER+SADD——并发双领只有一个
  `claimed`，另一个 `already_claimed`；抢到闸门后邮件被并发删除则回滚闸门返回 `not_found`。
- 在线通知走独立端口 `MailNotifierInterface`（framework/src/Mail/MailNotifierInterface.php）——
  **不直接依赖 ConnectionHubInterface**：社交 hub 下行是 JSON 字符串，Map 频道走二进制批量协议，
  原始字符串无法表达。通知失败不回滚（邮件已持久化，登录后可拉取）；demo 侧
  `MapServer` 按 uid 解析在线 PlayerActor 后定向入队 **mail:new** 帧（MapServer.php 通知实现）。
- demo 路由（MapServer，需 `NYTHROS_ECONOMY=1` 装配）：`mail:list` → **mail:list**
  并行标量列表帧（mailIds/titles/bodies/hasAttachments）；`mail:claim {mailId}` → **mail:claimed**
  （attachments 为 msgpack 字节串，V7 嵌套路径）或 `economy:result` already_claimed/not_found；
  `mail:delete {mailId}` → `economy:result`。

**任务**（framework/src/Quest/）：

- 定义：`QuestDefinition {id, name, source, targetId, requiredCount, rewards}`，进度源三型
  `SOURCE_KILL`（targetId=怪物类型 id）/ `SOURCE_COLLECT`（物品 id，按入包数量累计）/
  `SOURCE_TALK`（NPC id）。注册进 `QuestRepository`（`register`，demo 定义在
  packages/demo/src/MapChannelFactory.php）。
- 链：`QuestChain {id, questIds}`（有序、至少一个任务，构造期 fail-fast）；链式解锁由
  `QuestChainRules::chainOf / isUnlocked / nextQuestId / isChainComplete` 纯函数判定——前序未完成
  时进度上报被忽略，解锁是完成集的派生状态。`QuestService` 构造注入 chains，缺省 `[]` = 全任务恒解锁。
- 进度源接线：`QuestService::attachDispatcher($dispatcher)` 监听 `combat.kill` / `combat.pickup`
  埋点驱动 kill/collect（CombatService 派发）；talk 无自然事件，由路由显式调
  `reportTalk($uid, $npcId)`。
- 领奖：`claimReward($uid, $questId, $inventory)` 幂等（未完成/已领奖 false），奖励逐项
  `Inventory::add` 并置 rewarded。
- 存储：`QuestStoreInterface`（save/get/all/delete，整体覆盖语义），实现 `InMemoryQuestStore`
  / `RedisQuestStore`。

**quest:list → quest:rows 的客户端接法**（MapServer 路由，需 `NYTHROS_GAMEPLAY=1`）：
`quest:list` 的回执 **quest:rows** 是并行标量列表帧（questIds / counts / required / completed /
rewarded 五列等长对齐），且**不带 requestId**——按
[packages/client-js/README.md](https://github.com/nythros/nythros/tree/master/packages/client-js/README.md) 的 request 双模式约定：

```js
await client.request('quest:list', {}, { replyType: 'quest:rows' }); // 只发定类型帧，必须按帧类型匹配
const { entityId } = /* ... */;
await client.request('quest:claim', { questId: 'kill_wolves' });      // quest:result 带 requestId，缺省匹配即可
client.on('item:added', (f) => /* 领奖后的多帧结果用 on 订阅 */);
```

`quest:claim {questId}` 成功路径 = 逐项 **item:added** + **quest:result {op:'claim', code:'ok'}**；
未完成/已领奖 → `quest:result {code:'not_ready'|'already_claimed'}`。`quest:talk {npcId}` →
`quest:result {op:'talk', code:'ok'}`。

## 7. 经济与背包

**背包与装备**（framework/src/Inventory.php + Inventory/Equipment/）：

- `Inventory`：进程内计数表（itemId => count），`add/remove/count/all`；remove 数量不足时整组移除
  不出负数。语义是「地面拾取的移动背包」，与跨进程钱包模型刻意分离（裁决见
  framework/src/Auction/CurrencyLedger.php 类注释四条）。demo 侧以 entityId（uid@conn）为键挂在
  MapServer，标脏落库（`markInventoryDirty`）。
- `Equipment`：按槽位管理已穿戴装备，双闸校验（type 必须 `ItemDefinition::TYPE_EQUIPMENT`、slot
  必须在 `EquipmentSlot` 枚举 weapon/armor/accessory 内）；同槽重复穿戴顶替旧装备（返回被顶替
  itemId 回包）。

**经济帧**（MapServer，`NYTHROS_ECONOMY=1` 装配）：`equip` / `unequip` / `auction:sell` /
`auction:buy` / `auction:cancel` / `mail:list` / `mail:claim` / `mail:delete` / `economy:deposit`
九路由统一回执 **economy:result** `{op, code, message, ...}`；业务异常（InvalidArgumentException）
统一转 `code: 'invalid_argument'` 回执、连接不断。战斗链路里 **drop:spawned**（掉落生成）、
**item:added**（定向入包通知，CombatService 拾取路径与任务领奖共用）、**player:stats**（属性聚合
同步）也是经济可见帧。

**拍卖行与货币账本**（framework/src/Auction/）：

- `AuctionService::sell($sellerUid, $inventory, $itemId, $count, $price)`：扣货托管 → 登记挂单；
  登记失败回滚背包（零残留）。返回 `auc-` 前缀 auctionId。
- `AuctionService::buy($buyerUid, $auctionId, $price)`：`AuctionStore::purchase` 的 Redis Lua 原子
  完成「校验 + 删单 + 买家扣款 + 卖家入账」（并发双买恰有一个成功）；失败码
  `no_listing / self_purchase / price_mismatch / insufficient_balance`。成功后发货邮件
  （from = `AuctionService::SYSTEM_SENDER = 'auction'`）；邮件失败补偿 = 反向转账退款 + 恢复挂单
  （restore 复用原 auctionId）。
- `AuctionService::cancel($sellerUid, $auctionId)`：Lua 原子归属校验 + 删单 → 退回邮件。
- `CurrencyLedger`：`balance / deposit / withdraw`（余额键 `nythros:ec:balance:{uid}`，与
  AuctionStore 的 Lua 共用同一键规则——结算才能单脚本原子完成）；demo 最小入账入口是
  `economy:deposit {count}` 路由（生产应由掉落/任务结算驱动入账）。

**排行榜**（framework/src/Leaderboard/）：

- `LeaderboardStoreInterface`：`report`（业务上报，单 uid 覆盖写）/ `aggregate`（批量 upsert，
  离线统计任务口径）/ `remove` / `top($board, $n, $offset)` / `rankOf` / `size`；Redis ZSet 承载
  （分数降序，同分按字典序确定排列）。
- 查询帧在 `SocialServer::handleLeaderboard` 就地应答：**leaderboard:top** {boardId, n, offset} →
  **leaderboard:rows** {boardId, ranks, uids, scores}（平行列表）；**leaderboard:rank** {boardId} →
  **leaderboard:ranked** {boardId, uid, rank, score}（未上榜 rank 为 null）。未装配存储 → `error 501`；
  payload 缺 boardId → `error 400`。写榜（report）在社交帧面之外，由业务代码直接调 store。

## 8. 存储选型

**InMemory vs Redis，何时选哪个**：

- **进程内（InMemory）**：数据生命周期 = 连接会话。`InMemoryConnectionHub`（uid↔连接、分组索引、
  session——onClose 自动清除）与 `InMemoryQuestStore`（单测/无外部存储部署）是仅有的两个进程内实现。
  把「分组/会话」之外的任何东西写进进程内 store 都会随进程消失（见 §10）。
- **Redis 带 TTL**：临时共享状态——位置快照/掉线标记（300s）、组队（600s 续期）、token（30s）。
- **Redis 无 TTL**：永久（demo 规模）状态——好友、帮派、任务进度、邮件、余额、挂单、榜单。
  注意这些实现类注释都带「易失风险声明」：Redis 未开持久化（RDB/AOF）时重启即失；生产部署
  必须开持久化或替换为持久存储实现。

**如何自定义 store**——各模块都定义了可实现的接口（Redis 实现均照 `\Redis|\Closure` 工厂构造 +
键前缀注入 + uid/SERVICE_ID 格式白名单的先例）：

| 模块 | 要实现的接口 | 文件 |
|---|---|---|
| 连接层 | `ConnectionHubInterface`（下行另需 `HubTransportInterface`） | framework/src/Social/ |
| 位置 | `LocationStoreInterface` | framework/src/Social/LocationStoreInterface.php |
| 组队 | `TeamStoreInterface`（必须保 Lua 级返回码语义 0~9） | framework/src/Social/TeamStoreInterface.php |
| 公会 | `GuildStoreInterface` | framework/src/Social/GuildStoreInterface.php |
| 好友 | `FriendStoreInterface` | framework/src/Social/FriendStoreInterface.php |
| 邮件 | `MailStoreInterface`（+ 可选 `MailNotifierInterface`） | framework/src/Mail/ |
| 任务 | `QuestStoreInterface` | framework/src/Quest/QuestStoreInterface.php |
| 排行榜 | `LeaderboardStoreInterface` | framework/src/Leaderboard/LeaderboardStoreInterface.php |

例外：`AuctionStore` 与 `CurrencyLedger` 是 final 类、无接口（购买结算要求余额扣减与挂单删除
同存储同脚本，抽出接口会破坏该契约）；token 侧替换 `TokenStoreInterface`（engine/src/Security/）。

## 9. 最小可运行示例（以 verify-economy.php 为蓝本）

`packages/demo/bin/verify-economy.php` 是经济链路的端到端验收脚本，其步骤可作开发模板：

1. **启动**：`Redis(127.0.0.1:6379)` → `php bin/server start`（仓库根 bin/server；deploy.yaml：
   gateway 18285 / chat 18286 / team 18287 + map-1#ch-1 18081），经济路由需环境变量
   `NYTHROS_ECONOMY=1`；账号 `NYTHROS_ACCOUNTS=1001=secret,1002=secret,1003=secret`。
2. **gateway 登录**（脚本 `openSocialOnce`）：WebSocket 文本帧 `auth{username, password, mapId}` →
   校验回执 type 为 auth_ok / auth_failed / error；从 `auth_ok.payload` 取 `token` 与
   `map.wsAddress`（还有 `endpoints.chat/team` 供 chat/team 直连）。
3. **Map 直连**（脚本 `openMap`）：连 map.wsAddress，出站用二进制 WebSocket 帧
   （`websocketType = BINARY_TYPE_ARRAYBUFFER`），首帧 `auth{token}`（token 消费 map scope）；
   `auth_ok.payload.id` 为 `uid@` 前缀 entityId。
4. **验收五步**（脚本 step1~step5，每步一帧一断言的 `waitFrame` 轮询模式可复用）：
   掉落与归属（`drop:spawned` → 他人拾取得 `combat:error not_owner` → 击杀者拾取得 `item:added`）→
   穿戴（`equip` → `player:stats maxHp 130` + `economy:result ok`）→ 挂单（`auction:sell` →
   economy:result 附 `auc-` auctionId）→ 入账与购买（`economy:deposit {count:500}` →
   `auction:buy {auctionId, price:300}` → ok；重复购买得 `no_listing` 验证 Lua 互斥）→ 邮件全链
   （`mail:new` 在线通知 → `mail:list` → `mail:claim`（msgpack attachments 还原
   `[[itemId, count]]`）→ 重复领取 `already_claimed` → `mail:delete`）。
5. **收尾**：脚本 `finishAll` 打印 `RESULT: PASSED/FAILED` 并清理 Redis 残留键
   （`nythros:ml:*` / `nythros:ec:*`）。

社交语义（聊天/组队/公会/好友/排行榜）的对应验收在 `packages/demo/bin/verify-phase5.php`
（含 `expectSocialReply` / `expectNotify` 断言工具与 step11Leaderboard），玩法/任务链验收在
`packages/demo/bin/verify-mmorpg.php`（`QuestChain` 配置注入示例）。

## 10. 反模式清单

- **在 chat/team 进程做帧级玩法逻辑**：chat/team 角色只消费 token scope 做连接承载；帧级逻辑
  （战斗、AOI、背包）属 Map 实时线。社交帧面以 `SocialServer::handleAuthenticated` 的 switch 为准，
  新帧语义一律进 `SocialService`（纯业务、依赖注入、可单测）。
- **把永久数据写进程内 store**：好友/帮派/任务进度/余额只有 Redis（无 TTL）档位；
  `InMemoryQuestStore` 只用于单测/无外部存储部署。进程内背包（`Inventory`）是刻意设计的
  「移动背包」语义，别把它当钱包——跨进程一致余额走 `CurrencyLedger`。
- **忽视 scope 的 token 混用**：token 是 per-scope 一次性消费。gateway 签发的 `['map','chat','team']`
  token，chat 角色消费 chat scope、team 角色消费 team scope——跨 scope 重复消费同一 token 得
  `Replayed`/`Unauthorized`（403）。`map:enter` 续签只签 `['map']`，别拿它去登 chat/team。
  TokenManager 只接受 `'map'/'chat'/'team'` 子集 scope（engine/src/Security/TokenManager.php 白名单过滤）。
- **绕过 store 直接操作社交 Redis 键**：键构造带 uid/guildId 格式白名单与错误处理
  （InvalidArgumentException/RuntimeException 归因），业务代码应只调 store 接口；拼键直写会绕过
  白名单注入面收敛。
- **把离线当异常**：hub 的 `sendToUid` 对离线 uid 静默丢弃是既定语义（好友/公会通知、邮件通知
  都依赖它）——通知类帧无需也无法先确认在线；确需在线判断用 `isUidOnline`（team:invite 的
  pre-check 就是这么做的，竞态窗口由静默丢弃兜底）。
- **邮件通知失败回滚业务**：`MailService::send` 对 notifier 异常内部消化（通知失败不回滚——
  上抛会让调用方把「已交付」误判为「未交付」，AuctionService::buy 的退款补偿会构成双花）。
  自定义通知实现必须遵守「尽力而为」契约。
- **在广播帧里塞嵌套结构**：Map 二进制批量协议（V7）的批量帧是并行等长标量列表
  （quest:rows / mail:list 即此形态）；嵌套负载（如邮件附件）必须走 MsgpackSerializer 字节串路径。
  新帧命名/字段先查 `FrameType.php` / `PayloadKey.php` 码表。
- **把 TTL 当存活期**：组队/快照的 TTL 是「无写即过期」，不是固定存活期——每次队伍操作都续期。
  依赖「队伍必然存活 N 秒」的逻辑是错的；同样，`nythros:gw:offline:{uid}` 300s 过期后掉线恢复
  判定退化为新登录，这是设计而非缺陷。
