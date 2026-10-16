FROM composer:2.8.8@sha256:3df281ca83119bff1332f0f5f311960cb16b99238f8273acd965be6bd508aa8e AS composer
FROM php:8.4.19-cli-bookworm@sha256:3ddb5a91b44a1c922538576d73a6e808fb2438d9d2d65b1fc3ffd55619fac2e3
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
COPY --from=composer /usr/bin/composer /usr/local/bin/composer
COPY resources/php/99-shabbat-runtime.ini /usr/local/etc/php/conf.d/99-shabbat-runtime.ini
WORKDIR /app
COPY composer.json composer.lock ./
COPY patches ./patches
COPY scripts ./scripts
COPY src/Runtime/AddedFilePatchManager.php ./src/Runtime/AddedFilePatchManager.php
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
RUN php bin/runtime-permissions.php --require-non-root
VOLUME ["/app/data", "/app/runtime"]
CMD ["php", "/app/bot.php"]