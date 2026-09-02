#!/usr/bin/env bash
# skeleton-smoke.sh —— monorepo 侧 skeleton 冒烟入口（composer smoke:skeleton / smoke:skeleton:dev 的载体）。
# 两种组合：
#   packagist（默认）：packages/skeleton 按发布形态依赖从 Packagist 装 vendor，镜像独立仓 CI——
#     验证「tag 发布后用户拿到什么」，发版前必跑。
#   dev：composer config 覆写 path 仓库指向 ../engine ../framework（工作区代码），跑完恢复 composer.json——
#     验证「未发布改动是否破坏入门套件」。
# 冒烟本体是 packages/skeleton/scripts/smoke.sh（独立仓用户也能跑），此处只做组合装配。
set -e
cd "$(dirname "$0")/.."
MODE="${1:-packagist}"
DIR=packages/skeleton
# 恢复用文件备份而非 git checkout：git mv 暂存的 index 里可能是旧形态内容（坑过一次）。
restore() {
  if [ -f "$DIR/composer.json.smoke-bak" ]; then
    mv "$DIR/composer.json.smoke-bak" "$DIR/composer.json"
  fi
}

case "$MODE" in
  packagist)
    composer -d "$DIR" install --no-interaction --prefer-dist --no-progress
    ;;
  dev)
    cp "$DIR/composer.json" "$DIR/composer.json.smoke-bak"
    trap restore EXIT
    # versions 覆写为 0.1.0：path 包缺省别名是 dev-master（匹配不上 ^0.1 也过不了 stability），
    # 显式模拟本地包即当前发布版本——monorepo 时代 skeleton 的 path 仓库同款做法。
    composer -d "$DIR" config --json repositories.wt-engine '{"type":"path","url":"../engine","options":{"versions":{"nythros/engine":"0.1.0"}}}'
    composer -d "$DIR" config --json repositories.wt-framework '{"type":"path","url":"../framework","options":{"versions":{"nythros/framework":"0.1.0"}}}'
    composer -d "$DIR" update nythros/engine nythros/framework --no-interaction --no-progress
    ;;
  *)
    echo "usage: $0 [packagist|dev]"; exit 2
    ;;
esac

bash "$DIR/scripts/smoke.sh"
echo "[skeleton-smoke] mode=$MODE PASS（组合校验另跑 composer -d $DIR validate）"
composer -d "$DIR" validate --no-check-publish --no-interaction
