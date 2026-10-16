# Changelog

PHP 8.3 compatibility: module-delivery bookkeeping now uses the database-owned `BEGIN IMMEDIATE` transaction state and exact connection identity; native callback/message fixtures initialize inherited readonly properties from their declaring class. Regression tests cover rejected foreign/outside transactions, rollback and state reset.

## 3.0.0-dev.9 - GitHub prerelease

- Pin Composer 2.10.3 in CI and Docker to address GitHub Actions token compatibility and the upstream token-disclosure advisory; retain authentication, all quality gates, the application lock and MadelineProto 8.7.0.
- Preserve migration, bundled text and captured fixture bytes across Git line-ending settings, with a regression covering both autocrlf modes. Existing SQL/resource/patch bytes remain unchanged.
- Document the reviewed legacy Docker cutover into `/root/shabat`, stopped-writer backup/import, permission recovery and rollback.
- Publish matching deployment and scoped runtime corresponding-source archives with the actual merge commit, source inventory and SHA-256 sums. This remains a prerelease; no server deployment is performed by publication.

## Local publication preparation — 3.0.0-dev.9

- Prepare an isolated clean repository copy and separate application/runtime corresponding-source artifacts for manual review. The application version and runtime behavior remain unchanged.
- Rewrite the English and Hebrew README files, update installation/operations references and use the single Hebrew filename `readme-he.md`.
- Consolidate third-party notices, preserve technical provenance and hashes in dedicated documentation/manifests, and state the runtime source archive's scope explicitly.
- Remove workstation-specific path prefixes from historical reference metadata while retaining project/file identifiers, source hashes and unresolved grant findings. Baselines, diffs, reviewed dependency source and license texts remain unchanged.
- Record fresh isolated installation/quality/archive results and outstanding target-platform/live acceptance in the publication report. No commit, tag, push, PR, merge, release or deployment is part of this preparation.

## 3.0.0-dev.9 - delivery safety and independent scheduling

- Re-evaluate current calendar state after provider I/O; recheck bounded delivery evidence after topic, media and custom-emoji preflight, fallback, and local dispatch persistence.
- Enforce actual quiet hours, Tefillin spacing/daily caps and Omer's actual restoration gap. Coalesce never-dispatched reminder backlogs while preserving uncertain random IDs/payloads and delivery evidence.
- Add migration 016 with conservative legacy attempt backfill, indexed delivery evidence, queue metrics and bounded routine retention.
- Separate permission, notification and scheduled broadcast callbacks. Bound permission concurrency, use fair planning batches, skip disabled-module work and completed permission no-op reads.
- Add optional reviewed BroadcastManager lifecycle hooks for creator authorization, cancellation-aware waits and joined shutdown. Preserve all patch baselines/notices and previous approved output. Return native flood waits rather than hide them in an extended SDK wait.
- Add provider failure cooldowns below persistent cache, independent health timestamps, boundary/uncertainty/DST regressions and simulated load/retention tests. Keep MadelineProto 8.7.0 and Composer lock unchanged.

## 3.0.0-dev.8 - verified permission snapshots

- Read default restrictions from the verified matching raw full-peer response instead of combining fresh full info with cached Chat permissions. Reject incomplete, mismatched and malformed data before any write.
- Retain MadelineProto 8.7.0 and add a separately hash-guarded fresh-peer compatibility patch with immutable original source, diff, reviewed output and behavior/install regressions.
- Add snapshot provenance in migration 015. Preserve older unresolved evidence and block unverified restoration; allow an administrator to review intended normal Telegram permissions and explicitly adopt a verified current state outside protected periods.
- Update recovery/diagnostic/manual-result messages in six languages, migration coverage and isolated test-server deployment instructions. All earlier features remain included.

## 3.0.0-dev.7 - permission recovery and paged diagnostics

- Add guarded automatic reconciliation, bounded permission attempt evidence and explicit group-admin recovery with preserved snapshot history and one-use confirmation.
- Add the group panel choices to adopt current normal permissions or restore the original permissions, with fresh authority and calendar/manual-lock/shutdown protection.
- Replace the `/testzmanim` JSON message dump with one localized editable panel, paging, quick page entry, search, filters and a guarded JSON document export.
- Add migrations 013-014; retain all earlier migration checksums, locked dependencies and library patches. Update six locales, regression coverage and direct test-server deployment instructions.
## 3.0.0-dev.6 - permission equivalence and callback integration

