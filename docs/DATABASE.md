Current runtime and migration rules: [delivery safety, independent scheduling, queue retention and verification limits](DELIVERY_SAFETY.md). Historical release-specific numbers below apply to their named versions.

# Application database

The application uses PDO SQLite, separate from MadelineProto's session storage. Set `APP_DB_PATH` to a durable volume path. Telegram IDs are decimal TEXT, so group/user IDs are never passed through floating point. A 64-bit PHP runtime is required for MTProto random identifiers.

`Database` enables foreign keys, WAL, FULL synchronous commits and a 100 ms busy timeout. SQLite operations are short and synchronous; a busy error is surfaced to the caller instead of waiting indefinitely inside the event loop. Do not yield, fetch providers, or call Telegram inside `Database::transaction()`. Maintenance runs separately with the same `<database>.writer.lock` as the bot.

`migrations/001_initial.sql` creates:

| Table | Purpose |
| --- | --- |
| groups / group_settings | Subscription, typed per-group options and monotonically increasing revision |
| users / user_preferences | Personal preferences independent of group settings |
| locations | Verified provider location metadata |
| message_templates | Text, entities, media and buttons with revision protection |
| notification_rules | Typed fixed, interval and relative rule storage |
| calendar_cache | Provider payloads, expiry and explicit coverage bounds |
| scheduled_jobs | Durable outbox, event uniqueness, UTC run/cutoff times, leases and retries |
| group_lock_state | Original permission snapshot, exact applied permissions, checksum and recovery state |
| delivery_log | Each RPC attempt, persistent random ID, outcome and uncertainty |
| conversation_states | Hashed selection token, user/scope/group/revision/action, expiry and single-use confirmation |
| audit_log | Settings/template/removal actions without storing secret tokens |
| legacy_imports | Read-only import provenance and original file hashes |
| schema_migrations | Applied file checksum and installation time |

JSON is used for bounded lists and structured entities, media, snapshots and provider payloads. Settings and scheduler statuses are typed columns; the bot is not serialized into one document.

Every group change uses an expected revision. Concurrent/stale panels fail with `StaleSettings`. Group changes invalidate older previews and unsent content jobs, retaining delivery history and permission restoration debt. Personal changes cannot alter group records. Token confirmation checks remote administrator rights outside the transaction, then atomically consumes the token and updates the expected settings revision.

Permission snapshots commit durably before Telegram mutation. A pre-existing administrator lock is never claimed by the bot. Restoration returns the captured rights instead of granting every permission. Current rights that differ from both the snapshot and exact bot-applied permissions produce a visible conflict requiring an administrator to resolve it. Missing/corrupt snapshots never trigger invented permissions. The coordinator evaluates the currently desired state, so an old opening record cannot open a group during a new prohibited sequence.

Outbox jobs use a unique group/module/event key and retain the same MTProto `random_id` across retries/restarts. Leases expire and abandoned attempts are marked uncertain. A lost response can still be ambiguous; this is not an absolute exactly-once guarantee. Before RPC the worker rechecks group activation, module, revision, ownership, cutoff and the application's calendar eligibility policy. FLOOD_WAIT is a deferred retry, with a bounded attempt count and expiry; it does not sleep the whole bot.

Use `php bin/health.php --db=/path/to/shabbat.sqlite` to report the SQLite version actually loaded in PHP, integrity, foreign keys, schema, job states and permission states. It does not invoke Telegram.

The local Windows test runtime loads SQLite 3.45.2, which predates the documented WAL-reset fix. Isolated single-writer functional tests do not qualify this runtime for release. Production requires 3.51.3 or a specifically reviewed fixed backport; Docker installs the checksum-verified 3.51.3 source and the release check rejects older unreviewed versions. See [installation](INSTALLATION.md), [migration](MIGRATION.md) and [backup/restore](BACKUP_RESTORE.md).

Migration 002 adds typed per-operator draft state. Migration 003 adds temporary manual override columns, payment intents and a unique donation ledger. Migration 004 expands the six language choices in group/personal/payment tables while retaining revisions, manual overrides, foreign keys, approved intents, delivery records and permission snapshots. Applied migration files are immutable; their stored checksum is verified on subsequent maintenance runs.

All six supported language codes (`he`, `en`, `ru`, `fr`, `es`, `pt-br`) are SQL-constrained independently in group settings, personal preferences and payment intents after migration 004. The migration recreates these typed tables with explicit complete column lists; it does not regenerate schema at runtime or disable foreign-key verification. Manual overrides and the donation ledger are retained with their original identities.

