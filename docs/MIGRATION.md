For the running legacy installation at `/root/shabat`, follow the complete [production upgrade procedure](PRODUCTION_UPGRADE.md), including stopped backups, exact group verification and permission recovery.

# Dev.8 to dev.9

Stop all writers and use the verified backup/migrate sequence in [test deployment](SERVER_TEST_DEPLOYMENT.md). Migration 016 adds dispatch timestamps and indexes without changing prior migration files, permission snapshots or job payload/random IDs. Existing attempts that might have reached Telegram are conservatively marked uncertain. This can suppress additional Tefillin messages on the upgrade day rather than duplicate an unknown old delivery. Existing older unverified permission snapshots retain the dev.8 administrator-review requirement.

Rebuild the image for the reviewed BroadcastManager lifecycle patch. Do not modify a live vendor tree or remove data/session volumes. See [runtime changes and retention](DEV9_DELIVERY_SAFETY.md). Historical migration notes follow.

# Migration from the file-based bot

Keep the old bot stopped during explicit import and cutover. Do not run old and new writers together or delete the old MadelineProto session. These commands are operator instructions; development tests use isolated fixtures only.

1. Stop the bot and all maintenance writers. Copy the legacy directory to a protected read-only mount and retain the original files.
2. Initialize a new application database: `php bin/migrate.php --db=/path/to/shabbat.sqlite --writers-stopped`. For an existing application DB supply an existing `--backup-dir=/path/to/backups`; it is backed up and verified before any migration.
3. Inspect: `php bin/import-legacy.php --db=/path/to/shabbat.sqlite --legacy=/path/to/old-bot --dry-run`. The source can be the bot root or its `data` directory. With no destination DB, dry-run uses an in-memory database and does not create application records.
4. Compare report counts, templates, original rights, invalid JSON and recovery warnings with sampled legacy records.
5. Apply: `php bin/import-legacy.php --db=/path/to/shabbat.sqlite --legacy=/path/to/old-bot --apply --writers-stopped --backup-dir=/path/to/backups`.
6. Run health checks, test backup/restore on a separate path and verify a staging group before starting the new bot.

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

## Dev.6 to dev.7

Stop every writer and run the documented verified backup/migration command before starting dev.7. It adds **013_permission_recovery.sql** and **014_diagnostic_panels.sql** while preserving the exact checksums of migrations 001-012, group settings, original permission snapshots, pending conflicts and Telegram session data. The migration does not itself change Telegram permissions or resolve a conflict. Recovery happens only through the reviewed runtime rules or an authorized group-admin confirmation. See [test deployment](SERVER_TEST_DEPLOYMENT.md) and [permission recovery](PERMISSION_RECOVERY.md).

## Dev.6/dev.7 to dev.8

Dev.8 adds migration 015 for snapshot provenance. The first 14 migration files remain unchanged; upgrading dev.6 applies 013-015, while dev.7 applies 015. Stop all writers, preserve the same database/session volumes and run the verified backup/migration command before startup. Migration does not send Telegram requests or alter saved rights. An unresolved older snapshot requires explicit administrator resolution and cannot be automatically restored, even if its checksum is correct. Completed cycles can capture a fresh verified baseline. See the [affected-group procedure](DEV8_PERMISSIONS.md) and [current deployment commands](SERVER_TEST_DEPLOYMENT.md).
