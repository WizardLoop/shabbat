# 🕯️ Shabbat Bot

A Telegram bot for Shabbat and festival group quiet periods, Jewish calendar information and optional reminders. Built with PHP, MadelineProto and SQLite.

[English](README.md) | [עברית](readme-he.md)

[![License: AGPL-3.0-only](https://img.shields.io/badge/license-AGPL--3.0--only-blue)](LICENSE)
[![PHP 8.3–8.5](https://img.shields.io/badge/PHP-8.3%E2%80%938.5-777BB4)](composer.json)
[![MadelineProto 8.11.10](https://img.shields.io/badge/MadelineProto-8.11.10-2CA5E0)](composer.lock)

**Version 3.1.1.** Release requirements and acceptance limits are described in [implementation status](docs/IMPLEMENTATION_STATUS.md). For an existing installation, start with the [upgrade guide](docs/PRODUCTION_UPGRADE.md) ([עברית](docs/PRODUCTION_UPGRADE_HE.md)); the commands below create a new database.

[Features](#features) · [Requirements](#requirements) · [Install](#install) · [Configure](#configure) · [First use](#first-use) · [Operate and update](#operate) · [Documentation](#documentation) · [License](#license)

<a id="features"></a>
## What it does

- Close and reopen groups for Shabbat and festivals, with verified original permissions and guarded recovery.
- Choose each group's location, timezone, Israel/diaspora calendar, candle lighting and havdalah settings.
- Show daily zmanim and Daf Yomi; optionally schedule zmanim, Daf, tefillin and Omer reminders.
- Manage groups through private panels; customize messages, media and buttons.
- Provide delegated bot administration, broadcasts, statistics and optional Telegram Stars donations.

Six interface catalogs are supplied: Hebrew, English, Russian, French, Spanish and Brazilian Portuguese. External content and Hebrew ritual text can remain in their original language. See [group settings](docs/GROUP_SETTINGS.md) for details.

<a id="requirements"></a>
## Before you begin

You need a Linux server with persistent storage, internet access, **Docker Engine and the Docker Compose plugin**, plus:

| Item | Where to obtain it |
|---|---|
| Telegram API ID and API hash | [Telegram application management](https://my.telegram.org/apps) |
| Bot token | Create/select your bot with [BotFather](https://t.me/BotFather) |
| Administrator ID | Your trusted Telegram user ID; multiple IDs are comma-separated |
| GeoNames username | [GeoNames account](https://www.geonames.org/login) with web services enabled, for city/automatic timezone search |

Install [Docker Engine for your Linux distribution](https://docs.docker.com/engine/install/) and [the Compose plugin](https://docs.docker.com/compose/install/linux/), then check:

~~~sh
docker --version
docker compose version
~~~

Docker supplies PHP 8.4.27, Composer, SQLite, igbinary 3.2.16 for session compatibility and the locked dependencies. Manual installations require 64-bit PHP **8.3.36+, 8.4.27+ or 8.5.12+**, within those supported branches. See the [22 October 2026 security baseline](docs/INSTALLATION.md#php-security-baseline) and [manual installation](docs/INSTALLATION.md#manual-installation).

<a id="install"></a>
## 1. Extract a fresh installation

Download `shabbat-3.1.1.zip` and `SHA256SUMS` from the matching [GitHub release](https://github.com/WizardLoop/shabbat/releases). Run in **Bash on the server**, in the upload directory, with Docker access. The checksum must report `OK`.

~~~sh
set -euo pipefail
umask 077
awk '$2 == "shabbat-3.1.1.zip" {
  if (NF != 2 || length($1) != 64 || $1 ~ /[^0-9a-f]/) exit 1
  print; n++
} END { if (n != 1) exit 1 }' SHA256SUMS > deployment.sha256
sha256sum --check deployment.sha256
test ! -e shabbat-3.1.1
unzip -q shabbat-3.1.1.zip -d shabbat-3.1.1
cd shabbat-3.1.1
cp .env.example .env
chmod 600 .env
unset BUILD_COMMIT
~~~

Keep the supplied `build-info.json`. Do not add `BUILD_COMMIT` to `.env` for an unchanged release archive. Never extract an upgrade over a running installation.

<a id="configure"></a>
## 2. Configure the bot

Edit `.env` privately. Replace the example credentials and administrator ID:

~~~dotenv
API_ID=123456
API_HASH=replace_with_your_32_character_api_hash
BOT_TOKEN=replace_with_your_bot_token
ADMIN=123456789
GEONAMES_USERNAME=your_geonames_username
SOURCE_URL=https://github.com/WizardLoop/shabbat/releases/tag/v3.1.1
~~~

Keep the other values from `.env.example` unless an advanced setting is needed. Never publish this file or use test credentials in production. The source URL must offer the exact deployed source, including the corresponding-source archive. See [all configuration variables](docs/CONFIGURATION.md).

From the extracted project root, build and check the image, then initialize the **new empty database**. These commands do not start the bot:

~~~sh
docker compose build shabbat
docker compose run --rm --no-deps shabbat php bin/self-check.php --release
docker compose run --rm --no-deps shabbat php bin/igbinary-check.php
docker compose run --rm --no-deps shabbat php bin/runtime-permissions.php --require-non-root
docker compose run --rm --no-deps shabbat php bin/migrate.php --writers-stopped --db /app/data/shabbat.sqlite
~~~

Start when ready to connect to Telegram:

~~~sh
docker compose up -d shabbat
docker compose ps
docker compose logs --tail=100 shabbat
docker compose exec shabbat php bin/health.php
~~~

Health can take the initial 90-second startup period to become ready. The service is `shabbat`. Named volumes `bot-data` and `madeline-session` preserve application and session data; their full names include the Compose project name.

<a id="first-use"></a>
## 3. Set up Telegram and your first group

1. In BotFather, allow group additions. Enable `/setinline` for inline location/calendar search.
2. Send `/start` privately. Check the home buttons, `/preferences` and your language.
3. Add the bot as a group administrator with permission to restrict members. Optional deletion/pinning requires the corresponding rights.
4. As a group administrator, send `/add` **in the group**. This creates a new group in **off** mode and opens setup.
5. Review location, timezone, calendar, mode and candle/havdalah methods. Enable only the reminders you want. Return through `/groups` privately or `/settings` in the group.

Use `/add` for first setup: direct `/settings` registration currently inherits lock mode. Review the defaults before enabling a real group. Bot administrators and group administrators have separate permissions.

[All bot commands](docs/COMMANDS.md) · [Group settings](docs/GROUP_SETTINGS.md) · [Location and inline search](docs/INLINE_GEONAMES.md)

<a id="operate"></a>
## Everyday operation and updates

Run **on the host, from the installed project root**, with Docker access and the same Compose project identity:

| Action | Command | Effect |
|---|---|---|
| Start | `docker compose up -d shabbat` | Start/connect the bot |
| Stop | `docker compose stop shabbat` | Stop the bot; retain volumes |
| Restart | `docker compose restart shabbat` | Restart the current image |
| Status | `docker compose ps` | Display service state |
| Logs | `docker compose logs --tail=100 -f shabbat` | Follow logs; Ctrl+C stops the log view |
| Health | `docker compose exec shabbat php bin/health.php --deep` | Check the running scheduler/database |

**Updating requires a stopped-writer backup and migration check.** Follow the [upgrade guide](docs/PRODUCTION_UPGRADE.md) ([עברית](docs/PRODUCTION_UPGRADE_HE.md)) for verified paths, staging, import or SQLite upgrade, session preservation and rollback. Rebuilding alone does not migrate a database; `restart` alone does not install a new image. Do not run `composer update` for an ordinary deployment or remove data volumes.

<a id="documentation"></a>
## More help

| Topic | Guide |
|---|---|
| Detailed installation and manual PHP setup | [Installation](docs/INSTALLATION.md) |
| Environment variables and preferences | [Configuration](docs/CONFIGURATION.md) |
| Commands and administration | [Commands](docs/COMMANDS.md), [operations](docs/OPERATIONS.md), [admin panel](docs/ADMIN_PANEL.md) |
| Upgrade, import and recovery | [English](docs/PRODUCTION_UPGRADE.md), [עברית](docs/PRODUCTION_UPGRADE_HE.md), [import mapping](docs/MIGRATION.md), [backup/restore](docs/BACKUP_RESTORE.md) |
| Permissions, timing and reminders | [Permission recovery](docs/PERMISSION_RECOVERY.md), [calendar methods](docs/ZMANIM_METHODS.md), [delivery safety](docs/DELIVERY_SAFETY.md) |
| Problems and diagnostics | [Troubleshooting](docs/TROUBLESHOOTING.md), [diagnostics](docs/DIAGNOSTICS.md) |
| Development and validation | [Contributing](.github/CONTRIBUTING.md), [architecture](docs/ARCHITECTURE.md), [acceptance status](docs/IMPLEMENTATION_STATUS.md) |
| Releases and source packaging | [Changelog](CHANGELOG.md), [release notes](docs/RELEASE_NOTES.md), [release process](docs/RELEASE.md) |

<a id="license"></a>
## License and credits

The application is **AGPL-3.0-only**. Preserve its [license](LICENSE), historical copyright notices and [third-party notices](THIRD_PARTY_NOTICES.md). Dependency licenses remain separate. MadelineProto, BroadcastManager, Hebcal, GeoNames and bundled resource sources are credited there.

Operators must offer the exact corresponding source of deployed modifications. The separate corresponding-source ZIP includes the application and documented runtime sources, with no claim to the whole operating system or every development tool. See [licensing](docs/LICENSING.md).
