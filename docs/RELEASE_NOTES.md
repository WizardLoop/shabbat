# WizardLoop Shabbat 3.0.0

**Stable release `v3.0.0`.** This release retains the dev.9 bot implementation, MadelineProto 8.7.0, SQLite, the locked application dependencies, all five approved patch sets and all 16 migrations. It promotes the reviewed implementation and updates release packaging, identity and deployment documentation. See [CHANGELOG](../CHANGELOG.md), [implementation status](IMPLEMENTATION_STATUS.md) and [production upgrade](PRODUCTION_UPGRADE.md).

## Acceptance and changes

On 2026-10-16 the owner confirmed successful live dev.9 group locking, unlocking, original-permission restoration and restart. These results support those paths; they do not establish real Shabbat/Yom Tov timing, a full holiday sequence, Telegram capacity under load, a long-running soak, every client or actual Stars payments. The production import and cutover remain operator actions with their own checks.

The stable packaging path is now part of the repository: `composer package` or `php scripts/package.php` builds the deployment and scoped corresponding-source archives from an exact clean Git commit. The dependency and preferred-source inventory is pinned, with patch and per-file verification. The former installed-tool packaging path remains explicit `--development` tooling and is not the release source offer. The image retains the PHP 8.4 branch with its security-patched 8.4.26 base; Composer remains pinned to 2.10.3.

The dev.9 publication corrections remain included: PHP 8.3-compatible transaction bookkeeping and native fixtures, immutable migration/resource/fixture bytes across Git line-ending settings, Composer tooling fixes, and reviewed deployment documentation. No existing migration or patch is rewritten.

## Included functionality

Location-aware Shabbat/Yom Tov closures preserve verified original group permissions. Private/group settings, six interface catalogs, inline/GPS location flows, rich templates, delegated administration, broadcasts, statistics, optional Stars donations and group opt-in zmanim/Daf/Tefillin/Omer modules remain available with the boundaries in [implementation status](IMPLEMENTATION_STATUS.md).

Dev.9 delivery safety remains in place: eligibility is rechecked near dispatch; Tefillin spacing/daily caps and Omer restoration gaps use actual delivery evidence; never-dispatched backlogs are bounded; uncertain outcomes remain conservative. Permission, notification and scheduled-broadcast callbacks are independent, with bounded work and separate heartbeats. Configured closing notices remain an intentional quiet-period exception, and a request already accepted by Telegram cannot be recalled by a local check. See [delivery safety](DEV9_DELIVERY_SAFETY.md).

## Dependencies and schema

- MadelineProto: exactly `8.7.0`, commit `7b99dd0f2d964a492c377912ffce9f5ce61aa9ed`.
- BroadcastManager: locked `3.2.2`, commit `f2792a383416b66bb68e55f09414563e540538d6`, with the reviewed local derivative.
- Five active patch sets: four MadelineProto compatibility patches and one BroadcastManager set, with original baselines, reviewed outputs, diffs and manifests.
- Migrations 001–016 remain required and checksum-protected. **No new migration is added after dev.9.** Startup validates schema; maintenance applies migrations explicitly.

[Dependency evidence](DEPENDENCIES.md) · [Patch workflow](PATCHES.md) · [Licensing and source scope](LICENSING.md)

## Production upgrade and rollback

The complete [production guide](PRODUCTION_UPGRADE.md) targets the existing file-based bot at `/root/shabat` (one `b`). Build the reviewed source beside production, stop the single old writer, retain the whole private directory and image, copy stopped state into distinct new volumes, preview/apply the explicit legacy import, compare exact group IDs/settings, rehearse restore, then move the reviewed source into `/root/shabat` and start one instance under `shabbat-production`.

Legacy permission snapshots remain unverified until an authorized administrator reviews the intended normal Telegram rights. The guide explains recovery, monitoring and a rollback that restores the original bind path before restarting the retained old container. A file restore does not undo Telegram actions already accepted. Test-bot databases and sessions never replace production state.

Existing SQLite dev.9 deployments need the usual stopped-writer backup and migration/schema check, but no legacy import or new SQL migration. Preserve matching source, database, MadelineProto session and BroadcastManager state. See [backup and restore](BACKUP_RESTORE.md).

## Release artifacts and identity

| Asset | Purpose |
|---|---|
| `shabbat-3.0.0.zip` | Clean deployment source and `composer.lock`, plus exact release `build-info.json`; no installed vendor or private runtime state. Docker installs locked runtime dependencies. |
| `shabbat-3.0.0-corresponding-source.zip` | Application plus 57 locked runtime package trees, licenses, reviewed patches and pinned preferred-source supplements, with exact scope/index. |
| `RELEASE_MANIFEST.json` | Release commit, version, source fingerprint, package scope and artifact hashes. |
| `SHA256SUMS` | SHA-256 records for both ZIPs and the release manifest. |

Keep archive build metadata unchanged. It identifies the actual release commit with `local_changes=false`, not the historical legacy baseline. Set `SOURCE_URL=https://github.com/WizardLoop/shabbat/releases/tag/v3.0.0` for this unchanged release after its corresponding-source asset is available. Modified deployments need their own exact source offer.

The source collection does not claim to include all operating-system/container or development-tool sources, or a reproducible rebuild of upstream binaries. Existing provenance/license findings remain visible in [third-party notices](../THIRD_PARTY_NOTICES.md) and [publication status](PUBLICATION_STATUS.md). No production `.env`, database/WAL/SHM, session, backup, private log or cache is a release asset.

## Verification boundaries

The stable release is gated on its own reviewed commit, hosted checks, image validation and final artifact/source comparisons. Exact results belong to the matching Actions run and release evidence; prior test counts are not reused as a result for a different tree. Local Windows PHP's SQLite 3.45.2 remains below the release gate; the Docker target pins SQLite 3.51.3. Publication creates the authorized source/tag/release only. It does not migrate the server, change group permissions or restart the active bot.
