#!/usr/bin/env bash
# 定位：deploy/redis-ha/start.sh —— 开发机哨兵 HA 栈一键启动（1 主 + 1 从 + 3 哨兵）。
# Located at: deploy/redis-ha/start.sh — one-shot launcher of the dev Sentinel HA stack (1 master + 1 replica + 3 sentinels).
#
# 口径：与生产同构（3 哨兵 / quorum 2 / down-after 5s），端口与开发用单实例（6379）错开，两套可共存。
# Shape: identical to production (3 sentinels / quorum 2 / 5s down-after); ports avoid the dev single
# instance (6379) so both can coexist.
#
# 环境变量见 lib.sh（NYTHROS_HA_DIR / NYTHROS_HA_PASSWORD / NYTHROS_HA_MASTER_NAME / NYTHROS_HA_MIN_REPLICAS）。
# Environment variables live in lib.sh (NYTHROS_HA_DIR / NYTHROS_HA_PASSWORD / NYTHROS_HA_MASTER_NAME / NYTHROS_HA_MIN_REPLICAS).
#
# 用法 Usage:
#   bash deploy/redis-ha/start.sh          # 启动；应用侧按输出注入 NYTHROS_REDIS_SENTINELS
#   bash deploy/redis-ha/status.sh         # 查看角色与主库地址
#   bash deploy/redis-ha/failover-drill.sh # 手动切换演练 + 客户端自愈断言
#   bash deploy/redis-ha/stop.sh           # 停止

source "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/lib.sh"

command -v redis-server >/dev/null 2>&1 || fail "未找到 redis-server（本脚本在 WSL/Linux 内运行）"
command -v redis-cli >/dev/null 2>&1 || fail "未找到 redis-cli"

# 端口占用预检：任何目标端口已有 Redis 响应 = 栈可能已在运行
for port in "${ALL_PORTS[@]}"; do
    if response=$(redis_cli -p "$port" ping 2>/dev/null) && [ "$response" = "PONG" ]; then
        fail "端口 $port 已有 Redis 在运行（若为上次残留：bash deploy/redis-ha/stop.sh）"
    fi
done

mkdir -p "$RUNTIME_ROOT"

# 认证配置片段（密码为空则整体为空行，避免 "requirepass " 空值错误）
AUTH_LINES=""
SENTINEL_AUTH_LINE=""
if [ -n "$PASSWORD" ]; then
    AUTH_LINES="requirepass $PASSWORD"
    SENTINEL_AUTH_LINE="sentinel auth-pass $MASTER_NAME $PASSWORD"
fi

# ── 主库 ── Master
mkdir -p "$RUNTIME_ROOT/master"
cat > "$RUNTIME_ROOT/master/master.conf" <<EOF
port $MASTER_PORT
dir $RUNTIME_ROOT/master
daemonize yes
logfile $RUNTIME_ROOT/master/master.log
pidfile $RUNTIME_ROOT/master/master.pid
appendonly no
save ""
min-replicas-to-write $MIN_REPLICAS
min-replicas-max-lag 10
$AUTH_LINES
EOF

# ── 从库 ── Replica（masterauth 必须配：升主后要能连上新主，漏配会在切换后卡死）
mkdir -p "$RUNTIME_ROOT/replica"
cat > "$RUNTIME_ROOT/replica/replica.conf" <<EOF
port $REPLICA_PORT
dir $RUNTIME_ROOT/replica
daemonize yes
logfile $RUNTIME_ROOT/replica/replica.log
pidfile $RUNTIME_ROOT/replica/replica.pid
appendonly no
save ""
replicaof 127.0.0.1 $MASTER_PORT
replica-read-only yes
$AUTH_LINES
EOF

# ── 哨兵 ×3（quorum 2，与生产同构）── Sentinels (quorum 2, production-shaped)
for port in "${SENTINEL_PORTS[@]}"; do
    mkdir -p "$RUNTIME_ROOT/sentinel-$port"
    cat > "$RUNTIME_ROOT/sentinel-$port/sentinel.conf" <<EOF
port $port
dir $RUNTIME_ROOT/sentinel-$port
daemonize yes
logfile $RUNTIME_ROOT/sentinel-$port/sentinel.log
pidfile $RUNTIME_ROOT/sentinel-$port/sentinel.pid
sentinel monitor $MASTER_NAME 127.0.0.1 $MASTER_PORT 2
sentinel down-after-milliseconds $MASTER_NAME 5000
sentinel failover-timeout $MASTER_NAME 60000
sentinel parallel-syncs $MASTER_NAME 1
$SENTINEL_AUTH_LINE
EOF
done

redis-server "$RUNTIME_ROOT/master/master.conf"
redis-server "$RUNTIME_ROOT/replica/replica.conf"
for port in "${SENTINEL_PORTS[@]}"; do
    redis-server "$RUNTIME_ROOT/sentinel-$port/sentinel.conf" --sentinel
done

# ── 就绪等待：主 PONG → 从 link up → 哨兵认主（每步 ~15s 上限）──
deadline=$((SECONDS + 10))
until [ "$(redis_cli -p "$MASTER_PORT" ping 2>/dev/null)" = "PONG" ]; do
    [ $SECONDS -lt $deadline ] || fail "主库 $MASTER_PORT 未就绪（日志：$RUNTIME_ROOT/master/master.log）"
    sleep 0.2
done

deadline=$((SECONDS + 15))
until redis_cli -p "$REPLICA_PORT" info replication 2>/dev/null | grep -q 'master_link_status:up'; do
    [ $SECONDS -lt $deadline ] || fail "从库未与主库建链（日志：$RUNTIME_ROOT/replica/replica.log）"
    sleep 0.2
done

deadline=$((SECONDS + 15))
until sentinel_master_addr | grep -q "$MASTER_PORT"; do
    [ $SECONDS -lt $deadline ] || fail "哨兵未识别主库（日志：$RUNTIME_ROOT/sentinel-${SENTINEL_PORTS[0]}/sentinel.log）"
    sleep 0.2
done

echo "[redis-ha] 已启动（主 $MASTER_PORT / 从 $REPLICA_PORT / 哨兵 ${SENTINEL_PORTS[*]}，quorum 2）"
echo "[redis-ha] 运行目录：$RUNTIME_ROOT（配置/日志；NYTHROS_HA_DIR 可覆盖）"
bash "$REDIS_HA_DIR/status.sh"
echo
echo "应用侧注入（直连 6379 的日常开发不受影响，仅演练/HA 部署时设置）："
echo "  export NYTHROS_REDIS_SENTINELS=127.0.0.1:${SENTINEL_PORTS[0]},127.0.0.1:${SENTINEL_PORTS[1]},127.0.0.1:${SENTINEL_PORTS[2]}"
echo "  export NYTHROS_REDIS_MASTER=$MASTER_NAME"
[ -n "$PASSWORD" ] && echo "  export NYTHROS_REDIS_PASSWORD=***（哨兵密码另见 NYTHROS_REDIS_SENTINEL_PASSWORD）"
echo
echo "演练：bash deploy/redis-ha/failover-drill.sh    停止：bash deploy/redis-ha/stop.sh"