- Correct the public statistics callback allowlist at the actual SDK update-handler boundary, including callbacks emitted by the home menu and statistics tabs. Unavailable original panels return a localized callback response without new-message flooding.
- Compare reviewed Telegram-equivalent default permission representations consistently for manual and automatic transitions, retaining original snapshots and genuine-conflict protection. See the dev.6 feedback report for evidence and limits.
- Shabbat messages across public, inline and scheduled defaults show the nearest Shabbat-containing period and current/upcoming holidays, including verified entrance/final-exit times for weekday Yom Tov before Shabbat, without later Shabbat summaries, numeric UTC suffixes or visible source/update footers. Actual configured methods and validated source provenance remain intact.
- Recognize both captured provider spellings of Chol Hamoed for reminder policy, while retaining its non-Yom-Tov classification. Keep a verified active festival visible when its final exit is after civil midnight.
- Direct test-folder upload is documented; deployment ZIP/checksum files are excluded from Docker context. No dependency, patch or migration update is required.

## 3.0.0-dev.5 - test feedback corrections

- Manual lock/open uses a still-valid explicit override without depending on the calendar provider; each group reports a bounded actionable result, with pending retries distinguished from confirmed mutations.
- Telegram default permission changes support both basic groups and supergroups with the bot's required administrative permission. The full 22-flag snapshot remains preserved; the 21 selectable global restrictions exclude `view_messages`, which Telegram explicitly forbids in this method. Reactions and member-tag changes remain selectable.
- Reuse one auxiliary broadcast IPC client per event handler instead of constructing one every scheduler minute. Owner-only restart acknowledges the request, stops new work and hands restart to the pinned SDK with lifecycle guards.
- Group and group-administrator command menus contain only `settings`; private command scopes remain localized and role appropriate.
- Shabbat/festival notices are a single overview without previous/next buttons. Closest verified holidays, parsha/Mevarchim, intermediate candles and final continuous-period exit remain visible; Yom Tov timing is gated by Hebcal's `yomtov` field. The next three verified Shabbat summaries are compact.
- Group settings show a current Mode button with four choices. Save/cancel returns to the same subcategory/editor. Message-design actions are logically paired; default Jerusalem candle lighting explicitly shows 40 minutes.
- Public statistics gain editable overview/reminder sections; private administrative statistics are grouped by audience, activity, groups and delivery.
- Broadcast editing adds a short label/link wizard, a draft summary and a detailed final confirmation. Content edits preserve existing buttons; advanced keyboards remain available.
- `source` combines repository, license and build identity. Legacy `license`/`version` commands remain aliases; the project repository is always linked separately from the deployment's exact source offer.
- Expanded help and all changes are translated into Hebrew, English, Russian, French, Spanish and Brazilian Portuguese.

Migrations 001-012, Composer lock and all four library patch sets are unchanged from dev.4. No new schema migration is needed for dev.5, but the stopped-writer backup/migrate command remains part of the update procedure. See [the 11-item verification map](docs/DEV5_TEST_FEEDBACK.md) and [Docker test deployment](docs/SERVER_TEST_DEPLOYMENT.md). The supplied log proves repeated auxiliary IPC churn, not a completed reproduction of a live Telegram restart hang or the original permission error. No Docker or real Telegram acceptance is claimed.

## 3.0.0-dev.4 - unpublished local development snapshot

