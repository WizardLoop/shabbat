# Backup, restore and rollback

[Installation](INSTALLATION.md) | [Migration](MIGRATION.md) | [Database](DATABASE.md)

Application SQLite, MadelineProto session storage and BroadcastManager runtime files are separate datasets. Protect and back up all of them. A SQLite-only backup does not contain every dependency's state. Backups include private identifiers, preferences, templates, payment records and original group permissions; keep them out of Git and public archives.

## Application database backup

Run from the application root as the unprivileged application owner with access to the database and destination directory:

```sh
php bin/backup.php --db=/path/to/shabbat.sqlite --directory=/path/to/backups --keep=7
```

For the running Docker service, execute as its configured application user; replace the project name only if your deployment uses a different existing name:

```sh
docker compose -p shabbat-test exec -T shabbat php bin/backup.php --db=/app/data/shabbat.sqlite --directory=/app/data/backups --keep=7
```

The tool creates a unique SQLite copy using `VACUUM INTO`, including committed WAL data, verifies integrity/foreign keys/schema and writes a SHA-256 manifest. It creates the backup directory when needed. `--keep=7` prunes only this tool's strictly named backups in that directory; choose retention deliberately. It can run online, but database work can be substantial, so schedule it as maintenance rather than an event-loop action. Do not copy only the main file of a live WAL database.

SQLite 3.27 introduced `VACUUM INTO`; this bot's actual supported deployment requirements are stricter. Always pass the PHP-loaded SQLite release gate documented in [installation](INSTALLATION.md).

## Restore and isolated rehearsal

Stop every writer first. The restore tool requires the shared application writer lock and explicit operator attestation. It validates the backup and available manifest, checkpoints the stopped destination, verifies a staged copy, retains the previous database as `*.before-restore-*`, then installs the replacement.

Native maintenance example, from the matching application root:

```sh
php bin/restore.php --backup=/path/to/backups/shabbat-backup-YYYYMMDD-HHMMSS-XXXXXXXX.sqlite --db=/separate/path/restored.sqlite --writers-stopped
```

Use an actual filename emitted by the backup tool. The destination above is a separate rehearsal path. For restoring the stopped Docker deployment, the verified backup must be available inside the persistent data volume:

```sh
docker compose -p shabbat-test stop shabbat
docker compose -p shabbat-test run --rm --no-deps shabbat php bin/restore.php --backup=/app/data/backups/shabbat-backup-YYYYMMDD-HHMMSS-XXXXXXXX.sqlite --db=/app/data/shabbat.sqlite --writers-stopped
```

These commands modify the destination database. `--writers-stopped` is an operator assertion, not a command that stops other processes. The tool refuses an observed held writer lock, but every external writer must also be stopped.

The restore output includes its validation result. Compare the migration history and sampled group/template records in the isolated rehearsal. `php bin/health.php --db=/separate/path/restored.sqlite --deep` also checks database integrity, but **still requires recent live permission and notification heartbeats**; a non-running restored copy normally exits 1. That alone is not evidence of database corruption. Do not fabricate heartbeats or start a production-connected bot merely to make an offline check green.

After an explicitly authorized startup, run the real instance's health check:

```sh
docker compose -p shabbat-test exec -T shabbat php bin/health.php --deep
```

## Session state and rollback

Stop the bot and all library writers before taking a consistent private snapshot of the session and BroadcastManager state. Preserve the named `bot-data` and `madeline-session` volumes associated with your Compose project. Do not remove volumes during upgrade or rollback. Store an off-server private copy through your server's backup mechanism.

Retain matching application source, `composer.lock`, patch set, database, session and library-state snapshots. Reverting only PHP files after schema changes is not a complete rollback. Before starting an older compatible build, stop the new writer, preserve current evidence, resolve outstanding bot-owned permission changes and restore the reviewed matching state. Run only one instance against a bot/session/database.

Restoring files cannot reverse Telegram permission changes or messages already accepted by Telegram. A restored database may predate current remote permissions; fresh readback and visible conflict resolution remain necessary. Older unverified snapshots require administrator review rather than guessed reopening.
