# Shabbat 3.1.0

**Release policy: publication requires successful mandatory checks on the exact final commit. Use the matching GitHub Actions run and release manifest to verify the tested source and artifacts.**

Automated release checks and deployment acceptance have separate scopes. See [release requirements](RELEASE.md) and [implementation status](IMPLEMENTATION_STATUS.md).

## Changes

- Update MadelineProto to official stable 8.11.10 / TL layer 232 with a compatible stable Composer range and exact lock.
- Retire upstreamed UpdateLoop/cancellation, GeoPoint and formatted-date patches; retain the reviewed version-matched Fresh Peer and BroadcastManager changes where still required.
- Update the keyboard compatibility boundary for new inline constructors while preserving saved templates, callback IDs, styles/icons and distinct reply keyboards.
- Recognize the current Telegram permission fields. The new `manage_linked_peers` restriction is opt-in, including explicit **All** selection; the existing 21-field default and saved masks are unchanged. Administrator-rights diagnostics cover all 19 current fields.
- Add comparable dry-run/apply import plans, duplicate/directory-only group reporting, optional reviewed-report binding and post-import verification. Existing destination settings and unverified permission evidence remain protected.
- Shorten the English/Hebrew README pair and consolidate obsolete development reports into current references. Provide parallel guarded production instructions, including a Hebrew guide.

Existing group/calendar modules, languages, rich templates, administration, Stars donations, SQLite data, session handling and all 16 immutable migrations remain part of the application. See [keyboard compatibility](KEYBOARD_COMPATIBILITY.md), [implementation status](IMPLEMENTATION_STATUS.md), [changes](../CHANGELOG.md) and [patch documentation](PATCHES.md) for actual boundaries.

## Upgrade manually

Follow the [English production guide](PRODUCTION_UPGRADE.md) or [מדריך השדרוג בעברית](PRODUCTION_UPGRADE_HE.md). Verify /root/shabbat versus /root/shabat from the actual production container, stage the image separately, stop the old writer and preserve its whole folder, data, session, configuration, BroadcastManager state and image before cutover.

File-based installations preview/apply the bound import and review each warning. Existing 3.0 SQLite installations keep their data and compare it around the migration/schema check; they do not run legacy import. Old permission snapshots never become trusted solely because they have a valid checksum. Rollback retains old local state but cannot undo Telegram actions already accepted.

## Assets and exact source

| Asset | Purpose |
|---|---|
| `shabbat-3.1.0.zip` | Deployment source, lock and exact release build identity; Docker installs dependencies. No installed vendor or private state. |
| `shabbat-3.1.0-corresponding-source.zip` | Application, documented locked runtime sources, licenses, active patches and required preferred-source supplements. |
| `RELEASE_MANIFEST.json` | Actual commit, version, fingerprint, scope and artifact hashes. |
| `SHA256SUMS` | Checksums for both ZIPs and the manifest. |

Keep archive build-info.json unchanged and leave BUILD_COMMIT out of the private .env when using an unchanged release ZIP. Set SOURCE_URL to https://github.com/WizardLoop/shabbat/releases/tag/v3.1.0 once its corresponding-source asset is available. Modified deployments need their exact source offer.

The source scope excludes the complete operating system/container and optional development-tool source; consult [licensing](LICENSING.md), [third-party notices](../THIRD_PARTY_NOTICES.md) and the source manifest.

## Acceptance limits

Shutdown tests use the official SDK loop and disconnect/finalize paths with an in-memory transport and modeled flush. The loop `stop()` contract prevents rescheduling after the current body returns; safe application shutdown also requires finalization before releasing writer ownership. These fixtures do not establish real session serialization, Telegram delivery, IPC-daemon shutdown or Linux-signal acceptance.

The owner reported dev.9 locking, unlocking, original-permission restoration and restart on 2026-10-16. Those are historical results, not new 3.1.0 acceptance. Tests with fake transports cannot establish real client rendering, actual /start and callback delivery, complete holy-day timing, paid operations, sustained load or a long soak. Record final automated/image/archive checks separately and complete relevant isolated live checks before production use.

Publishing GitHub assets does not deploy to the server, migrate production, change group permissions or restart the active bot.
