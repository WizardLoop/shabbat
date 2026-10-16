# Release preparation and publication

The version in [VERSION](../VERSION) is **3.0.0-dev.9**. This remains a development snapshot for review and isolated acceptance testing. A clean source archive does not establish production readiness. See [current release notes](RELEASE_NOTES.md), [publication status](PUBLICATION_STATUS.md) and [licensing/source scope](LICENSING.md).

## Source and publication boundary

Prepare files in an isolated copy and keep runtime data separate. For the owner-authorized `v3.0.0-dev.9` prerelease, require green checks on the reviewed PR, mark it ready and merge without rewriting history, then validate the merged commit before creating the matching tag and release assets. No server deployment is part of that process. Release metadata must identify the actual merged commit and matching source fingerprint; the historical preparation baseline alone does not identify local changes.

The two publication artifacts are `shabbat-3.0.0-dev.9.zip` and `shabbat-3.0.0-dev.9-corresponding-source.zip`. The first contains the clean repository; the second adds the locked Composer runtime sources and notices within the scope documented in [LICENSING](LICENSING.md). The release also includes `SHA256SUMS` and `RELEASE_MANIFEST.json`. Local diagnostic `checks/` directories are not copied into the source ZIP. The corresponding-source asset is the separately reviewed runtime collection with preferred-source supplements, not the generic developer packager's installed-tool archive.

## Reproducible checks

Run these from the root of a disposable clean copy, as its nonroot owner with permission to write that copy. It must have no production `.env`, session or database. The Composer installation writes only that copy's dependencies and applies the approved local patches. Do not run installation or patch application against an active bot tree.

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

`--no-check-all` retains Composer's strict manifest/lock validation while omitting its advice against the intentional exact MadelineProto constraint. Ordinary installation and upgrades use `composer install` with the existing lock. A dependency update is a separate reviewed change. Tests use isolated fixtures; the release self-check enforces the target-platform gate and must not be bypassed when the host SQLite is too old.

Record the locked inventory and current advisory evidence from the same isolated installation:

```sh
composer show --locked --format=json
composer licenses --format=json
composer audit --locked --format=json
php scripts/dependencies.php
```

The first three commands display package/license/advisory reports; the advisory command needs network access. `scripts/dependencies.php` writes `docs/dependencies.json` in the copy and validates bundled data/license artifacts. The release workflows use Composer 2.10.3; the installed license report must come from a verified locked installation. Advisory results and license evidence are separate checks.

For a target with Docker, run this from the disposable repository root as a user authorized to use its Docker engine. Compose requires the declared `.env` file even for a build. Create it from the public example in this fresh copy; no live Telegram credentials are needed to build the image. Do not overwrite an existing configured installation's `.env`.

```sh
cp .env.example .env
docker compose build --no-cache shabbat
```

Retain the archive's `build-info.json` so its source identity remains available. For a later Git checkout, supply `BUILD_COMMIT` only from the actual reviewed commit, as described in [installation](INSTALLATION.md). Do not substitute an invented commit or the old baseline for a changed working tree.

This builds the configured `shabbat` service image, installs runtime dependencies, verifies all patches and runs the image's offline platform/filesystem checks. It does not start a Telegram bot. A passed local PHP suite is not evidence that this image build or a remote CI run passed.

## Archive review

Inspect the final ZIP contents and hashes, not only its staging directory. Extract the application ZIP into a fresh disposable directory and repeat installation from `composer.lock`, patch verification and the relevant checks. Verify both README files, public configuration, required dotfiles, tests/fixtures, resources, migrations, all patch artifacts and license notices are present. Check local links and anchors, external assets, case-sensitive filenames and secret patterns.

Exclude `.git`, actual `.env` files, sessions, application/runtime databases and WAL/SHM files, backups, logs, caches, coverage and private work material. Do not exclude required JSON/TXT fixtures or baselines by extension. Do not copy test-created state back into an archive. The runtime corresponding-source manifest must name every included package and deliberate exclusion; it is not a complete source archive for the Docker base image, operating system or development toolchain.

The existing `php scripts/package.php` command is available to maintainers with a development installation. Run it from the isolated repository root as the file owner: it verifies patches and writes three archive variants, manifests and checksums to `dist/`. Its dependency input is the installed `vendor` distribution. In particular, including a tool PHAR is not evidence that the tool's preferred source was bundled. The dedicated publication artifacts have a narrower runtime source scope and their own manifest.

## Before changing a live installation

Complete target-image/platform acceptance, disposable-group Telegram permission capture/readback/restoration, shutdown/restart, inline/GPS/client rendering, rich messages, custom emoji, broadcasts, command menus and Stars acceptance as applicable. Run a wall-clock soak and exercise backup/restore and rollback. These are separate from this local preparation and are not performed by packaging.

Use the [legacy production cutover guide](PRODUCTION_UPGRADE.md), [upgrade/import guide](MIGRATION.md) and [backup/restore guide](BACKUP_RESTORE.md). Stop every writer before migration, retain matching code/database/session/BroadcastManager state backups, apply the numbered migrations and validate the target runtime before startup. Dev.9 requires migration 016; older unresolved permission snapshots still need the documented provenance-aware recovery. Do not use a test bot's database as production state.

Before a later authorized deployment, make the exact corresponding source available and set `SOURCE_URL` accordingly. Development version labels and archived source fingerprints must remain truthful until the outstanding acceptance work is complete.
