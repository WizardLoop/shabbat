# Operations

[Bot commands](COMMANDS.md) · [Backup and restore](BACKUP_RESTORE.md) · [Production upgrade](PRODUCTION_UPGRADE.md)

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

## Operator services and Stars donations

The configured operator authorization callback is mandatory for `ManualGroups`. It is a separate bot-operator permission, not a cached group administrator role. Bind it to the project's existing trusted operator IDs and authenticated Telegram sender. Do not expose the service through an ordinary unprotected callback.

`ManualGroups::apply(true, operatorId, ttl)` closes registered groups; `apply(false, ...)` restores bot-owned original permissions. These operations use the same durable permission snapshots and fresh Telegram readback as scheduled locks. Existing administrator locks are retained, conflicts/missing snapshots remain visible, and partial failures are reported per group. They do not send success announcements themselves. Cancellation stops the batch without discarding already persisted snapshots.

The temporary typed override expires after one hour by default, with a maximum of 24 hours. The response includes the expiry and a visible override warning. A manual opening can explicitly override an active festival's group lock; it does not change religious calendar flags/times or authorize routine reminder delivery during a quiet period. The runtime calls `ManualGroups::desired(group, calendarDesired, utcNow)` and resumes the current calendar policy at the exact expiry. An administrator-prelocked group is never opened merely because the operator selected this command.

Migration `003_manual_payments.sql` adds `manual_override_mode` and `manual_override_until` to group settings. The change increments the group's settings revision, revokes old panels and replans future content while retaining permission restoration debt and delivery history.

The donation service preserves the existing 5, 25, 100, 150, 250 and 400 Telegram Stars offers. `Donations::createOffers(userId, language)` exports native MTProto invoices with currency `XTR`, one labeled price, DataJSON `{}`, and native green URL buttons. It does not configure a paid broadcast, subscription, recurring charge, payment-provider token, email, phone or shipping request. The root handler displays the returned text/buttons in private chat.

Each opaque invoice payload has an independently generated random nonce. SQLite stores its exact payer, Stars amount, currency, language, creation/expiry, export status and precheckout reservation. No payer ID or role is inferred from invoice text. `answerPrecheckout` checks all these fields and atomically binds the intent to one query before answering `messages.setBotPrecheckoutResults`. The RPC happens outside the database transaction. An expired, reused, wrong-payer, modified or unknown invoice is declined with an explicit `/donate` invitation to create a new offer.

Old three-part `donate|user|amount` payloads had no durable nonce/approved intent. They cannot be safely authenticated after migration and are explicitly declined instead of being silently accepted. Existing production records/files are not modified by these services or their tests. Operators should reconcile any already accepted, unverifiable legacy payment separately rather than inventing new accounting entries.

`recordPayment` is called only for an authenticated `messageActionPaymentSentMe` update. Its payer comes from the actual raw `from_id.peerUser`, not the embedded invoice payload. It validates exact amount/currency, requires precheckout approval, and records a unique charge and unique intent atomically. Re-delivery after restart returns duplicate without sending another thank-you. A service update arriving after invoice expiry can still be recorded when checkout was approved before expiry. A rejected or failed ledger write is visible and does not become a fake success.

Private database records include payer ID, amount/currency, approved query ID, charge UID and optional service message ID. Charge identifiers and invoice payloads are not returned in the success report or placed in audit/error logs; email and expanded Telegram payment-request information are ignored. Keep the application database and backups private. The `stats` API returns aggregate donation count and Stars only. Define record retention with the operator's accounting needs; this document does not assert legal compliance.

Implementation was checked against the locked MadelineProto TL schema: `inputMediaInvoice` requires DataJSON, `payments.exportInvoice` exports the URL, and `messages.setBotPrecheckoutResults` answers precheckout. Presets/native invoice shape come from the preserved WizardLoop implementation; the project retains its historic MIT copyright/permission notice. New service code is AGPL-3.0-only.

Tests use isolated SQLite databases and fake RPC/permission gateways. They cover authorization, snapshot restoration, override expiry, partial failures/cancellation, invoice binding/expiry, replay/conflicting charges, ledger rollback and restart. No Stars charge, real invoice or production Telegram group was used.

Invoice titles, descriptions, prompts and recoverable errors use the same six-locale resources as the rest of the private UI. Migration 004 expands the SQL language constraints without rewriting an existing approved intent or ledger record. Test invoice exports remain fake; no live Telegram invoice or Stars payment was made during development.

Live BroadcastManager status uses the bundled reviewed presentation hook and the six-language `BroadcastStatusFormatter`. Each operator's manager receives its localized initial caption, progress counters/status, and native colored controls with unchanged `bm` operation IDs. The formatter does not send, filter or mutate delivery state. The application verifies operator authorization before control actions. A legacy text-only library summary receives an explicit localized recovery notice rather than being guessed or retranslated; its original file is retained, and structured application progress/audit results remain the current status view. See [patch provenance and installation](../patches/broadcastmanager/README.md).

Composer/Docker/startup patch verification now covers the active Fresh Peer and BroadcastManager patch sets. With `--no-scripts`, run `php scripts/apply-patches.php` then `php scripts/verify-patches.php` before startup. Installation refuses an active writer or unknown/partially patched source. Reflection tests invoke the actual reviewed library formatting methods without constructing a Telegram API; live status editing/colors still require staging verification.
