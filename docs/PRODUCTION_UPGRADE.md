# Production upgrade from the legacy file-based bot

[English overview](../README.md) | [עברית](../readme-he.md) | [Import mapping](MIGRATION.md) | [Backup and restore](BACKUP_RESTORE.md) | [Permission recovery](PERMISSION_RECOVERY.md)

This procedure is for the existing Docker Compose installation at **`/root/shabat`** (one `b`), upgrading the file-based implementation at historical baseline `2976ab8339f489d933c7246e0cb27f3b0e4aa697` to **`3.0.0`**. The final source directory remains `/root/shabat`. `/root/shabbat-test` is a separate test installation and is not a migration source or destination.

**לשרת הקיים `/root/shabat`: בונים את הגרסה החדשה בנפרד, עוצרים את הבוט הישן, מגבים את כל התיקייה, מייבאים ובודקים, ורק אז מחליפים את תיקיית המקור ומפעילים מופע יחיד. הרשאות ישנות עשויות לדרוש אישור ידני של מנהל קבוצה; ייבוא תקין אינו מבטיח שחזור אוטומטי שלהן.**

This targets the stable 3.0.0 release. On dev.9, the owner confirmed live locking, unlocking, original-permission restoration and restart. That confirmation does not cover automatic holy-day transitions, load capacity, a long-running soak or this production import. Complete the remaining checks relevant to your deployment with a separate test bot, and schedule the monitored production window deliberately. These are operator instructions; writing this document does not execute a deployment. The commands below are for **Bash on the Linux server as root**, with Docker daemon access, Compose v2, `curl`, `unzip`, `tar`, `sha256sum` and sufficient free storage. Every `run ... php ...` maintenance command explicitly replaces the image's normal bot command; it does not log in to Telegram. Only the final `up` starts the new bot.

## 1. Understand what moves and what requires review

The historical Compose service is named `shabbat` and bind-mounts the entire project directory as `/app`. The old bot reads `.env`, stores group settings below `data/`, starts MadelineProto at `bot.shabbat`, and points BroadcastManager at `data/`. Root files such as `ids.txt`, `state_open.txt` and `*_lock*.txt` also matter. A copy of `data/DBgroups.txt` alone is not a backup.

The new Compose service is also `shabbat`, but source/vendor are built into the image. Its separate named volumes hold `/app/data` and `/app/runtime`; the session prefix is `/app/runtime/bot.shabbat`. This procedure uses a distinct production Compose project, so its volumes cannot reuse test or old project state accidentally. All future commands must keep that project name.

| Legacy material | Treatment |
| --- | --- |
| Entire old folder, including `.env`, vendor, session files/directories, data and root state | Preserved in a private stopped snapshot and a retained old source directory. |
| `data/DBgroups.txt` | Unique valid entries become registered groups. Existing new-database settings are preserved on re-import. |
| Alert flags, opening/closing text, entities, media file IDs and buttons | Imported according to the exact whitelist in [migration](MIGRATION.md); malformed/unsupported records produce warnings. |
| Root `ids.txt`, operator drafts and supported global draft files | Imported without granting administrator authority. Configure trusted owners through `ADMIN`. |
| MadelineProto session prefix `bot.shabbat*` | Copied intact to the new session volume while all old writers are stopped. It is not interpreted by the importer. |
| Entire legacy `data/` | Also copied to the new data volume to retain BroadcastManager history and separate library state. |
| `chatb1.json` and old global lock/date files | Retained as recovery evidence, never accepted as proof of current Telegram permissions or today's calendar state. |

Imported groups keep the legacy Jerusalem/Asia-Jerusalem policy: city-default candle lighting, closure ten minutes before candles, havdalah at 8.5 degrees without an additional opening delay, Hebrew, and existing alert/message flags. They are **active** (`lock` or `lock_messages`); importing is not an automatic switch to off. Daily zmanim, daf, tefillin and Omer remain off for imported groups. Review these defaults if the server contains local modifications to the old implementation.

**Permission acceptance is a separate gate.** Any imported `chatb1.json` is `legacy_unverified`. Even an otherwise open group without a snapshot receives `recovery_required` when a global old state file exists, including a leftover `open_lock.txt`. Such groups require an authorized group administrator to set/check the intended normal permissions in Telegram, refresh **Resolve permission problem**, and explicitly adopt current permissions outside Shabbat/Yom Tov and all active closure windows. The bot will not infer that a checksum makes an old snapshot safe. Do not delete recovery records or global state files to bypass this review.