The explicit legacy importer stores only the reviewed admin-state whitelist in `admin_state`/`admin_directories`, with bounded exact bytes and provenance. `INSERT OR IGNORE` preserves existing edited drafts on repeated runs. Canonical positive `ids.txt` users populate `users`/default preferences without granting authorization. Automatic startup compatibility still reads only the old group list. Modern authenticated, expiring conversation tokens are never created from an old private draft/workflow file.

`known_broadcast_peers` (migration 005) stores known delivery audience IDs, reviewed peer type, title and import timestamps independently of `groups` registration. Unknown imported negative dialog IDs cannot become lock targets merely through this table. Explicit import is additive; delivery code must resolve a current Telegram type/permission before filtering or sending. This table is included in the application's SQLite backup. BroadcastManager's own internal records and MadelineProto session storage remain separate dependency state and require their separate stopped-writer snapshots; see [backup/restore](BACKUP_RESTORE.md).

## dev.4 additive migrations

Migrations 001-008 retain their exact original bytes/checksums. Upgrade stopped copies with the maintained backup/migration command; startup only validates and never silently applies schema changes.

| Migration | Added durable state |
| --- | --- |
| 009_admin_roles_links | Delegated bot-manager capabilities/revisions, system tracking-link configuration, HMAC visitor/daily counters and replay protection |
| 010_subscriber_directory | Known peer metadata and observed activity, explicit private-chat eligibility, saved per-manager filters/search/paging, observed group roles, broadcast authorization and per-operator broadcast options |
| 011_custom_star_amounts | Rebuilds the payment intent and dependent donation tables transactionally to allow integer amounts 1-10000 while preserving every existing intent, approval, charge, identity and FK/uniqueness constraint |
| 012_tefillin_acknowledgements | Typed enabled/label preferences, template buttons_mode (inherit/custom/none), tefillin rounds, verified message bindings, unique per-user votes, callback deduplication and durable keyboard-edit revisions/retry deadlines |

The directory's observed group roles are informational and never grant authority. Group-manager authorization is still checked freshly against Telegram. Configured recovery owners remain separate from delegated database roles. Private message audiences exclude newly observed group-only users until a real private interaction occurs; migration preserves previously known private registrations.

Acknowledgements are bound to a durable successful tefillin job, its confirmed native message IDs and exact random ID. Registration precedes completion of delivery bookkeeping so recovery can replay safely without resending. Votes and callback IDs are committed in short SQLite transactions; remote markup edits happen afterwards, are coalesced per message and respect durable rate-limit deadlines. Replacing/removing the default keyboard suppresses the acknowledgement.

Template source text/entities remain native, including an explicit empty entity array for plain text. A buttons-only template inherits current generated calendar content. Template preview candidates are transient user/group/revision-bound conversation state until explicitly confirmed.

## Dev.7 additive migrations

Migration **013_permission_recovery.sql** adds a separately checksummed recovery baseline, the last twenty permission operations per group (before/requested/observed state, stage and outcome), and expiring hashed one-use recovery confirmations. Explicit administrator resolutions retain original snapshot evidence and the selected baseline in the audit log. No existing snapshot or earlier migration is rewritten by installation.

Migration **014_diagnostic_panels.sql** adds one expiring diagnostic snapshot per manager, private panel binding, view/search/filter/input state, fenced interaction ownership and export throttling/deduplication. Navigation uses stored data instead of refetching the provider or all Telegram groups. Sensitive configuration/session data is excluded from diagnostic capture.

All writers must stop for backup and migration. A dev.6 database applies 013 and 014; a fresh database applies all fourteen files, and a repeated migration applies none. Startup verifies every migration checksum. See [recovery](PERMISSION_RECOVERY.md), [diagnostics](DIAGNOSTICS.md) and [deployment](SERVER_TEST_DEPLOYMENT.md).

## Permission snapshot provenance (dev.8)

Migration 015 retains all old snapshot/applied bytes and records whether a baseline came from the verified fresh native reader. Existing rows default to legacy unverified status. Original checksums only establish byte consistency. Unresolved legacy cycles cannot restore until the administrator reviews the actual normal permissions through the guarded recovery workflow. Capture evidence and provenance accompany new cycles; recovery audit history retains the former snapshot.
