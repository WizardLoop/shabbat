# Third-party technical provenance

This companion to [THIRD_PARTY_NOTICES](../THIRD_PARTY_NOTICES.md) retains technical identities, modification history and open findings. The five checked-in patch manifests are authoritative for exact source, output, prerequisite and diff SHA-256 values. Historical hashes below identify earlier reviewed artifacts, not the current output unless stated.

## Original project and reference

The historical WizardLoop Shabbat baseline is commit `2976ab8339f489d933c7246e0cb27f3b0e4aa697`, Copyright (c) 2024 WizardLoop, originally MIT. Its [complete original grant](../LICENSES/SHABBAT-LEGACY-MIT.txt) remains unchanged. The current application uses AGPL-3.0-only without altering historical grants.

The JewishPulse interface comparison used commit `d7b06eb1acb206543dcff35a7c14ebce4760b4e5` under AGPL-3.0-only. [FEATURE_PARITY](FEATURE_PARITY.md) records that relationship. No JewishPulse graphic branding or hardcoded bot links are imported.

Local reference path labels in the patch provenance identify historical project exports, not files required at installation and not public download URLs. Publication preparation removes the workstation's absolute home/Desktop prefix while retaining project/file identity, hashes and all license findings. Baselines, reviewed PHP, diffs, historical source and approved target hashes are unchanged.

## Active patch identity

All four MadelineProto patch sets target version **8.7.0**, commit `7b99dd0f2d964a492c377912ffce9f5ce61aa9ed`. The BroadcastManager set targets **3.2.2**, commit `f2792a383416b66bb68e55f09414563e540538d6`.

| Set | Upstream baseline SHA-256 | Current approved output SHA-256 |
|---|---|---|
| [UpdateLoop](../patches/madelineproto/manifest.json) | `2fbcbb73822258102837f53f4a231b87526d66809be7acd6339156ea778a8c2f` | `8f6cb1a483c879024bddea289e90d4bf2d71dd15716102bc83a1f34a243782e4` |
| [GeoPoint](../patches/madelineproto-geopoint/manifest.json) | `9ed8054caade6abc96420b0f5b120bec029ee2bc7b7b6aacca56fb7ddc61a27b` | `5633cc441e541e5e919dfa8156192587a68e578e49a496acac91172be5f2549d` |
| [MessageEntity factory](../patches/madelineproto-formatted-date/manifest.json) | `06a9d438cc198f56cfb745a2a3808932269033671146cf82f84ae88e8d6eae60` | `b265ed707326e1d71c7a8bdf8291f0b8c94a153b98a2c4c45c776fec3b374005` |
| [PeerHandler](../patches/madelineproto-fresh-peer/manifest.json) | `3e1af3624f2256f53264fe4256475d0a5f353af8761cc9716133b7f0412538d2` | `8188777fc376f2b3cca39963d79f85239fc84141f978128ea2bb591f03891928` |
| [BroadcastManager](../patches/broadcastmanager/manifest.json) | `2a9a42dc6e3f6d7b51c178e7d9db962a5b5ac424046af18afcfcdb12d41dc6e8` | `d665b24009aabfb5b09e00edad1271f1501bf0ef797d5a2593129da91c8cc435` |

### UpdateLoop cancellation and recovery

[The patch review](../patches/madelineproto/README.md) identifies a retained historical PHP patch script and a reproduced reference diff. The script's SHA-256 is `1ec2691498e878d28ca8e9cde94b00c803d9ccba789096ffc8471156dfb429e9`; identical copies were found in local project exports `newsearch-v2` and `psy new/release-r21-work`. The reference diff is reproduced from that script, not claimed to be a historical Git diff.

The applied derivative adds shutdown/authorization guards and delayed recovery to the reference cancellation checks. Direct timeouts and timeout-derived cancellation can recover; unrelated cancellation is rethrown and a stopped loop stops. The immutable baseline, script, reference diff, applied diff and manifest remain bundled. The historical standalone script has no license header: its hash verifies identity, not an independently recovered license grant.

### Fractional coordinates

The [GeoPoint correction](../patches/madelineproto-geopoint/README.md) changes the two longitude/latitude property types and annotations from integer to float, matching Telegram double coordinates. Access hashes, accuracy radius and surrounding serialization remain unchanged. It is separate from the historical UpdateLoop work; the pinned original file, reviewed output, diff and manifest are retained.

### Formatted-date entities

The [factory/class correction](../patches/madelineproto-formatted-date/README.md) adds the native/Bot API factory branches and a new independently authored `FormattedDate` wrapper. Native timestamps, UTF-16 offsets/lengths and flags remain represented; the locked schema and complete upstream license accompany the baseline and diff. The new class SHA-256 is `ffe03d0d78215bc42968cd836c25b1f8a602f161fb55eba189b4102d9b99d5e5`; its copyright is WizardLoop 2026 and its grant is AGPL-3.0-or-later. The unmodified upstream license SHA-256 is `57c8ff33c9c0cfc3ef00e650a1cc910d7ee479a8bc509f6c9209a7c2a11399d6`, identical to the root AGPL text.

Two historical exports share script SHA-256 `c3091f61a401d8022b8ef1d228cf8c5f31a1094982de69a0be108e21885cb967` and wrapper SHA-256 `a9e3146e49a88bec469e67e53f5e27cffb1b5127cde547590f2e385ef02dd264`. Their project declarations conflict: MIT in `wizardloop/theisraelis`, proprietary in `psychedelize/psy-bot-suite`. No standalone grant or recoverable Git history was found. Those original exports are not redistributed; [the investigation](MADELINE_FORMATTED_DATE.md) preserves the finding and explains the new implementation's source basis.

### Fresh full-peer response evidence

The [PeerHandler correction](../patches/madelineproto-fresh-peer/README.md), reviewed 2026-10-16, preserves the public `getFullInfo` signature and exposes per-call raw response evidence when full-info caching is disabled. This supports current permission validation without trusting a stale combined chat/cache record. It is a new local correction, not attributed to a recovered historical patch or an upstream fix. Its baseline was recorded as matching the pinned remote and installed source; original AGPL/copyright headers remain intact.

### BroadcastManager history and current lifecycle changes

The [BroadcastManager review](../patches/broadcastmanager/README.md) records three stages:

| Stage | Reviewed-source SHA-256 | Scope |
|---|---|---|
| dev.3 | `3d9425f7911b12c69faae5e87954db134dc3fb7c2f7d654a72f972ebeb84e424` | Per-instance status/counter/control presentation. |
| dev.4 through dev.8 | `08db35c271f671041257ebe79d1010cdd7c33c4d5868e4553c78612f9e8bf0f3` | Optional message-transport hook at native text/media/album/edit/status sites. |
| dev.9, current | `d665b24009aabfb5b09e00edad1271f1501bf0ef797d5a2593129da91c8cc435` | Runtime authorization/cancellation/admission hooks, cancellation-aware waits, worker/progress joining, cancelled queue persistence and bounded completed progress history. |

The older statement that the patch did not change delivery lifecycle applies to the historical presentation stage only. The current derivative includes lifecycle behavior. The native library retains separate persistent state; null optional hooks preserve native call paths, while current future joining is part of the reviewed implementation. The application requests native flood-wait errors for manager-controlled retry. Exact original source, both prior approved sources and all guarded transition diffs remain in the manifest. Unknown intermediate bytes fail verification.

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