The old Composer specification used `^8.6` for MadelineProto and `*` for BroadcastManager, without a committed lock. Therefore the historical Git commit does not identify the server's actual installed dependency versions. Preserve its vendor and image; session compatibility must be checked during controlled startup. Keep original session bytes for rollback. Do not delete a session or create a second production-connected session as a troubleshooting shortcut.

Review any pending broadcasts before the window. Copied `scheduled-broadcasts.json` and `self-destruct-jobs.json`, when present, are library state, not SQLite import records. Old schedules lack the new creator audit binding: future unattributed schedules block processing, and due unattributed schedules are cancelled rather than replayed as authorized new work. Due self-destruction jobs can act after startup. Preserve this state and review it privately; do not promise that interrupted broadcasts resume or that first startup has no external effects.

## 2. Set paths and identify the actual production container

Run the blocks in the same root Bash session. The timestamp creates new backup/staging destinations. Do not reuse a partially completed attempt without inspecting its state.

```sh
set -euo pipefail
umask 077
ORIGINAL_DIR=/root/shabat
TEST_DIR=/root/shabbat-test
VERSION=3.0.0
NEW_PROJECT=shabbat-production
ARCHIVE="shabbat-${VERSION}.zip"
test "$VERSION" = 3.0.0
test "$NEW_PROJECT" = shabbat-production
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
UPLOAD_DIR=/root/shabbat-release-upload
RELEASE_DIR="/root/shabbat-releases/$VERSION-$STAMP"
BACKUP_DIR="/root/shabbat-backups/$STAMP"
RETIRED_DIR="/root/shabat-retired-$STAMP"
test "$(readlink -f -- "$ORIGINAL_DIR")" = /root/shabat
test "$ORIGINAL_DIR" != "$TEST_DIR"
test -f "$ORIGINAL_DIR/docker-compose.yml"
test -f "$ORIGINAL_DIR/bot.php"
test -f "$ORIGINAL_DIR/.env"
test -f "$ORIGINAL_DIR/data/DBgroups.txt"
test ! -e "$RELEASE_DIR"
test ! -e "$BACKUP_DIR"
test ! -e "$RETIRED_DIR"
df -h /root
du -sh "$ORIGINAL_DIR"
docker compose version
docker ps -a --filter label=com.docker.compose.service=shabbat \
  --format '{{.ID}}  {{.Names}}  {{.Status}}'
```

Choose the existing production container from this list; never choose the test container. The next placeholder must be replaced with its actual ID. The inspection prints only mount paths and Compose labels, not resolved environment secrets.

```sh
OLD_CONTAINER_ID='REPLACE_WITH_VERIFIED_PRODUCTION_CONTAINER_ID'
docker inspect --format '{{range .Mounts}}{{println .Type .Source "->" .Destination}}{{end}}' "$OLD_CONTAINER_ID"
docker inspect --format '{{index .Config.Labels "com.docker.compose.project"}} / {{index .Config.Labels "com.docker.compose.service"}}' "$OLD_CONTAINER_ID"
test "$(docker inspect --format '{{index .Config.Labels "com.docker.compose.service"}}' "$OLD_CONTAINER_ID")" = shabbat
test "$(docker inspect --format '{{range .Mounts}}{{if eq .Destination "/app"}}{{.Type}}:{{.Source}}{{end}}{{end}}' "$OLD_CONTAINER_ID")" = "bind:$ORIGINAL_DIR"
OLD_PROJECT="$(docker inspect --format '{{index .Config.Labels "com.docker.compose.project"}}' "$OLD_CONTAINER_ID")"
OLD_IMAGE_ID="$(docker inspect --format '{{.Image}}' "$OLD_CONTAINER_ID")"
OLD_RESTART_POLICY="$(docker inspect --format '{{.HostConfig.RestartPolicy.Name}}' "$OLD_CONTAINER_ID")"
test -n "$OLD_PROJECT"
test "$OLD_PROJECT" != "$NEW_PROJECT"
test "$OLD_RESTART_POLICY" = always
install -d -m 700 "$UPLOAD_DIR" "$BACKUP_DIR"
```

