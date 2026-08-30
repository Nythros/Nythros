# Dockerfile —— Nythros 官方运行镜像。
# 基础 php:8.3-cli 缺 ext-redis / pdo_mysql（pcntl/posix CLI 镜像自带），此处补齐；
# composer install 在镜像内完成（path 仓库 packages/* 直接解析源码，避免跨阶段 symlink 陷阱）。
# Dockerfile — the official Nythros runtime image.
# The stock php:8.3-cli image lacks ext-redis / pdo_mysql (pcntl/posix ship with the CLI image); installed here.
# composer install runs inside the image (the path repos packages/* resolve against real source, avoiding
# cross-stage symlink pitfalls).
#
# 用法 Usage（详见 docs/deployment.md）:
#   docker build -t nythros/server .
#   docker run --rm -p 18285:18285 -p 18081:18081 nythros/server          # 端口以 deploy.yaml 为准
#   docker compose --profile app up -d --build                            # 依赖 + 应用一键起

FROM composer:2 AS composer-bin

FROM php:8.3-cli

# 运行依赖：pdo_mysql（归档存储）、ext-redis（token/注册发现/快照/采样）；pcntl/posix CLI 镜像自带。
# Runtime deps: pdo_mysql (archive storage) and ext-redis (tokens/registry/snapshots/sampling);
# pcntl/posix ship with the CLI base image.
RUN set -eux; \
    docker-php-ext-install -j"$(nproc)" pdo_mysql; \
    apt-get update && apt-get install -y --no-install-recommends $PHPIZE_DEPS; \
    pecl install redis; \
    docker-php-ext-enable redis; \
    apt-get purge -y $PHPIZE_DEPS; \
    apt-get autoremove -y; \
    rm -rf /var/lib/apt/lists/*

COPY --from=composer-bin /usr/bin/composer /usr/bin/composer
WORKDIR /app

# 源码全量拷贝 → 镜像内装生产依赖（path 仓库解析为真实源码目录）。
# Copy the full source, then install production deps inside the image.
COPY composer.json composer.lock phpunit.xml.dist phpstan.neon ./
COPY bin ./bin
COPY packages ./packages
COPY tools ./tools
RUN composer install --prefer-dist --no-dev --no-interaction --no-progress --no-scripts

ENV NYTHROS_CONFIG_DIR=/app/packages/demo/config

# WebSocket 端口（social 三角色 + map/副本，以 deploy.yaml 为准）+ metrics 端口（docs/deployment.md §4）。
EXPOSE 18285 18286 18287 18081 18082 18083 18084 19100

CMD ["php", "bin/server", "start"]
