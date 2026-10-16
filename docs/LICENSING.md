# Licensing and corresponding source

WizardLoop Shabbat uses **AGPL-3.0-only** for the current combined application, as declared by `composer.json` and original application SPDX headers. [LICENSE](../LICENSE) is the complete, unchanged GNU AGPL version 3 text. [The historical MIT grant](../LICENSES/SHABBAT-LEGACY-MIT.txt) retains the original WizardLoop copyright and permission notice; the current policy does not revoke earlier grants or offer the whole new application under an alternative MIT license.

## Preserve component grants

Dependencies, imported fragments and patch baselines keep their original copyright and license notices. MadelineProto's package metadata declares `AGPL-3.0-only`; its source headers and the added formatted-date wrapper permit version 3 or later. These texts are retained unchanged and their common version 3 grant is used by the combined application. The difference is recorded, not silently rewritten.

Hebcal output, GeoNames place data and the Omer count resources retain **CC-BY-4.0**. The Wikisource Omer transcription/editorial resource retains **CC-BY-SA-4.0**, its contributor attribution, pinned revisions and modification notes. The complete Creative Commons legal texts are in [LICENSES](../LICENSES/README.md). API account terms and quotas are separate from these data grants. See [third-party notices](../THIRD_PARTY_NOTICES.md) and [technical provenance](THIRD_PARTY_DETAILS.md).

## Exact source offer

`/source`, with `/license` and `/version` aliases, shows the application identity and source information. `SOURCE_URL` must identify the exact deployed corresponding source, including local application changes and all active patches. A moving upstream branch or the Composer lock alone cannot identify a modified deployment. `BUILD_COMMIT` is build metadata; a fabricated commit does not turn an unpublished working tree into published source.

When conveying a modified covered program, retain notices and the license, identify modifications and provide the required corresponding source. For remote interaction with the modified network program, GNU AGPL section 13 requires the applicable source offer. The complete conditions are in [LICENSE](../LICENSE), including sections 4–6 and 13, and the [official GNU text](https://www.gnu.org/licenses/agpl-3.0.html). This document records the project's release practice and evidence, not a certification of unrelated contributions or deployments.

## Publication archive scope

The `v3.0.0` release provides two separate artifacts:

| Artifact | Scope |
|---|---|
| `shabbat-3.0.0.zip` | Clean repository source: application, resources, migrations, tests, scripts, Composer manifest/lock, public configuration, Docker files, documentation, licenses and required GitHub files. No installed `vendor` tree. |
| `shabbat-3.0.0-corresponding-source.zip` | Application material plus the 57 locked Composer runtime packages, their source/license notices and the applied reviewed patches. Exact contents, exclusions and hashes are recorded in its manifest. |

The corresponding-source artifact is scoped to the application and its Composer runtime dependencies. Development tools are reproducible from `composer.lock` and are inventoried, but are not bundled in that runtime source artifact; PHPStan's distributed PHAR must not be called its preferred source. The archive is not a complete Docker-image or operating-system source distribution. PHP, SQLite, Composer and base-image/system components are external to that scope; Docker pins and source references describe the build inputs. Any unresolved or omitted preferred-source component must be listed in the publication report before the artifact can be described as complete within its stated scope.

Keep the application source, all five patch sets, immutable bases/intermediates, added files, exact diffs, manifests and notices available together. The two reviewed public Amp HTTP Server TLS example private keys may be omitted from a distribution only with the exact omission records preserved; they are not used by this bot. Other private keys and real runtime credentials do not belong in source archives.

Release packaging uses the checked-in [source-input manifest](../resources/release/source-manifest.json) and [preferred-source supplements](../resources/release/README.md). The manifest pins all 2,960 included runtime dependency files and 92 supplementary source files. Packaging verifies a locked, patched runtime installation against those hashes and adds the exact reviewed application tree; it excludes installed development tools and generated Composer autoload metadata. Original component notices and the two approved example-key omission records remain attached to that scope.

## Evidence and remaining review

The [dependency inventory](dependencies.json) distinguishes 57 runtime packages and 26 development packages, exact versions, metadata, distributed license files and findings. `LICENSE_TEXT_PRESENT` means a recognized license text was found; it is not an authorship or compatibility certificate. Regenerate it with `php scripts/dependencies.php` from the isolated locked installation. Composer security advisories are a separate check.

On **2026-10-16**, the WizardLoop project maintainer stated that they authored the historical UpdateLoop helper retained at [original-reference.php](../patches/madelineproto/original-reference.php) and explicitly authorized its distribution under **AGPL-3.0-only**. The [manifest](../patches/madelineproto/manifest.json) binds this attestation to SHA-256 `1ec2691498e878d28ca8e9cde94b00c803d9ccba789096ffc8471156dfb429e9`. The exact headerless helper bytes are preserved; the dated statement supplies the maintainer's authorization without rewriting historical source or dependency licenses. It is not an independent authorship or legal certification.

The historical formatted-date exports still have unresolved standalone grants and are not redistributed. Their finding is separate from the UpdateLoop attestation. Preserve any remaining contributor or deployment-only provenance findings. [Publication status](PUBLICATION_STATUS.md), release manifests and the recorded checks identify the evidence available for the actual release.