Verify that the production container's `/app` mount is a **bind mount from `/root/shabat`**. The baseline uses restart policy `always`; if the final assertion fails, record/review the actual policy instead of blindly changing it. Any extra mounts, external session/database services, symlinks to private storage, a local SQLite database, custom Compose overrides, or additional production writers require a tailored backup before proceeding. This guide covers the inspected file-based layout. Inventory other services/supervisors that could restart the old bot and suspend them for the window. Do not alter the test installation.

Record the old runtime versions while it is still running; this PHP command only reads Composer metadata:

```sh
docker exec "$OLD_CONTAINER_ID" php -r '
require "/app/vendor/autoload.php";
echo "PHP ", PHP_VERSION, PHP_EOL;
foreach (["danog/madelineproto", "wizardloop/broadcastmanager"] as $name) {
    echo $name, " ", Composer\InstalledVersions::getPrettyVersion($name), " ",
        Composer\InstalledVersions::getReference($name), PHP_EOL;
}' > "$BACKUP_DIR/old-runtime-versions.txt"
printf '%s\n' "$OLD_CONTAINER_ID" > "$BACKUP_DIR/old-container-id.txt"
printf '%s\n' "$OLD_PROJECT" > "$BACKUP_DIR/old-compose-project.txt"
printf '%s\n' "$OLD_IMAGE_ID" > "$BACKUP_DIR/old-image-id.txt"
printf '%s\n' "$OLD_RESTART_POLICY" > "$BACKUP_DIR/old-restart-policy.txt"
```

Ensure storage can hold the complete old folder twice, its saved image, the new image and new data/session volumes. Keep these backup files private and outside every Docker build context.

## 3. Download, verify and build the reviewed release beside production

The deployment asset is `shabbat-3.0.0.zip`, with files directly at its root and no installed vendor. The separate corresponding-source ZIP is for source inspection; it is not the deployment archive. Use the assets attached to the stable `v3.0.0` release. The checksum block requires exactly one valid SHA-256 record for the expected deployment filename; missing or duplicate records stop the procedure.

```sh
RELEASE_URL="https://github.com/WizardLoop/shabbat/releases/download/v${VERSION}"
curl --fail --location --proto '=https' --tlsv1.2 \
  "$RELEASE_URL/$ARCHIVE" -o "$UPLOAD_DIR/$ARCHIVE"
curl --fail --location --proto '=https' --tlsv1.2 \
  "$RELEASE_URL/SHA256SUMS" -o "$UPLOAD_DIR/SHA256SUMS"
(
  cd "$UPLOAD_DIR"
  awk -v asset="$ARCHIVE" '
    $2 == asset {
      if (NF != 2 || length($1) != 64 || $1 ~ /[^0-9a-f]/) exit 1
      print $1 "  " asset
      count++
    }
    END { if (count != 1) exit 1 }
  ' SHA256SUMS > deployment.sha256
  sha256sum --check deployment.sha256
)
install -d -m 700 "$RELEASE_DIR"
unzip -q "$UPLOAD_DIR/$ARCHIVE" -d "$RELEASE_DIR"
test "$(cat "$RELEASE_DIR/VERSION")" = "$VERSION"
test -f "$RELEASE_DIR/composer.lock"
test -f "$RELEASE_DIR/build-info.json"
cp "$RELEASE_DIR/.env.example" "$RELEASE_DIR/.env"
chmod 600 "$RELEASE_DIR/.env"
```

If uploading manually, upload the same two release assets to `UPLOAD_DIR` and begin with the checksum block. Do not unpack over the running old folder or copy the old installed vendor into the new source. Retain the supplied `build-info.json`; it records the release build's actual source identity. Do not substitute the historical baseline SHA or the test build's metadata. Leave `BUILD_COMMIT` absent from the new `.env`; the build block also unsets an inherited shell value so a stale test commit cannot override the archive identity.

Edit the **new** `.env` privately. Copy the existing production `API_ID`, `API_HASH`, `BOT_TOKEN` and trusted `ADMIN` values from the old private file without printing them or putting them in shell history. Retain the same bot identity. Add the GeoNames account and current configuration deliberately; [configuration](CONFIGURATION.md) documents every field. Use plain `KEY=value` syntax supported by both Compose and the PHP reader. Set these public/container paths exactly:

```dotenv
APP_DB_PATH=/app/data/shabbat.sqlite
DATA_DIR=/app/data
SESSION_PATH=/app/runtime/bot.shabbat
HEARTBEAT_PATH=/app/data/scheduler-heartbeat.json
LEGACY_DATA_PATH=.
SOURCE_URL=https://github.com/WizardLoop/shabbat/releases/tag/v3.0.0
```

