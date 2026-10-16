# 🕯️ WizardLoop Shabbat Bot

A Telegram bot built with PHP, MadelineProto and SQLite for Shabbat and festival group quiet periods, Jewish calendar views and opt-in reminders.

[English](README.md) | [עברית](readme-he.md)

[![License: AGPL-3.0-only](https://img.shields.io/badge/license-AGPL--3.0--only-blue)](LICENSE)
[![PHP 8.3–8.5](https://img.shields.io/badge/PHP-8.3%E2%80%938.5-777BB4)](composer.json)
[![MadelineProto 8.7.0](https://img.shields.io/badge/MadelineProto-8.7.0-2CA5E0)](composer.lock)
[![Development 3.0.0-dev.9](https://img.shields.io/badge/status-3.0.0--dev.9-orange)](VERSION)

**Current source: 3.0.0-dev.9, a development snapshot prepared for review.** Its source package may precede the public repository. Use the matching archive or reviewed checkout and retain its build metadata. Offline tests, Docker acceptance and live Telegram acceptance are separate. See [publication status](docs/PUBLICATION_STATUS.md), [changes](CHANGELOG.md) and [delivery safety](docs/DEV9_DELIVERY_SAFETY.md). Badges describe source configuration; they do not assert a passed build or a published release.

## Contents

[Features](#features) · [Requirements](#requirements) · [Docker installation](#docker-installation) · [Manual installation](#manual-installation) · [Configuration](#configuration) · [Telegram setup](#telegram-setup) · [Group setup](#group-setup) · [Location and languages](#location-and-languages) · [Calendar modules](#calendar-modules) · [Bot commands](#bot-commands) · [Operations](#operations) · [Upgrade and legacy import](#upgrade-and-legacy-import) · [Backup and rollback](#backup-and-rollback) · [Troubleshooting](#troubleshooting) · [Project and development](#project-and-development) · [License and credits](#license-and-credits)

<a id="features"></a>
## Features

| Area | Implemented behavior |
|---|---|
| Shabbat/festivals | Per-group location, Israel/diaspora calendar, candle/havdalah methods, operational offsets and continuous adjacent holy-day closures. |
| Modes | Off, messages only, lock only, or lock and messages; Shabbat and festivals are selected separately. |
| Permission recovery | Original 22-flag snapshots, verified fresh reads, durable restoration state, conflicts and an authorized recovery panel. The default lock mask has 21 selectable member restrictions; administrator powers are separate. |
| Private controls | Known groups, guided settings, independent personal preferences, preview and confirmation. |
| Calendar/reminders | Daily zmanim, Daf Yomi, tefillin and seasonal Omer with explicit group opt-in and separate settings. |
| Message design | Text, Telegram entities, reusable media and default/custom/no keyboards; separate close/open/announcement/module templates. Optional cleanup defaults off. |
| Administration | Full/limited/view-only managers, subscriber directory, broadcasts, statistics, diagnostics, system links and tracking links. |
| Storage/operation | SQLite migrations/backups, durable delivery jobs, independent scheduler heartbeats, one application writer and explicit maintenance tools. |
| Donations/source | Optional Telegram Stars, including whole custom amounts 1–10000; version, license and an operator-supplied exact source URL. |

dev.9 adds delivery-time eligibility checks, bounded reminder backlogs, actual spacing/quiet-window checks and independent permission, notification and broadcast work. MadelineProto remains 8.7.0 and the dependency lock is retained. Missing solar values are reported; no polar fallback is invented. Times and ritual texts are informational, with method choices made by the administrator.

<a id="requirements"></a>
## Requirements

| Component | Requirement |
|---|---|
| Recommended deployment | Linux, Docker Engine and Compose v2, outbound HTTPS/Telegram access, persistent storage. |
| Manual runtime | 64-bit PHP 8.3/8.4/8.5 and Composer 2. Docker pins PHP 8.4.19 and Composer 2.10.3. |
| Extensions | PDO/PDO SQLite, mbstring, XML, DOM, fileinfo, zlib; ZIP for packaging. Verify all locked requirements with `composer check-platform-reqs`. Docker also builds pcntl and sockets. |
| SQLite | Application minimum 3.35. Release gate accepts 3.51.3+, or reviewed 3.44.6 / 3.50.7. Check PHP's loaded library. Docker builds checksum-verified 3.51.3. |
| Telegram | Your application API ID/hash, BotFather token and configured operator user IDs or resolvable usernames. |
| GeoNames | Your webservice-enabled account for city search/automatic timezone lookup. Known cities and explicit coordinates remain available without it. |
| Filesystem | Persistent application data and separate MadelineProto session. The runtime also needs a writable working directory for its library worker log and PrimeModule copies. |

Offline tests use fake Telegram gateways and provider fixtures without bot credentials. Live staging acceptance must cover permission restoration, interruption/restart, topics, client rendering, inline/GPS, Stars and command menus.

<a id="docker-installation"></a>
## Docker installation

Shell examples use a Linux/POSIX terminal. Run Docker commands **on the host, from the extracted project root**, with Docker-daemon access. The image runs as `shabbat`, UID/GID `10001`. There is no separate Composer service.

1. Obtain the reviewed source. For the prepared publication archive:

~~~sh
unzip shabbat-3.0.0-dev.9-publication.zip -d shabbat
cd shabbat
~~~

It contains source and `composer.lock`, without installed `vendor/`. Docker installs locked production dependencies. **Retain archive `build-info.json`.** In this publication archive it records the verified historical baseline commit, `local_changes=true` and the exact publication-source fingerprint; the baseline is not a new commit of these changes. For a reviewed Git checkout instead, supply its actual commit before building:

~~~sh
export BUILD_COMMIT="$(git rev-parse HEAD)"
~~~

A baseline commit alone does not identify uncommitted changes; the source fingerprint also identifies this snapshot. Do not substitute an unrelated commit.

2. Create private configuration once in this new installation:

~~~sh
cp .env.example .env
chmod 600 .env
~~~

Edit `.env`: replace Telegram examples, set `ADMIN`, your GeoNames account when needed, and `SOURCE_URL` to the exact corresponding source you make available. Keep simple `KEY=value` syntax compatible with PHP raw INI and Compose. Exclude the configured file from uploads.

3. Build and check the image. These steps install/patch dependencies and write image metadata without starting the bot:

~~~sh
docker compose build
docker compose run --rm --no-deps shabbat php bin/self-check.php --release
docker compose run --rm --no-deps shabbat php bin/runtime-permissions.php --require-non-root
~~~

4. Initialize a **new empty database**, with the service stopped. Existing databases require the upgrade/backup procedure.

~~~sh
docker compose run --rm --no-deps shabbat php bin/migrate.php --writers-stopped --db /app/data/shabbat.sqlite
~~~

5. Start when ready for real Telegram connections and operations on registered groups:

~~~sh
docker compose up -d shabbat
docker compose ps
docker compose logs --tail=100 shabbat
docker compose exec shabbat php bin/health.php
~~~

Service: `shabbat`. Named volumes: `bot-data` → `/app/data`, `madeline-session` → `/app/runtime`; actual names include the Compose project name. Preserve the project identity and volumes during upgrades. Compose forces `DATA_DIR=/app/data` and `SESSION_PATH=/app/runtime/bot.shabbat`. Initial health waits for both scheduler loops; the configured start period is 90 seconds.

[Installation details](docs/INSTALLATION.md) · [Staging guide](docs/SERVER_TEST_DEPLOYMENT.md)

<a id="manual-installation"></a>
## Manual installation

Use separate source and a non-root service account that can write the working/data/session directories. Run **from that project root** after copying/configuring `.env` as above:

~~~sh
composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader
composer check-platform-reqs --no-dev
mkdir -p data/session
php bin/self-check.php --release
php bin/runtime-permissions.php --require-non-root
php bin/migrate.php --writers-stopped --db data/shabbat.sqlite
php bot.php
~~~

The last command starts the bot in the foreground and connects to Telegram. Stop with Ctrl+C and wait for exit; repeat `php bot.php` to start again. Manual process supervision is the operator's responsibility; no systemd unit is supplied. Windows can run many offline checks; the POSIX non-root probe and Linux deployment acceptance require the appropriate host.

Composer normally applies/verifies local patches on install. If scripts were disabled, run these **in the stopped installation**:

~~~sh
composer install --no-dev --no-scripts --no-interaction --prefer-dist
php scripts/apply-patches.php
php scripts/verify-patches.php
~~~

Unknown/partially modified dependency bytes fail verification. Reapplication is idempotent. Never patch an installed library while its bot is running.

<a id="configuration"></a>
## Configuration

The bot reads project-root `.env` as raw INI. Nonempty process environment values take precedence for credential/path lookup; avoid duplicate empty overrides. Maintenance path resolution also reads `.env`; explicit `--db` selects the target database. Relative paths are rooted at the project.

| Variable | Default / requirement | Purpose |
|---|---|---|
| `API_ID` | Required positive integer | Telegram application identifier. |
| `API_HASH` | Required 32 hexadecimal characters | Telegram application secret. |
| `BOT_TOKEN` | Required | This installation's BotFather token. |
| `ADMIN` | Configure for operator access | Comma-separated IDs/resolvable usernames; numeric IDs avoid ambiguity. Configured owners retain recovery/restart access. |
| `DATA_DIR` | Project `data` | Application and BroadcastManager state; Compose overrides to `/app/data`. |
| `APP_DB_PATH` | `DATA_DIR/shabbat.sqlite` | Application database; example explicitly uses `data/shabbat.sqlite`. |
| `SESSION_PATH` | Project `bot.shabbat` | MadelineProto session; example uses `data/session/bot.shabbat`, Compose uses `/app/runtime/bot.shabbat`. |
| `HEARTBEAT_PATH` | `DATA_DIR/scheduler-heartbeat.json` | Permission/notification heartbeat file. |
| `LEGACY_DATA_PATH` | Project root | Read-only legacy root containing `data/DBgroups.txt`; startup only adds missing registrations. |
| `GEONAMES_USERNAME` | Empty | Your webservice account; demo is unsupported. |
| `GEONAMES_ENDPOINT` | `https://secure.geonames.org` | Verified HTTPS endpoint, no HTTP fallback. |
| `CUSTOM_EMOJI_ENABLED` | `1` | `0` selects basic emoji; definite eligibility failures also trigger guarded fallback. |
| `SOURCE_URL` | Empty; set before deployment | HTTPS exact corresponding source URL. Missing configuration is reported, not blocked at startup. |
| `BUILD_COMMIT` | Archive metadata or 40-hex commit | Build/runtime identity and Compose build argument. Preserve fingerprints for local changes. |

[Configuration details](docs/CONFIGURATION.md) · [Privacy](docs/PRIVACY.md)

<a id="telegram-setup"></a>
## Telegram setup

1. Obtain API credentials through [Telegram application management](https://my.telegram.org/apps).
2. Create/select the bot with [BotFather](https://t.me/BotFather), obtain its token and allow group additions.
3. Open the bot privately with `/start`. Add it to the intended group with rights to restrict members/change default permissions. Optional deletion/pinning needs the relevant Telegram rights.
4. Enable BotFather `/setinline` for inline search/sharing. Suggested placeholder: `shabbat · zmanim · daf · omer · loc city`. City-name search requires neither `/setinlinegeo` nor `/setinlinefeedback`; GPS sharing is explicit.
5. With command-menu mutation access, send `/setcommands all all preview` privately to inspect the menu count; `/setcommands all all` updates Telegram. Group administration alone does not grant that access.

Optional cleanup only processes updates Telegram delivers. General messages from other bots require the receiving bot's **Bot-to-Bot Communication Mode** enabled through BotFather, group administrator status and **Group Privacy Mode** disabled, as described in [Telegram's bot-to-bot documentation](https://core.telegram.org/api/bots/bot-to-bot). Deletion still needs the relevant rights; this is not a guarantee of receiving every message.

<a id="group-setup"></a>
## Group setup

1. Send `/add` **as a group administrator in the group**. New groups added this way start **off** and open private setup.
2. Confirm the named group, location, IANA zone and Israel/diaspora calendar. Jerusalem defaults must be reviewed; they are not inferred location.
3. Select mode and Shabbat/festival policy, then candle/havdalah methods and operational offsets.
4. Explicitly enable desired zmanim/Daf/tefillin/Omer modules. Preview privately; a group TEST needs separate confirmation and is rate limited.
5. Return through private `/groups` or group `/settings`. Personal and group settings remain separate.

**Current registration caveat:** `/settings` can register a previously unknown group after verifying its administrator, and that path inherits the database's **lock** default. Use `/add` for the explicit off-first flow and review mode/location immediately. `/settings` does not reactivate a removed group; `/add` can. This behavior is documented as implemented, not silently changed during publication preparation.

Panels show current values, upcoming work and recovery status. Changes require current authority and a user/group/revision-bound confirmation. A missing/closed forum topic produces an error, not redirection. Restoration uses saved original rights; missing/unverified/conflicting snapshots need authorized resolution. Optional cleanup of member/bot messages starts disabled. Group removal is blocked by unresolved restoration debt.

[Settings](docs/GROUP_SETTINGS.md) · [Guided interface](docs/USER_EXPERIENCE.md) · [Permission recovery](docs/PERMISSION_RECOVERY.md)

<a id="location-and-languages"></a>
## Location and languages

Six locale catalogs are supplied: Hebrew `he`, English `en`, Russian `ru`, French `fr`, Spanish `es`, Brazilian Portuguese `pt-br`. Their keys/placeholders are checked; this is not a promise that every third-party error or inherited interface is translated. Hebrew ritual wording remains Hebrew, with supplied explanatory text. Telegram menus use `pt`; client menu language and the saved bot preference are separate.

Use private `/preferences` or `/language`; group `/language` requires its administrator. For city search, create a [GeoNames account](https://www.geonames.org/login), activate free webservices and set `GEONAMES_USERNAME`.

In the appropriate panel: **location/timezone → inline city search → city/country/region → inspect coordinates/timezone → confirm the named target**. Private search, known cities, coordinates, IANA zone search and prompted GPS replies are available. Choices expire, belong to one user and are consumed once. Changing timezone does not move coordinates.

Public `loc city` and a bare city name support location search/sharing. Public timezone queries include `tz Jerusalem` or `Asia/Jerusalem`; they do not update settings. Setup queries `location CITY` and `timezone QUERY` require the active private location/timezone prompt and lead to confirmation. Other modes: `shabat`, `shabbat`, `שבת`, `zmanim`, `daf`, `omer`. Check the result's displayed location: personal sharing and an authorized group-context preview may differ. Quota/account failures remain visible errors.

[Inline/GeoNames guide](docs/INLINE_GEONAMES.md)

<a id="calendar-modules"></a>
## Calendar modules

| Module | Implemented settings / limits |
|---|---|
| Shabbat/festivals | City candle custom or exact 10/15/18/20/22/30/40/custom minutes. Jerusalem city default is 40; explicit 18 remains 18. Havdalah: 8.5° or fixed 42/50/72/custom minutes. Fixed 72 does not represent every Rabbeinu Tam method. |
| Group timing | Close-before-candles/open-after-havdalah offsets are separate. Adjacent holy days stay closed continuously. Israel/diaspora is explicit. Announcements have independent timing/design, Hebrew dates and Torah-portion metadata. |
| Zmanim | Civil date/IANA zone; dawn, misheyakir, sunrise, Gra/MGA Shema/prayer, midday, mincha, plag, sunset, nightfall and midnight where available. Missing values remain unavailable. |
| Daf Yomi | Civil-date calculation and provider study link; skipping sends never pauses the cycle. |
| Tefillin | Fixed/interval/sunset-relative schedules, deduplication, limits, spacing, quiet windows/pause. Start uses 11.5° `misheyakir`; the stricter provider field is not selectable. No Shabbat/Yom Tov sends; explicit Chol HaMoed/observed Tisha B'Av policies. Cutoff rechecked at dispatch. Optional unique-user acknowledgement toggles participation. |
| Omer | 49-day seasonal count advances across unsent days/years. Night sends wait for selected nightfall and group quiet-period end; optional day completion omits blessing. `ashkenaz` and documented `sephard` sequence, not every community variant. Full form includes selected preparation/traditional additions. |

Blessing guidance concerns individual eligibility; delivery history is not a person's counting history. See [method definitions](docs/ZMANIM_METHODS.md), [calendar presentation](docs/CALENDAR_PRESENTATION.md) and [text provenance](THIRD_PARTY_NOTICES.md).

<a id="bot-commands"></a>
## Bot commands

Send these **in Telegram**. Menus are shortcuts, not a full permission contract. `[...]` means optional.

### Users

| Command | Where / permission | Effect |
|---|---|---|
| `/start`, `/help` | Private; any user | Home menu/help. |
| `/preferences` | Private; own settings | Personal location/calendar/methods. |
| `/language`, `/lang` | Private; own settings | Personal language; group usage needs group admin. |
| `/groups [search]` | Private; fresh group-admin checks | List/search manageable known groups. |
| `/shabat [YYYY-MM-DD]`, `/shabbat [YYYY-MM-DD]` | Private or registered group; any user | Shabbat/festival times for that context. |
| `/zmanim [YYYY-MM-DD]` | Private or registered group; any user | Daily times. |
| `/daf [YYYY-MM-DD]` | Private or registered group; any user | Daf Yomi. |
| `/omer [YYYY-MM-DD]` | Private or registered group; any user | Count/season for the selected date. |
| `/stats` | Private or group; any user | Public aggregates, without subscriber identities. |
| `/donate` | Private; any user | Optional Stars offers; payment needs Telegram checkout. |
| `/source`, `/license`, `/version` | Private or group; any user | Source offer, licensing/build identity. |

### Group administrators

| Command | Where / permission | Effect |
|---|---|---|
| `/add` | Group; current group admin | Register/reactivate and open setup. |
| `/remove` | Group; current group admin | Deactivate; restoration debt blocks removal. |
| `/settings` | Group; current group admin | Private group settings; see registration caveat above. |
| `/settings [GROUP_ID]` | Private; current target-group admin | Open target or list groups without ID. |
| `/language`, `/lang` | Group; current group admin | Private selection of group language. |

Advanced **private** forms require the panel's exact token, its owner/scope/revision and current group authority for group targets. Prefer the guided buttons.

| Form | Effect |
|---|---|
| `/city TOKEN QUERY` | Search pending location. |
| `/coordinates TOKEN LATITUDE LONGITUDE IANA_ZONE [NAME]` | Preview explicit coordinates/zone. |
| `/location TOKEN IANA_ZONE` or `/location TOKEN auto` | Prompt for GPS reply; auto requires GeoNames. |
| `/timezone TOKEN QUERY` | Search IANA zones. |
| `/configure TOKEN FORM` | Preview the panel's supported settings form. |
| `/viewdate TOKEN YYYY-MM-DD` | Selected calendar date. |
| `/template TOKEN` + newline + content | Review a group template candidate. |
| `/buttons TOKEN` + newline + keyboard content | Review JSON/legacy keyboard content. |

### Bot managers

All below run **in private**. Bot-manager roles are separate from group administration; view-only roles cannot mutate.

| Command | Required access | Effect |
|---|---|---|
| `/admin` | Bot manager | Administration panel. |
| `/adminstats` | Statistics | Administrative aggregates. |
| `/testzmanim [GROUP_ID]` | Statistics | Paged diagnostics/filter/search and optional private JSON export. |
| `/subscribers` | Subscribers | Known peer/profile directory. |
| `/broadcast` | Broadcast | Drafts/jobs; sending/editing/deleting needs mutation rights and relevant confirmation. |
| `/managers` | Manage managers | Delegated roles within actor's authority. |
| `/systemlinks` | System links | Navigation links, which confer no permissions. |
| `/trackinglinks` | Tracking links | Activation counters/management; changes need mutation access. |
| `/setcommands [LANGUAGE] [SCOPE] [preview]` | Command-menu mutation, even preview | Inspect or update Telegram menus. |
| `/closegroups`, `/opengroups` | Group-operations mutation | Immediately apply a one-hour override to active registered groups; preserve prelocks/report conflicts. Real permission operations. |
| `/restart` | Configured `ADMIN` owner only | Orderly runtime restart. |

Menu languages: `all|he|en|ru|fr|es|pt-br`; scopes: `all|private|operators|groups|group_admins`; defaults: `all all`. Example: `/setcommands ru group_admins preview`. Group menus currently list `/settings`; other supported commands can be typed.

[Administration](docs/ADMIN_PANEL.md) · [Diagnostics](docs/DIAGNOSTICS.md)

<a id="operations"></a>
## Operations

Docker commands run **on the host at the same project root/project identity**, with daemon access. PHP commands run **inside the image at /app**, or at the manual root as its service user. Operational output can contain group IDs/state; keep it private.

| Task | Command | Effect / condition |
|---|---|---|
| Build | `docker compose build` | Build from source/lock. |
| Start | `docker compose up -d shabbat` | Start/recreate and connect to Telegram. |
| Stop | `docker compose stop shabbat` | Graceful stop, 60-second grace; retain volumes. |
| Restart | `docker compose restart shabbat` | Restart same service; does not rebuild image. |
| Status | `docker compose ps` | Container/service state. |
| Logs | `docker compose logs --tail=100 shabbat` | Read logs; add `--follow` to stream, Ctrl+C exits viewer. |
| Health | `docker compose exec shabbat php bin/health.php` | Schema/connection, both heartbeats and queue/lock metrics. |
| Deep health | `docker compose exec shabbat php bin/health.php --deep` | Also integrity/foreign-key checks; more expensive. |
| Release gate | `docker compose run --rm --no-deps shabbat php bin/self-check.php --release` | Offline platform/patch checks. |
| Write probe | `docker compose run --rm --no-deps shabbat php bin/runtime-permissions.php --require-non-root` | Offline temporary-file probe; may append to existing worker log. |

Both heartbeats must be no older than 180 seconds. Offline/restored DBs can pass structural checks yet return `healthy=false` / exit 1 without schedulers. Never manufacture heartbeat files.

Run direct maintenance below **at project root as service user**. In Docker, prefix PHP commands with `docker compose run --rm --no-deps shabbat` and use container paths. `--writers-stopped` is an attestation, not a stop command.

| Task | Command | Effect / requirement |
|---|---|---|
| Migrate | `php bin/migrate.php --writers-stopped --db data/shabbat.sqlite --backup-dir backups` | All writers stopped, existing backup directory. Back up existing DB/apply migrations; omit backup only for new DB. |
| Backup | `php bin/backup.php --db data/shabbat.sqlite --directory backups --keep 7` | Consistent SQLite copy/manifest; retain seven tool-named backups. Online maintenance allowed. |
| Restore | `php bin/restore.php --backup backups/CHOSEN.sqlite --db data/shabbat.sqlite --writers-stopped` | Verified chosen backup, stopped writers; previous destination retained. |
| Inspect legacy | `php bin/import-legacy.php --db data/shabbat.sqlite --legacy /path/to/legacy-copy --dry-run` | Inspect/report, no imported-record writes or Telegram actions. |
| Import legacy | `php bin/import-legacy.php --db data/shabbat.sqlite --legacy /path/to/legacy-copy --apply --writers-stopped --backup-dir backups` | Existing migrated DB/backup directory, stopped writers; additive import. |
| Calendar diagnostic | `php bin/check-calendar.php --date=2026-10-15` | Public HTTPS only; no DB, .env or session. `--help` lists coordinates/zone/calendar/language options. |
| Verify patches | `php scripts/verify-patches.php` | Inspect approved installed bytes. |
| Apply patches | `composer patches` | Apply/verify; stopped installation only. |
| Build identity | `php bin/build-metadata.php --commit=YOUR_40_HEX_COMMIT` | Replace placeholder with verified provenance; writes metadata/rejects stale fingerprint. |

<a id="upgrade-and-legacy-import"></a>
## Upgrade and legacy import

Preserve private configuration, data/session and Compose project identity. Use reviewed source/lock; stop every writer before schema or installed dependency changes. Routine upgrades use `composer install`, not `composer update`.

For an existing Compose DB, from the prepared project connected to the **same volumes**:

~~~sh
docker compose build
docker compose stop shabbat
docker compose run --rm --no-deps shabbat mkdir -p /app/data/backups
docker compose run --rm --no-deps shabbat php bin/backup.php --db /app/data/shabbat.sqlite --directory /app/data/backups --keep 7
~~~

Complete the stopped session/BroadcastManager backup and restore rehearsal below **before proceeding**. Then apply the schema and release checks:

~~~sh
docker compose run --rm --no-deps shabbat php bin/migrate.php --writers-stopped --db /app/data/shabbat.sqlite --backup-dir /app/data/backups
docker compose run --rm --no-deps shabbat php bin/self-check.php --release
~~~

`docker compose up -d shabbat` then activates the reviewed image; inspect logs/health. Manual installations follow stop/backup/install-from-lock/migrate/check/start.

dev.8 → dev.9 adds `016_delivery_safety.sql`. dev.6 also needs 013–015; earlier versions apply all missing migrations automatically. Do not edit applied SQL. Uncertain past delivery attempts are handled conservatively; older unverified permission snapshots still require administrator review.

For the old file-based bot: preserve a protected legacy copy; stop old writers for final import/cutover; initialize a new DB; run dry-run, compare counts/templates/rights/warnings, then apply with stopped writers and backup directory. The exact commands appear above. For Docker mount only the protected copy read-only:

~~~sh
docker compose run --rm --no-deps --volume /absolute/path/legacy-copy:/legacy:ro shabbat php bin/import-legacy.php --db /app/data/shabbat.sqlite --legacy /legacy --dry-run
~~~

Explicit import preserves applicable groups, templates/entities/media/buttons, drafts, known peers and permission evidence without Telegram calls. New reminder modules remain off; malformed/missing snapshots remain review items. The startup `DBgroups.txt` bridge only adds registrations and does not replace full import. Never substitute a test bot's DB/session for production state.

[Migration](docs/MIGRATION.md) · [Schema](docs/DATABASE.md) · [Older snapshots](docs/DEV8_PERMISSIONS.md)

<a id="backup-and-rollback"></a>
## Backup and rollback

SQLite backup uses `VACUUM INTO`, validates integrity/foreign keys/schema history and writes SHA-256 metadata. Copying only a live main file can omit committed WAL. Retention only removes this tool's strictly named backups in the chosen directory; choose `--keep` deliberately.

SQLite does **not** include the session or all BroadcastManager files. Stop their writers and keep these separate paths, private configuration and matching code/lock alongside the DB backup. Docker example **after stopping the service**, on the host project root. Use a new empty snapshot directory for each backup (replace `../shabbat-private-backup` consistently):

~~~sh
mkdir -p ../shabbat-private-backup
chmod 700 ../shabbat-private-backup
docker compose cp shabbat:/app/data ../shabbat-private-backup/data
docker compose cp shabbat:/app/runtime ../shabbat-private-backup/runtime
cp .env ../shabbat-private-backup/config.env
chmod 600 ../shabbat-private-backup/config.env
~~~

This copies complete stopped durable directories to a sibling outside the Docker build context. Keep them private, outside publication material. For manual installation copy the actual resolved data/session paths with writers stopped. Verify all backups before replacement.

Create a separate rehearsal directory and restore a chosen verified SQLite backup there:

~~~sh
mkdir -p ../shabbat-rehearsal
php bin/restore.php --backup backups/CHOSEN.sqlite --db ../shabbat-rehearsal/restored.sqlite --writers-stopped
php bin/health.php --db ../shabbat-rehearsal/restored.sqlite --deep
~~~

Replace `CHOSEN.sqlite` with the real filename. Inspect structural fields, schema and sampled records; overall health may exit 1 without live heartbeats.

Rollback: stop writers; retain a current recovery backup; resolve/record outstanding Telegram permission obligations; restore older compatible DB/code/lock/session/BroadcastManager state; start one matching instance and review fresh remote reconciliation. DB restoration cannot reverse already accepted Telegram changes. Restore validates the input/manifest, obtains the writer lock and retains `*.before-restore-*`. Busy writers or incompatible schema require investigation, not deleting locks/volumes.

[Backup/restore details](docs/BACKUP_RESTORE.md)

<a id="troubleshooting"></a>
## Troubleshooting

| Symptom | Action |
|---|---|
| Missing commit/changed source at build | Retain verified archive metadata or supply actual reviewed checkout identity. Regenerate archive metadata after source changes. |
| SQLite release gate fails | Use the pinned image/accepted fixed runtime; development warnings are not production acceptance. |
| Schema missing/stale | Stop writers, back up and migrate explicit paths. Startup does not migrate. |
| Patch failure | Check lock/manifests; reinstall in a clean stopped copy. Do not weaken verification. |
| New group sends nothing | Review mode, location, module opt-in and schedule; `/add` starts off. |
| Settings inaccessible/expired | Open private chat and fresh `/settings` as current group administrator. |
| City search fails | Check GeoNames activation/account/quota/HTTPS; use known cities/coordinates if needed. |
| Unhealthy status | Inspect both heartbeat ages, schema, queue and logs; stopped schedulers have no fresh heartbeat. |
| Group not reopening | Use recovery/diagnostics for missing/unverified/conflicting snapshots; preserve original restrictions. |
| Missing/late reminder | Check timezone/date, quiet hours, holiday/cutoff policy, module and topic. Dispatch rechecks eligibility. |
| Custom emoji rejection | Set `CUSTOM_EMOJI_ENABLED=0`; definite eligibility failures also trigger fallback. |
| Permission-denied files | Check image UID/GID 10001 and working/data/session paths; run write probe. Avoid world-writable permissions. |

[More troubleshooting](docs/TROUBLESHOOTING.md) · [Privacy](docs/PRIVACY.md) · [Security reporting](.github/SECURITY.md)

<a id="project-and-development"></a>
## Project and development

| Path | Contents |
|---|---|
| `bot.php`, `src/` | Entry point; calendar/UI/scheduling/storage/Telegram/runtime services. |
| `bin/`, `migrations/` | Maintenance tools and immutable numbered schema changes. |
| `resources/` | Six locales, attributed Omer resources and PHP configuration. |
| `patches/` | Reviewed bases/replacements/diffs, provenance and SHA-256 manifests. |
| `tests/` | Offline tests and required JSON/TXT/PHP fixtures. |
| `scripts/` | Patches, quality, dependencies and local packaging. |
| `docs/`, `LICENSES/` | Guides, provenance and license texts. |
| `.github/` | CI/release workflows and contribution/security templates. |

Run **in a separate development copy at its root as developer**, without production state/credentials. Install includes test tools; tests use isolated temporary state.

~~~sh
composer install --no-interaction --prefer-dist
composer validate --strict --no-check-all
composer check-platform-reqs
php scripts/verify-patches.php
composer test
composer lint
composer style
composer analyse
composer check-locales
composer check-docs
composer self-check
~~~

`--no-check-all` omits generic version-range advice for the deliberate exact MadelineProto pin; strict schema/lock checks remain. `lint` checks syntax/quality; `style` enforces PHPCS errors; `analyse` runs PHPStan. `composer style-review` includes the broader style report; `composer style-fix` **rewrites files**, for reviewed development copies only.

At the same development root:

~~~sh
composer show --locked --format=json
composer licenses --format=json
composer audit --locked --format=json
composer dependencies
~~~

These report locked packages, installed licenses and current advisories; audit contacts advisory services. `dependencies` writes evidence under `docs/`; review/sanitize it before inclusion. Do not replace the lock to silence a check.

Developer-only patch regeneration, from the development root:

~~~sh
php patches/madelineproto/generate-artifacts.php
php patches/broadcastmanager/generate-artifacts.php
~~~

These commands rewrite patch artifacts/manifests from bundled reviewed sources. They are for an intentional patch review, not routine installation. Review the changed hashes/diffs and run the full patch checks afterward. The apply/verify PHP scripts also accept an optional first positional vendor directory; leave it unset for the normal `vendor/` path.

For local packaging with required development dependencies and verified identity:

~~~sh
php scripts/package.php
~~~

This writes application, corresponding-source and server-test ZIPs/checksums/manifests to `dist/`, without publishing. The separate delivery here uses `*-publication.zip` without installed dependencies and a separately inventoried corresponding-source bundle. Consult each manifest for inclusion/omissions; application source plus a lockfile is not a complete dependency source bundle.

[Architecture](docs/ARCHITECTURE.md) · [Dependencies](docs/DEPENDENCIES.md) · [Patches](docs/PATCHES.md) · [Release preparation](docs/RELEASE.md) · [Contributing](.github/CONTRIBUTING.md) · [Code of conduct](.github/CODE_OF_CONDUCT.md)

<a id="license-and-credits"></a>
## License and credits

The integrated application is **AGPL-3.0-only**: [LICENSE](LICENSE), [licensing policy](docs/LICENSING.md). Historical MIT notices/prior grants remain. Dependency, data and transcription licenses are separate; their headers/notices are preserved.

Credit to [WizardLoop](https://github.com/WizardLoop), [MadelineProto](https://github.com/danog/MadelineProto), [BroadcastManager](https://github.com/WizardLoop/BroadcastManager), [Hebcal](https://www.hebcal.com/), [GeoNames](https://www.geonames.org/) and attributed Hebrew Wikisource liturgy sources. Components, licenses, immutable revisions, modifications and attribution are recorded in [THIRD_PARTY_NOTICES.md](THIRD_PARTY_NOTICES.md), dependency manifests and resource provenance.

Operators/forks must make the **exact deployed corresponding source** available, including local application/dependency changes and necessary build material, and set `SOURCE_URL`. A different public `main` is insufficient. Publication material excludes private configuration, live DB/WAL/SHM, sessions, backups, logs and caches, while retaining required source, tests/fixtures, patch baselines and license texts.
