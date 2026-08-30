# 安全指南（Security）

> 面向读者：部署 Nythros 到公网、或为其开发需要鉴权/权限控制的功能的程序。读完你能：
> 理解多 scope token 的信任链与边界、配置限流与 GM 权限、按上线 checklist 自查。
> 机制实现在 engine Security 模块与 framework Gm/Auth 模块；本文讲「怎么用对、边界在哪」。

## 1. 信任链总览

```text
明文凭据(仅 gateway) ──> 多 scope token ──> 各服务按 scope 消费（一次性）
     Social gateway          Redis(TTL)         Map/chat/team
```

- **明文凭据只在 gateway 终结**：`auth{username,password,mapId}` 只发给 Social gateway（18285），
  校验通过后签发 token；Map/chat/team **永远不接收密码**，只消费 token。
- **token 是多 scope 的**：`TokenManager::issue(uid, mapId, scopes: ['map','chat','team'])` 签发，
  scope 白名单过滤（仅 map/chat/team 子集，保序去重）；每个服务只消费自己的 scope
  （`TokenManager::consume(token, scope)`，一次性、防重放）。
- **传输安全**：线协议本身不含加密层，公网部署必须前置 TLS 终结（反向代理/负载均衡），
  见 [deployment.md](deployment.md) 生产清单。

## 2. Token 使用纪律

| 规则 | 依据 |
|---|---|
| token 只发给通过鉴权的连接，**永不转发给第三方连接**（如广播、聊天列表） | 一次泄漏即身份冒用 |
| scope 最小签发：只用 Map 的连接别签 chat/team scope | `issue()` 缺省 `['map']`，按需追加 |
| 消费即毁：`consume()` 是一次性的，重放必失败；重连走 SDK 重连链路重新换票 | 与转移票据同语义 |
| TTL 保持短时（缺省 30s 级）——token 是握手凭证不是会话令牌，长会话由连接与位置快照承载 | `issue()` 第 4 参 |
| token 存储实现带 TTL 与原子消费；换 Redis store 时不要绕过 `TokenStoreInterface` 自行读写 | `InMemoryTokenStore` / `RedisTokenStore` |

实现位置：`packages/engine/src/Security/`（`TokenManager`/`TokenRecord`/`TokenStatus` 五态/
两 store）、demo 侧装配 `StaticAuthenticator`（内存哈希账号表）+ `ThrottledAuthenticator`
防爆破包装（framework Auth 模块）。

生产账号体系两种装配形态（run-worker 装配）：

- **生产**：`NYTHROS_ACCOUNTS_FILE` 指向 PHP 文件，返回 `[uid => password_hash(...)]`——
  明文密码不进 env/进程列表；哈希用 `password_hash`/`password_verify`（bcrypt）；
- **开发**：`NYTHROS_ACCOUNTS` 环境变量 `uid=password` 明文对，装载即哈希（缺省 `1001=secret,...`）；
- **防爆破**：连续失败 `NYTHROS_AUTH_MAX_ATTEMPTS` 次（缺省 5）锁定该 username
  `NYTHROS_AUTH_LOCKOUT_SECONDS` 秒（缺省 60）——锁定拒绝在认证器之前短路；多网关实例时
  全局上限按实例数放大，生产可调低阈值。

生产替换点：账号来源接自有账号库/服务（实现 `AuthenticatorInterface`），userId 与 username
解耦（demo 复用 username）；`StaticGmAuthorizer` 替换为自有权限体系同理。

## 3. 输入校验与限流

- **认证入口限流**：gateway 用 `SimpleTokenBucket`（engine Network 模块，
  `RateLimiterInterface` 实现）按连接限速（缺省 refill 10/s、capacity 20）。批量开服场景调大前
  先读 performance.md §6.3 的实测说明；账号级防爆破由 `ThrottledAuthenticator` 承接（§2）。
- **协议版本守卫**（ADR-027）：`NYTHROS_MIN_CLIENT_VERSION` 设置后，握手 version 缺失/过低
  在认证之前被拒（token 不消费）——过期客户端在协议破坏性演进时被显式隔离而不是行为未定义。
- **游戏路由前置校验**：新路由照抄 `MapServer::attack` 的形状——目标有效/存活/非自身/距离/冷却
  全部服务器判定（best-practices §3）；校验失败回错误帧（400/422 语义，protocol.md §5）。
- **帧大小与解析容错**：二进制解码对未知 keyCode/值类型/魔数不匹配/截断一律快速失败
  （`DecodeException`），不吞异常不降级解析。
- **scope 字符串白名单**：`TokenManager` 对 scope 做正则白名单（小写字母开头，长度 1~32），
  收敛 Redis 键构造注入面——新增 scope 类型必须先扩白名单。

## 4. GM 权限模型

GM 能力走 `Nythros\Framework\Gm` 命令总线，**不是直连 Redis 的裸脚本**：

```text
调用方 → GmCommandBus::dispatch(uid, commandName, payload) → GmPermissionInterface 校验 → 命令执行 → GmResult
```

- 内置命令：`BroadcastCommand` / `KickCommand` / `DrainCommand` / `StatusCommand`
  （`packages/framework/src/Gm/Command/`）；扩展点：`GmBroadcasterInterface`、`GmKickerInterface`、
  `GmDrainHandlerInterface`、`GmStatusProviderInterface`。
- **权限判定强制在总线**：`GmCommandBus` 构造即注入 `GmPermissionInterface`
  （demo 装配 `StaticGmAuthorizer`，生产替换为自有权限体系）；自定义命令必须注册进总线，
  严禁在业务代码里复制 GM 逻辑。
- GM 身份与业务账号隔离：不要用游戏内成就/客服工单等旁路决定 GM 权限。

## 5. 数据与密钥

- 演示账号表（`1001/1002/1003`，密码 `secret`）**仅限本地验收**；公网部署改用
  `NYTHROS_ACCOUNTS_FILE` 哈希账号文件（§2），并接自有账号体系。
- Redis 无密码直连只适合开发（compose 栈即如此）；生产 Redis 必须开认证 + 网络隔离，
  因为 token/位置快照/转移票据都在里面。
- MySQL 账号遵循最小权限：Nythros 运行时只需要单库读写（`MySqlStorage` 单表 upsert +
  `createSchema()`），不需要 DDL 常驻权限。

## 6. 上线 checklist

- [ ] 明文凭据只在 gateway 出现，Map/chat/team 全走 token
- [ ] scope 最小签发、消费一次性、TTL 短时（§2 逐条）
- [ ] TLS 前置终结；Redis 开认证、MySQL 最小权限（§5）
- [ ] 认证入口限流已配置并压测过（§3）；账号防爆破阈值已按账号规模调校（§2）
- [ ] 协议版本守卫已设置 `NYTHROS_MIN_CLIENT_VERSION`（§3，ADR-027）
- [ ] 备份/恢复演练过一次（deployment.md §7）
- [ ] 所有游戏路由有服务器权威校验（best-practices §3）
- [ ] GM 命令全部过 `GmPermissionInterface`，权限体系已替换 `StaticGmAuthorizer`（§4）
- [ ] 演示账号已下线（§5）
- [ ] 错误回执不泄漏内部细节（堆栈/SQL/文件路径不出现在客户端帧里）
