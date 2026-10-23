# Licensing and corresponding source

WizardLoop Shabbat uses **AGPL-3.0-only** for the combined application, as declared in composer.json and original application SPDX headers. [LICENSE](../LICENSE) retains the complete GNU AGPL version 3 text. The [historical MIT grant](../LICENSES/SHABBAT-LEGACY-MIT.txt) remains effective for its original scope.

## Preserve original grants

Dependency sources, patch baselines and upstream supplements retain original notices. MadelineProto metadata declares AGPL-3.0-only, while its source headers permit version 3 or later; the common version 3 grant is used without rewriting either text. The RTC AV package declares MIT in metadata but distributes a BSD-3-Clause license and BSD headers. This unresolved metadata difference is recorded, not silently repaired.

Hebcal output, GeoNames data and Omer count resources retain CC-BY-4.0; the Wikisource Omer transcription/editorial resource retains CC-BY-SA-4.0. Preserve their attribution, pinned provenance and modification notes. Complete texts are in [LICENSES](../LICENSES/README.md). API access terms and quotas are separate from data grants.

The Docker session serializer extension igbinary 3.2.16 retains its original BSD-3-Clause notice. Its pinned source is included in the corresponding-source inventory; this does not extend the source archive scope to the complete container operating system.

## Exact deployed source offer

/source, /license and /version display application/source identity. SOURCE_URL must identify the exact deployed source, including application changes and active patches. A moving branch, lock file alone or fabricated BUILD_COMMIT cannot identify an unpublished modified deployment.

When conveying a modified covered program, retain notices, identify modifications and provide the required corresponding source. GNU AGPL section 13 governs the applicable source offer for remote interaction with a modified network program. Read [the complete license](../LICENSE), especially sections 4–6 and 13, and [the official GNU text](https://www.gnu.org/licenses/agpl-3.0.html). This document records release evidence and practice; it does not certify unrelated contributions or deployments.

## 3.1.1 archive scope

| Artifact | Contents |
|---|---|
| shabbat-3.1.1.zip | Clean application source, resources, migrations, tests, scripts, exact Composer lock, public configuration, Docker, documentation, licenses and GitHub workflows. No installed vendor tree. |
| shabbat-3.1.1-corresponding-source.zip | The application plus 83 locked Composer runtime packages with their original source/notices, the two reviewed patch outputs and pinned preferred-source supplements. Its manifest states exact hashes and exclusions. |

The checked-in [source manifest](../resources/release/source-manifest.json) pins the exact runtime dependency files and supplementary source files, including: 18 Windows helper source files, 84 MadelineProto build/schema/language inputs the pinned RTC AV README, and the official igbinary 3.2.16 source archive plus its original COPYING. It verifies exact lock and runtime source bytes; generated Composer autoload/bin metadata and all 26 development packages are excluded. PHPStan's distributed PHAR is not described as its preferred source.

The two reviewed public Amp HTTP Server TLS example private keys are omitted by exact recorded hash; neither is used by the bot. Real credentials, sessions, databases, backups and private work material must never enter source archives.

Keep the two active patch sets, original bases/intermediates, reviewed outputs, diffs, manifests and notices together. Former local UpdateLoop, GeoPoint and formatted-date patches are retired; historical attribution findings are summarized in [technical provenance](THIRD_PARTY_DETAILS.md).

## Explicit limits and review findings

This is an application/Composer-runtime source collection, **not** the complete source of a Docker image, PHP, SQLite, external native multimedia libraries or every development tool. No reproducible native binary rebuild or all-platform source completeness is claimed. The retained Windows helper binaries have pinned associated preferred source, but their rebuild was not verified.

Current TL232 and language-generator inputs are supplied; full historical schemas, complete upstream documentation, its toolchain and historical mutable RPC data are not. No generator or optional FFmpeg downloader was executed. The AV FFI declarations retain upstream bytes; whether they are directly maintained preferred source or generated derivatives is unestablished. No separate generator exists in the pinned package tree; external derivation provenance remains a review finding rather than an assumed MIT grant.

The [dependency inventory](dependencies.json) records 83 runtime and 26 development packages. LICENSE_TEXT_PRESENT means a recognized distributed license text was found; it is not an authorship or compatibility certificate. The AV metadata mismatch remains NOT VERIFIED. Regenerate with php scripts/dependencies.php in an isolated exact-lock installation. Composer advisories are a separate check.

Any unresolved source or licensing finding must remain in [publication status](PUBLICATION_STATUS.md) and the release report; do not label this a universally complete source distribution.
