#!/usr/bin/env bash
# Nythros 长跑每小时只读巡检（非侵入：只读日志/timeline/Redis 计数器，绝不向服务栈发流量）
# 职责：把「明天才发现这 24h 白跑」压缩成「最迟 1 小时内自动止损」。
# 判据（PROBLEM = 立即终止长跑并留证据）：
#   P1 soak 进程消失且无 RESULT（会话连坐/OOM 等外因）
#   P2 日志出现 ABORT
#   P3 波次推进 < 30/h（预期 ~59/h；卡死/子进程僵死）
#   P4 rssTotal 相对上次巡检发生跳变（本负载形态下全程逐字节冻结是实证事实，动了=回归）
#   P5 dungeon 累计出站字节零增长（服务端玩法进度心跳停了 = driver 死/协议漂移，静默的跨波版）
#   P6 最近连续 ≥2 波 auth 全 0
# WARN（记录不动作）：auth 非满员、推进 30-55/h、memAvail 逼近 guard。
# 状态文件 /tmp/soak-hourly-state；历史 /tmp/soak-health-history.log；止损告警 /tmp/soak-alert.flag
LOG=${1:-/tmp/nythros-drill/soak-play-24h.log}
INTERVAL=${2:-3600}
STATE=/tmp/soak-hourly-state
HIST=/tmp/soak-health-history.log
ALERT=/tmp/soak-alert.flag
EXPECT_AUTH=${3:-240}

say() { echo "$(date '+%F %T') $*" >> "$HIST"; }

dungeon_bytes() {
  redis-cli --scan --pattern 'nythros:perf:dungeon-*:counters' 2>/dev/null \
    | while read -r k; do redis-cli hget "$k" network.out_bytes 2>/dev/null; done \
    | awk '{s+=$1+0} END{print s+0}'
}

latest_rss() {
  grep 'wave#' "$LOG" 2>/dev/null | tail -1 | grep -oE 'rssTotal=[0-9]+' | cut -d= -f2
}

wave_count() { grep -c 'wave#' "$LOG" 2>/dev/null; }

problem_stop() {
  local reason="$1"
  say "PROBLEM: $reason —— 执行止损终止长跑"
  local spid
  spid=$(pgrep -f "benchmarks/soa[k]-map.php" | head -1)
  [ -n "$spid" ] && kill -TERM "$spid" 2>/dev/null   # soak 有 finally：SIGTERM→PHP 默认终止→托管栈成孤儿，需端口族兜底清理
  sleep 5
  local pids
  pids=$(ss -tlnp 2>/dev/null | grep -E ':1828[567]|:1808[1-4]' | grep -oE 'pid=[0-9]+' | cut -d= -f2 | sort -u)
  [ -n "$pids" ] && kill -9 $pids 2>/dev/null
  pkill -f "benchmarks/stres[s]-play.php" 2>/dev/null
  {
    echo "KILLED"
    echo "每小时巡检止损: $reason ($(date '+%F %T'))"
    echo "长跑日志: $LOG（现场已保留）"
  } > /tmp/soak-DONE.flag
  echo "$reason" > "$ALERT"
  say "止损完成，DONE 标记已写（通知 watcher 将以 KILLED 呈现）"
  exit 2
}

say "===== 巡检启动 log=$LOG interval=${INTERVAL}s ====="
FIRST=1
while true; do
  # 长跑自然结束 → 巡检使命完成，自退
  if [ -f /tmp/soak-DONE.flag ]; then say "检测到 DONE 标记，巡检正常退出"; exit 0; fi

  W=$(wave_count)
  RSS=$(latest_rss)
  DB=$(dungeon_bytes)
  AUTH0=$(grep 'wave#' "$LOG" 2>/dev/null | tail -3 | grep -cv "auth=$EXPECT_AUTH/$EXPECT_AUTH")

  if [ "${FIRST:-0}" = "1" ]; then
    printf 'w=%s rss=%s db=%s\n' "$W" "$RSS" "$DB" > "$STATE"
    say "基线: waves=$W rss=$RSS dungeonBytes=$DB"
    FIRST=0
  else
    read -r PW PRSS PDB < <(tr ' ' '\n' < "$STATE" | sed -n 's/w=//p;s/rss=//p;s/db=//p' | paste -sd' ' -)
    DW=$((W - ${PW:-W}))
    # P1 进程消失
    if ! pgrep -f "benchmarks/soa[k]-map.php" >/dev/null; then problem_stop "soak 进程消失且无 DONE 标记（外因终止）"; fi
    # P2 ABORT
    if grep -q 'ABORT' "$LOG" 2>/dev/null; then problem_stop "日志出现 ABORT: $(grep ABORT "$LOG" | tail -1 | cut -c1-120)"; fi
    # P3 波次推进
    if [ "$DW" -lt 30 ]; then problem_stop "波次推进仅 ${DW}/h（<30，疑似卡死）"; fi
    # P4 RSS 跳变（本负载形态实证逐字节冻结）
    if [ -n "$PRSS" ] && [ "$RSS" != "$PRSS" ]; then problem_stop "rssTotal 跳变 ${PRSS}→${RSS}KB（字节冻结被打破）"; fi
    # P5 dungeon 累计字节零增长
    if [ -n "$PDB" ] && [ "$DB" = "$PDB" ] && [ "$DB" != "0" ]; then problem_stop "dungeon 累计出站字节 1h 零增长（玩法进度停摆）"; fi
    # P6 连续 auth 全 0
    if [ "$AUTH0" -ge 2 ] && [ "$(grep 'wave#' "$LOG" | tail -2 | grep -c "auth=0/")" = "2" ]; then problem_stop "连续 auth 全 0（登录链断）"; fi
    # WARN 项
    [ "$DW" -lt 55 ] && say "WARN: 波次推进 ${DW}/h 偏低（预期~59）"
    [ "$AUTH0" -gt 0 ] && [ "$(grep 'wave#' "$LOG" | tail -3 | grep -c 'auth=0/')" -lt 2 ] && say "WARN: 近 3 波存在非满员 auth"
    MEMA=$(grep 'wave#' "$LOG" | tail -1 | grep -oE 'memAvail=[0-9.]+' | cut -d= -f2)
    awk -v m="$MEMA" 'BEGIN{exit !(m>0 && m<400)}' && say "WARN: memAvail=${MEMA}MB 逼近 guard 线（页缓存形态，§6.4 已备案）"
    say "OK: waves=+$DW (共$W) rss=$RSS dungeonBytes=$DB"
    printf 'w=%s rss=%s db=%s\n' "$W" "$RSS" "$DB" > "$STATE"
  fi
  sleep "$INTERVAL"
done