Compose explicitly fixes `DATA_DIR` and `SESSION_PATH`; the other paths above avoid a stale host/test path in `.env`. The release page must expose the matching corresponding-source asset before production service. Do not use test bot credentials. Never share `docker compose config` output without `--quiet`.

```sh
new_compose() {
  docker compose --project-name "$NEW_PROJECT" \
    --project-directory "$RELEASE_DIR" --file "$RELEASE_DIR/docker-compose.yml" "$@"
}
if docker volume inspect "${NEW_PROJECT}_bot-data" >/dev/null 2>&1 \
  || docker volume inspect "${NEW_PROJECT}_madeline-session" >/dev/null 2>&1; then
  printf '%s\n' 'STOP: this production project already has volumes; inspect the earlier attempt.' >&2
  exit 1
fi
unset BUILD_COMMIT
new_compose config --quiet
new_compose build shabbat
new_compose run --rm --no-deps shabbat php scripts/verify-patches.php
new_compose run --rm --no-deps shabbat php bin/self-check.php --release
new_compose run --rm --no-deps shabbat php bin/runtime-permissions.php --require-non-root
new_compose run --rm --no-deps shabbat php bin/check-calendar.php
```

These checks use the new image/volumes and leave the old bot running. The calendar check contacts public providers, not Telegram. Stop on any failure; no production cutover has happened yet. Do not run `up`, `php bot.php`, or a session diagnostic against the production credentials during this stage.

## 4. Stop the single production writer and preserve the entire old installation

Choose a weekday maintenance window outside religious/operational closure periods and pending broadcasts, with a group administrator available for recovery. Complete or review old in-flight work first. From this step until the explicit new startup, production is offline.

```sh
test "$(readlink -f -- "$ORIGINAL_DIR")" = /root/shabat
test ! -e "$ORIGINAL_DIR/data/shabbat.sqlite"
docker update --restart=no "$OLD_CONTAINER_ID"
docker stop --time 120 "$OLD_CONTAINER_ID"
test "$(docker inspect --format '{{.State.Running}}' "$OLD_CONTAINER_ID")" = false
test "$(docker inspect --format '{{.State.Restarting}}' "$OLD_CONTAINER_ID")" = false
docker inspect --format 'exit={{.State.ExitCode}} oom={{.State.OOMKilled}}' "$OLD_CONTAINER_ID"
install -d -m 700 "$BACKUP_DIR/legacy"
cp -a -- "$ORIGINAL_DIR/." "$BACKUP_DIR/legacy/"
(
  cd "$ORIGINAL_DIR"
  find . -type f -print0 | sort -z | xargs -0 sha256sum
) > "$BACKUP_DIR/legacy-files.sha256"
(
  cd "$BACKUP_DIR/legacy"
  sha256sum --check "$BACKUP_DIR/legacy-files.sha256"
) > "$BACKUP_DIR/legacy-copy-verification.txt"
docker image save --output "$BACKUP_DIR/old-image.tar" "$OLD_IMAGE_ID"
sha256sum "$BACKUP_DIR/old-image.tar" > "$BACKUP_DIR/old-image.tar.sha256"
```

The restart-policy change prevents the old `restart: always` container from returning after a Docker daemon restart during cutover. It does not stop an external supervisor; verify every other writer is stopped too. An OOM/forced termination or a failed snapshot/hash check requires investigation before continuing. The SQLite-absence check deliberately stops this file-based procedure if an application database already exists.

Keep the old stopped container and image. Do not run `down`, remove volumes, prune images, or replace the original folder yet. Back up the protected directory off-server using your private backup mechanism. Its credentials, session, IDs, drafts and permission snapshots must never become release assets or public issue attachments.

## 5. Copy stopped state into separate new volumes, then preview the import

The root user below is used only for controlled offline copying/import, because the protected legacy snapshot may not be readable by UID 10001. The old snapshot is mounted read-only. Nothing is copied into the new image/build context. The normal bot will run as UID/GID 10001 after ownership is set explicitly.

