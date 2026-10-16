# Implementation and acceptance status

Version: **3.0.0-dev.9**. This document describes the current prerelease source, not a production acceptance certificate. The application remains MadelineProto 8.7.0, PHP and SQLite. The publication follow-up updates Composer build tooling, protects immutable file bytes and documents deployment; it does not change bot behavior.

## Implemented paths and limits

| Area | Current implementation | Boundary / acceptance still needed |
|---|---|---|
| Shabbat and Yom Tov | Location/timezone-specific calendar; consecutive protected days; configurable candles/havdalah and operational offsets | Provider evidence must be valid; high-latitude missing solar times are not guessed; real timing/readback acceptance remains necessary |
| Group permissions | Fresh native permission capture, durable original snapshot, guarded close/open, conflict recovery panel | Old unverified snapshots require administrator review; Telegram cannot make concurrent administrator edits atomic with the bot |
| Group enrollment | `/add`, `/settings`, legacy group-list compatibility and explicit full import | `/add` creates a new group in off mode; first registration through `/settings` currently inherits lock mode. Review the shown mode before enabling a new real group |
| Calendar views | Nearest Shabbat, upcoming dates and Yom Tov entry/exit, daily zmanim, Daf Yomi and Omer | Provider text and Hebrew ritual text are not completely translated into every interface language |
| Scheduled content | Per-group opt-in daily modules, fixed/interval/solar tefillin reminders and Omer policies | Personal preferences supply views/settings, not automatic private reminder subscriptions |
| Delivery safety | Final dispatch eligibility, ordinary holy-period quiet, actual spacing/caps, durable uncertainty, bounded backlog | A configured closing notice is an intentional exception; an already-dispatched network request may arrive after the local boundary |
| Group presentation | Rich templates with entities/media, independent keyboards, share defaults, tefillin acknowledgement toggles | Telegram/client support, custom emoji eligibility and live media rendering require testing; fallback handles supported definite failures |
| Moderation | Configurable restriction flags and optional received-message cleanup | Other-bot visibility depends on Telegram delivery rules, BotFather mode/privacy and admin state; deletion also needs delete permission |
| Languages | Six matching catalogs and localized menus/panels | Key/placeholder parity is not native-speaker certification; provider errors, technical details and liturgy can retain source-language text |
| Location | Guided inline city/ID/coordinates/timezone/GPS flow with named-context confirmation | GeoNames account/quota needed for remote search/automatic timezone; sharing/search alone never saves settings |
| Administration | Delegated roles, subscriber directory, statistics, guarded broadcasts, system/tracking links and paged diagnostics | Networked sends, command installation, permission operations and restart have explicit authorization/side effects |
| Donations | Stars offers and custom 1-10,000 amount flow with payment validation/ledger | Real invoice/payment acceptance has not been established by offline tests |
| Scheduling and load | Independent permission/notification/broadcast lanes, four permission workers, bounded cycles, backoff/circuit cooldown | Synthetic load is not a Telegram capacity guarantee; concurrent slow native reads can occupy all slots |
| Persistence | 16 checksum-tracked migrations, explicit backup/import/restore, durable jobs and permission evidence | Startup validates schema rather than migrating; rollback needs compatible code, DB, session and library state |
| Maintenance | Bounded routine-history cleanup, separate heartbeats, queue metrics and explicit deep integrity check | Retained uncertain/recovery/interactive state may grow; operators still need monitoring, backups and archival policy |

## Verification policy

The existing test suites exercise fake transports, provider fixtures, controlled clocks, isolated databases, migration/restore, patch lifecycle and simulated load. The publication checks must be run against the final isolated source and its own locked Composer installation. Original dev.9 test results are historical evidence and are not automatically attributed to a modified publication tree.

A delivered `checks/PUBLICATION_REPORT.md` and machine-readable report identify the exact publication archives, source fingerprints, commands, actual results, skips and blockers. Those reports are separate delivery artifacts, not runtime source. The [publication status](PUBLICATION_STATUS.md) explains archive scope.

Local Windows limitations include unavailable Docker and POSIX-only/symlink-dependent test coverage. The local PHP-loaded SQLite 3.45.2 is deliberately rejected by the release gate; the Docker target pins SQLite 3.51.3 and must pass that gate. Do not describe an unexecuted CI run, image build or Telegram operation as successful.

## Required live acceptance

Before production cutover, use a separate bot and disposable groups to verify image startup, exact original-permission restoration, conflicts/recovery, automatic transitions, restarts and shutdown, inline/GPS/client behavior, source offer and payment flow where enabled. Exercise both forum and non-forum groups if used. Verify that the correct existing production data is imported; do not substitute the test bot's database.

GitHub publication is separately authorized and gated on the reviewed source and CI. It does not change production, restart the active bot or send Telegram messages.

## Documentation map

[English README](../README.md) · [עברית](../readme-he.md) · [Installation](INSTALLATION.md) · [Configuration](CONFIGURATION.md) · [Group settings](GROUP_SETTINGS.md) · [Calendar methods](ZMANIM_METHODS.md) · [User experience](USER_EXPERIENCE.md) · [Administration](ADMIN_PANEL.md) · [Migration](MIGRATION.md) · [Backup/restore](BACKUP_RESTORE.md) · [Delivery safety](DEV9_DELIVERY_SAFETY.md) · [Permission recovery](PERMISSION_RECOVERY.md) · [Diagnostics](DIAGNOSTICS.md) · [Licensing](LICENSING.md) · [Release preparation](RELEASE.md)
