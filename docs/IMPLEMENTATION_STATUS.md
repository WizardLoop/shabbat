# Implementation and acceptance status

**Release policy: publication requires successful mandatory checks on the exact final commit. Use the matching GitHub Actions run and release manifest to verify the tested source and artifacts.**

Version: **3.1.0**, targeting the locked official MadelineProto 8.11.10 / TL layer 232 release. Existing PHP/SQLite bot capabilities and migration bytes are retained. This page distinguishes implemented behavior, previous acceptance and checks still required on the final new tree.

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

## Recorded live acceptance

On 2026-10-16 the project owner reported successful live dev.9 tests of group locking, unlocking, restoration of the original permissions and restart. This is operator-reported evidence for those paths. It does not establish a real Shabbat/Yom Tov transition, a full holiday sequence, sustained load capacity, a long wall-clock soak, every client/language, payment flow or the legacy production migration.

That confirmation belongs to the earlier implementation. It is not live acceptance of MadelineProto 8.11.10, the new keyboard boundary or the 3.1.0 import procedure. No new 3.1.0 Telegram acceptance is claimed.

## Automated verification and host limits

Shutdown coverage exercises official SDK loop recovery/stop behavior and real disconnect/finalize methods with an in-memory transport and modeled delayed flush. The lock barrier must retain writer ownership until finalization completes. Loop `stop()` prevents rescheduling after the current body returns; it does not promise immediate cancellation of that body. These fixtures do not serialize a real session or exercise Telegram, an IPC daemon or Linux signals. Platform-specific worker checks must be evaluated on the relevant operating system.

The suites exercise fake transports, provider fixtures, controlled clocks, isolated databases, migration/restore, patch lifecycle and simulated load. Run them against the final reviewed 3.1.0 commit and locked installation. Earlier 3.0.0 hosted results do not replace this version's mandatory clean-install, regression, analysis, image and artifact checks. Publication requires those gates to pass on the exact final commit. Release manifests, SHA-256 sums and the matching GitHub Actions run identify the tested source/artifacts. Local checks directories are external evidence, not runtime source.

Local Windows limitations include unavailable Docker and POSIX-only/symlink-dependent coverage. The local PHP-loaded SQLite 3.45.2 is deliberately rejected by the release gate; the Docker target pins SQLite 3.51.3 and must pass that gate. Do not describe an unexecuted image build, CI run or Telegram operation as successful. See [publication status](PUBLICATION_STATUS.md) for source scope and [release preparation](RELEASE.md) for gates.

## Remaining deployment acceptance

On a separate bot and disposable groups, verify the feature paths your installation uses: conflicts/recovery, automatic protected-period transitions, interrupted work/shutdown, inline/GPS/client rendering, source offer, command menus, rich messages and payment flow where enabled. Exercise forum and non-forum groups if used. Observe a real scheduled close/open cycle and monitor queue/heartbeat behavior under your traffic; synthetic load is not a Telegram capacity guarantee.

The actual production path must first be verified: /root/shabbat is requested, while older instructions named /root/shabat. Legacy import needs a stopped full backup, exact group-ID/settings comparison, permission review, restore rehearsal and monitored cutover. Test-bot data is never substituted for production data. Follow [the production upgrade guide](PRODUCTION_UPGRADE.md). Publishing the source/tag/release does not perform these server actions or restart the active bot.

## Documentation map

[English README](../README.md) · [עברית](../readme-he.md) · [Installation](INSTALLATION.md) · [Configuration](CONFIGURATION.md) · [Group settings](GROUP_SETTINGS.md) · [Calendar methods](ZMANIM_METHODS.md) · [User experience](USER_EXPERIENCE.md) · [Administration](ADMIN_PANEL.md) · [Migration](MIGRATION.md) · [Backup/restore](BACKUP_RESTORE.md) · [Delivery safety](DELIVERY_SAFETY.md) · [Permission recovery](PERMISSION_RECOVERY.md) · [Diagnostics](DIAGNOSTICS.md) · [Licensing](LICENSING.md) · [Release preparation](RELEASE.md)
