#!/usr/bin/env bash
# Nythros 长跑监视器（WSL 侧常驻，setsid 脱离会话；本会话 11h play 长跑专用 LOG 路径）
# 退出协议：完成写 /tmp/soak-DONE.flag（首行 PASS/FAIL/GONE）；心跳写 /tmp/soak-watch.status
LOG=${1:-/tmp/nythros-drill/soak-play-11h.log}
rm -f /tmp/soak-DONE.flag
while true; do
  W=$(grep -c 'wave#' "$LOG" 2>/dev/null)
  LAST=$(grep 'wave#' "$LOG" 2>/dev/null | tail -1 | cut -c1-90)
  printf '%s waves=%s last=[%s]\n' "$(date +%H:%M:%S)" "$W" "$LAST" > /tmp/soak-watch.status
  if grep -q '^RESULT:' "$LOG" 2>/dev/null; then
    R=$(grep '^RESULT:' "$LOG")
    if echo "$R" | grep -q PASS; then M=PASS; else M=FAIL; fi
    { echo "$M"; echo "长跑完成: $R ($(date '+%F %H:%M:%S'), waves=$W)"; } > /tmp/soak-DONE.flag
    exit 0
  fi
  if ! pgrep -f 'php benchmarks/soak-map' >/dev/null; then
    {
      echo GONE
      echo "长跑进程消失且无 RESULT ($(date '+%F %H:%M:%S'), waves=$W)"
      tail -8 "$LOG"
    } > /tmp/soak-DONE.flag
    exit 1
  fi
  sleep 60
done
