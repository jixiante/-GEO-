ARG PHP_CLI_IMAGE=php:8.4-cli-bookworm
ARG COMPOSER_IMAGE=composer:2

FROM ${COMPOSER_IMAGE} AS composer

FROM ${PHP_CLI_IMAGE}

WORKDIR /var/www/html

# 使用国内 Debian 源，加速 apt 安装（Bookworm：deb822 或经典 sources.list）
RUN set -eux; \
    if [ -f /etc/apt/sources.list.d/debian.sources ]; then \
        sed -i 's|deb.debian.org|mirrors.aliyun.com|g' /etc/apt/sources.list.d/debian.sources; \
        sed -i 's|security.debian.org|mirrors.aliyun.com|g' /etc/apt/sources.list.d/debian.sources; \
    elif [ -f /etc/apt/sources.list ]; then \
        sed -i 's|deb.debian.org|mirrors.aliyun.com|g' /etc/apt/sources.list; \
        sed -i 's|security.debian.org|mirrors.aliyun.com|g' /etc/apt/sources.list; \
    fi

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        curl \
        git \
        libfreetype6-dev \
        libjpeg62-turbo-dev \
        libonig-dev \
        libpng-dev \
        libpq-dev \
        libsqlite3-dev \
        libzip-dev \
        postgresql-client \
        unzip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install \
        gd \
        mbstring \
        pdo_pgsql \
        pdo_sqlite \
        pcntl \
        zip \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer /usr/bin/composer /usr/bin/composer

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_MEMORY_LIMIT=-1 \
    COMPOSER_PROCESS_TIMEOUT=2000

ARG COMPOSER_PACKAGIST_MIRROR=
RUN if [ -n "${COMPOSER_PACKAGIST_MIRROR}" ]; then \
        composer config -g repo.packagist composer "${COMPOSER_PACKAGIST_MIRROR}"; \
    fi

COPY composer.json composer.lock ./
RUN --mount=type=cache,target=/tmp/composer-cache \
    sh -eu -c 'cache_file=/tmp/composer-cache/files/laravel/framework/f14b62ea551f1d073f1676b0b1c3640742cf4d51.zip; \
      if ! unzip -tqq "$cache_file" >/dev/null 2>&1; then \
        mkdir -p "$(dirname "$cache_file")"; \
        rm -f "$cache_file"; \
        curl --http1.1 --fail --location --retry 8 --retry-all-errors --retry-delay 2 \
          --output "$cache_file" \
          https://codeload.github.com/laravel/framework/legacy.zip/727a8ea2949c23ca8b5316b86a00984b6017b7a0; \
        unzip -tqq "$cache_file"; \
      fi'
RUN --mount=type=cache,target=/tmp/composer-cache \
    COMPOSER_CACHE_DIR=/tmp/composer-cache \
    COMPOSER_MAX_PARALLEL_HTTP=1 \
    sh -eu -c 'attempt=1; while ! composer install --no-interaction --prefer-dist --no-dev --no-scripts; do \
      if [ "$attempt" -ge 8 ]; then exit 1; fi; \
      attempt=$((attempt + 1)); \
      echo "[build] composer download interrupted; retry ${attempt}/8"; \
      sleep 2; \
    done'

COPY . .

# 仅刷新 autoload；不调 artisan / composer 脚本（镜像为 --no-dev，构建期无 Boost 等 dev 包）
RUN composer dump-autoload --optimize --no-interaction --no-scripts

COPY docker/php/opcache.ini /usr/local/etc/php/conf.d/99-opcache.ini
COPY docker/php/opcache-dev.ini /usr/local/etc/php/conf.d/zz-opcache-dev.ini
COPY docker/php/php-docker-overrides.ini /usr/local/etc/php/conf.d/99-overrides.ini

RUN cp docker/entrypoint.sh /usr/local/bin/geoflow-entrypoint \
    && chmod +x /usr/local/bin/geoflow-entrypoint

ENTRYPOINT ["/usr/local/bin/geoflow-entrypoint"]
CMD ["php", "artisan", "about"]
