#!/usr/bin/env bash
# 定位：deploy/redis-ha/stop.sh —— 停止 HA 栈；缺省保留运行目录（日志可复盘），NYTHROS_HA_CLEAN=1 连同目录清理。
# Located at: deploy/redis-ha/stop.sh — stops the HA stack; the runtime dir (logs) is kept by default for
# post-mortem; NYTHROS_HA_CLEAN=1 removes it too.

source "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/lib.sh"

for port in "${ALL_PORTS[@]}"; do
    if redis_cli -p "$port" ping >/dev/null 2>&1; then
        redis_cli -p "$port" shutdown nosave >/dev/null 2>&1 || true
        echo "[redis-ha] 已停止端口 $port"
    fi
done

if [ "${NYTHROS_HA_CLEAN:-0}" = "1" ]; then
    rm -rf "$RUNTIME_ROOT"
    echo "[redis-ha] 运行目录已清理：$RUNTIME_ROOT"
else
    echo "[redis-ha] 运行目录保留（日志）：$RUNTIME_ROOT（清理：NYTHROS_HA_CLEAN=1 bash deploy/redis-ha/stop.sh）"
fi
