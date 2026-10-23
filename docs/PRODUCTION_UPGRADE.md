# Production upgrade to 3.1.1

[עברית](PRODUCTION_UPGRADE_HE.md) · [README](../README.md) · [Import mapping](MIGRATION.md) · [Permission recovery](PERMISSION_RECOVERY.md)

This is a manual runbook, not an executed deployment. Use the released, verified 3.1.1 assets after required checks pass. The requested path is **/root/shabbat**; older instructions used **/root/shabat**. Verify the real container and directory before changing either. Never use the test bot's data.

Commands cover the legacy whole-project bind mount or standard 3.0 Docker data/session volumes. The 3.0 path probe only reads configuration; it opens no API/session. A custom session name or lock-only remnants stop this procedure. Custom mounts, external storage, symlinks to private data or another writer need a tailored backup first. Run server blocks in one **root Bash session** with Docker/Compose, unzip, GNU coreutils and sufficient storage. Keep backups outside source/build directories. Only the final start connects the new bot to Telegram.

The new image uses PHP **8.4.27**. Manual runtimes require **8.3.36+, 8.4.27+ or 8.5.12+** within those branches; see the [22 October 2026 security baseline](INSTALLATION.md#php-security-baseline). Preserve the old image for rollback, but do not treat an older interpreter as meeting the new baseline.

For an installation already upgraded to 3.1.0 with SQLite, use [updating an existing SQLite deployment](INSTALLATION.md#updating-an-existing-sqlite-deployment), retaining its Compose project, volumes and session. Do not import legacy data again or reset queues. Prepared Daf repair happens only on the normal retry path while a job remains eligible. The full staged cutover below is for the separate older-installation path.

## 1. Upload from your computer

Download the deployment ZIP and SHA256SUMS from the matching GitHub release. In the computer's terminal, replace SERVER with your SSH hostname/IP. SFTP can upload the same files to the same protected destination.

~~~sh
ssh root@SERVER "install -d -m 700 /root/shabbat-upload-3.1.1"
scp shabbat-3.1.1.zip SHA256SUMS root@SERVER:/root/shabbat-upload-3.1.1/
~~~

## 2. Verify production and set protected paths

On the server, list containers and inspect the selected production container. Replace the ID placeholder and change ORIGINAL_DIR to /root/shabat **only if that is the verified real path**. Assertions reject a different path/layout. Review the displayed mounts; no environment secrets are printed.

~~~sh
set -euo pipefail
umask 077
docker ps -a --filter label=com.docker.compose.service=shabbat \
  --format '{{.ID}}  {{.Names}}  {{.Status}}'
ORIGINAL_DIR=/root/shabbat
OLD_CONTAINER_ID=REPLACE_WITH_VERIFIED_PRODUCTION_CONTAINER_ID
docker inspect --format '{{range .Mounts}}{{println .Type .Source "->" .Destination}}{{end}}' "$OLD_CONTAINER_ID"
docker inspect --format '{{index .Config.Labels "com.docker.compose.project.working_dir"}}' "$OLD_CONTAINER_ID"
case "$ORIGINAL_DIR" in /root/shabbat|/root/shabat) ;; *) exit 1 ;; esac
test "$(readlink -f -- "$ORIGINAL_DIR")" = "$ORIGINAL_DIR"
test "$(docker inspect --format '{{index .Config.Labels "com.docker.compose.project.working_dir"}}' "$OLD_CONTAINER_ID")" = "$ORIGINAL_DIR"
test "$(docker inspect --format '{{index .Config.Labels "com.docker.compose.service"}}' "$OLD_CONTAINER_ID")" = shabbat
test -f "$ORIGINAL_DIR/.env"
test -f "$ORIGINAL_DIR/docker-compose.yml"
OLD_PROJECT="$(docker inspect --format '{{index .Config.Labels "com.docker.compose.project"}}' "$OLD_CONTAINER_ID")"
OLD_IMAGE_ID="$(docker inspect --format '{{.Image}}' "$OLD_CONTAINER_ID")"
OLD_RESTART_POLICY="$(docker inspect --format '{{.HostConfig.RestartPolicy.Name}}' "$OLD_CONTAINER_ID")"
case "$OLD_RESTART_POLICY" in always|unless-stopped|no) ;; *) echo 'Review nonstandard restart policy first.' >&2; exit 1 ;; esac
APP_MOUNT="$(docker inspect --format '{{range .Mounts}}{{if eq .Destination "/app"}}{{.Type}}:{{.Source}}{{end}}{{end}}' "$OLD_CONTAINER_ID")"
MOUNT_DESTINATIONS="$(docker inspect --format '{{range .Mounts}}{{println .Destination}}{{end}}' "$OLD_CONTAINER_ID" | sed '/^$/d' | sort)"
if [ "$APP_MOUNT" = "bind:$ORIGINAL_DIR" ] && [ "$MOUNT_DESTINATIONS" = /app ]; then
  LAYOUT=legacy
  test -s "$ORIGINAL_DIR/data/DBgroups.txt"
  test ! -e "$ORIGINAL_DIR/data/shabbat.sqlite"
