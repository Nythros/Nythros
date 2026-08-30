#!/usr/bin/env bash
# Nythros soak 状态巡检（只读，任何用户可跑，不干扰长跑）。输出健康摘要 + 趋势。
T=1; for k in $(redis-cli --scan --pattern "nythros:perf:*:counters" 2>/dev/null); do v=$(redis-cli hget "$k" eventbus.dropped_total 2>/dev/null); T=$((T + ${v:-0})); done
LOG=/tmp/nythros-drill/soak-2h.log
LATEST=$(grep '^\[soak\] wave#' $LOG 2>/dev/null | tail -1)
echo "soak 进程: $(pgrep -f 'soak-m''ap.php' >/dev/null && echo RUNNING || echo EXITED)"
echo "最后波次: ${LATEST:-（尚无波次）}"
echo "累计丢弃: $((T - 1))"
echo "可用内存: $(awk '/MemAvailable/{printf "%.0fMB", $2/1024}' /proc/meminfo)  | Redis: $(redis-cli ping 2>/dev/null || echo DOWN)"
# frameMean/p99 趋势（近 5 波）
grep -o 'frameMean=[^ ]*' $LOG 2>/dev/null | tail -5 | tr '\n' ' '
grep -o 'p99=[0-9]*ms' $LOG 2>/dev/null | tail -5 | tr '\n' ' '
echo
