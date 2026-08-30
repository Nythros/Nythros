# ADR-021：移除 gateway-worker，统一自研网关栈

> 状态：已接受（2026-08-24，用户批准）。
> 取代：ADR-014（全文）；ADR-015 中依赖 gateway-worker 运行时的实施细化（Events.php 壳、Gateway 静态 API、启动铁序中的 Register/Gateway/BusinessWorker）。
> 关联：ADR-013（自研三层拓扑与对称直连，多数裁决回归生效）、ADR-020（结构重划）。

## 1. 背景

ADR-014 引入 gateway-worker 做「接入 + 社交层」，价值主张为多 Gateway 分散连接与 Business 热更新不断连。实践检验下两项价值在本项目均不成立，且双栈成本持续放大。本 ADR 回摆到 ADR-013 的自研单栈形态（社交连接层改接自研 GatewayServer 栈）。

## 2. 理由

1. **双栈成本**：两套连接模型（自研 Server 自管连接 vs bindUid/joinGroup 静态 API）、两套部署编排（launch.php vs start_register/gateway/businessworker 三脚本）、两套测试与文档心智。
2. **社交低频**：社交消息频率远低于地图 tick；5000 人频道基准下瓶颈在地图层不在社交层，自研连接表广播完全够用。
3. **分布式收益落空**：多 Gateway 分散连接的价值在跨机部署，而部署标准已是 Docker Compose 单机一等公民；Business reload 不断连与「热更新只做配置表热载」的关闭裁决直接冲突——业务热更新已不做。
4. **兼容性风险**：gateway-worker 4.x 与 workerman ^5.2.2 兼容性自 ADR-015 起即为待验证项；消除依赖即消除风险。

## 3. 影响面

### 3.1 删除清单

| 目标 | 说明 |
|---|---|
| `packages/demo/gateway-worker/` 全目录 | start_register / start_gateway / start_businessworker / Events.php |
| `packages/demo/composer.json` | `workerman/gateway-worker` 依赖 + `Nythros\Demo\GatewayWorker\` autoload 段 |
| 根 `bin/server` 编排壳中 Register/Gateway/Business spawn 步骤 | 收敛为 social 单元 + map 单元两级 |

### 3.2 SocialService 连接层改造方向

- Gateway 静态 API 调用点全部替换为自研连接注册表模型：`bindUid` → uid 连接登记；`sendToAll`/`sendToGroup`/`sendToUid` → 连接表遍历广播 / 分组索引组播 / 定向发送；`isUidOnline` → 连接表命中判定；`getSession`/`updateSession` → 连接附属会话对象。
- 分组语义保留：group→conns 索引（`map:{mapId}:{channelId}` / `team:{teamId}` / `guild:{guildId}`），onClose 自动清除全部索引（对齐 gateway-worker 内置自动解绑的行为承诺）。
- TeamStore / GuildStore / LocationStore 及 `nythros:gw:*` Redis 键结构不变（纯 Redis 存储，与连接运行时解耦，零迁移）。
- 登录链路回归 ADR-013 形态：authenticate → discover('map') 过滤 → 最少在线选频道 → issue token → `login_ok{token, map/chat/team 地址}`。token 恢复多 scope（map/chat/team），各服务 consume 自己的 scope（撤销 ADR-015「只签 ['map']」收窄）。

### 3.3 deploy.yaml 变更

- 恢复 `social:` 部署单元声明（gateway/chat/team 三 Worker，沿用 ADR-013 决策 C：默认合并、可配置拆开、代码不动）；DeployConfig SERVICE_TYPES 白名单由 `['map']` 恢复为 `['gateway','chat','team','map']`。
- 启动铁序简化：Redis(外部) → social 单元 → map 单元（Register 进程消失）。

### 3.4 不复活的能力

- RPC 链与 ActorRef 族维持删除（ADR-015 裁决）：社交五语义 world/channel/team/guild/private 均可由连接表 + Redis 存储支撑，无需服务间 RPC。
- 区域聊天（chat:region_players 类查询）暂缓：待引擎分区 layering 落地后随 AOI 能力再议（回应 ADR-015 待确认点 4）。
- `map-rolling.php` 保留：操作 ServiceRegistry heartbeat merge，与 gateway-worker 无关。

## 4. 回滚策略

- 与 R1 结构重划同批实施但**独立成提交**（上移提交与去 gateway-worker 提交分开），便于单独 revert。
- Redis 数据面键结构不变，无数据迁移；回滚 = revert 对应提交恢复 gateway-worker 形态（ADR-014/015 文档保留可依）。
- 回滚判据：R1 验收（社交五语义 + 组队并发 + 断线恢复）不达标且短期不可修复。

## 5. 状态

已接受。执行载体：R1 结构重划。
