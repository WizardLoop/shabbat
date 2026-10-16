# Publication and release status

## Stable release 3.0.0

The owner-authorized `v3.0.0` stable release uses the exact reviewed merge commit. Its deployment asset is `shabbat-3.0.0.zip`; `shabbat-3.0.0-corresponding-source.zip` adds the 57 locked runtime package trees and preferred-source supplements below. `SHA256SUMS` and `RELEASE_MANIFEST.json` identify the archives, source fingerprint and scope; the matching Actions run supplies CI evidence. Archive `build-info.json` records the actual commit and `local_changes=false` after exact source equality. See [release notes](RELEASE_NOTES.md) and [production cutover](PRODUCTION_UPGRADE.md).

Stable packaging is now repository tooling: `composer package` / `php scripts/package.php` reads a clean committed Git tree and verifies the locked runtime sources and committed supplement inventory before writing the two ZIPs and metadata to `dist/`. Git and Python 3.9+ are packaging prerequisites. The old three-archive installed-tool path is explicitly `--development` and is not a release source offer. [Release preparation](RELEASE.md) documents the checks and prerequisites.

The owner reported successful dev.9 live locking, unlocking, restoration of original group permissions and restart on 2026-10-16. The stable promotion preserves that runtime behavior; it does not convert those tests into holy-day, load, soak, all-client or production-migration acceptance. The Docker PHP 8.4 base moves to security-patched 8.4.26; Composer stays 2.10.3. Application lock, approved patch bytes and SQL migration bytes remain preserved. Publishing the GitHub release does not deploy or restart the bot.

## Historical local preparation

The earlier local documentation/public-source preparation of **3.0.0-dev.9** preserved runtime behavior and locked dependencies. That preparation itself created no commit, tag, GitHub release or production deployment. Its historical artifact names and metadata are recorded below; use the current release assets above for deployment.

## Historical local delivery layout

| Path in the local delivery | Contents |
|---|---|
| `repo/` | Clean files intended for the repository root; no `.git`, installed `vendor`, actual `.env`, databases, sessions, logs, private backups or generated test caches |
| `artifacts/shabbat-3.0.0-dev.9-publication.zip` | The clean repository source, including dotfiles, tests, resources, immutable migration sources and reviewed patch baselines, plus public archive-only `build-info.json` required for no-Git Docker builds |
| `artifacts/shabbat-3.0.0-dev.9-corresponding-source.zip` | Application source plus the 57 locked runtime Composer dependency source trees, their notices and approved local modifications; see the archive's source-scope/index |
| `artifacts/SHA256SUMS` | SHA-256 hashes for the delivered ZIPs |
| `checks/` | Sanitized implementation inventory, original-source preservation evidence, executed-check reports, archive manifests and documented blockers |

The archive-only `build-info.json` records the verified original baseline commit, `local_changes=true` and the exact publication-source fingerprint. It is excluded from `repo/` and Git; it neither creates nor claims a new commit. Extract the publication ZIP into a new directory, retain that metadata unchanged, and follow the README. It is a source distribution requiring `composer install` or a Docker build, not a preconfigured bot. At that time `scripts/package.php` was a separate three-archive developer packager. Stable 3.0.0 replaces its default with the reviewed two-archive release workflow and retains the old behavior only under `--development`; historical archive names and scope do not describe current release assets.

## Corresponding-source scope

The separate source archive preserves application resources, migration/build/install/test scripts, `composer.lock`, licenses, the five active patch sets, exact upstream baselines and patched runtime dependency sources. Runtime dependency versions/references and files are indexed in its manifest.

The locked `amphp/process` package also distributes two Windows helper executables.
Their MIT-licensed preferred source is included separately under
`additional-source/amphp-windows-process-wrapper/` at the immutable
[v1.2.0 source commit](https://github.com/amphp/windows-process-wrapper/tree/8d5aca0c56b73cba460894d79ee83ec558b41027).
The version association is supported by official package history and matching
executable Git blobs. A reproducible rebuild of those binaries was not performed.

The source collection also supplements Composer's MadelineProto distribution with
pinned upstream TL generator/build inputs, current schema/language inputs and a
required documentation-template input. Original paths, commits and Git-blob/file
hashes are indexed in the archive. Generators were not executed; upstream
documentation/translation workflows that query mutable RPC/Weblate data are not
claimed to be reproducible offline. Historical schema archives and generated
upstream documentation are outside this collection.


Development tool installations are excluded from this source archive. In particular, a distributed PHPStan PHAR is not labeled as preferred source. Development tools remain reproducible through the committed lock, and their license inventory is retained. PHP, SQLite, operating-system packages and Docker base images are external runtime/build dependencies, not bundled source or a distributed image in this delivery. Dockerfile digests, download checksums and upstream references document those external inputs. No claim is made that this ZIP contains the full source of an entire Linux container or every optional development tool.

See [licensing](LICENSING.md), [third-party notices](../THIRD_PARTY_NOTICES.md), [dependency inventory](DEPENDENCIES.md) and the source archive's scope manifest for exact inclusions, omissions and outstanding provenance findings.

## Review boundaries

- The public README pair is `README.md` and `readme-he.md`; there is no competing `README.he.md`.
- Original application code, the Composer lock, SQL migration bytes, dependency licenses and approved patch/base bytes are preserved. The release inventory and documentation checker use the requested Hebrew filename; provenance metadata uses portable project-relative identifiers.
- The public `.env.example` keeps the same key/value placeholders and uses comment-free syntax shared by Compose and PHP INI parsing. Explanations are in the configuration guide.
- Current acceptance and feature gaps are documented in [implementation status](IMPLEMENTATION_STATUS.md); they are not silently fixed or marketed as complete.
- Exact test results belong to the final separate checks report. Previously recorded dev.9 results do not replace a clean-install check of this copy.

When publishing manually, use the exact reviewed source and preserve its corresponding-source offer. Do not upload private environment/session/data files. The historical local preparation performed no remote publication or production action; the current authorized stable release is described at the top of this document.