```sh
new_compose run --rm --no-deps --user 0:0 \
  --volume "$BACKUP_DIR/legacy:/legacy:ro" shabbat sh -ec '
  test -z "$(find /app/data -mindepth 1 -maxdepth 1 -print -quit)"
  test -z "$(find /app/runtime -mindepth 1 -maxdepth 1 -print -quit)"
  test ! -e /legacy/data/shabbat.sqlite
  test ! -e /legacy/data/upgrade-backups
  cp -a /legacy/data/. /app/data/
  set -- /legacy/bot.shabbat*
  test -e "$1"
  cp -a "$@" /app/runtime/
  mkdir -m 700 /app/data/upgrade-backups
'
new_compose run --rm --no-deps --user 0:0 shabbat \
  php bin/migrate.php --writers-stopped --db /app/data/shabbat.sqlite \
  > "$BACKUP_DIR/migrate.json"
new_compose run --rm --no-deps --user 0:0 \
  --volume "$BACKUP_DIR/legacy:/legacy:ro" shabbat \
  php bin/import-legacy.php --db /app/data/shabbat.sqlite --legacy /legacy --dry-run \
  > "$BACKUP_DIR/import-preview.json"
```

A missing session prefix, non-empty destination volume, unexpected database, unreadable file or importer error is a stop condition. Do not replace these assertions with deletion commands. Inspect the earlier attempt or custom server layout. No real session has been opened by these maintenance commands.

Read the private JSON preview. Compare its unique group IDs and count with the old subscription list, and sample opening/closing messages, buttons, media IDs, alert flags and private drafts against the snapshot. Inspect **every warning and recovery entry**, not only the count. Invalid IDs, malformed JSON, unsupported buttons, oversized drafts and missing originals must be accounted for. A preview can report the expected fixed Jerusalem timing warning; that does not resolve unrelated warnings. The importer covers the whitelist documented in [migration](MIGRATION.md), not arbitrary local additions to the old bot.

Dry-run is supported and does not apply application records. It is not a full transaction rehearsal: some normalized private-draft handling occurs only during apply. The apply operation makes a verified pre-import SQLite backup but the complete legacy import is not one all-or-nothing transaction. If apply fails, keep the new bot stopped, preserve its report/state, and review or restore the verified pre-import database before retrying. Re-import preserves existing records; it does not repair a malformed record by overwriting an existing preference.

## 6. Apply and verify every registered group before starting the bot

After accepting the preview and planning any required permission review:

```sh
new_compose run --rm --no-deps --user 0:0 \
  --volume "$BACKUP_DIR/legacy:/legacy:ro" shabbat \
  php bin/import-legacy.php --db /app/data/shabbat.sqlite --legacy /legacy \
  --apply --writers-stopped --backup-dir /app/data/upgrade-backups \
  > "$BACKUP_DIR/import-apply.json"
```

The next snippet uses the project's real database API to check schema checksums, integrity, foreign keys, exact group-ID equality and one settings row per group. It prints a private inventory for human review and fails on invalid IDs, missing groups, unexpected groups or an empty source list. It does not call Telegram or invent a new application CLI option.

```sh
new_compose run --rm --no-deps -T --user 0:0 \
  --volume "$BACKUP_DIR:/review:ro" shabbat php \
  > "$BACKUP_DIR/import-verification.json" <<'PHP'
<?php
require '/app/vendor/autoload.php';
use Shabbat\Storage\Database;
$db = Database::open('/app/data/shabbat.sqlite');
$db->assertCurrentSchema();
$checks = $db->integrity();
if ($checks['integrity_check'] !== ['ok'] || $checks['foreign_key_check'] !== []) {
    throw new RuntimeException('Database integrity or foreign-key check failed.');
}
$expected = [];
foreach (preg_split('/\R/', file_get_contents('/review/legacy/data/DBgroups.txt')) as $line) {
    $id = trim($line);
    if ($id === '') { continue; }
    if (!preg_match('/^-?[0-9]{1,20}$/D', $id)) {
        throw new RuntimeException('Invalid legacy group identifier requires review.');
    }
    $expected[$id] = $id;
}
$expected = array_values($expected);
sort($expected, SORT_STRING);
$actual = $db->pdo()->query('SELECT group_id FROM groups')->fetchAll(PDO::FETCH_COLUMN);
sort($actual, SORT_STRING);
if ($expected === [] || $actual !== $expected) {
    throw new RuntimeException('Imported group IDs do not exactly match the non-empty legacy list.');
}
$settings = $db->pdo()->query('SELECT group_id,mode,language,location_name,timezone_id,candle_minutes,havdalah_method,havdalah_value,close_before_candles_minutes,open_after_havdalah_minutes,times_alert_enabled,close_message_enabled,open_message_enabled,zmanim_enabled,daf_enabled,tefillin_enabled,omer_enabled FROM group_settings ORDER BY group_id')->fetchAll();
if (count($settings) !== count($expected)) {
    throw new RuntimeException('Missing group settings.');
}
$counts = [];
foreach (['groups','group_settings','users','message_templates','admin_state','known_broadcast_peers','legacy_imports'] as $table) {
    $counts[$table] = (int) $db->pdo()->query('SELECT COUNT(*) FROM '.$table)->fetchColumn();
}
$recovery = $db->pdo()->query('SELECT group_id,status,snapshot_provenance,last_error FROM group_lock_state ORDER BY group_id')->fetchAll();
echo Database::encode(['checks'=>$checks,'counts'=>$counts,'group_settings'=>$settings,'permission_review'=>$recovery]), PHP_EOL;
PHP
```

