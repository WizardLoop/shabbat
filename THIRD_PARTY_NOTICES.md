# Third-party notices

WizardLoop Shabbat **3.0.0-dev.9** is distributed as an **AGPL-3.0-only** application. Dependency, historical-source and data licenses retain their own scope. The full application license is in [LICENSE](LICENSE); the [licensing guide](docs/LICENSING.md) explains the source offer. Exact locked versions, sources, license paths and findings are recorded in the [dependency inventory](docs/dependencies.json).

## Code and tools

| Component | Source and license | Attribution and local changes |
|---|---|---|
| Original WizardLoop Shabbat | [WizardLoop/shabbat](https://github.com/WizardLoop/shabbat), historical MIT | Copyright (c) 2024 WizardLoop. The complete original grant is retained in [SHABBAT-LEGACY-MIT.txt](LICENSES/SHABBAT-LEGACY-MIT.txt); earlier grants remain effective. |
| MadelineProto 8.7.0 | [Pinned source](https://github.com/danog/MadelineProto/tree/7b99dd0f2d964a492c377912ffce9f5ce61aa9ed), package metadata AGPL-3.0-only | Preserve its distributed license and copyright headers. Four local compatibility patches cover update-loop cancellation, fractional coordinates, formatted-date entities and fresh full-peer response evidence. |
| WizardLoop BroadcastManager 3.2.2 | [Pinned source](https://github.com/WizardLoop/BroadcastManager/tree/f2792a383416b66bb68e55f09414563e540538d6), AGPL-3.0-only | Preserve WizardLoop notices. The local patch adds presentation, message-transport, authorization, cancellation and joined-worker lifecycle hooks. |
| Amp and Revolt | [Amp](https://github.com/amphp) and [Revolt](https://github.com/revoltphp/event-loop), MIT | Distributed author/copyright notices remain in their packages. Exact direct and transitive versions are in the inventory. |
| Development tools | PHPUnit and PHP_CodeSniffer: BSD-3-Clause; PHPStan: MIT | Locked tool and transitive-package notices are inventoried separately from runtime dependencies. The runtime corresponding-source archive does not bundle the development tools. |
| SQLite | [SQLite public-domain dedication](https://sqlite.org/copyright.html) | External PHP-loaded runtime library. The Dockerfile identifies the reviewed source version and checksum; SQLite is not relicensed as application code. |

There are **five active patch sets**. Their original sources, approved intermediate sources, diffs, added source and SHA-256 manifests are preserved under [patches/](patches). [Technical provenance](docs/THIRD_PARTY_DETAILS.md) records exact identities and historical changes. Installation verifies them before startup; running vendor trees must not be changed.

MadelineProto package metadata says `AGPL-3.0-only`, while retained source headers allow version 3 or later. The new formatted-date wrapper also grants `AGPL-3.0-or-later`. These grants are preserved; the combined application's policy uses version 3. The metadata/header difference remains a recorded finding.

## Data and text

| Resource | Attribution and license | Modifications and provenance |
|---|---|---|
| Hebcal API responses and regression fixtures | **Hebcal.com**, [CC-BY-4.0](https://www.hebcal.com/home/195/jewish-calendar-rest-api) | Projected fixtures identify removed fields and normalization; full-response fixtures retain every response field. Queries, retrieval dates and hashes accompany the fixtures. |
| [Omer count strings](resources/templates/omer-counts.json) | **Hebcal.com**, CC-BY-4.0 | 49 Hebrew/English counts, retrieved 2026-10-13. The basic Ashkenaz suffix modification is identified in `OmerText`. |
| [Omer explanatory translations](resources/templates/omer-counts-localized.json) | **Hebcal.com; explanatory translations by WizardLoop Shabbat**, CC-BY-4.0 | Static French, Spanish, Brazilian Portuguese and Russian explanations derived from the verified count structure; ritual Hebrew is separate. |
| [Omer liturgy](resources/templates/omer-liturgy.json) | **Hebrew Wikisource contributors**, CC-BY-SA-4.0 for the transcription/editorial resource | Pinned revisions cover Ashkenaz/Sephard preparations and endings, Harachaman, Psalm 67, Ana Bekoach and daily sefira. Changes remove vowel/cantillation marks, normalize punctuation/lines and divine-name presentation, and identify the רחמם variant. |
| GeoNames place data | **GeoNames.org**, [CC-BY-4.0](https://www.geonames.org/about.html) | An operator-owned API account is required. API access terms and quotas are separate from data reuse permissions. |

The complete [CC-BY-4.0](LICENSES/CC-BY-4.0.txt) and [CC-BY-SA-4.0](LICENSES/CC-BY-SA-4.0.txt) terms are retained unchanged. [Detailed resource provenance](docs/THIRD_PARTY_DETAILS.md#data-provenance) links the exact fixture records and Wikisource revisions. Traditional underlying liturgy is distinguished from the separately attributed transcription/editorial resource; no complete modern siddur is claimed.

## References and unresolved evidence

[JewishPulse](https://github.com/WizardLoop/JewishPulse/tree/d7b06eb1acb206543dcff35a7c14ebce4760b4e5), AGPL-3.0-only, informed the interface comparison and documentation structure. Its branding and hardcoded bot links are not used. The [feature comparison](docs/FEATURE_PARITY.md) describes the reference relationship.

No third-party graphic asset was added. Telegram standard emoji and button metadata are interface features. Provenance of an older remote thumbnail is unestablished; current inline results do not depend on it.

Historical formatted-date reference exports had conflicting project declarations and no recovered standalone grant or Git history. Their hashes and findings are retained in [the provenance review](docs/MADELINE_FORMATTED_DATE.md); those original exports are not redistributed. The retained historical UpdateLoop script has a provenance hash but no standalone license header; its authorship/grant provenance remains a release-review item. Do not treat a successful checksum or a package license inventory as a resolution of that item.
