# Test stable 3.0.0 with a separate bot

Use a **new test bot identity and disposable groups**. The fresh-install procedure below uses `/root/shabbat-test-3.0.0` and Compose project `shabbat-staging-300`; it preserves both the existing `/root/shabbat-test` installation and production `/root/shabat`. These are instructions, not an executed deployment. Never run two clients against one bot/session/database.

An existing SQLite test deployment should follow the [stopped-writer upgrade](../README.md#upgrade-and-legacy-import) and [complete backup/restore procedure](BACKUP_RESTORE.md), preserving its existing project/volumes. Do not unpack this release over its old files or apply the fresh-volume instructions below to its state. The file-based production bot has its own [full cutover guide](PRODUCTION_UPGRADE.md).

## Prepare a fresh isolated source directory

Run on the Linux test host in the same Bash session, with root/Docker access, Compose v2, `curl`, `unzip` and `sha256sum`. The commands create a new source directory and download public release assets; they do not start a bot.

```sh
set -euo pipefail
umask 077
VERSION=3.0.0
ARCHIVE="shabbat-${VERSION}.zip"
TEST_SOURCE=/root/shabbat-test-3.0.0
TEST_PROJECT=shabbat-staging-300
UPLOAD_DIR=/root/shabbat-test-release-upload
test "$TEST_SOURCE" = /root/shabbat-test-3.0.0
test ! -e "$TEST_SOURCE"
if docker volume inspect "${TEST_PROJECT}_bot-data" >/dev/null 2>&1 \
  || docker volume inspect "${TEST_PROJECT}_madeline-session" >/dev/null 2>&1; then
  printf '%s\n' 'STOP: the test project already has state; inspect it before another attempt.' >&2
  exit 1
fi
install -d -m 700 "$UPLOAD_DIR"
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
install -d -m 700 "$TEST_SOURCE"
unzip -q "$UPLOAD_DIR/$ARCHIVE" -d "$TEST_SOURCE"
cd "$TEST_SOURCE"
test "$(cat VERSION)" = "$VERSION"
test -f build-info.json
cp .env.example .env
chmod 600 .env
```

For manual upload, transfer the deployment ZIP and `SHA256SUMS` to `UPLOAD_DIR` and use the same exact checksum check before extracting. The separate corresponding-source archive is the source-offer artifact; it is not the deployment ZIP. Retain release `build-info.json` unchanged; it records the actual release commit and fingerprint with `local_changes=false`.

Edit the new `.env` privately with the new test bot's Telegram credentials, trusted `ADMIN`, GeoNames account and container paths from [configuration](CONFIGURATION.md). Never copy production credentials/data/session. Set `SOURCE_URL=https://github.com/WizardLoop/shabbat/releases/tag/v3.0.0` for this unchanged release and leave `BUILD_COMMIT` absent.

## Build, initialize and start deliberately

In the same shell at the fresh source root:

```sh
test_compose() {
  docker compose --project-name "$TEST_PROJECT" \
    --project-directory "$TEST_SOURCE" --file "$TEST_SOURCE/docker-compose.yml" "$@"
}
unset BUILD_COMMIT
test_compose config --quiet
test_compose build shabbat
test_compose run --rm --no-deps shabbat php scripts/verify-patches.php
test_compose run --rm --no-deps shabbat php bin/self-check.php --release
test_compose run --rm --no-deps shabbat php bin/runtime-permissions.php --require-non-root
test_compose run --rm --no-deps shabbat php bin/check-calendar.php
test_compose run --rm --no-deps shabbat php bin/migrate.php --writers-stopped --db /app/data/shabbat.sqlite
```

These commands build/install/patch the locked runtime, check the platform/filesystem, query public calendar providers and initialize the empty test database. They do not open a Telegram session. Existing-volume assertions are intentional; investigate a partial attempt instead of deleting state to force a pass.

When the test bot identity and disposable groups are ready, start its networked activity:

```sh
test_compose up -d --no-build shabbat
test_compose ps
test_compose logs --tail=120 shabbat
```

Keep logs private. After both scheduler passes complete:

```sh
test_compose exec -T shabbat php bin/health.php --deep
test_compose ps
```

Verify `/source` reports 3.0.0 and the exact release commit/source URL. Health requires recent permission and notification heartbeats; an offline database cannot satisfy it. Stop the test bot when the planned test is finished using `test_compose stop shabbat`. Preserve its project/volumes for diagnosis and later tests.

## Permission recovery and acceptance

The owner already reported successful dev.9 live locking, unlocking, original-permission restoration and restart. Record new results against the exact stable source separately; do not expand that earlier confirmation into holiday/load/soak acceptance.

1. In a disposable group, set specific normal restrictions such as disabled media, invitations or pinning. Confirm the intended group/settings and exercise closing/opening, read back the exact restored rights, then repeat across a restart.
2. For an unresolved older snapshot, outside protected periods and active locks, review the normal Telegram rights. In **Resolve permission problem**, refresh, inspect the group/rights and explicitly choose the supported action. Adopting current permissions preserves older evidence and does not itself send a restriction update.
3. Test automatic protected-period transitions separately. Include forum/non-forum groups, inline/GPS, client rendering, rich messages, custom emoji, broadcasts, command menus and Stars only where used, with controlled disposable data.
4. Inspect `/testzmanim GROUP_ID` and its private export when needed. Do not delete lock-state records or repeatedly force restoration to hide a conflict.
5. Observe both heartbeat ages, oldest due jobs, delivery delay and retry behavior under representative traffic, including a real wall-clock soak. Record what ran and what remains untested.

Do not use `down -v`, transfer test state into production or run old/new instances on one session. [Rollback](BACKUP_RESTORE.md) needs matching source, database and library/session state; it cannot undo Telegram actions already accepted. See [delivery safety](DEV9_DELIVERY_SAFETY.md), [implementation status](IMPLEMENTATION_STATUS.md) and [publication status](PUBLICATION_STATUS.md).
