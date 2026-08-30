# ADR-006：Entity ≠ Actor

> 状态：已接受（v0.1 规划期）

Entity 管状态与身份，Actor 管行为执行，Cell 管空间位置。掉落物是 Entity 不是 Actor；
玩家是 Entity + Actor；全局技能系统可以是 Actor 但不绑定 Entity。