- Replace dense group configuration text with categorized button menus, current-value summaries, preset pickers and guided custom inputs in six languages.
- Use the requested exact Hebrew welcome, expanded role-appropriate help and ordered upcoming Shabbat/festival pages with intermediate candle times, final exit, parsha and Mevarchim.
- Add 12 animated emoji IDs, verified Unicode alternatives, native button icons/colors and guarded one-time basic-emoji fallback. Normalize user-visible em dashes without changing UTF-16 entity positions.
- Preserve native custom-message text/entities, including empty entity arrays, media and independently editable keyboards. Add inherit/custom/none modes and default inline-share buttons for group Shabbat, daily times and Daf messages.
- Add the default blue tefillin acknowledgement with unique per-message/user toggles, callback deduplication, durable counts and bounded coalesced keyboard edits/retries. Group managers can rename, disable or replace it.
- Add delegated manager capabilities, view-only access, full known subscriber profiles/filter/search/paging, authorized broadcast audiences, rich drafts, edit-last, scheduling, self-destruct jobs and confirmed maintenance controls.
- Add system deep links and HMAC-based tracking-link statistics. Navigation links do not grant access or mutate privileged settings.
- Add custom integer Stars donations from 1 to 10,000 with payer-bound, one-use invoice flows; preserve existing invoices and charges in migration 011.
- Add migrations 009-012 without editing migrations 001-008. Existing test databases require stopped-writer backup/migration.
- Keep MadelineProto exactly 8.7.0 and Composer lock unchanged. Extend the separately reviewed BroadcastManager 3.2.2 patch with an optional per-instance native-message transport for emoji fallback; bundle pristine source, previous reviewed output and an exact guarded transition diff.
- Preserve dev.3 calendar corrections and continuous holy-period/permission restoration logic. Actual Docker build, remote CI and live Telegram acceptance remain unperformed. No commit, tag or publication was created.

## 3.0.0-dev.3 — unreleased

### Fixed

* Complete Hebcal solar replies include empty elevation-only seaLevel series and sparse molad/Kiddush Levanah fields. They are not daily schedules. An explicit supported daily-field contract prevents these and unknown extensions from invalidating or influencing operational times.
* Sunset, present supported daily coverage, exact dates/IANA offsets, preceding-night midnight and selected operational nightfall validation remain enforced; missing required data still stops permission changes.
* Full unprojected public fixtures now cover the exact default Jerusalem response. The earlier dev.2 seven-series projection did not cover that contract.

### Added

* Credential-free read-only calendar diagnostics through the real HTTP/provider/service path, with safe stage/reason codes.
* Full-response service/UI tests for daily times, Shabbat/festivals, tefillin and Omer, including valid out-of-season behavior.

Locked dependencies, four vendor patches and eight migrations remain unchanged. No production changes, commits, tags or publication.

## 3.0.0-dev.2 — unreleased

### Fixed

* Accept verified preceding-night solar midnight without weakening ordinary solar date or IANA offset validation; regression captures include Jerusalem winter, summer and daylight-saving transitions.
* Recognize bare inline city names and retain explicit provider/configuration errors.
* Edit the originating private callback panel instead of sending new menu messages, including save, information, statistics and rich-preview navigation.

### Changed

* Simplified location setup hides internal workflow tokens behind city/ID/coordinates/timezone/GPS prompts and named-context confirmation.
* Organized six-language information categories, emoji command descriptions, original-style statistics and richer group-specific Shabbat/Daf/start presentation.
* Consistent primary, neutral and danger button colors; custom message styles remain supported.

Dependencies, four reviewed library patches and eight migration files remain unchanged. Real Telegram, Docker and remote CI acceptance remain pending; no production change or publication is performed.

## 3.0.0-dev.1 — unreleased

### Fixed

* Docker now gives the unprivileged bot user ownership of /app itself. PrimeModule temporary Python fallback files and MadelineProto 8.7.0's IPC bootstrap logger can be created without changing locked dependencies or running the bot as root.
* An offline permission check exercises the locked PrimeModule source copies, worker logger and data/session paths. The image build runs it after USER shabbat; CI and release preparation repeat it with fresh runtime volumes.
* PHP exception argument collection is disabled globally in Docker, including IPC workers, and at main Runner startup. This prevents the token argument exposure observed in MadelineProto TL traces; arbitrary exception messages still require private handling.

## 3.0.0-dev — unreleased

### Added

