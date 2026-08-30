# ADR-011：开发/验收环境 Windows → WSL 迁移

> 状态：已接受（阶段 2 裁决）

结论：阶段 2 期间将开发/验收环境从 Windows 迁移到 WSL，后续阶段以 Linux/WSL 环境为准
（实测环境：WSL / PHP 8.3 / Workerman 5.2 / Redis 127.0.0.1:6379，见 blueprint/08-阶段2-验收总结）。

理由：

- Workerman 在 Windows 下不支持多进程（v5 下 Worker count 无效、恒单进程），且与 Linux 行为差异大，
  Windows 上的验收结果不能代表真实部署行为；
- Redis 跨进程 token 语义在 Windows 转发场景下存在跨机时钟偏差风险（Redis 经 localhost 转发，
  PHP 侧与 Redis 侧时钟不同源）；
- 阶段 2 的 token 四态与 5 进程并发竞态验收依赖真实 fork 语义，必须在 Linux 环境验证。

遗留痕迹：WorkermanWebSocketServer 中 `min($workerCount, 1)` 是 Windows 时期遗留的钳制，
阶段 4 放开多进程时移除。
