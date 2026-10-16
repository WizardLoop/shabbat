# Configuration

[English](../README.md) | [עברית](../readme-he.md) | [Installation](INSTALLATION.md)

The bot reads the private project-root `.env` as raw INI; nonempty process environment values override file values. Maintenance tools using `RuntimePaths::resolve()` also read that file. An explicit supported CLI path such as `--db` overrides the resolved database path. Keep the file as plain `KEY=value` lines, without interpolation or mixed comment conventions. The public [example](../.env.example) contains placeholders only.

| Variable | Requirement / default | Purpose and cautions |
|---|---|---|
| `API_ID` | Required | Telegram MTProto application ID from `my.telegram.org`. |
| `API_HASH` | Required | Matching private MTProto application hash. |
| `BOT_TOKEN` | Required | Token issued by BotFather for this bot. |
| `ADMIN` | Required for root operator access | Comma-separated trusted Telegram IDs/usernames resolved through the existing report-peer mechanism. Numeric IDs avoid username reassignment ambiguity. Delegated roles are managed separately in the bot. |
| `DATA_DIR` | Defaults to project `data`; Compose forces `/app/data` | Durable application and library state directory. |
| `APP_DB_PATH` | Defaults to `DATA_DIR/shabbat.sqlite`; example `data/shabbat.sqlite` | Application SQLite database. Relative paths resolve against the application root. |
| `SESSION_PATH` | Runtime fallback is project `bot.shabbat`; example `data/session/bot.shabbat`; Compose forces `/app/runtime/bot.shabbat` | Independent MadelineProto session. Protect the entire session state and avoid concurrent writers. |
| `LEGACY_DATA_PATH` | Example/default `.` | Old project root or data location used by the supported legacy bridge/import paths. Mount external legacy data read-only during rehearsal. |
| `HEARTBEAT_PATH` | Defaults to `DATA_DIR/scheduler-heartbeat.json`; example `data/scheduler-heartbeat.json` | Shared heartbeat document containing separate permission and notification timestamps. It is private runtime data. |
| `GEONAMES_USERNAME` | Optional; required for remote city/ID/GPS timezone lookup | Operator-owned account with web services enabled. Manual coordinates plus an explicit IANA zone remain available without it. |
| `GEONAMES_ENDPOINT` | `https://secure.geonames.org` | Validated HTTPS provider endpoint. Account/quota errors are provider failures, not empty city lists. |
| `CUSTOM_EMOJI_ENABLED` | `1`; set `0` for basic emoji | Enables supplied custom icons. Definite eligibility errors also trigger the guarded basic-emoji fallback. Client/account acceptance still needs a live test. |
| `SOURCE_URL` | Empty in unpublished example; required by deployment/source policy | HTTPS URL to the exact corresponding source of the deployed build. Missing/invalid values produce the source-offer warning; startup does not enforce this policy automatically. |
| `BUILD_COMMIT` | Optional process/Compose build metadata | Actual matching 40-hex commit for build identity. Not a bot credential and not a replacement for the source fingerprint of local modifications. |

`BUILD_COMMIT` is intentionally not filled in `.env.example`. Do not enter the old repository commit as if it described new unpublished files. Compose's `environment` section overrides the data/session values from its `env_file`. Keep the same Compose project name on upgrades so named volumes remain attached.

## Per-user and per-group preferences

Private preferences and authorized group settings are separate contexts. Each context has a language, location, IANA timezone and calendar methods. Group messages use the group language; a manager's private panel uses their personal preference. Location search/sharing previews do not save settings until the named context is confirmed.

The six catalogs are `he`, `en`, `ru`, `fr`, `es` and `pt-br`. Catalog completeness and placeholder parity are checked automatically; this is not a claim of independent native-speaker review or complete translation of provider scripture/liturgy. Hebrew source text and external provider strings may remain in their source language.

`/language` and `/lang` open the authorized language selector. `/setcommands all all preview` is an operator-only private preview; omitting `preview` writes command menus to Telegram. The menu language for Brazilian Portuguese is Telegram's `pt`, while application preferences use `pt-br`. Group administrator menus expose `/settings`. See the complete command tables in the READMEs and [operator guide](OPERATIONS.md).

New daily-zmanim/daf, tefillin and Omer scheduling modules are opt-in. Group locking, closing/opening notices and other content settings are independently configurable; imported legacy alerts use reviewed legacy settings. See [group settings](GROUP_SETTINGS.md), [methods](ZMANIM_METHODS.md) and [migration](MIGRATION.md).

Never publish the configured `.env`, sessions, private databases or backups. Provider errors and diagnostic exports should be reviewed before sharing because operational data can include group identifiers and preferences.
