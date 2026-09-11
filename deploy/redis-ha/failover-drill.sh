#!/usr/bin/env bash
# 定位：deploy/redis-ha/failover-drill.sh —— 哨兵切换演练（触发真实 failover，断言客户端自愈与耐久链）。
# Located at: deploy/redis-ha/failover-drill.sh — the Sentinel failover drill (triggers a real failover and
# asserts client self-healing plus the durability chain).
#
# 用法 Usage:
#   bash deploy/redis-ha/failover-drill.sh              # 演练（先自愈拓扑，演练后复位；环境始终干净）
#   bash deploy/redis-ha/failover-drill.sh --keep       # 演练后保留反转拓扑（观察用）

source "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/lib.sh"

KEEP=0
[ "${1:-}" = "--keep" ] && KEEP=1

# 栈就绪检查：主/从/哨兵都要在（演练要真切换，不能半栈跑）
for port in "$MASTER_PORT" "$REPLICA_PORT" "${SENTINEL_PORTS[@]}"; do
    if ! redis_cli -p "$port" ping >/dev/null 2>&1; then
        fail "端口 $port 不可达——先启动 HA 栈：bash deploy/redis-ha/start.sh"
    fi
done

# 拓扑自愈：上次演练残留的「切换完成但旧主未降级」半状态（WSL 哨兵 tilt 模式会拖慢降级）会让
# min-replicas 拒写、WAIT 无副本——先重建栈，保证演练从干净拓扑开始。
# Topology self-healing: the half-transitioned state left by a previous drill (failover done, old master not yet
# demoted — WSL sentinel tilt mode slows the demotion) makes min-replicas refuse writes and WAIT ack nothing —
# rebuild the stack so every drill starts from a clean topology.
if ! topology_healthy; then
    echo "[redis-ha] 拓扑不健康（可能是上次演练残留的半状态），重建 HA 栈..."
    bash "$REDIS_HA_DIR/stop.sh" >/dev/null
    bash "$REDIS_HA_DIR/start.sh" >/dev/null
fi

echo "[redis-ha] 演练前拓扑："
bash "$REDIS_HA_DIR/status.sh"

export NYTHROS_REDIS_SENTINELS="127.0.0.1:${SENTINEL_PORTS[0]},127.0.0.1:${SENTINEL_PORTS[1]},127.0.0.1:${SENTINEL_PORTS[2]}"
export NYTHROS_REDIS_MASTER="$MASTER_NAME"
if [ -n "$PASSWORD" ]; then
    export NYTHROS_REDIS_PASSWORD="$PASSWORD"
    export NYTHROS_REDIS_SENTINEL_PASSWORD="$PASSWORD"
fi

echo
php "$REDIS_HA_DIR/drill-probe.php"
PROBE_STATUS=$?

echo
echo "[redis-ha] 演练后拓扑："
bash "$REDIS_HA_DIR/status.sh"

if [ "$KEEP" = "1" ]; then
    echo
    echo "[redis-ha] 保留反转拓扑（--keep）。复位：bash deploy/redis-ha/stop.sh && bash deploy/redis-ha/start.sh"
else
    echo
    echo "[redis-ha] 复位拓扑（演练环境保持干净）..."
    bash "$REDIS_HA_DIR/stop.sh" >/dev/null
    bash "$REDIS_HA_DIR/start.sh" >/dev/null
    echo "[redis-ha] 拓扑已复位："
    bash "$REDIS_HA_DIR/status.sh"
fi

exit "$PROBE_STATUS"
