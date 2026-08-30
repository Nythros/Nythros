# ADR-004：Gateway / Map Server 分离

> 状态：已接受（v0.1 规划期）

控制流和实时数据流负载特征不同。高频实时逻辑不要全部压在 Gateway 上。

## 关联

- 网关栈形态后来经 ADR-021 调整为自研单栈对称直连：[ADR-021](ADR-021-移除gateway-worker统一网关栈.md)
- 双通道拓扑：[docs/architecture.md](../../docs/architecture.md) §2
