# 安全策略（Security Policy）

## 报告漏洞

**请不要用公开 Issue 报告安全漏洞。**

通过 GitHub 的 **Private vulnerability reporting**（仓库页 → Security → Report a vulnerability）私下报告，
或联系维护者邮箱（见 GitHub 个人主页）。我们承诺：

- 48 小时内确认收到；
- 7 天内给出初步评估（影响面 / 严重程度 / 修复计划）；
- 修复发布后才公开披露，报告中署名尊重报告者意愿。

## 安全模型要点（评估漏洞前请先了解）

- 明文凭据只在 Social gateway 终结；Map/chat/team 只消费多 scope 一次性 token
  （信任链详见 [docs/security.md](docs/security.md)）；
- 线协议不含加密层，公网部署必须前置 TLS 终结（[docs/deployment.md](docs/deployment.md) §6）；
- 演示账号（`1001/secret`）与静态认证器/白名单授权器是**本地验收占位**，生产必须替换；
- 每个游戏路由的校验责任在业务层（`MapServer::attack` 的前置校验是标准姿势）。

## 已知边界（非漏洞）

- Redis 无认证直连只适合开发环境；
- `verify-*` 脚本与 demo 装配不构成生产安全基线；
- WebSocket 帧无上限长度校验的部署需在前置代理层限制（协议层预留 STRING32 4GB 编码能力，
  生产建议代理层限帧 ≤ 1MB）。

## 影响版本

修复针对最新 tag 发布；0.x 期间不为旧版本出安全补丁（升级即可，见 CHANGELOG）。
