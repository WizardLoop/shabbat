#!/usr/bin/env bash
set -euo pipefail
umask 077

# Run on the Linux host holding the current SQLite deployment.
CURRENT=${1:-/root/shabat}
PROJECT=${2:-shabbat-production-310}
ASSETS=${3:?Pass the directory containing the release ZIP and SHA256SUMS}
ASSETS=$(realpath "$ASSETS")
CURRENT=$(realpath "$CURRENT")
test "$CURRENT" != /
test -d "$ASSETS"
cd /
SOURCE="$CURRENT"

compose() {
  docker compose --project-name "$PROJECT" \
    --project-directory "$SOURCE" --file "$SOURCE/docker-compose.yml" \
    "$@" </dev/null
}

test "$(id -u)" = 0
test -f "$CURRENT/.env"
test -f "$CURRENT/docker-compose.yml"
case "$(cat "$CURRENT/VERSION")" in 3.0.*|3.1.*) ;; *) echo "Expected a 3.0.x or 3.1.x deployment" >&2; exit 1 ;; esac
CID=$(compose ps -q shabbat)
test -n "$CID"
test "$(docker inspect -f '{{.State.Running}}' "$CID")" = true
test "$(docker inspect -f '{{range .Mounts}}{{if eq .Destination "/app/data"}}{{.Name}}{{end}}{{end}}' "$CID")" = "${PROJECT}_bot-data"
test "$(docker inspect -f '{{range .Mounts}}{{if eq .Destination "/app/runtime"}}{{.Name}}{{end}}{{end}}' "$CID")" = "${PROJECT}_madeline-session"

BACKUP=$(mktemp -d /root/shabat-backup-3.2.0.XXXXXX)
STAGE=$(mktemp -d "$(dirname "$CURRENT")/shabat-release-3.2.0.XXXXXX")
printf 'Backup: %s\nNew source: %s\n' "$BACKUP" "$STAGE"

OLD_IMAGE=$(docker inspect -f '{{.Image}}' "$CID")
ROLLBACK_IMAGE="shabbat-rollback:pre-320-$(date +%Y%m%d-%H%M%S)"
docker image tag "$OLD_IMAGE" "$ROLLBACK_IMAGE"
printf '%s\n' "$ROLLBACK_IMAGE" > "$BACKUP/old-image.txt"

(
  cd "$ASSETS"
  awk '$2 == "shabbat-3.2.0.zip" && NF == 2 && length($1) == 64 && $1 !~ /[^0-9a-f]/ { print; n++ } END { if (n != 1) exit 1 }' SHA256SUMS > "$BACKUP/deployment.sha256"
  sha256sum -c "$BACKUP/deployment.sha256"
)
unzip -q "$ASSETS/shabbat-3.2.0.zip" -d "$STAGE"
test "$(cat "$STAGE/VERSION")" = 3.2.0
test -f "$STAGE/build-info.json"
awk '!/^[[:space:]]*(SOURCE_URL|BUILD_COMMIT)[[:space:]]*=/' \
  "$CURRENT/.env" > "$STAGE/.env"
printf '\nSOURCE_URL=https://github.com/WizardLoop/shabbat/releases/tag/v3.2.0\n' >> "$STAGE/.env"
chmod 600 "$STAGE/.env"
unset BUILD_COMMIT

SOURCE="$STAGE"
compose config --quiet
compose build shabbat

SOURCE="$CURRENT"
printf 'Stopping the live service to back up both volumes. Keep this backup: %s\n' "$BACKUP"
compose stop shabbat
test "$(docker inspect -f '{{.State.Running}}' "$CID")" = false

SOURCE="$STAGE"
compose run --rm --no-deps --interactive=false -T --user 0:0 \
  --volume "$BACKUP:/backup" shabbat sh -ec '
    umask 077
    test -s /app/data/shabbat.sqlite
    test -s /app/runtime/bot.shabbat/safe.php
    tar -czf /backup/state.tar.gz -C /app data runtime
  '
tar -tzf "$BACKUP/state.tar.gz" >/dev/null
sha256sum "$BACKUP/state.tar.gz" > "$BACKUP/state.sha256"

compose run --rm --no-deps --interactive=false -T shabbat sh -ec '
  php scripts/verify-patches.php
  php bin/self-check.php --release
  php bin/igbinary-check.php
  php bin/runtime-permissions.php --require-non-root
  mkdir -p /app/data/backups
  php bin/migrate.php --writers-stopped --db /app/data/shabbat.sqlite --backup-dir /app/data/backups
'

mv "$CURRENT" "$BACKUP/source"
mv "$STAGE" "$CURRENT"
SOURCE="$CURRENT"
compose up -d --no-build --force-recreate shabbat
compose ps
compose logs --tail=60 shabbat
printf '\nSTART_COMMAND_FINISHED\nBackup: %s\n' "$BACKUP"