elif [ -z "$APP_MOUNT" ] && [ "$MOUNT_DESTINATIONS" = "$(printf '/app/data\n/app/runtime')" ]; then
  LAYOUT=sqlite
  test "$(docker inspect --format '{{range .Mounts}}{{if eq .Destination "/app/data"}}{{.Type}}{{end}}{{end}}' "$OLD_CONTAINER_ID")" = volume
  test "$(docker inspect --format '{{range .Mounts}}{{if eq .Destination "/app/runtime"}}{{.Type}}{{end}}{{end}}' "$OLD_CONTAINER_ID")" = volume
  test "$(cat "$ORIGINAL_DIR/VERSION")" = 3.0.0
  docker exec "$OLD_CONTAINER_ID" php -r '
  require "/app/vendor/autoload.php";
  $paths=Shabbat\Runtime\RuntimePaths::resolve("/app");
  if($paths->dataDirectory!=="/app/data"
    || $paths->databasePath!=="/app/data/shabbat.sqlite"
    || $paths->sessionPath!=="/app/runtime/bot.shabbat") {
    throw new RuntimeException("Custom active data/session paths need a reviewed adaptation.");
  }
  '
else
  echo 'STOP: custom storage layout needs a reviewed backup/cutover.' >&2
  exit 1
fi
VERSION=3.1.1
NEW_PROJECT=shabbat-production-311
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
UPLOAD_DIR=/root/shabbat-upload-3.1.1
RELEASE_DIR="/root/shabbat-stage-$VERSION-$STAMP"
BACKUP_DIR="/root/shabbat-backups/$VERSION-$STAMP"
RETIRED_DIR="$ORIGINAL_DIR.before-$VERSION-$STAMP"
test -n "$OLD_PROJECT"
test "$OLD_PROJECT" != "$NEW_PROJECT"
test ! -e "$RELEASE_DIR"
test ! -e "$BACKUP_DIR"
test ! -e "$RETIRED_DIR"
df -h /root
docker system df
install -d -m 700 "$BACKUP_DIR" "$RELEASE_DIR"
printf '%s\n' "$ORIGINAL_DIR" "$OLD_CONTAINER_ID" "$OLD_PROJECT" "$OLD_IMAGE_ID" "$OLD_RESTART_POLICY" "$LAYOUT" > "$BACKUP_DIR/old-installation.txt"
~~~

## 3. Verify, configure and build in staging

The checksum must pass. Keep archive build-info.json. Edit the new .env privately: copy the same production API_ID/API_HASH/BOT_TOKEN and trusted ADMIN values; preserve relevant custom application settings and configure GeoNames deliberately; container paths are set below. Never print credentials or copy installed vendor. Keep BUILD_COMMIT absent from .env.

~~~sh
(
  cd "$UPLOAD_DIR"
  awk '$2 == "shabbat-3.1.1.zip" {
    if (NF != 2 || length($1) != 64 || $1 ~ /[^0-9a-f]/) exit 1
    print; n++
  } END { if (n != 1) exit 1 }' SHA256SUMS > deployment.sha256
  sha256sum --check deployment.sha256
)
unzip -q "$UPLOAD_DIR/shabbat-3.1.1.zip" -d "$RELEASE_DIR"
test "$(cat "$RELEASE_DIR/VERSION")" = "$VERSION"
test -f "$RELEASE_DIR/build-info.json"
cp "$RELEASE_DIR/.env.example" "$RELEASE_DIR/.env"
chmod 600 "$RELEASE_DIR/.env"
~~~

Use these public container paths in the new .env alongside private credentials:

~~~dotenv
APP_DB_PATH=/app/data/shabbat.sqlite
DATA_DIR=/app/data
SESSION_PATH=/app/runtime/bot.shabbat
HEARTBEAT_PATH=/app/data/scheduler-heartbeat.json
LEGACY_DATA_PATH=.
SOURCE_URL=https://github.com/WizardLoop/shabbat/releases/tag/v3.1.1
~~~

After editing, build and run offline checks. The new project must have no existing volumes; inspect an earlier attempt instead of deleting them. These commands do not start the bot. Ensure space for the entire old folder, data/session snapshots, old image and new image.

~~~sh
new_compose() {
  docker compose --project-name "$NEW_PROJECT" --project-directory "$RELEASE_DIR" \
    --file "$RELEASE_DIR/docker-compose.yml" "$@"
}
if docker volume inspect "$NEW_PROJECT"_bot-data >/dev/null 2>&1 \
  || docker volume inspect "$NEW_PROJECT"_madeline-session >/dev/null 2>&1; then
  echo 'STOP: destination volumes already exist.' >&2
  exit 1
fi
unset BUILD_COMMIT
new_compose config --quiet
new_compose build shabbat
new_compose run --rm --no-deps shabbat php scripts/verify-patches.php
new_compose run --rm --no-deps shabbat php bin/self-check.php --release
new_compose run --rm --no-deps shabbat php bin/igbinary-check.php
new_compose run --rm --no-deps shabbat php bin/runtime-permissions.php --require-non-root
~~~

## 4. Stop the old writer, then back up everything

Choose a weekday window outside closure periods with a group administrator available. Review pending broadcasts/self-destruction jobs and stop external supervisors/other writers. From here production is offline. Preserve the old container, image and volumes. A forced/OOM termination requires investigation before continuing.

~~~sh
docker update --restart=no "$OLD_CONTAINER_ID"
docker stop --time 120 "$OLD_CONTAINER_ID"
test "$(docker inspect --format '{{.State.Running}}' "$OLD_CONTAINER_ID")" = false
test "$(docker inspect --format '{{.State.Restarting}}' "$OLD_CONTAINER_ID")" = false
test "$(docker inspect --format '{{.State.OOMKilled}}' "$OLD_CONTAINER_ID")" = false
test "$(docker inspect --format '{{.State.ExitCode}}' "$OLD_CONTAINER_ID")" != 137
install -d -m 700 "$BACKUP_DIR/source" "$BACKUP_DIR/data" "$BACKUP_DIR/session"
cp -a -- "$ORIGINAL_DIR/." "$BACKUP_DIR/source/"
(
  cd "$ORIGINAL_DIR"
  find . -type f -print0 | sort -z | xargs -0 sha256sum
) > "$BACKUP_DIR/source.sha256"
(cd "$BACKUP_DIR/source"; sha256sum --check "$BACKUP_DIR/source.sha256") > "$BACKUP_DIR/source-check.txt"
docker cp "$OLD_CONTAINER_ID:/app/data/." "$BACKUP_DIR/data/"
if [ "$LAYOUT" = legacy ]; then
  set -- "$BACKUP_DIR/source"/bot.shabbat*
  test -e "$1"
  cp -a -- "$@" "$BACKUP_DIR/session/"
else
  docker cp "$OLD_CONTAINER_ID:/app/runtime/." "$BACKUP_DIR/session/"
  test -s "$BACKUP_DIR/data/shabbat.sqlite"
fi
if [ -d "$BACKUP_DIR/session/bot.shabbat" ]; then
  test -s "$BACKUP_DIR/session/bot.shabbat/safe.php"
elif [ -f "$BACKUP_DIR/session/bot.shabbat" ]; then
  test -s "$BACKUP_DIR/session/bot.shabbat.safe.php"
else
  echo 'STOP: canonical session payload is missing; do not create a new session.' >&2
  exit 1
fi
for part in data session; do
  (cd "$BACKUP_DIR/$part"; find . -type f -print0 | sort -z | xargs -0 sha256sum) > "$BACKUP_DIR/$part.sha256"
done
docker image save --output "$BACKUP_DIR/old-image.tar" "$OLD_IMAGE_ID"
sha256sum "$BACKUP_DIR/old-image.tar" > "$BACKUP_DIR/old-image.tar.sha256"
~~~

The snapshot includes .env, configuration, installed dependencies, data, database/WAL/SHM, session, root state and BroadcastManager files. Keep a protected off-server copy too. SQLite alone or DBgroups.txt alone is insufficient. No new bot/session has started.