* All 22 locked native member-restriction flags, default 21 including reactions/member-tag editing, and read-only bot administrator-right diagnostics.
* Separate opt-in holy-period cleanup for members and other bots, using authenticated incoming updates and a durable deadline/lease/retry queue.
* Delete previous tefillin reminder only after a confirmed replacement; durable successful-message pointers and independent crash-replayable cleanup bookkeeping.
* Group-specific rich Shabbat/festival announcements, all intermediate candle times, Hebrew/civil dates, parasha/Mevarchim/provider metadata and seven local-time presets plus custom time.
* Independent announcement rich templates, preserved through migration 008, and a bundled test-server ZIP with an isolated setup/rehearsal guide.

* Explicit UTC clock and shared calendar/solar services, per-group SQLite settings and revision-bound workflows.
* Complete he/en/ru/fr/es/pt-br catalogs for personal/group panels, automatic notices and scoped native command descriptions.
* GeoNames city selection with private confirmation, IANA timezone support and separate personal preferences.
* Opt-in daily zmanim, Daf Yomi, tefillin and Omer planning; bundled licensed texts for all 49 Omer counts.
* Permission snapshots and outbox jobs with recovery, leases, stable MTProto random IDs and execution cutoffs.
* Additive automatic registration from `data/DBgroups.txt`; existing groups can use `/settings` without registering again.
* Maintenance import, consistent SQLite backup/restore, self-check, dependency evidence and local release packaging.
* Full-source patch verification for MadelineProto 8.7.0, including deliberate cancellation and shutdown tests.
* A separately reviewed BroadcastManager 3.2.2 presentation hook for six-language live status/counters and native colored controls, preserving upstream defaults and notices.

### Changed

* Application licensing to AGPL-3.0-only; original MIT notices retained and historical grants preserved.
* MadelineProto is constrained to exactly 8.7.0; its unchanged 83-package lock prevents accidental dependency selection. This upstream tag is a pre-release, and upgrades require explicit source/patch review. BroadcastManager wildcard replaced by a tested 3.2 patch range.
* SQLite application data is separate from the MadelineProto session. Numbered migrations include administrative drafts, bound Stars accounting, temporary manual overrides and six-language preservation.
* Operator menus retain BroadcastManager capabilities and use the configured session/data paths.

### Fixed

* Shared calendar engine retains ongoing events rather than discarding passed entry times.
* Adjacent prohibited days are evaluated as continuous intervals; operational offsets are separate from displayed candle/havdalah times.
* Exact 8.5-degree nightfall and explicit minute choices are retained without integer truncation or arbitrary minute adjustments.
* Native integer peer IDs are handled in private GPS and payment updates; bot-owned GPS reply tokens remain scoped to their user/group.
* A separate exact-source MadelineProto GeoPoint correction preserves fractional coordinates before inline dispatch.
* A new reviewed formatted-date correction completes the locked entity factory and adds a typed native/Bot API wrapper. It preserves timestamps, UTF-16 offsets and valid flags, with strict two-file/schema guards; historical reference code with unresolved grants is not redistributed. See [review](docs/MADELINE_FORMATTED_DATE.md).
* Permission FLOOD_WAIT deadlines survive restart, stale/expired owners cannot confirm mutations, and desired state is refreshed before RPCs.
* Content waits for bot-owned permission recovery; Omer waits its configured gap after the actual restore. Eligibility deferrals do not exhaust transport retries.
* Explicit group rejoining preserves settings, while startup list import cannot reactivate removed groups. Startup schema validation rejects missing or changed migrations without writing.

### Security

* Settings confirmation tokens bind user, group, revision, action and expiration; public location text cannot write settings.
* Durable snapshots precede permission mutation. Manual conflicts stop automatic restoration.
* Local vendor patches fail closed on unknown, partial or incompatible sources.

### Breaking changes and migration

This is a development version. Stop the old writer, take and verify backups, migrate an isolated copy, and rehearse rollback before production. New modules stay disabled for imported groups. Automatic list registration does not import active-lock snapshots; the explicit importer must run before replacing an old bot that owns locks. The old session is never deleted or converted. See [migration](docs/MIGRATION.md).

## Earlier releases

The repository contains historical tags through `v2.0.3`. Historical release descriptions and dates have not been reconstructed or invented; consult the existing Git history.
