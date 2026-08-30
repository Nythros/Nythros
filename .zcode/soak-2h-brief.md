# 任务简报：Nythros 2 小时全负载 soak 结果分析（新会话粘贴此文件全部内容即可）

你在 `D:\workspace\php\Nythros` 仓库工作（PHP 8.3 / Workerman 5 游戏服务器引擎框架，WSL2 路径
`/mnt/d/workspace/php/Nythros`，所有 WSL 命令用 `wsl.exe -e bash -c '...'` 包裹；默认用户 wen，
Redis 本机 6379 无密码）。

一轮 2 小时全负载 soak 长跑已在 WSL 中完成（若 tmux 会话 `nythros-soak` 仍在，先检查其状态，
等它结束再分析）。长跑配置：240 并发客户端、真实游戏负载（150ms/步走廊离散走位）、
均衡拓扑（map-1,map-2 多频道分摊）、六类崩塌自动熔断守卫。产物：

- 逐波日志：`/tmp/nythros-drill/soak-2h.log`（行格式 `[soak] wave#N auth=... fps=... p99=... | workers=... rssTotal=KB rssPeak=KB redisKeys=... memAvail=MB frameMean=ms`，末行 `RESULT: PASS|FAIL`，异常中止会有 `ABORT:` 行）
- 完整时间线：`/tmp/nythros-drill/soak-timeline.jsonl`（每行一个 JSON，kind=sample/wave/abort）
- 每波 stress 原始输出留存：`/tmp/nythros-drill/stress-last.log`

**分析要求（逐项给出数字与结论）：**
1. 进程与日志是否跑满约 120 分钟（对比首末采样时间戳），RESULT 是什么；
2. RSS 斜率：全采样点最小二乘（KB/采样），是否零泄漏；rssPeak 趋势；
3. p99 与 frameMean 的全程分布（min/P50/P95/max/mean）与**分段趋势（前/中/后 1/3）**——
   重点：已知冒烟观察到 frameMean 逐波 4.86→5.77→6.23ms 爬升、p99 312→590ms，判定这次
   2h 数据里该趋势是否收敛/稳定/恶化（这是本轮分析的核心问题）；
4. auth 是否全程满员（每波 240/240）、redisKeys 与 memAvail 有无单调增长（键泄漏/内存压力）；
5. 有无 ABORT 记录（有则分析原因与建议修复方向）；
6. 综合判定：是否具备「开服前 24h 累计长跑」条件，并给出下一轮观察点。

**参考资料**：`blueprint/33-长跑与故障演练记录.md`（§7-§11 前几轮数据与修复史）、
`docs/performance.md`（容量与阈值口径）。历史基线：3h 单 mapId 长跑 RSS 斜率 0.000、p99 均值 169ms；
2min 均衡拓扑冒烟 frameMean 4.9→6.2ms。分析完成后把结论补进 blueprint/33 新章节并更新 CHANGELOG，
跑 `composer cs/stan/internal/api` + `php vendor/phpunit/phpunit/phpunit`（预期 4 个已知
Windows 环境预存失败，不算回归）确认绿，git 保持单提交（amend + 重打 v0.1.0 tag）。
不要动 `/tmp/nythros-drill` 下的文件（保留现场），如需复跑压测先读 `benchmarks/` 下工具自述。