Review the settings against the source flags and inspect normalized templates through the backed-up database/private reports. Template totals include both group templates and personal drafts, so they need not equal the number of groups or the preview's group-template count. Legacy-selected groups and saved drafts confer no administrator authority. Keep the original files as evidence even after a successful import.

Preserve a verified post-import SQLite backup without any retention deletion. This snippet calls the existing `BackupService::backup` directly to a new filename; it does not invoke the pruning helper. It also copies the migration/import backups out of the volume and sets ownership on the **new** data/session volumes only.

```sh
new_compose run --rm --no-deps --user 0:0 shabbat php -r '
require "/app/vendor/autoload.php";
$db = Shabbat\Storage\Database::open("/app/data/shabbat.sqlite");
$result = (new Shabbat\Storage\BackupService())->backup($db, "/app/data/upgrade-backups/post-import.sqlite");
echo Shabbat\Storage\Database::encode($result), PHP_EOL;
' > "$BACKUP_DIR/post-import-backup.json"
install -d -m 700 "$BACKUP_DIR/sqlite"
new_compose run --rm --no-deps --user 0:0 \
  --volume "$BACKUP_DIR/sqlite:/export" shabbat sh -ec '
  cp -a /app/data/upgrade-backups/. /export/
  chown -R 10001:10001 /app/data /app/runtime
'
new_compose run --rm --no-deps shabbat php bin/runtime-permissions.php --require-non-root
new_compose run --rm --no-deps shabbat php scripts/verify-patches.php
new_compose run --rm --no-deps shabbat php bin/self-check.php --release
```

Rehearse the actual restore into a new disposable destination in a maintenance container. It validates the backup and creates an isolated SQLite copy; it does not replace `/app/data/shabbat.sqlite` or open the bot session.

```sh
new_compose run --rm --no-deps shabbat php bin/restore.php \
  --backup /app/data/upgrade-backups/post-import.sqlite \
  --db /tmp/shabbat-restore-rehearsal.sqlite --writers-stopped \
  > "$BACKUP_DIR/restore-rehearsal.json"
```

Accept the restore output and the complete source/private-state backup before proceeding. `health.php`, even with `--deep`, also needs recent live scheduler heartbeats; an offline restore cannot satisfy that live-health gate. Use the structural checks above before startup, then the real live probe afterwards.

## 7. Put the reviewed source at `/root/shabat`

Proceed only after all offline checks and backups pass, every import warning is understood, and the administrator has a plan for each recovery entry. The old bot remains stopped. The two renames preserve the complete original directory and move the already-built source into the requested final path; they do not overlay old vendor or data with new files.

```sh
test "$(docker inspect --format '{{.State.Running}}' "$OLD_CONTAINER_ID")" = false
test "$(docker inspect --format '{{.HostConfig.RestartPolicy.Name}}' "$OLD_CONTAINER_ID")" = no
test "$(readlink -f -- "$ORIGINAL_DIR")" = /root/shabat
case "$(readlink -f -- "$RELEASE_DIR")" in
  /root/shabbat-releases/*) ;;
  *) printf '%s\n' 'Unexpected staging path; stop.' >&2; exit 1 ;;
esac
case "$RETIRED_DIR" in
  /root/shabat-retired-*) ;;
  *) printf '%s\n' 'Unexpected retained-source path; stop.' >&2; exit 1 ;;
esac
test ! -e "$RETIRED_DIR"
mv -T -- "$ORIGINAL_DIR" "$RETIRED_DIR"
mv -T -- "$RELEASE_DIR" "$ORIGINAL_DIR"
RELEASE_DIR="$ORIGINAL_DIR"
cd "$ORIGINAL_DIR"
new_compose config --quiet
printf '%s\n' "$RETIRED_DIR" > "$BACKUP_DIR/retired-source-path.txt"
printf '%s\n' "$NEW_PROJECT" > "$BACKUP_DIR/new-compose-project.txt"
```

