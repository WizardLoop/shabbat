# Third-party technical provenance

This companion to [THIRD_PARTY_NOTICES](../THIRD_PARTY_NOTICES.md) records component identity, modifications and open findings. The two active patch manifests are authoritative for exact baseline, approved output, diff and intermediate hashes.

## Original project and reference

The original WizardLoop Shabbat baseline is commit 2976ab8339f489d933c7246e0cb27f3b0e4aa697, Copyright (c) 2024 WizardLoop, originally MIT. Its [grant](../LICENSES/SHABBAT-LEGACY-MIT.txt) remains unchanged. The combined application uses AGPL-3.0-only without revoking earlier grants.

The JewishPulse comparison used commit d7b06eb1acb206543dcff35a7c14ebce4760b4e5, AGPL-3.0-only. Graphic branding and hardcoded bot links were not imported.

## Active patch identity

| Set | Locked base | Baseline SHA-256 | Approved output SHA-256 |
|---|---|---|---|
| [Fresh full-peer evidence](../patches/madelineproto-fresh-peer/manifest.json) | MadelineProto 8.11.10, 49d3d8460257c7bfb1a7e12708e1bfdd59e898c1 | 6caa058975e7122eeb84409542a7c8241a2da5c63ca4c63e1ec2392c7b3f7314 | 78187d7216c069d47d1c05d8128834603b958b55bc8478576850bce9e86489df |
| [BroadcastManager](../patches/broadcastmanager/manifest.json) | 3.2.2, f2792a383416b66bb68e55f09414563e540538d6 | 2a9a42dc6e3f6d7b51c178e7d9db962a5b5ac424046af18afcfcdb12d41dc6e8 | d665b24009aabfb5b09e00edad1271f1501bf0ef797d5a2593129da91c8cc435 |

The PeerHandler derivative preserves the upstream public signature and 8.11.10 community/invalid-peer handling, while exposing raw per-call response evidence when full-info caching is disabled. This supports permission validation without treating a stale combined cache record as current RPC evidence. It is a local correction, not an upstream attribution.

BroadcastManager retains original source, dev.3 presentation source (3d9425f7911b12c69faae5e87954db134dc3fb7c2f7d654a72f972ebeb84e424), dev.8 transport source (08db35c271f671041257ebe79d1010cdd7c33c4d5868e4553c78612f9e8bf0f3) and guarded transition diffs. The approved derivative includes lifecycle admission/cancellation guards, tracked worker/progress draining and bounded flood handling. These are behavior changes, not merely presentation. Unknown source bytes fail verification.

## Retired patches and historical findings

3.1.0 uses official SDK UpdateLoop, GeoPoint and FormattedDate source. Their former local patch directories, helper and wrapper are absent from this release tree. Retirement does not claim that every upstream shutdown behavior is resolved; [publication status](PUBLICATION_STATUS.md) and actual regression evidence govern that conclusion.

For historical identification only:

- The earlier standalone UpdateLoop helper SHA-256 was 1ec2691498e878d28ca8e9cde94b00c803d9ccba789096ffc8471156dfb429e9. On 2026-10-16 the maintainer stated authorship and authorized distribution of those exact bytes under AGPL-3.0-only. Prior release provenance retains that scoped statement; it is not an independent legal certification.
- Historical formatted-date exports had script SHA-256 c3091f61a401d8022b8ef1d228cf8c5f31a1094982de69a0be108e21885cb967 and wrapper SHA-256 a9e3146e49a88bec469e67e53f5e27cffb1b5127cde547590f2e385ef02dd264. Their MIT/proprietary project declarations conflicted and no standalone grant or recovered Git history was found. Those exports were not redistributed.
- The separately authored wrapper shipped in earlier releases had SHA-256 ffe03d0d78215bc42968cd836c25b1f8a602f161fb55eba189b4102d9b99d5e5, Copyright WizardLoop 2026, AGPL-3.0-or-later. It is no longer an active source input.

## Current SDK preferred source

The [source manifest](../resources/release/source-manifest.json) binds 4,095 files from 83 exact runtime distributions, with only the two approved patched outputs differing from upstream. Two public Amp TLS example private keys are omitted by exact hash. All other runtime files, resources and notices remain inventoried; no extension-based source filtering is used.

The MadelineProto supplement has **84 files** at SDK commit 49d3d8460257c7bfb1a7e12708e1bfdd59e898c1, schema submodule 1d027e48ce9196d86110f8324e23a5c78fc4ef6a and documentation submodule 10ea0769245a3d080c3e501b12603e804ad9f3af. Original Git blobs and SHA-256 values accompany each file. It includes TL/secret parser builders, file-reference-map generators, current TL232/MTProto/secret schemas, all language inputs and the local language generator. The runtime package supplies their referenced parser/runtime classes. No generator was executed.