## 5. Copy into fresh volumes and prepare the database

The snapshot is mounted read-only. Root is used only for offline copying/maintenance; the bot will run as UID/GID 10001. SHA256 verifies copied data/session before migration.

~~~sh
new_compose run --rm --no-deps --user 0:0 \
  --volume "$BACKUP_DIR:/review:ro" shabbat sh -ec '
  test -z "$(find /app/data -mindepth 1 -maxdepth 1 -print -quit)"
  test -z "$(find /app/runtime -mindepth 1 -maxdepth 1 -print -quit)"
  cp -a /review/data/. /app/data/
  cp -a /review/session/. /app/runtime/
  (cd /app/data; sha256sum --check /review/data.sha256)
  (cd /app/runtime; sha256sum --check /review/session.sha256)
  test ! -e /app/data/upgrade-3.1.1
  mkdir -m 700 /app/data/upgrade-3.1.1
' > "$BACKUP_DIR/copied-state-check.txt"
inventory() {
  new_compose run --rm --no-deps --user 0:0 shabbat php -r '
  require "/app/vendor/autoload.php";
  $db=Shabbat\Storage\Database::open("/app/data/shabbat.sqlite");
  $result=[];
  $tables=$db->pdo()->prepare("SELECT name FROM sqlite_master WHERE type=? ORDER BY name");
  $tables->execute(["table"]);
  foreach($tables->fetchAll(PDO::FETCH_COLUMN) as $name) {
    if($name==="schema_migrations" || str_starts_with($name,"sqlite_")) continue;
    if(!preg_match("/^[a-z_][a-z0-9_]*$/D",$name)) throw new RuntimeException("Unexpected table name");
    $columns=$db->pdo()->query("PRAGMA table_info(\"".$name."\")")->fetchAll(PDO::FETCH_ASSOC);
    $order=implode(",",array_map(fn($c)=>"\"".str_replace("\"","\"\"",$c["name"])."\"",$columns));
    $rows=$db->pdo()->query("SELECT * FROM \"".$name."\" ORDER BY ".$order);
    $hash=hash_init("sha256"); $count=0;
    while($row=$rows->fetch(PDO::FETCH_ASSOC)) {
      hash_update($hash,json_encode($row,JSON_THROW_ON_ERROR)."\n"); $count++;
    }
    $result[$name]=["rows"=>$count,"sha256"=>hash_final($hash)];
  }
  echo json_encode($result,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR),PHP_EOL;
  '
}
if [ "$LAYOUT" = sqlite ]; then
  inventory > "$BACKUP_DIR/database-before.json"
fi
new_compose run --rm --no-deps --user 0:0 shabbat \
  php bin/migrate.php --writers-stopped --db /app/data/shabbat.sqlite \
  --backup-dir /app/data/upgrade-3.1.1 > "$BACKUP_DIR/migrate.json"
if [ "$LAYOUT" = sqlite ]; then
  inventory > "$BACKUP_DIR/database-after.json"
  cmp "$BACKUP_DIR/database-before.json" "$BACKUP_DIR/database-after.json"
fi
~~~

For 3.0 SQLite, row hashes must match and no legacy import is needed; continue at step 7. For legacy, continue below. No SQL schema change is introduced in 3.1.1; investigate any unexpected difference before startup.

## 6. Legacy only: review and bind the import

Preview every registered group, setting, template, message, alert, draft and permission warning. Duplicates are reported/deduplicated. Directory-only groups are reported, not automatically subscribed: they may be stale. If necessary, reconcile them in a separate reviewed import copy, preserve the original snapshot and generate a fresh preview. The whitelist is documented in [migration](MIGRATION.md); arbitrary local extensions need review.

~~~sh
test "$LAYOUT" = legacy
new_compose run --rm --no-deps --user 0:0 \
  --volume "$BACKUP_DIR:/review:ro" shabbat \
  php bin/import-legacy.php --db /app/data/shabbat.sqlite \
  --legacy /review/source --dry-run > "$BACKUP_DIR/import-preview.json"
~~~

Read the private JSON and accept every source/target difference before the next block. --expect-report rejects changed source or affected target records. Existing settings/templates are preserved. Imported groups are active, with documented Jerusalem/legacy timing and alert defaults; new daily/reminder modules stay off. Review location/mode before normal service.

Apply is not one all-or-nothing transaction. On failure keep the bot stopped, preserve evidence and restore/review the verified pre-import backup before retrying. Do not weaken report matching.

