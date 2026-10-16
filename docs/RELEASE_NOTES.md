# WizardLoop Shabbat 3.0.0-dev.9

**Local development snapshot, prepared for source review.** This version retains MadelineProto, SQLite and the existing bot features. Publication preparation organizes the source, documentation and license evidence; it does not change bot behavior or establish a stable/RC release. See [publication status](PUBLICATION_STATUS.md) for this preparation and [CHANGELOG](../CHANGELOG.md) for previous development versions.

## Current implementation

Dev.9 adds transport-time calendar and quiet-hour checks after provider, media, topic and emoji preflight; actual Tefillin spacing/daily caps; conservative handling of uncertain delivery evidence; and coalescing of never-dispatched reminder backlogs. Omer checks its restoration gap again at delivery. Configured closing notices remain an explicit exception to ordinary protected-period notification suppression.

Permission reconciliation, notifications and scheduled broadcasts use independent non-overlapping callbacks. Permission work has bounded concurrency; notification planning uses fair bounded batches. Shared provider requests and short failure cooldowns reduce repeated failed work. Queue/heartbeat metrics distinguish notification activity from completed permission work, and bounded maintenance preserves unresolved recovery and uncertain delivery evidence.

The reviewed BroadcastManager patch adds optional authorization, cancellation and admission hooks, cancellation-aware waits, joined worker/progress futures and cancelled queue persistence. Manual and scheduled bulk operations share one admission queue. The dependency keeps its native retry/error handling, with native flood waits exposed to manager-controlled retry. [Delivery and scheduler details](DEV9_DELIVERY_SAFETY.md) explain limits and exceptions.

## Dependencies and migrations

- Application version: `3.0.0-dev.9` from `VERSION`.
- MadelineProto: exactly `8.7.0`, commit `7b99dd0f2d964a492c377912ffce9f5ce61aa9ed`.
- BroadcastManager: locked `3.2.2`, commit `f2792a383416b66bb68e55f09414563e540538d6`, with the reviewed local derivative.
- Five active patch sets: four MadelineProto compatibility patches and one BroadcastManager set. Exact baselines, prior approved sources, diffs, added source and manifests remain included.
- Migration `016_delivery_safety.sql` is required. Migrations 001–015 and `composer.lock` remain preserved. Startup validates schema; migration is an explicit maintenance action.

See [dependency evidence](DEPENDENCIES.md), [patch workflow](PATCHES.md) and [technical attribution](THIRD_PARTY_DETAILS.md).

## Upgrade and rollback

Stop all writers, preserve verified application/database, MadelineProto session and BroadcastManager-state backups, then apply the migrations before startup. Follow [MIGRATION](MIGRATION.md) for the full legacy import; additive `DBgroups.txt` registration alone does not import the old templates, states or active permission obligations. Existing unresolved snapshots are retained and remain subject to the [verified permission recovery procedure](PERMISSION_RECOVERY.md).

Rollback restores matching pre-upgrade code and state while stopped. Older code cannot be assumed to understand the new schema. Review unresolved permission obligations before reverting. The [backup/restore guide](BACKUP_RESTORE.md) describes the separate state stores.

## Publication contents

The clean repository ZIP contains application source, resources, tests, migrations, scripts, locked Composer metadata, public configuration, Docker files, documentation, notices and required GitHub files. The separate corresponding-source ZIP adds the 57 Composer runtime packages and licenses, including the reviewed patched sources. Development tool distributions and external Docker/system runtime sources are outside that archive's stated scope. [LICENSING](LICENSING.md) explains source offers, retained grants and evidence limitations.

The publication README files are complete English and Hebrew counterparts. Technical hashes/history are kept in manifests and the provenance companion, leaving the third-party notices concise. Historical license grants and upstream source headers are preserved. No production configuration, session, database, WAL/SHM, private backup or log belongs in these artifacts.

## Verification limits

Exact current results and artifact identities belong to the external `checks/PUBLICATION_REPORT.md`, manifests and SHA-256 file, with a public summary in [PUBLICATION_STATUS](PUBLICATION_STATUS.md). Historical test counts are not reused as results for this publication copy. The local Windows PHP runtime's SQLite 3.45.2 is below the release gate; the Dockerfile pins checksum-verified SQLite 3.51.3, but a successful target image build must be demonstrated separately.

Offline tests use controlled clocks, isolated data and fake transports. They do not establish live Telegram delivery capacity, real flood quotas, accepted-request cancellation, rendered client behavior, actual Stars payments or a long wall-clock soak. A request already accepted by Telegram cannot be recalled by a local timing check. Missing Docker/live acceptance remains visible in the report; no production migration or bot restart is part of publication preparation.

MadelineProto's metadata/header license difference and historical local-reference provenance findings remain documented in [THIRD_PARTY_NOTICES](../THIRD_PARTY_NOTICES.md). Source packaging does not silently resolve them. No commit, tag, push, PR, merge, GitHub Release, image publication or deployment was created by this preparation.
