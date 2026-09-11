#!/usr/bin/env bash
# 定位：deploy/redis-ha/status.sh —— HA 栈状态速查（各节点角色 + 哨兵认定的主库 + 从库链路）。
# Located at: deploy/redis-ha/status.sh — quick HA-stack status (per-node role + sentinel-declared master + replica link).

source "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/lib.sh"

echo "[redis-ha] 数据节点："
echo "  $MASTER_PORT → $(role_of "$MASTER_PORT")"
echo "  $REPLICA_PORT → $(role_of "$REPLICA_PORT")"

echo "[redis-ha] 哨兵认定主库："
for port in "${SENTINEL_PORTS[@]}"; do
    if addr=$(redis_cli -p "$port" sentinel get-master-addr-by-name "$MASTER_NAME" 2>/dev/null | tr '\n' ':' | sed 's/:$//'); then
        echo "  哨兵 $port → ${addr:-（未知）}"
    else
        echo "  哨兵 $port → 未运行"
    fi
done