If the second rename fails, stop and inspect both paths. The original is preserved at `RETIRED_DIR`; return it to `/root/shabat` only after verifying that the destination is absent. Do not merge directory contents or use an overwrite operation to conceal a partial cutover.

**The stopped old container still has a bind mount whose source is `/root/shabat`. It must not be started while that path contains the new source.** Its old image/vendor assumptions do not match the new layout. The rollback section restores the old directory before restarting that container.

## 8. Start exactly one production instance and accept it

This step connects to Telegram, sends the existing administrator startup notification, and enables all imported active group behavior. It is not a harmless smoke test. Confirm the bot identity, the reviewed source URL, the maintenance window and the intended permissions once more.

```sh
test "$(docker inspect --format '{{.State.Running}}' "$OLD_CONTAINER_ID")" = false
new_compose up -d --no-build shabbat
new_compose ps
new_compose logs --tail=120 shabbat
```

Keep logs private. Wait for completed permission and notification scheduler passes, then run:

```sh
new_compose exec -T shabbat php bin/health.php --deep
new_compose ps
```

Both heartbeat ages must be current; allow initial startup/provider work to complete and investigate repeated failure. A green health probe establishes the checks it reports, not that every group has resolved its imported permission debt. Do not start a second process to investigate session/authentication errors. Stop the new service and inspect the preserved versions/session state first; rollback remains available.

As the configured bot owner, use `/version` or `/source` privately to verify release identity, `/admin` and its diagnostics to inspect registered groups, then `/settings` for a representative group and every group marked for recovery. Existing imported groups need no `/add`. Confirm group count, local time/location, original notification flags and custom message previews without sending a mass broadcast or using `/closegroups` / `/opengroups` as a test.

For **each** unresolved legacy permission record, a current group administrator must follow [permission recovery](PERMISSION_RECOVERY.md): outside protected periods, configure the actual intended normal group permissions in Telegram; refresh the bot's **Resolve permission problem** panel; review the named group; and explicitly choose **Use current permissions as normal**. A legacy/unverified snapshot cannot be used by the restore button. Adoption does not itself send a restriction update. Re-check diagnostics; leave unexplained mismatches unresolved rather than treating generally open permissions as the intended baseline.

Review copied broadcast history and any cancelled/blocked schedule. Do not recreate an interrupted broadcast without checking which messages Telegram already accepted. Monitor both heartbeats, pending permission recovery, queue age, delivery delay and private logs through startup and the first real scheduled close/open cycle. Investigate missing work, repeated retries, provider failures or growing queues before considering the cutover accepted. Retain the old source/session/private backup until that cycle and recovery behavior have been accepted; the earlier dev.9 manual/live confirmation does not substitute for this observation.

The permanent production commands are now run **from `/root/shabat`, with the recorded project name**:

```sh
cd /root/shabat
docker compose --project-name shabbat-production ps
docker compose --project-name shabbat-production logs --tail=120 shabbat
docker compose --project-name shabbat-production exec -T shabbat php bin/health.php --deep
```

For an intentional later stop, use `docker compose --project-name shabbat-production stop shabbat`; for an approved restart, use `docker compose --project-name shabbat-production restart shabbat`. The new persistent volumes are `shabbat-production_bot-data` and `shabbat-production_madeline-session`. Keep both, the private `.env`, matching source/lock/patches and corresponding-source identity in the normal backup policy. A later SQLite-version upgrade follows the stopped-writer backup/migration instructions, not this legacy import again.

## 9. Roll back without mixing old and new state

Before any new startup, rollback can return the preserved old directory/container directly. **After the new bot has contacted Telegram, rollback also requires a remote-state review:** restoring files does not undo sent messages, updated permissions, broadcast actions or Telegram session changes. Settle any new bot-owned permission changes and stop new activity before restoring older state. Do not copy the new SQLite database or newly written session over the old file-based installation.

