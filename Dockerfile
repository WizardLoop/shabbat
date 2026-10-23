FROM composer:2.10.3@sha256:af98f42dfff7c68ba8d53c2164fd9fde1087b7d449514baa38c418b1f6bc4bac AS composer
FROM php:8.4.27-cli-bookworm@sha256:496b5e02fe9fe44fcfb715023b931048111dc4e0bc719789d83c906be9f04b05
RUN apt-get update \
    && apt-get install -y --no-install-recommends ca-certificates tzdata libsqlite3-dev libonig-dev libxml2-dev libzip-dev unzip curl build-essential pkg-config \
    && curl --fail --location --proto '=https' --tlsv1.2 https://www.sqlite.org/2026/sqlite-autoconf-3510300.tar.gz -o /tmp/sqlite.tar.gz \
    && echo '81f5be397049b0cae1b167f2225af7646fc0f82e4a9b3c48c9ea3a533e21d77a  /tmp/sqlite.tar.gz' | sha256sum -c - \
    && tar -xzf /tmp/sqlite.tar.gz -C /tmp \
    && cd /tmp/sqlite-autoconf-3510300 \
    && ./configure --prefix=/usr/local --enable-shared --disable-static \
    && make -j"$(nproc)" && make install && ldconfig \
    && docker-php-ext-install pdo_sqlite mbstring dom xml zip pcntl sockets \
    && rm -rf /var/lib/apt/lists/* /tmp/sqlite.tar.gz /tmp/sqlite-autoconf-3510300 \
    && groupadd --gid 10001 shabbat \
    && useradd --uid 10001 --gid shabbat --create-home shabbat
# The retained production session uses igbinary serialization. Keep the exact source and license.
RUN curl --fail --location --proto '=https' --tlsv1.2 https://pecl.php.net/get/igbinary-3.2.16.tgz -o /tmp/igbinary-3.2.16.tgz \
    && echo '8bf25d465abc7973d9e2c9a3039a5f8eea635b23bc1477017ff3999ff95836da  /tmp/igbinary-3.2.16.tgz' | sha256sum -c - \
    && pecl install /tmp/igbinary-3.2.16.tgz \
    && docker-php-ext-enable igbinary \
    && install -d -m 0755 /usr/local/share/shabbat/igbinary \
    && cp /tmp/igbinary-3.2.16.tgz /usr/local/share/shabbat/igbinary/igbinary-3.2.16.tgz \
    && tar -xzf /tmp/igbinary-3.2.16.tgz -C /tmp \
    && install -m 0644 /tmp/igbinary-3.2.16/COPYING /usr/local/share/shabbat/igbinary/COPYING \
    && rm -rf /tmp/igbinary-3.2.16 /tmp/igbinary-3.2.16.tgz \
    && php -r 'if (!extension_loaded("igbinary") || phpversion("igbinary") !== "3.2.16") { throw new RuntimeException("Pinned igbinary extension is unavailable."); }'
COPY --from=composer /usr/bin/composer /usr/local/bin/composer
COPY resources/php/99-shabbat-runtime.ini /usr/local/etc/php/conf.d/99-shabbat-runtime.ini
WORKDIR /app
COPY composer.json composer.lock ./
COPY patches ./patches
COPY scripts ./scripts
COPY src/Runtime/PatchManager.php ./src/Runtime/PatchManager.php
COPY src/Runtime/PatchSet.php ./src/Runtime/PatchSet.php
COPY src/Runtime/SingleInstance.php ./src/Runtime/SingleInstance.php
COPY src/Runtime/RuntimePaths.php ./src/Runtime/RuntimePaths.php
RUN composer install --no-dev --no-scripts --no-interaction --prefer-dist --optimize-autoloader \
    && php scripts/apply-patches.php \
    && php scripts/verify-patches.php
COPY --chown=shabbat:shabbat . .
ARG BUILD_COMMIT=
RUN php bin/build-metadata.php --commit="$BUILD_COMMIT" \
    && mkdir -p /app/data /app/runtime \
    && chown shabbat:shabbat /app \
    && chown -R shabbat:shabbat /app/data /app/runtime \
    && php bin/self-check.php --release
ENV DATA_DIR=/app/data SESSION_PATH=/app/runtime/bot.shabbat
USER shabbat
# PrimeModule fallback copies and the IPC bootstrap logger use the working directory.
# Exercise these filesystem requirements as the actual bot user before startup.
RUN php bin/runtime-permissions.php --require-non-root \
    && php bin/igbinary-check.php
VOLUME ["/app/data", "/app/runtime"]
CMD ["php", "/app/bot.php"]