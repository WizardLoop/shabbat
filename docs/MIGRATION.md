# Database upgrade and legacy import

Use the [production runbook](PRODUCTION_UPGRADE.md) ([עברית](PRODUCTION_UPGRADE_HE.md)) to verify whether the real installation is at /root/shabbat or /root/shabat, back up stopped state and preserve the session. Existing 3.0 SQLite deployments keep their data and run the migration/schema gate; they do not run legacy import again. Version 3.1.0 adds no SQL migration and retains migrations 001–016 unchanged.

## File-based legacy import

Keep the old bot stopped during explicit import and cutover. Do not run old and new writers together or delete the old MadelineProto session. These commands are operator instructions; development tests use isolated fixtures only.

1. Stop the bot and all maintenance writers. Copy the legacy directory to a protected read-only mount and retain the original files.
2. Initialize a new application database: `php bin/migrate.php --db=/path/to/shabbat.sqlite --writers-stopped`. For an existing application DB supply an existing `--backup-dir=/path/to/backups`; it is backed up and verified before any migration.
3. Inspect: `php bin/import-legacy.php --db=/path/to/shabbat.sqlite --legacy=/path/to/old-bot --dry-run`. The source can be the bot root or its `data` directory. With no destination DB, dry-run uses an in-memory database and does not create application records.
4. Compare report counts, templates, original rights, invalid JSON and recovery warnings with sampled legacy records.
5. Save and review the private dry-run JSON, then apply with `php bin/import-legacy.php --db=/path/to/shabbat.sqlite --legacy=/path/to/old-bot --apply --writers-stopped --backup-dir=/path/to/backups --expect-report=/private/path/import-preview.json`.
6. Check schema/integrity, compare the import result and rehearse restore on a separate path before startup. Live health additionally requires recent scheduler heartbeats; run it after authorized startup.


## Preview, comparison and repeatability

Dry-run and apply parse the same group and personal templates. Reports are private: they contain group/user identifiers and source-derived preferences.

- `report_version: 1` identifies the schema; a reviewed preview has `dry_run: true`.
- `source_sha256`, `destination_sha256` and `plan_sha256` bind the source, affected destination records and planned import. `--expect-report=PATH` refuses a changed source/target/plan before application records are written.
- `duplicates` records canonical group/audience duplicates. `orphan_group_directories` lists directories not present in the subscription list; they are reported for review, never silently re-subscribed. `group_list_present` distinguishes a missing subscription list.
- Group comparisons include `source_settings` and `different_settings`. Template/draft comparisons include source/destination value hashes and `matches_source`. Existing destination rows are preserved even when they differ.
- Successful apply returns `verification.status = passed`, checked/inserted/preserved record counts, integrity and foreign-key results. The CLI also reports the verified `pre_import_backup` path, SHA-256 and integrity.
- The import is additive but not one all-or-nothing transaction. On failure keep every writer stopped, retain the report and state, inspect or restore the verified pre-import backup, then create a fresh preview before retrying.

To include a genuinely active directory-only group, reconcile it against the operator's subscription evidence in a separate reviewed import copy. Preserve the original snapshot and regenerate the preview; appending every old directory would also reactivate stale groups. Unsupported/malformed files and local extensions require explicit review.

The startup compatibility bridge reads only `data/DBgroups.txt` from `LEGACY_DATA_PATH` and adds missing subscribed group records. Existing groups therefore use `/settings` without re-registering using `/add`. It does not migrate schema, overwrite edited preferences, remove groups or restore permissions. Explicit maintenance import is still required to review templates, drafts, snapshots and old lock state.

Import is additive and repeatable. Edited/new database settings and templates are preserved. All new daily zmanim/daf, tefillin and Omer modules remain off for imported groups. Existing alert flags determine the old times and opening/closing message behavior. Personal/private drafts are imported separately and an old selected group file never confers administrator permission.

The pinned legacy source requested Jerusalem GeoName 281184 with `b=18&M=on`. Hebcal's Jerusalem city rule uses forty minutes despite that query value; city-default `candle_minutes=null` preserves that behavior. Legacy closure is ten minutes before candles; opening is at provider havdalah, with no extra offset. The old opening-check helper omitted the closure subtraction; the importer reports that mismatch instead of carrying separate inconsistent engines forward.

