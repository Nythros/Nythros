# Redis 哨兵 HA 开发栈（deploy/redis-ha）

开发机上练「主库挂了自动切换」的最小环境：**1 主 + 1 从 + 3 哨兵**，全部是 Redis 自带能力
（同一个 `redis-server` 换 `--sentinel` 启动方式），**不需要安装任何额外软件**。

Parameters are production-shaped (3 sentinels / quorum 2 / 5s down-after), so a drill here exercises the
same topology production runs. Ports deliberately avoid the dev single instance (6379) — both can coexist.

```text
                      ┌──────────────┐
       客户端 ───────► │  哨兵 ×3      │  只回答「现在谁是主」，不碰业务数据
   (应用按需询问)      │ 26379/80/81  │  quorum 2，down-after 5s
                      └──────┬───────┘
                             │ 监控 / 选举
              ┌──────────────┴──────────────┐
              ▼                             ▼
        ┌───────────┐   异步复制    ┌───────────┐
        │  主 16379 │ ───────────► │  从 16380 │
        └───────────┘              └───────────┘
```

## 快速开始

```bash
bash deploy/redis-ha/start.sh            # 启动（就绪校验：主 PONG → 从 link up → 哨兵认主）
bash deploy/redis-ha/status.sh           # 各节点角色 + 哨兵认定主库
bash deploy/redis-ha/failover-drill.sh   # 演练：手动切换 → 客户端自愈断言（PASS/FAIL）
bash deploy/redis-ha/stop.sh             # 停止（日志保留；NYTHROS_HA_CLEAN=1 连目录清理）
```

演练脚本自带拓扑自愈（上次演练残留的半状态会先重建栈）与**自动复位**（演练结束回到 16379 为主）；
`--keep` 保留反转拓扑用于观察。`NYTHROS_HA_DRILL_DEBUG=1` 打开逐轮耗时/解析地址日志。

应用侧（可选，仅 HA 部署/演练时设置；日常开发不设 = 直连 6379，行为与接入前一致）：

```bash
export NYTHROS_REDIS_SENTINELS=127.0.0.1:26379,127.0.0.1:26380,127.0.0.1:26381
export NYTHROS_REDIS_MASTER=nythros
```

| 环境变量 | 作用 | 缺省 |
|---|---|---|
| `NYTHROS_HA_PASSWORD` | 主/从/哨兵统一认证密码（演练认证路径） | 空（无认证） |
| `NYTHROS_HA_DIR` | 运行目录（配置/日志/数据/pid） | `${TMPDIR}/nythros-redis-ha` |
| `NYTHROS_HA_MASTER_NAME` | 哨兵监控组名 | `nythros` |
| `NYTHROS_HA_MIN_REPLICAS` | `min-replicas-to-write`（脑裂写保护；设 0 关闭） | `1` |
| `NYTHROS_HA_CLEAN` | `stop.sh` 连运行目录一起清理 | 不清理（保留日志） |

## 关键参数为什么这么定

- **3 哨兵 / quorum 2**：奇数才能防「两个哨兵互相以为对方挂了」的脑裂投票；quorum 2 表示
  3 个里 2 个同意才切。
- **`min-replicas-to-write 1`**：没有健康从库时主库**拒写**（`NOREPLICAS`）——这是防止
  「切换窗口里旧主仍接受写入、数据写进一台即将被清空重同步的节点」的关键护栏（实测该窗口约 10s）。
- **从库 `masterauth`**：升主后新主需要连旧主（此时已降级为从）做重同步；漏配会在切换后卡死。
- **`down-after-milliseconds 5000`**：主库失联 5s 后判定客观下线并开始选举。

## 常见坑

1. **哨兵配置文件会被运行时改写**（写入 myid / 已知从库 / 已知哨兵）——必须放可写目录，
   Docker 里不能只读挂载。
2. **防火墙只放哨兵端口是不够的**：哨兵只告诉应用「主库地址」，数据是应用**直连主库/从库**的，
   6379/16379 这类数据端口必须可达。
3. **容器/多网卡环境**要给数据节点配 `replica-announce-ip` / `replica-announce-port`，否则哨兵
   上报容器内网 IP，应用连不上。本目录的脚本走本机原生进程（WSL/Linux），不涉及该问题。
4. **切换不是零中断**：`down-after` + 选举 + 通知期间（通常 5~15s）写入会失败，之后由
   `RedisConnector` 的周期刷新（缺省 5s）自动重指向并自愈——演练脚本会打印实测耗时。
5. **运行目录不要放 `/mnt/*`（WSL 的 DrvFs）**：Redis 磁盘型复制（从库落 temp RDB 再加载）在 DrvFs 上
   会以 `Failed trying to load the MASTER synchronization DB from disk` 失败——脚本已默认改用
   `${TMPDIR}` 原生盘（`NYTHROS_HA_DIR` 可覆盖）。
6. **WSL2 上哨兵会持续进入 tilt 模式**：实测子系统墙钟每 ~34s 向前跳 ~1.85s，哨兵据此判定时钟异常
   并保护性延迟动作——手动切换可能耗时 20-30s（`sentinel_tilt:1` 可查）。这是 WSL 环境特性，
   生产物理机/云主机没有；演练脚本用**单调钟**计时并容忍慢切换，不会因此误判失败。

## 演练实测基线（WSL2，本仓库 2026-09）

```text
① 标记写入 + WAIT 1 → 1 个副本确认（耐久屏障语义成立）
② 触发 failover → 哨兵 switch-master（受 tilt 影响 0.8~19s）
   → RedisConnector 检测到主变并把连接原地重指向新主（切换后 0.0s）
③ 同一连接恢复读写；独立连接直连新主复核标记存在 → PASS
```

## 相关文档

- 部署 checklist / 备份恢复演练：[docs/deployment.md](../../docs/deployment.md) §6 / §7
- Redis 单点风险与 HA 路线：[ADR-028](../../blueprint/adr/ADR-028-Redis单点风险与HA路线.md)
- 客户端连接器与切换自愈实现：[ADR-031](../../blueprint/adr/ADR-031-哨兵HA与连接自愈.md)
