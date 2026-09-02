#!/usr/bin/env bash
# smoke.sh —— skeleton 端到端冒烟：launch 起双频道 → 三客户端连入 → 断言 auth_ok / 世界快照 / 移动广播。
# 三种运行语境同一脚本：
#   ① 独立仓 CI / 用户本地：bash scripts/smoke.sh（ROOT=仓库根）
#   ② monorepo 本地：根 composer 脚本 smoke:skeleton 覆写 ROOT=packages/skeleton
# 前置：ROOT 下已 composer install（vendor/ 存在）。Linux 语境（CI/WSL）运行。
set -e

ROOT="${ROOT:-$(cd "$(dirname "$0")/.." && pwd)}"
cd "$ROOT"
if [ ! -f vendor/autoload.php ]; then
  echo "[smoke] fatal: $ROOT 下缺 vendor/，先执行 composer install"
  exit 1
fi

LOG_PREFIX=/tmp/nythros-skeleton-smoke
rm -f "$LOG_PREFIX"-*.log /tmp/nythros-skeleton-client-*.pid

port_busy() {
  (exec 3<>/dev/tcp/127.0.0.1/"$1") 2>/dev/null && { exec 3<&- 3>&-; return 0; } || return 1
}

# 起始检查：端口必须空闲。上一轮服务未完全退出时端口仍被垂死实例 LISTEN，
# 客户端会连到旧世界导致断言随机挂（连跑复现过）。给 20s 释放窗口，超时即失败。
for p in 18081 18082; do
  for _ in $(seq 1 40); do
    port_busy "$p" || break
    sleep 0.5
  done
  if port_busy "$p"; then
    echo "[smoke] fatal: 端口 $p 持续被占用（上一轮服务未退出？或与其他程序冲突）"
    exit 1
  fi
done

cleanup() {
  [ -n "${LAUNCH_PID:-}" ] && kill -INT "$LAUNCH_PID" 2>/dev/null || true
  # 等 launch 及其子进程真正退出，避免连跑时端口被垂死实例占住（最多 10s）
  for _ in $(seq 1 20); do
    kill -0 "${LAUNCH_PID:-0}" 2>/dev/null || break
    sleep 0.5
  done
}
trap cleanup EXIT

php bin/launch.php > "$LOG_PREFIX-launch.log" 2>&1 &
LAUNCH_PID=$!

# 等两个频道端口就绪（最多 20s）；就绪前 launch 崩溃则立即失败并留日志
wait_port() {
  local port=$1 i
  for i in $(seq 1 40); do
    if (exec 3<>/dev/tcp/127.0.0.1/"$port") 2>/dev/null; then exec 3<&- 3>&-; return 0; fi
    if ! kill -0 "$LAUNCH_PID" 2>/dev/null; then
      echo "[smoke] fatal: launch 进程退出"; cat "$LOG_PREFIX-launch.log"; exit 1
    fi
    sleep 0.5
  done
  echo "[smoke] fatal: 端口 $port 超时未就绪"; cat "$LOG_PREFIX-launch.log"; exit 1
}
wait_port 18081
wait_port 18082

# bob 后台先连主城；alice 在 bob 在场时连同一 AOI 世界 → 互相可见；carol 连全量副本
php client.php bob 18081 > "$LOG_PREFIX-bob.log" 2>&1 &
BOB_PID=$!
php client.php alice 18081 > "$LOG_PREFIX-alice.log" 2>&1
php client.php carol 18082 > "$LOG_PREFIX-carol.log" 2>&1
wait "$BOB_PID" 2>/dev/null || true

# 断言：三客户端均完成认证；主城/副本都有 NPC 世界快照；AOI 世界收到移动广播
grep -q auth_ok "$LOG_PREFIX-alice.log"
grep -q auth_ok "$LOG_PREFIX-bob.log"
grep -q auth_ok "$LOG_PREFIX-carol.log"
grep -q spawned "$LOG_PREFIX-alice.log"
grep -q spawned "$LOG_PREFIX-carol.log"
grep -q entity_moved "$LOG_PREFIX-alice.log"

echo "[smoke] ALL PASS"