`MsgOpener*` / `MsgCloser*` preserve text, MTProto entities, file identifiers, custom buttons/styles/icons. Malformed metadata is reported; original sources are retained. `chatb1.json` is imported with a checksum and a recovery-required status. Global `state_open.txt` and lock dates are provenance for review, never a source of today's event/week. A global state without a group's original snapshot produces a visible review requirement, not a mass opening or guessed permissions.

Migration never invokes Telegram. At runtime current calendar policy and fresh permission reads decide recovery. A malformed/missing snapshot or manual conflict needs an authorized administrator response. Re-import does not erase that obligation.

Schema migration files are immutable once applied: a changed checksum aborts. Rollback after a schema upgrade requires restoring a verified compatible database plus the matching application version. It does not reverse permission RPCs already accepted by Telegram. Resolve outstanding bot-owned locks before decommissioning or removing a group. See [database](DATABASE.md) and [backup/restore](BACKUP_RESTORE.md).

Explicit rich import also reads the canonical root `ids.txt` for known positive int64 user IDs. Duplicates are ignored; negative dialog IDs do not become users or new group registrations. Known users gain no operator/group-administrator authority. Existing personal preferences are preserved. The `idsnew.txt` pagination snapshot is retained as draft bytes but is not a separate authority or subscription source.

The exact reviewed admin draft names are copied additively into `admin_state`, preserving original bytes: private `grs1.txt`, `messagetodelete.txt`, `txt.txt`, `ent.txt`, `media.txt`, and optional per-operator `BUTTONS.txt`, `broadcastsend.txt`, `pinmessage.txt`, `page_var.txt`; global `data/BUTTONS.txt`, `broadcastsend.txt`, `pinmessage.txt`, `startline_cat.txt`, `endline_cat.txt`, `page_var.txt`; and root `ids.txt`, `idsnew.txt`. Per-user buttons take precedence in normalized personal templates, with global legacy buttons as fallback. Existing SQLite drafts/options are never overwritten by re-import. The importer does not read session/auth/config/secret files, call a broadcast RPC, infer roles from old workflow state or create a new authenticated conversation token. Draft values above 1 MiB are reported and retained in the source for review.

Migration `004_expand_languages.sql` is an explicit static table-copy migration, run with stopped writers and a verified backup. It preserves every earlier group/personal field, revisions, imported timestamps, temporary manual overrides, payment IDs/nonce/payer/amount/query/charge bindings and foreign keys while allowing all six configured languages. Delivery jobs/random IDs, permission snapshots, templates, conversations, audit history and operator drafts remain intact. An interrupted transaction rolls back; a migration checksum mismatch aborts. Isolated preservation tests compare every pre-existing row and verify foreign keys after upgrading from 001–003.

Migration 005 creates `known_broadcast_peers` independently of Shabbat subscriptions. Explicit canonical `ids.txt` import retains valid positive and negative int64 delivery peer IDs with type `unknown`, without calling Telegram. A negative legacy channel/chat ID therefore remains available to the operator's audience workflow without registering it for Shabbat locking. Current per-peer type/title edits are preserved on re-import; actual Telegram peer type and permissions must be refreshed before any delivery. The startup group-list bridge does not import this audience table.

## Migration008: group controls and independent cleanup

Run the numbered migration only during stopped-writer maintenance. It adds group-only lock_block_flags (default21, including send_reactions/edit_rank), two holy-message cleanup switches and tefillin_delete_previous, all cleanup switches defaultfalse. It preserves original lock snapshots while keeping an in-flight previous applied mask, and adds durable deletion work plus confirmed tefillin message pointers. Existing group zmanim rich designs are copied once into independent times_alert templates while their original rows/IDs remain. Sent reminders receive separate replayable bookkeeping; no previously sent job is returned to pending to trigger deletion. The earlier seven migration checksums remain immutable. SQLite backups include these tables; restore the full schema/data together.

## Older database and permission state

Migrations 013–015 add recovery evidence, diagnostics and snapshot provenance without changing the earlier migration bytes. Older unresolved snapshots remain unverified and require the [administrator recovery procedure](PERMISSION_RECOVERY.md); a matching checksum alone cannot authorize restoration.

Migration 016 adds dispatch timestamps/indexes and conservatively marks older delivery attempts that might have reached Telegram as uncertain. This can suppress an additional reminder rather than duplicate an unknown old delivery. The independent scheduler and retention behavior are described in [delivery safety](DELIVERY_SAFETY.md). Never delete unresolved recovery or delivery evidence to bypass an upgrade check.
