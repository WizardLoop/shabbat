# Release preparation and publication

The version in [VERSION](../VERSION) is **3.0.0**, stable. The owner confirmed dev.9 live locking, unlocking, original-permission restoration and restart. Broader holy-day, load, soak and feature-specific acceptance remain separate. See [release notes](RELEASE_NOTES.md), [implementation status](IMPLEMENTATION_STATUS.md) and [licensing/source scope](LICENSING.md).

## Review and publication sequence

Work in an isolated checkout without production credentials or state. Require green checks on the reviewed PR, merge it, and verify the exact resulting commit before creating `v3.0.0` and a stable GitHub release. Release artifacts must identify that actual commit and fingerprint with `local_changes=false`. Tagging and publishing require the owner's authorization; none of the commands below publishes or deploys.

The release provides `shabbat-3.0.0.zip`, `shabbat-3.0.0-corresponding-source.zip`, `RELEASE_MANIFEST.json` and `SHA256SUMS`. The deployment ZIP contains clean repository source plus build identity, without installed vendor. The corresponding-source ZIP adds the locked runtime sources, licenses, reviewed patches and pinned preferred-source supplements. The manifest states the scope and exclusions; no claim covers the complete Docker operating system or all development-tool source.

## Reproduce quality checks

Run at the root of a disposable checkout, as its nonroot owner with write access, with the supported PHP/Composer runtime. The locked installation writes only this checkout's dependencies and applies the approved patches. Never install or patch dependencies inside an active bot tree.

```sh
composer validate --strict --no-check-all
composer install --no-interaction --prefer-dist
composer check-platform-reqs
php scripts/verify-patches.php
composer test
composer lint
composer analyse
composer style
composer check-docs
composer check-locales
php bin/self-check.php
php bin/self-check.php --release
```

`--no-check-all` omits generic version-range advice for the deliberate MadelineProto pin while retaining strict manifest/lock validation. Installation/upgrades use `composer install` with the existing lock; dependency changes require separate review. The release self-check enforces the loaded SQLite/target runtime gate. Do not bypass it on an older host runtime.

Record the locked inventory and current advisory evidence from that same installation:

```sh
composer show --locked --format=json
composer licenses --format=json
composer audit --locked --format=json
php scripts/dependencies.php
```

The first three display reports; the audit requires network access. `scripts/dependencies.php` updates dependency documentation and validates bundled data/license artifacts. Review any resulting source changes and commit them before release packaging; the final source must match the reviewed commit. Composer 2.10.3 is pinned in workflows and the image.

## Build the target image

From the fresh checkout root, with Docker Engine access and Compose v2:

```sh
cp .env.example .env
export BUILD_COMMIT="$(git rev-parse HEAD)"
docker compose build --no-cache shabbat
```

Use the example only in a fresh copy; never overwrite a configured `.env`. No real Telegram credentials are needed for the image build. It installs locked runtime dependencies, verifies patches and runs offline platform/filesystem checks without starting the bot. This checkout example supplies its actual commit. When building a release ZIP instead, retain its `build-info.json`, leave `BUILD_COMMIT` out of `.env` and unset an inherited shell value; do not override the archive identity.

## Build the release artifacts

Prerequisites, in addition to the locked development installation above: **Git and Python 3.9+**, a clean committed checkout, and enough space for two archives. Python uses only its standard library. The checkout must preserve exact committed bytes: line-ending conversion is rejected even if Git considers a file unchanged. Use a fresh checkout with `core.autocrlf=false` and preserve the repository attributes; do not rewrite migration, resource or fixture bytes to make packaging pass. The PHP wrapper selects `python3` (`python` on Windows); set `PYTHON` to the desired executable if necessary. These requirements apply to release packaging, not normal Docker/manual bot operation.

Run at the reviewed checkout root as its owner:

```sh
composer package
```

The equivalent command is `php scripts/package.php`. It writes the two release ZIPs, `RELEASE_MANIFEST.json` and `SHA256SUMS` to `dist/`, reading the exact Git commit and verified installed runtime package sources. The committed source-input manifest pins preferred-source supplements and runtime file hashes; source mismatch is a failure, not a reason to relax checks. `--output` selects an alternate output directory. Consult the packaging scripts and manifest for supported inputs.

The explicit compatibility command `php scripts/package.php --development` invokes the old local development packager. It writes its three installed-vendor archive variants under `dist/development/` by default to keep them apart from release assets. These are development tools, and are **not** the release corresponding-source artifact. A tool PHAR is not its preferred source. Never publish that output under the stable release source offer.

After successful packaging, check the external checksums from the output directory:

```sh
cd dist
sha256sum --check SHA256SUMS
```

This reads the two ZIPs and manifest. Inspect the archives themselves: exact committed application bytes, public configuration/dotfiles, English/Hebrew docs, scripts, tests/fixtures, resources, immutable migrations, patch baselines/outputs and license material must be present. The source archive must match its runtime/package/supplement inventory. Extract the deployment ZIP into a fresh disposable directory and verify locked installation, patch checks and relevant quality/image checks there.

Exclude `.git`, actual `.env`, sessions, application/runtime databases and WAL/SHM, backups, logs, caches, coverage and private work material. Required JSON/TXT fixtures and baselines remain source. Do not copy test-created state back into the archives. Record exact commit, fingerprint, CI results, asset hashes and remaining limitations before publishing the authorized release.

## Production remains a separate action

Use [the complete `/root/shabat` legacy cutover guide](PRODUCTION_UPGRADE.md) for the old file-based production bot. Existing SQLite deployments use [migration](MIGRATION.md) and [backup/restore](BACKUP_RESTORE.md) instead of importing legacy data again. Stable 3.0.0 adds no migration after dev.9; all 16 sources remain required.

Stop all writers before migration, retain matching source/database/session/BroadcastManager state, verify import/settings and restore, and monitor the first real production close/open cycle. Review old permission snapshots through the provenance-aware recovery panel. Keep the exact corresponding source available and set `SOURCE_URL` before public operation. Publication itself does not execute a server command or restart a bot.
