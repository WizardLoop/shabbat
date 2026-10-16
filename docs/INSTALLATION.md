# Installation

[English overview](../README.md) | [עברית](../readme-he.md) | [Configuration](CONFIGURATION.md) | [Migration](MIGRATION.md)

This is the `3.0.0-dev.9` development snapshot. Install the reviewed source package or matching checkout, rather than assuming the public default branch already contains it. Installation does not imply that Docker or Telegram acceptance has passed for your host. See [publication status](PUBLICATION_STATUS.md).

## Requirements and command context

Run the following commands in the unpacked project root on Linux. Docker commands require access to the Docker daemon and Compose v2; that access is privileged. PHP maintenance commands run as the image's non-root user. The only Compose service is `shabbat`; there is no `composer` service.

The Dockerfile pins PHP 8.4.19 and Composer 2.10.3 images by digest and builds checksum-verified SQLite 3.51.3. It installs dependencies from `composer.lock`, applies and verifies the five local patch sets before startup, and runs without root as UID/GID 10001. Image building uses network access but no bot credentials. CI uses the same Composer version, including the upstream [GitHub Actions token-validation and disclosure fix](https://github.com/composer/composer/security/advisories/GHSA-f9f8-rm49-7jv2); authentication and all validation gates remain enabled. Runtime requires a separate configured bot, Telegram access and calendar provider access.

Manual installation requires 64-bit PHP 8.3, 8.4 or 8.5, Composer 2, the extensions required by `composer check-platform-reqs`, and a patched PHP-loaded SQLite version accepted by `php bin/self-check.php --release`. That gate accepts SQLite >=3.51.3 or the explicitly reviewed 3.44.6/3.50.7 backports. A newer standalone `sqlite3` executable does not change the library loaded by PHP PDO. The image is the documented deployment baseline; native process supervision is operator-managed.

## New isolated Docker installation

Use a new Compose project name and a separate test bot. The commands below use `shabbat-test`. Keep an existing deployment's project name unchanged to keep using its named volumes.

```sh
cp .env.example .env
chmod 600 .env
```

Edit `.env` privately before continuing. Configure `API_ID`, `API_HASH`, `BOT_TOKEN`, trusted `ADMIN` IDs, the optional GeoNames account and the exact deployed `SOURCE_URL`. See [all environment variables](CONFIGURATION.md). The example uses plain `KEY=value` lines that both Compose and PHP's raw INI reader can read. Keep explanatory comments in the documentation rather than mixing incompatible comment syntax in the shared file.

```sh
docker compose -p shabbat-test config --quiet
docker compose -p shabbat-test build shabbat
docker compose -p shabbat-test run --rm --no-deps shabbat php scripts/verify-patches.php
docker compose -p shabbat-test run --rm --no-deps shabbat php bin/self-check.php --release
docker compose -p shabbat-test run --rm --no-deps shabbat php bin/runtime-permissions.php --require-non-root
```

These commands validate configuration/build/runtime; they do not log in to Telegram. `config --quiet` avoids printing resolved credentials. For a new database with all writers stopped:

```sh
docker compose -p shabbat-test run --rm --no-deps shabbat php bin/migrate.php --writers-stopped --db /app/data/shabbat.sqlite
docker compose -p shabbat-test run --rm --no-deps shabbat php bin/check-calendar.php
```

Migration creates the schema in the persistent data volume. The calendar diagnostic contacts public providers but does not start the bot, read its private session or send Telegram messages. For an existing database, use the backup-required procedure below instead of the fresh-database command.

The next command starts the configured bot and enables its configured Telegram actions:

```sh
docker compose -p shabbat-test up -d shabbat
docker compose -p shabbat-test logs --tail=120 shabbat
```

After a complete scheduler cycle, check the running instance:

```sh
docker compose -p shabbat-test exec -T shabbat php bin/health.php --deep
docker compose -p shabbat-test ps
```

Health requires recent permission and notification heartbeats, as well as database/schema checks. An offline database alone cannot produce a successful live-health result. `--deep` adds integrity and foreign-key scans; use the ordinary probe for frequent monitoring.

## Updating an existing SQLite deployment

Run as the operator from the new matching source root, retaining the existing private `.env`, Compose project name and volumes. Stop all writers before patching or migration. Do not reuse this procedure as a substitute for [file-based legacy import](MIGRATION.md).

```sh
docker compose -p shabbat-test stop shabbat
docker compose -p shabbat-test build shabbat
docker compose -p shabbat-test run --rm --no-deps shabbat php bin/self-check.php --release
docker compose -p shabbat-test run --rm --no-deps shabbat php -r 'if (is_dir("/app/data/backups") === false && mkdir("/app/data/backups", 0700, true) === false) { exit(1); }'
docker compose -p shabbat-test run --rm --no-deps shabbat php bin/migrate.php --writers-stopped --backup-dir /app/data/backups --db /app/data/shabbat.sqlite
docker compose -p shabbat-test up -d --force-recreate shabbat
```

Migration verifies a backup before changing an existing database and rejects modified migration checksums. Startup validates the schema; it never silently migrates it. Keep independent private backups of the session and third-party runtime state as described in [backup and restore](BACKUP_RESTORE.md). Never remove named volumes as an upgrade step.

## Manual installation and no-scripts mode

Run as an unprivileged application owner in a separate checkout with writable private data/session directories. Use the supported PHP runtime, not a running bot's dependency tree.

```sh
composer validate --strict --no-check-all
composer install --no-dev --no-scripts --no-interaction --prefer-dist --optimize-autoloader
composer check-platform-reqs
php scripts/apply-patches.php
php scripts/verify-patches.php
php bin/self-check.php --release
```

`--no-scripts` requires both explicit patch commands before startup. Ordinary `composer install` runs these hooks automatically; never apply patches while the bot is running. A dependency mismatch or partially patched file must stop installation. Normal upgrades use `composer install` with the existing lock; dependency updates are a separate reviewed maintenance task.

Configure `.env`, create the fresh database while writers are stopped, then start only the intended bot:

```sh
php bin/migrate.php --writers-stopped --db data/shabbat.sqlite
php bot.php
```

`php bot.php` is a foreground networked process. It connects to Telegram and may act on registered groups. Use your own process supervisor for long-running native deployments. The [English](../README.md) and [Hebrew](../readme-he.md) guides list the operation commands and their effects.

## Source identity and production acceptance

Docker build identity requires either an explicit 40-hex `BUILD_COMMIT` from the actual reviewed checkout or the retained archive `build-info.json`. The release deployment ZIP includes the actual release commit, `local_changes=false` and its exact application-source fingerprint. Earlier local preparation archives instead record their historical baseline and `local_changes=true`; those are distinct artifacts. Retain archive metadata unchanged when building from a ZIP; for a reviewed Git checkout export `BUILD_COMMIT="$(git rev-parse HEAD)"` before building. A baseline commit alone does not identify unpublished changes. For an existing legacy bot, use [the stopped-writer production cutover guide](PRODUCTION_UPGRADE.md).

Before replacing a live bot, test the image build, exact permission restoration, restart recovery, inline/GPS flows, supported clients, command scopes and donation flows with a separate bot/group. Preserve and rehearse rollback. No installation command should modify real groups as an implicit test.
