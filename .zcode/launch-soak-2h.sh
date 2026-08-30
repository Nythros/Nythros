#!/usr/bin/env bash
# Nythros 2 小时全负载 soak —— 一键启动脚本（setsid+nohup 脱离控制终端运行，关聊天/取消前台调用均不影响）。
# 用法（在本机 PowerShell 或 cmd）：
#   wsl.exe -e bash -c 'bash /mnt/d/workspace/php/Nythros/.zcode/launch-soak-2h.sh'
# 说明：soak/fault-drill 只含 redis-down 与 kill9 两场景（无需特权）；mysql-down 场景依赖
# systemctl，需 root——脚本会检测并以「冒烟跳过 mysql 场景」继续，或你在 PowerShell 手动跑：
#   wsl.exe -u root -e bash -lc 'bash /mnt/d/workspace/php/Nythros/.zcode/launch-soak-2h.sh --with-mysql-drill'
#
# 运维附记（blueprint/33 §12）：原用 tmux 托管，但本机 WSL 会话（pts）结束会连带清理其派生进程组，
# 两轮非守卫死亡均因 tmux server 整体被信号杀、无 SOAK-EXITED 落盘——改 setsid+nohup 脱管，120 分钟实证存活。
set -euo pipefail
cd /mnt/d/workspace/php/Nythros

# 0) 参数：--with-mysql-drill 时以 sudo 跑故障矩阵（需交互输入密码，仅手动模式用）
DRILL="php benchmarks/fault-drill.php --scenario=redis"
for a in "$@"; do [ "$a" = "--with-mysql-drill" ] && DRILL="sudo php benchmarks/fault-drill.php"; done

# 1) 前置自检
command -v redis-cli >/dev/null || { echo "缺 redis-cli：sudo apt-get install redis-tools"; exit 1; }
redis-cli ping >/dev/null || { echo "Redis 未响应（127.0.0.1:6379），先启动 Redis"; exit 1; }
mkdir -p /tmp/nythros-drill && touch /tmp/nythros-drill/.wtest 2>/dev/null || { echo "/tmp/nythros-drill 不可写（可能属主为 root）：wsl.exe -u root -e bash -c 'chown -R wen:wen /tmp/nythros-drill'"; exit 1; }
rm -f /tmp/nythros-drill/.wtest
ss -tln | grep -qE ":18285|:18081" && { echo "检测到服务端口已被占用，先停旧实例："; ss -tlnp | grep -E ':1828[567]|:1808[1-4]'; echo "（脚本不自动杀，请人工确认后重试）"; exit 1; }

# 2) 冒烟：先跑故障矩阵（redis-down 场景，约 2 分钟，托管自起自停）确认契约在线
echo "== 冒烟：故障矩阵（$(date +%H:%M:%S)）=="
$DRILL || { echo "冒烟失败——修复后重试，勿直接长跑"; exit 1; }

# 3) 清理冒烟残留（仅 nythros 端口族，绝不误杀无关进程）+ setsid 脱管启动 2h soak
pids=$(ss -tlnp 2>/dev/null | grep -E ':1828[567]|:1808[1-4]' | grep -oE 'pid=[0-9]+' | cut -d= -f2 | sort -u)
[ -n "$pids" ] && kill -9 $pids 2>/dev/null || true
sleep 1
redis-cli flushall >/dev/null
mkdir -p /tmp/nythros-drill
# 现场保护：若存在上一轮日志（已含 RESULT 结论），先备份再开新轮，避免 `>` 直接覆盖分析产物
[ -s /tmp/nythros-drill/soak-2h.log ] && grep -q "^RESULT:" /tmp/nythros-drill/soak-2h.log \
  && cp /tmp/nythros-drill/soak-2h.log "/tmp/nythros-drill/soak-2h.$(date +%Y%m%d-%H%M%S).bak.log" || true

# 3.1 常驻监视器（脱离会话）：轮询结果日志，完成/异常退出时落 /tmp/soak-DONE.flag + 心跳
cat > /tmp/soak-watch.sh <<'EOS'
#!/usr/bin/env bash
LOG=/tmp/nythros-drill/soak-2h.log
rm -f /tmp/soak-DONE.flag
while true; do
  W=$(grep -c "wave#" "$LOG" 2>/dev/null)
  echo "$(date +%H:%M:%S) waves=$W last=[$(grep "wave#" "$LOG" 2>/dev/null | tail -1 | sed 's/.*wave#/w/;s/ |.*//')]" > /tmp/soak-watch.status
  if grep -q "^RESULT:" "$LOG" 2>/dev/null; then
    echo "PASS" > /tmp/soak-DONE.flag
    echo "soak 完成: $(grep '^RESULT:' "$LOG") ($(date '+%F %T'), waves=$W)" >> /tmp/soak-DONE.flag
    exit 0
  fi
  if ! pgrep -f 'soak-ma''p\.php --minutes=120' >/dev/null; then
    echo "GONE" > /tmp/soak-DONE.flag
    echo "soak 进程消失但无 RESULT ($(date '+%F %T'), waves=$W)" >> /tmp/soak-DONE.flag
    tail -8 "$LOG" >> /tmp/soak-DONE.flag
    exit 1
  fi
  sleep 60
done
EOS
chmod +x /tmp/soak-watch.sh
setsid nohup bash /tmp/soak-watch.sh >/tmp/soak-watch.out 2>&1 </dev/null &

# 3.2 soak 本体（setsid 脱离控制终端；退出码经 SOAK-EXITED 落日志）
setsid nohup bash -c \
  'cd /mnt/d/workspace/php/Nythros && php benchmarks/soak-map.php --minutes=120 --clients=240 --wave-seconds=60 --move-ms=150 --settle-moves=40 --map-ids=map-1,map-2 > /tmp/nythros-drill/soak-2h.log 2>&1; echo SOAK-EXITED-$? >> /tmp/nythros-drill/soak-2h.log' \
  >/dev/null 2>&1 </dev/null &

sleep 3
pgrep -f 'soak-ma''p\.php --minutes=120' >/dev/null || { echo "soak 启动失败，查看 /tmp/nythros-drill/soak-2h.log"; tail -5 /tmp/nythros-drill/soak-2h.log; exit 1; }
echo "===== 已启动（setsid 脱管，$(date +%H:%M:%S)，监视器 /tmp/soak-watch.sh）====="
echo "实时进度：wsl.exe tail -5 /tmp/nythros-drill/soak-2h.log"
echo "监控巡检：wsl.exe -e bash -c 'bash /mnt/d/workspace/php/Nythros/.zcode/soak-status.sh'"
echo "心跳/完成标记：wsl.exe cat /tmp/soak-watch.status   /   wsl.exe cat /tmp/soak-DONE.flag（结束后出现）"
echo "预计约 2 小时 + 尾波开销后结束；结束后在 ZCode 新开会话粘贴 .zcode/soak-2h-brief.md 内容做分析"