If the maintenance attempt stopped **before either rename in step 7**, the original folder is still in place. Verify that no new production service is running and that no retained-source directory was created, then restore the old restart policy and start its existing container:

```sh
test ! -e "$RETIRED_DIR"
test "$(readlink -f -- "$ORIGINAL_DIR")" = /root/shabat
test -z "$(docker ps --filter label=com.docker.compose.project="$NEW_PROJECT" --filter label=com.docker.compose.service=shabbat --quiet)"
docker update --restart="$OLD_RESTART_POLICY" "$OLD_CONTAINER_ID"
docker start "$OLD_CONTAINER_ID"
```

For this pre-swap rollback, stop here. The remaining rollback blocks apply after step 7 replaced the directory.

If the new service was started, stop it and disable automatic restart before touching directories. These commands run in the same session, with `RELEASE_DIR=/root/shabat` after the swap:

```sh
NEW_CONTAINER_ID="$(new_compose ps -a -q shabbat)"
if [ -n "$NEW_CONTAINER_ID" ]; then
  docker update --restart=no "$NEW_CONTAINER_ID"
fi
new_compose stop --timeout 120 shabbat
if [ -n "$NEW_CONTAINER_ID" ]; then
  test "$(docker inspect --format '{{.State.Running}}' "$NEW_CONTAINER_ID")" = false
fi
test "$(docker inspect --format '{{.State.Running}}' "$OLD_CONTAINER_ID")" = false
```

Preserve the stopped new volumes for diagnosis. The helper command only reads the volumes and writes private tar files outside the source tree; it does not launch the bot. Use a new destination if this rollback capture has already been taken.

```sh
test ! -e "$BACKUP_DIR/failed-new-state"
install -d -m 700 "$BACKUP_DIR/failed-new-state"
new_compose run --rm --no-deps --user 0:0 \
  --volume "$BACKUP_DIR/failed-new-state:/export" shabbat sh -ec '
  tar -cpf /export/new-data.tar -C /app/data .
  tar -cpf /export/new-session.tar -C /app/runtime .
'
sha256sum "$BACKUP_DIR/failed-new-state/"*.tar \
  > "$BACKUP_DIR/failed-new-state/SHA256SUMS"
```

After reviewing any remote effects, return the preserved old folder to its original path. This block applies only after the completed step-7 swap; use the separate pre-swap branch above if no rename occurred.

```sh
FAILED_SOURCE_DIR="/root/shabat-failed-$STAMP"
test "$(readlink -f -- "$ORIGINAL_DIR")" = /root/shabat
test -d "$RETIRED_DIR"
case "$RETIRED_DIR" in /root/shabat-retired-*) ;; *) exit 1 ;; esac
case "$FAILED_SOURCE_DIR" in /root/shabat-failed-*) ;; *) exit 1 ;; esac
test ! -e "$FAILED_SOURCE_DIR"
mv -T -- "$ORIGINAL_DIR" "$FAILED_SOURCE_DIR"
mv -T -- "$RETIRED_DIR" "$ORIGINAL_DIR"
test -f "$ORIGINAL_DIR/.env"
test -f "$ORIGINAL_DIR/data/DBgroups.txt"
docker update --restart="$OLD_RESTART_POLICY" "$OLD_CONTAINER_ID"
docker start "$OLD_CONTAINER_ID"
docker logs --tail=120 "$OLD_CONTAINER_ID"
```

This restarts the exact retained old container/image against its original code, vendor, `.env`, data and pre-cutover session. Do not use `compose up --build` or pull the old mutable image for rollback. If the old image/container or retained directory is missing, stop and restore from the private full-folder/image backup with a separately reviewed recovery procedure; do not improvise a partial rollback.

Keep the failed new source, both new volumes and all reports. A new attempt must start with an inspected state and a fresh stopped snapshot; the existing-destination guards are intentional. If authentication or permissions changed on Telegram during the new run, the old runtime still needs explicit review even though its local bytes were preserved.

## Acceptance record

Record the verified release checksum/commit, the old runtime versions/container/image, backup paths and hashes, import counts/warnings, exact group-ID comparison, schema/integrity/restore results, final Compose project/volume names, live health, and each group's permission-review outcome. Record unresolved limitations honestly. Keep identifiers, session material, private logs, credentials and backup contents outside GitHub and public release assets.