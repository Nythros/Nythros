#!/usr/bin/env bash
# 定位：deploy/redis-ha/lib.sh —— HA 脚本共用常量与工具函数（被 start/stop/status/failover-drill source）。
# Located at: deploy/redis-ha/lib.sh — shared constants and helpers sourced by start/stop/status/failover-drill.
#
# 运行目录默认落在**原生 Linux 存储**（${TMPDIR:-/tmp}）而非仓库目录：仓库常位于 /mnt/*（WSL DrvFs），
# 而 Redis 的磁盘型复制（replica 落 temp RDB 再加载）在 DrvFs 上会以 "Failed trying to load the MASTER
# synchronization DB from disk: No such file or directory" 失败（2026-09 实测；原生盘同一配置立即可用）。
# The runtime dir defaults to NATIVE Linux storage (${TMPDIR:-/tmp}) rather than the repo: the repo often sits
# on /mnt/* (WSL DrvFs), where Redis's disk-based replication (the replica writes a temp RDB, then loads it)
# fails with "Failed trying to load the MASTER synchronization DB from disk: No such file or directory"
# (measured 2026-09; the identical config links instantly on native storage).

set -euo pipefail

REDIS_HA_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

RUNTIME_ROOT="${NYTHROS_HA_DIR:-${TMPDIR:-/tmp}/nythros-redis-ha}"
PASSWORD="${NYTHROS_HA_PASSWORD:-}"
MASTER_NAME="${NYTHROS_HA_MASTER_NAME:-nythros}"
MIN_REPLICAS="${NYTHROS_HA_MIN_REPLICAS:-1}"

MASTER_PORT=16379
REPLICA_PORT=16380
SENTINEL_PORTS=(26379 26380 26381)
ALL_PORTS=(16379 16380 26379 26380 26381)

REPO_ROOT="$(cd "$REDIS_HA_DIR/../.." && pwd)"

redis_cli() { redis-cli --no-auth-warning ${PASSWORD:+-a "$PASSWORD"} "$@"; }

fail() { echo "[redis-ha] 错误: $*" >&2; exit 1; }

# 节点角色描述（供 status/drill 输出）Role description of a data node (for status/drill output).
role_of() {
    local port="$1"
    local info
    if ! info=$(redis_cli -p "$port" info replication 2>/dev/null); then
        echo "未运行"; return
    fi
    local role link
    role=$(printf '%s\n' "$info" | grep -m1 '^role:' | tr -d '\r' | cut -d: -f2)
    if [ "$role" = "slave" ]; then
        link=$(printf '%s\n' "$info" | grep -m1 '^master_link_status:' | tr -d '\r' | cut -d: -f2)
        echo "从库(link=$link)"
    else
        echo "$role"
    fi
}

# 主库当前地址 "ip:port"（取第一个哨兵的认定；哨兵不可用 = 空）Current master address "ip:port" per the first sentinel (empty when unreachable).
sentinel_master_addr() {
    redis_cli -p "${SENTINEL_PORTS[0]}" sentinel get-master-addr-by-name "$MASTER_NAME" 2>/dev/null | tr '\n' ':' | sed 's/:$//'
}

# 拓扑健康：哨兵认定的主库确为 master，且另一节点是 link=up 的从库（切换后未完成降级的半状态 = 不健康）。
# Healthy topology: the sentinel-declared master really is a master and the other node links up as its replica
# (a half-transitioned state — failover done but the old master not yet demoted — counts as unhealthy).
topology_healthy() {
    local master_addr port other
    master_addr="$(sentinel_master_addr)"
    [ -n "$master_addr" ] || return 1
    port="${master_addr##*:}"
    if [ "$port" != "$MASTER_PORT" ] && [ "$port" != "$REPLICA_PORT" ]; then
        return 1
    fi
    other=$MASTER_PORT
    [ "$port" = "$MASTER_PORT" ] && other=$REPLICA_PORT

    [ "$(role_of "$port")" = "master" ] || return 1
    case "$(role_of "$other")" in
        *"link=up"*) return 0 ;;
        *) return 1 ;;
    esac
}