~~~sh
new_compose run --rm --no-deps --user 0:0 \
  --volume "$BACKUP_DIR:/review:ro" shabbat \
  php bin/import-legacy.php --db /app/data/shabbat.sqlite --legacy /review/source \
  --apply --writers-stopped --backup-dir /app/data/upgrade-3.1.1 \
  --expect-report /review/import-preview.json > "$BACKUP_DIR/import-apply.json"
new_compose run --rm --no-deps --user 0:0 \
  --volume "$BACKUP_DIR:/review:ro" shabbat php -r '
  $r=json_decode(file_get_contents("/review/import-apply.json"),true,512,JSON_THROW_ON_ERROR);
  if(($r["verification"]["status"]??null)!=="passed") throw new RuntimeException("Import verification failed");
  echo "Import comparison passed.",PHP_EOL;
'
~~~

Old chatb1.json snapshots/global lock files are evidence, not verified current permissions. Administrator review may be required even if the group appears open. Never delete recovery state or automatically restore a permissive old snapshot. See [permission recovery](PERMISSION_RECOVERY.md).

## 7. Verify, back up and rehearse restore

These maintenance commands check schema/integrity, make a new SQLite backup without pruning old backups, export it privately and restore to a disposable path. They never open the Telegram session. Offline health needs live heartbeats; use structural checks here and real health after startup.

~~~sh
new_compose run --rm --no-deps --user 0:0 shabbat php -r '
require "/app/vendor/autoload.php";
$db=Shabbat\Storage\Database::open("/app/data/shabbat.sqlite");
$db->assertCurrentSchema();
$checks=$db->integrity();
if($checks["integrity_check"]!==["ok"] || $checks["foreign_key_check"]!==[]) throw new RuntimeException("Database check failed");
$result=(new Shabbat\Storage\BackupService())->backup($db,"/app/data/upgrade-3.1.1/ready.sqlite");
echo Shabbat\Storage\Database::encode($result),PHP_EOL;
' > "$BACKUP_DIR/ready-backup.json"
install -d -m 700 "$BACKUP_DIR/sqlite"
new_compose run --rm --no-deps --user 0:0 \
  --volume "$BACKUP_DIR/sqlite:/export" shabbat sh -ec '
  cp -a /app/data/upgrade-3.1.1/. /export/
  chown -R 10001:10001 /app/data /app/runtime
'
new_compose run --rm --no-deps shabbat php bin/restore.php \
  --backup /app/data/upgrade-3.1.1/ready.sqlite \
  --db /tmp/restore-rehearsal.sqlite --writers-stopped > "$BACKUP_DIR/restore-rehearsal.json"
new_compose run --rm --no-deps shabbat php scripts/verify-patches.php
new_compose run --rm --no-deps shabbat php bin/self-check.php --release
new_compose run --rm --no-deps shabbat php bin/igbinary-check.php
new_compose run --rm --no-deps shabbat php bin/runtime-permissions.php --require-non-root
~~~

## 8. Swap the source and start exactly one bot

Proceed after accepting reports, backup/restore and recovery plans. The old directory is renamed intact, never overlaid. If the second rename fails, stop and restore the retained directory only after verifying ORIGINAL_DIR is absent. Keep the old container stopped while its original bind path holds new source.

~~~sh
test "$(docker inspect --format '{{.State.Running}}' "$OLD_CONTAINER_ID")" = false
test "$(docker inspect --format '{{.HostConfig.RestartPolicy.Name}}' "$OLD_CONTAINER_ID")" = no
case "$ORIGINAL_DIR" in /root/shabbat|/root/shabat) ;; *) exit 1 ;; esac
test "$(readlink -f -- "$ORIGINAL_DIR")" = "$ORIGINAL_DIR"
test "$RELEASE_DIR" = "/root/shabbat-stage-$VERSION-$STAMP"
test "$RETIRED_DIR" = "$ORIGINAL_DIR.before-$VERSION-$STAMP"
test ! -e "$RETIRED_DIR"
mv -T -- "$ORIGINAL_DIR" "$RETIRED_DIR"
mv -T -- "$RELEASE_DIR" "$ORIGINAL_DIR"
RELEASE_DIR="$ORIGINAL_DIR"
cd "$ORIGINAL_DIR"
new_compose config --quiet
printf '%s\n' "$RETIRED_DIR" "$NEW_PROJECT" > "$BACKUP_DIR/new-installation.txt"
new_compose up -d --no-build shabbat
new_compose ps
new_compose logs --tail=120 shabbat
~~~

