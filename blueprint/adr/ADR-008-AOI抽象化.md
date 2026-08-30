# ADR-008：AOI 抽象化

> 状态：已接受（v0.1 规划期）

Engine API 暴露 AOIProvider / AOIQuery / InterestArea，不暴露 getNineCells()。第一版用
Grid/Cell 实现，九宫格查询只是默认实现细节，不是 API 承诺。后续可替换 Quadtree / Radius /
Sector，业务层无感。

## 关联

- 空间索引实现与配置：[docs/cell-guide.md](../../docs/cell-guide.md)
