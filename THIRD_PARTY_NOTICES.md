# Third-party notices

WizardLoop Shabbat **3.1.1** uses **AGPL-3.0-only** for the combined application. Dependencies, historical source and data retain their own grants and notices. The complete application license is in [LICENSE](LICENSE); exact versions, source revisions, license files and findings are in the [dependency inventory](docs/dependencies.json).

## Code and tools

| Component | Source and license evidence | Attribution and local changes |
|---|---|---|
| Original WizardLoop Shabbat | [WizardLoop/shabbat](https://github.com/WizardLoop/shabbat), historical MIT | Copyright (c) 2024 WizardLoop. The original [MIT grant](LICENSES/SHABBAT-LEGACY-MIT.txt) remains effective. |
| MadelineProto 8.11.10 | [Pinned source](https://github.com/danog/MadelineProto/tree/49d3d8460257c7bfb1a7e12708e1bfdd59e898c1), metadata AGPL-3.0-only; source headers version 3 or later | Preserve original notices. One local PeerHandler patch exposes fresh full-peer response evidence. Official UpdateLoop, GeoPoint and FormattedDate source replaces those former local patches. |
| WizardLoop BroadcastManager 3.2.2 | [Pinned source](https://github.com/WizardLoop/BroadcastManager/tree/f2792a383416b66bb68e55f09414563e540538d6), AGPL-3.0-only | Preserve WizardLoop notices. The local patch supplies presentation, transport, authorization, cancellation and joined-worker lifecycle hooks. |
| Amp and Revolt | [Amp](https://github.com/amphp), [Revolt](https://github.com/revoltphp/event-loop), MIT | Original package notices remain attached; Windows helper source is supplied separately with its MIT license. |
| PHP RTC components | [danog repositories](https://github.com/danog), exact pins in the inventory | Most declare BSD-3-Clause. **php-rtc-av declares MIT in metadata but distributes a BSD-3-Clause LICENSE and BSD source headers.** Both are preserved; the MIT declaration is not confirmed by its distributed license. Optional FFmpeg/native libraries are not included. |
| Development tools | PHPUnit/PHP_CodeSniffer: BSD-3-Clause; PHPStan: MIT | All 26 development packages are inventoried separately and excluded from the runtime source collection. |
| igbinary 3.2.16 | [Official PECL source](https://pecl.php.net/package/igbinary/3.2.16), [original BSD-3-Clause notice](resources/release/preferred-source/additional-source/igbinary/COPYING) | Unmodified PHP extension used for session serialization. Docker verifies the pinned source hash and retains the archive and original notice; both are included in the corresponding-source inputs. |
| SQLite | [Public-domain dedication](https://sqlite.org/copyright.html) | External PHP-loaded library. Docker pins its source checksum; it is not relicensed as application code. |

There are **two active patch sets**, with immutable baselines, reviewed outputs, diffs and SHA-256 manifests under [patches](patches). Installation applies and verifies them before startup. Do not modify a running vendor tree. [Technical provenance](docs/THIRD_PARTY_DETAILS.md) records their identities and the retirement of earlier patches. Using official SDK code does not establish that every shutdown regression is resolved.

The source collection contains 83 locked runtime packages and pinned preferred-source supplements. It is not a complete operating-system/container source distribution or a claim of reproducible native binary rebuilding. [Licensing and archive scope](docs/LICENSING.md) records the boundaries and unresolved provenance evidence.

## Data and text

| Resource | Attribution and license | Modifications and provenance |
|---|---|---|
| Hebcal API responses and regression fixtures | **Hebcal.com**, [CC-BY-4.0](https://www.hebcal.com/home/195/jewish-calendar-rest-api) | Projected fixtures identify removed fields and normalization; full-response fixtures retain every response field. Queries, retrieval dates and hashes accompany the fixtures. |
| [Omer count strings](resources/templates/omer-counts.json) | **Hebcal.com**, CC-BY-4.0 | 49 Hebrew/English counts, retrieved 2026-10-13. The basic Ashkenaz suffix modification is identified in `OmerText`. |
| [Omer explanatory translations](resources/templates/omer-counts-localized.json) | **Hebcal.com; explanatory translations by WizardLoop Shabbat**, CC-BY-4.0 | Static French, Spanish, Brazilian Portuguese and Russian explanations derived from the verified count structure; ritual Hebrew is separate. |
| [Omer liturgy](resources/templates/omer-liturgy.json) | **Hebrew Wikisource contributors**, CC-BY-SA-4.0 for the transcription/editorial resource | Pinned revisions cover Ashkenaz/Sephard preparations and endings, Harachaman, Psalm 67, Ana Bekoach and daily sefira. Changes remove vowel/cantillation marks, normalize punctuation/lines and divine-name presentation, and identify the רחמם variant. |
| GeoNames place data | **GeoNames.org**, [CC-BY-4.0](https://www.geonames.org/about.html) | An operator-owned API account is required. API access terms and quotas are separate from data reuse permissions. |

The complete [CC-BY-4.0](LICENSES/CC-BY-4.0.txt) and [CC-BY-SA-4.0](LICENSES/CC-BY-SA-4.0.txt) terms are retained unchanged. [Detailed resource provenance](docs/THIRD_PARTY_DETAILS.md#data-provenance) links the exact fixture records and Wikisource revisions. Traditional underlying liturgy is distinguished from the separately attributed transcription/editorial resource; no complete modern siddur is claimed.

## References and historical evidence

[JewishPulse](https://github.com/WizardLoop/JewishPulse/tree/d7b06eb1acb206543dcff35a7c14ebce4760b4e5), AGPL-3.0-only, informed the interface comparison and documentation organization. Its branding and hardcoded bot links are not used; this is a historical reference relationship.

No third-party graphic asset was added. Telegram standard emoji and button metadata are interface features. Provenance of an older remote thumbnail is unestablished; current inline results do not depend on it.

The former local UpdateLoop helper, GeoPoint patch and independently authored formatted-date wrapper are no longer shipped in this source tree. Earlier releases retain their historical notices and hash-scoped provenance. The maintainer's 2026-10-16 authorization of the historical UpdateLoop helper is limited to that exact prior helper; it does not change dependency grants. Historical formatted-date exports with conflicting project declarations were never redistributed. [Technical provenance](docs/THIRD_PARTY_DETAILS.md) retains their identifying hashes without treating them as active installation inputs.