Full historical schema regeneration, complete generated upstream documentation, its development toolchain and historical mutable RPC responses are outside this collection. No separate license file was present in the pinned schema/documentation submodule trees; their bytes and the parent MadelineProto notices are preserved without inventing a new grant.

## RTC source findings

The new RTC PHP packages are locked and included with their original source, notices and Go reference-source files where distributed. danog/php-rtc-av metadata declares MIT while its distributed LICENSE and PHP source headers state BSD-3-Clause; the dependency report preserves the mismatch as **NOT VERIFIED** for the declared MIT grant.

The AV package contains FFI declaration headers and an optional FFmpeg downloader, not FFmpeg native binaries. The downloader was not run. Native libraries, their optional installation and platform-specific builds are outside the Composer source collection. The eight editable C declaration files match the pinned upstream Git blobs. No separate generator or extraction recipe exists in that tree; whether they are directly maintained preferred source or generated derivatives is unestablished. Their external provenance and any additional notice requirements remain review findings, not a proven missing generator. The pinned AV README is retained as a supplement because Composer dist excludes it; it explicitly states BSD-3-Clause and FFmpeg 9 ABI. The optional downloader repository/tag/release returned HTTP 404 during review, and no native build provenance is claimed.

## Data provenance

- **Hebcal.com, CC-BY-4.0:** [projected Jerusalem fixtures](../tests/Fixtures/hebcal-jerusalem-provenance.json) record retrieval dates, exact queries, removed fields, normalization and hashes. Dates, times and offsets were not changed. [Default complete-response provenance](../tests/Fixtures/hebcal-jerusalem-default-full-provenance.json) and [complete runtime-response provenance](../tests/Fixtures/hebcal-full-runtime-provenance.json) describe the unprojected captures; all response fields remain in those fixtures.
- **Omer counts, Hebcal.com, CC-BY-4.0:** [resource metadata](../resources/templates/omer-counts.json) retains the exact query and 2026-10-13 retrieval for all 49 Hebrew/English counts. `OmerText` identifies the basic Ashkenaz counting-suffix modification. [Localized explanations](../resources/templates/omer-counts-localized.json), credited to Hebcal.com and WizardLoop Shabbat, retain CC-BY-4.0 and are separate from Hebrew ritual wording; no runtime-generated prayer or translated blessing is claimed.
- **Omer transcription/editorial resource, Hebrew Wikisource contributors, CC-BY-SA-4.0:** retrieved 2026-10-13 from [Ashkenaz revision 3017054](https://he.wikisource.org/w/index.php?title=סידור/נוסח_אשכנז/ספירת_העומר&oldid=3017054), [Sephard revision 2929975](https://he.wikisource.org/w/index.php?title=סדר_ספירת_העומר&oldid=2929975), [Psalm 67 revision 1014815](https://he.wikisource.org/w/index.php?title=תהלים_סז&oldid=1014815) and [Ana Bekoach revision 3085666](https://he.wikisource.org/w/index.php?title=אנא_בכח&oldid=3085666). [Resource metadata](../resources/templates/omer-liturgy.json) records vowel/cantillation removal, divine-name presentation, punctuation/line normalization and the רחמם variant. Separate Ashkenaz/Sephard preparations/endings, Harachaman, Psalm 67, Ana Bekoach and daily sefira are included. The ancient underlying text and the separately licensed modern transcription/editorial resource have distinct scopes.
- **GeoNames.org, CC-BY-4.0:** [provider attribution and terms](https://www.geonames.org/about.html); operator API access terms/quotas are separate. No demo account is bundled.

The [retained grants](../LICENSES/README.md) identify the official Creative Commons text endpoints. Application AGPL licensing does not replace these data grants. Old remote-thumbnail provenance remains unestablished; current inline results do not rely on it. No third-party graphic asset was added.

## Amp Windows process helper source

The locked `amphp/process` runtime package retains its two upstream Windows helper
executables and notices. Its official [binary update record](https://github.com/amphp/process/commit/6810aacc5d7049d9db87e8161e2ef0e66caa1472)
identifies wrapper **v1.2.0**; the executable Git blobs match those at the locked
package revision. The separate corresponding-source collection adds the
[preferred source at commit 8d5aca0c56b73cba460894d79ee83ec558b41027](https://github.com/amphp/windows-process-wrapper/tree/8d5aca0c56b73cba460894d79ee83ec558b41027)
under `additional-source/amphp-windows-process-wrapper/`, including its original
MIT license. Exact source and executable hashes are in the archive's scope
manifest and the separate checks evidence. No reproducible binary rebuild is
claimed, and the Linux Docker runtime does not execute those Windows helpers.