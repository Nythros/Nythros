# ADR-012：RedisTokenStore 提前落地（token 跨进程状态共享正解）

> 状态：已接受（阶段 2 裁决）

结论：token 跨进程状态共享的唯一正解是 Redis；RedisTokenStore 在阶段 2 提前落地为验收前置，
而不是留到阶段 4。

理由：

- Workerman Linux 下每 Worker 独立 fork 进程，InMemoryTokenStore 进程内存不共享——gateway 进程
  签发的 token 在 map 进程 consume 必为 Invalid（阶段 2 实测首轮 sync FAILED 即此根因）；
- 原子性：单 EVAL Lua 脚本原子完成「墓碑判定 → GET → expiresAt 判定 → DEL + 写墓碑」，
  5 进程并发 consume 同一 token 恰一个 Valid；
- 墓碑防重放：消费/移除后写 `:tombstone` 键（TTL = 剩余存活时间），二次消费判定 Replayed，
  与 InMemoryTokenStore 四态语义对齐；
- 过期判定用 PHP 侧时钟（可注入）而非 Redis 时钟，规避跨机时钟偏差；
- 连接用工厂闭包：fork 会复制 socket fd，多 worker 共享同一 Redis 连接会破坏协议，
  工厂在 fork 后各进程首次使用时各自建连。

## 关联

- 安全模型：[docs/security.md](../../docs/security.md) §2
