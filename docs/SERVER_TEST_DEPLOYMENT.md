# Deploy the publication source to an isolated test server

Use a separate test bot and the existing `shabbat-test` Compose project in `/root/shabbat-test`. These are operator instructions; publication preparation does not execute them. Keep `.env`, named data/session volumes and private backups. The supplied publication ZIP contains source without installed dependencies; Docker installs the locked runtime dependencies and patches during the build.

For a new test server, follow [installation](INSTALLATION.md) first. For the older file-based bot, follow [explicit legacy migration](MIGRATION.md); updating an existing SQLite test installation is a different operation.

## Upload

From a local shell opened in the delivered `shabbat-publication/artifacts` folder, with SSH/SCP access to the test server, replace `SERVER_IP`:

```sh
scp shabbat-3.0.0-dev.9-publication.zip SHA256SUMS root@SERVER_IP:/root/shabbat-test/
```

This copies the source ZIP and checksum file to an already existing directory. It does not stop or start the bot. The corresponding-source archive is a separate source-offer artifact; it is not required for this Docker installation command.

## Update the existing test deployment

Run on Linux from the test directory with Docker access. Stop at the first error. Preserve a private backup of data/session/library state before cutover; the migration step also creates and verifies its application-database backup.

```sh
set -eu
cd /root/shabbat-test
sha256sum -c SHA256SUMS --ignore-missing
docker compose -p shabbat-test stop shabbat
unzip -o shabbat-3.0.0-dev.9-publication.zip
chmod 600 .env
docker compose -p shabbat-test config --quiet
docker compose -p shabbat-test build shabbat
docker compose -p shabbat-test run --rm --no-deps shabbat php scripts/verify-patches.php
docker compose -p shabbat-test run --rm --no-deps shabbat php bin/self-check.php --release
docker compose -p shabbat-test run --rm --no-deps shabbat php bin/runtime-permissions.php --require-non-root
docker compose -p shabbat-test run --rm --no-deps shabbat php -r 'if (is_dir("/app/data/backups") === false && mkdir("/app/data/backups", 0700, true) === false) { exit(1); }'
docker compose -p shabbat-test run --rm --no-deps shabbat php bin/migrate.php --writers-stopped --backup-dir /app/data/backups --db /app/data/shabbat.sqlite
docker compose -p shabbat-test run --rm --no-deps shabbat php bin/check-calendar.php
docker compose -p shabbat-test up -d --force-recreate shabbat
docker compose -p shabbat-test logs --tail=120 shabbat
```

The final `up` command starts the configured bot and its networked actions. Earlier build/check/migration commands do not send Telegram messages. An existing database needs the backup directory; a new database initializes its schema. The runtime requires all 16 migration sources and rejects changed applied checksums.

After the first completed scheduler cycle:

```sh
docker compose -p shabbat-test exec -T shabbat php bin/health.php --deep
docker compose -p shabbat-test ps
```

Verify `/source` reports `3.0.0-dev.9` and the expected source identity. Retain the archive build metadata: its commit is the original baseline, explicitly accompanied by local changes and this publication fingerprint, not a new publication commit. Docker refuses missing or mismatched archive identity. Provide the exact accessible source URL before public operation.

## Existing permission problems and acceptance

1. Outside a protected period and without an active lock, review the group's normal rights in Telegram. An older overly permissive snapshot cannot reconstruct restrictions that were never saved correctly.
2. In group settings, open **Resolve permission problem**, refresh, inspect the displayed group/rights, then confirm the intended supported action. Adopting current permissions preserves the earlier evidence and does not itself change Telegram rights.
3. In a disposable group, disable media, invitations or pinning before closing/opening. Verify exact restoration, repeat after restart and check scheduled transitions separately. Include forum/non-forum groups if used.
4. Review `/testzmanim GROUP_ID` and its JSON-file export when investigating; avoid repeated forced restoration or deleting lock-state records.
5. Watch both heartbeat ages, oldest due jobs and delivery delay. A running process alone does not prove the queue is keeping up.

Do not use `down -v`, copy test data into production or run both versions on one session/database. [Rollback](BACKUP_RESTORE.md) requires matching code, data and session/library state; it cannot undo Telegram actions already accepted.

The publication checks report records local results and limits. Docker image build and live Telegram acceptance must be performed on the target test server when unavailable locally. See [delivery safety](DEV9_DELIVERY_SAFETY.md) and [publication status](PUBLICATION_STATUS.md).