Starting can act on active groups, send a startup notification and process library work. Old unattributed broadcast schedules may be blocked/cancelled; copied self-destruction work can run. Review preserved BroadcastManager state; never assume an interrupted broadcast is safe to replay.

After startup, check health. In Telegram check /start, home/settings transitions, callbacks, /source identity, group counts, location/timezone, templates and alert preferences. In a disposable group with known pre-existing restrictions, test close/open and restart; do not use bulk /closegroups or /opengroups as a smoke test.

~~~sh
new_compose exec -T shabbat php bin/health.php --deep
new_compose ps
~~~

A group administrator must resolve every unverified snapshot outside protected periods: set intended normal rights in Telegram, refresh the named recovery panel and explicitly adopt current permissions. Generally open rights are not necessarily the intended baseline. Monitor both heartbeats, queues, permissions and reminders through the first real Shabbat/festival close/open sequence. Earlier 3.0 live checks do not certify 3.1.1, holiday timing or a long soak. Retain rollback material until acceptance.

Future commands run from the verified ORIGINAL_DIR with **--project-name shabbat-production-311**. Keep that project identity and both volumes. Back up matching source, .env, database, session and BroadcastManager state.

## 9. Rollback

**Before either rename/start:** old source/state remain unchanged. If no new service is running and RETIRED_DIR does not exist, restore the recorded restart policy and start the old container:

~~~sh
test ! -e "$RETIRED_DIR"
test "$(readlink -f -- "$ORIGINAL_DIR")" = "$ORIGINAL_DIR"
test -z "$(docker ps --filter label=com.docker.compose.project="$NEW_PROJECT" --filter label=com.docker.compose.service=shabbat --quiet)"
docker update --restart="$OLD_RESTART_POLICY" "$OLD_CONTAINER_ID"
docker start "$OLD_CONTAINER_ID"
~~~

Stop here for pre-swap rollback. **After a completed swap/start**, first review remote effects: files cannot undo Telegram permissions/messages/broadcasts. Resolve new bot-owned permission debt before decommissioning, then stop and preserve new state:

~~~sh
NEW_CONTAINER_ID="$(new_compose ps -a -q shabbat)"
if [ -n "$NEW_CONTAINER_ID" ]; then
  docker update --restart=no "$NEW_CONTAINER_ID"
fi
new_compose stop --timeout 120 shabbat
if [ -n "$NEW_CONTAINER_ID" ]; then
  test "$(docker inspect --format '{{.State.Running}}' "$NEW_CONTAINER_ID")" = false
fi
test "$(docker inspect --format '{{.State.Running}}' "$OLD_CONTAINER_ID")" = false
test ! -e "$BACKUP_DIR/failed-new-state"
install -d -m 700 "$BACKUP_DIR/failed-new-state"
new_compose run --rm --no-deps --user 0:0 \
  --volume "$BACKUP_DIR/failed-new-state:/export" shabbat sh -ec '
  tar -cpf /export/data.tar -C /app/data .
  tar -cpf /export/session.tar -C /app/runtime .
'
sha256sum "$BACKUP_DIR/failed-new-state/"*.tar > "$BACKUP_DIR/failed-new-state/SHA256SUMS"
FAILED_DIR="$ORIGINAL_DIR.failed-$VERSION-$STAMP"
test "$RETIRED_DIR" = "$ORIGINAL_DIR.before-$VERSION-$STAMP"
test -d "$RETIRED_DIR"
test ! -e "$FAILED_DIR"
case "$ORIGINAL_DIR" in /root/shabbat|/root/shabat) ;; *) exit 1 ;; esac
mv -T -- "$ORIGINAL_DIR" "$FAILED_DIR"
mv -T -- "$RETIRED_DIR" "$ORIGINAL_DIR"
test -f "$ORIGINAL_DIR/.env"
docker update --restart="$OLD_RESTART_POLICY" "$OLD_CONTAINER_ID"
docker start "$OLD_CONTAINER_ID"
docker logs --tail=100 "$OLD_CONTAINER_ID"
~~~

The old folder is restored before its bind-mounted container restarts; original volumes/image/session were retained. Never overwrite the old snapshot with the new database/session. Session compatibility and remote Telegram state still require observation. Do not remove either state set, volumes or retained images during this procedure.
