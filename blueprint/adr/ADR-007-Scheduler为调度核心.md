# ADR-007：Scheduler 为调度核心

> 状态：已接受（v0.1 规划期）

Scheduler（TickScheduler / RegionScheduler / TimerWheel / TaskQueue）是 Engine 核心调度能力，
按 Region 分配 CPU Budget，后续可扩展动态 Region Rebalance。RegionScheduler 是 World 内的
调度策略实现，受 Runtime 预算控制。
