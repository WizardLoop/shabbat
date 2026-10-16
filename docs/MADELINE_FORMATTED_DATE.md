# MadelineProto formatted-date compatibility and version policy

Reviewed 2026-10-14. The selected package is danog/madelineproto 8.7.0, immutable commit 7b99dd0f2d964a492c377912ffce9f5ce61aa9ed. Both composer.json's exact constraint and composer.lock preserve this version; composer install uses the full 83-package lock. At the 2026-10-14 review, GitHub marked 8.7.0 as a pre-release and 8.6.5 as Latest; these are historical observations, not a current release lookup. This project remains a 3.0.0 development snapshot and does not describe the selected dependency as an upstream stable release.

## The concrete error

The package's layer 225 TL schema declares messageEntityFormattedDate#904ac7c7 with UTF-16 offset/length, a Unix timestamp and six boolean flags. The original EventHandler/Message/Entities/MessageEntity.php factory lacks both a FormattedDate wrapper and its dispatch branch. Valid received entities raise AssertionError: Unknown entity type: messageEntityFormattedDate at the original factory line 60. Message::__construct calls that factory before typed event-handler dispatch, so catching an error only inside a bot handler does not repair the missing wrapper.

This is message-formatting metadata. It does not replace the group's IANA timezone, change calendar calculations, reinterpret Hebcal JSON dates or convert the operational Shabbat/festival boundaries.

Before changing vendor, eight valid inputs reproduced the exact failure without creating an API, a session or a Telegram connection. A separate probe assembled explicitly labeled schema wire with the native integer encoder and used the actual locked TL decoder for all 19 valid flag combinations. It was not represented as a complete TL(null) serializer integration. Historical raw investigation output is not included in the publication source. Reproducible regression tests and the source artifacts remain included; the current publication report records fresh checks.

## Available history and reference

The local shabbat repository has 98 reachable commits across all references and reflogs. Searches of file paths, commit messages and content changes found no previous FormattedDate/messageEntityFormattedDate correction or dedicated test. This is the accessible local history; deleted/unavailable remote history is not claimed.

Two local exports contain byte-identical implementations. The labels below are logical local-reference project/file identifiers, not public download URLs:

- local-reference/newsearch-v2/docker/patch-madeline-formatted-date.php
- local-reference/psy new/release-r21-work/docker/patch-madeline-formatted-date.php

Their script SHA256 is c3091f61a401d8022b8ef1d228cf8c5f31a1094982de69a0be108e21885cb967. Their docker/madeline-formatted-date/FormattedDate.php wrapper SHA256 is a9e3146e49a88bec469e67e53f5e27cffb1b5127cde547590f2e385ef02dd264. The corresponding test is tests/madeline_formatted_date_compatibility_test.php.

These directories have no usable Git history. Their filesystem modification timestamp is 2026-09-03T23:06:59Z; that is not asserted to be a commit or authorship date. The export package declarations conflict (MIT versus proprietary) and no standalone grant/header was found for those reference files. Their behavior and hashes were reviewed, but the original script/wrapper is not redistributed as an audited upstream artifact.

A fresh implementation is based on the official protocol and the locked SDK's entity conventions. Its applied unified diff is a new reviewed correction, not described as the original unchanged historical patch. Native/BotAPI conversion retains the timestamp and UTF-16 offsets; the protocol's unknown-entity rejection remains in place. It does not add a broad catch-and-ignore fallback.

## Official source check and protocol contract

The official v8 head checked on 2026-10-14 was b1a4c5efee60a87cdbe7bc2b1eb542166ec7e2e3 (commit date2026-10-07); its entity factory still lacked this support. No newer compatible official correction was approved during this review. The exact tested 8.7.0 source is retained instead of silently selecting a moving branch.

The supported BotAPI date_time format is r|w?[dD]?[tT]?. Empty formatting is valid. Relative formatting cannot be mixed with other flags; short/long variants of time or date cannot coexist. Telegram controls the actual client rendering. Generated HTML previews may preserve the underlying text without a dynamic date tag, while native entity capture/resend retains the date metadata; neither is claimed as a live client test.

See the [official constructor](https://core.telegram.org/constructor/messageEntityFormattedDate), [date-time formatting](https://core.telegram.org/bots/api#date-time-entity-formatting), [selected release](https://github.com/danog/MadelineProto/releases/tag/8.7.0) and [checked official source](https://github.com/danog/MadelineProto/blob/b1a4c5efee60a87cdbe7bc2b1eb542166ec7e2e3/src/EventHandler/Message/Entities/MessageEntity.php).

## Installation, licensing and future updates

Use the shared [patch workflow](PATCHES.md): install from the lock, apply and verify approved patches before startup, and use the explicit route after --no-scripts. Startup only verifies; never change an active Composer tree or running container. The new class and factory are an approved set: partial, unknown, mismatched or incompatible source must stop the build. A matching already-patched set is a no-op. Corresponding-source archives retain the exact SDK baseline, new class, diff, manifest, rights notices and license.

The supplied Docker layout mounts /app/data and /app/runtime only. It does not mount /app or /app/vendor, so an old named vendor volume cannot hide dependencies in that layout. Patching occurs during image construction. The other project's /opt/theisraelis copy workaround is specific to its broader runtime mounts and is not silently transplanted here.

AGPLv3 allows conveying modified source and the modifications that produce it, subject to its conditions: retain notices/license, identify changes and dates, license the covered combined work appropriately, provide corresponding source with distributed object code, and offer the modified program's source to remote network users where section 13 applies. MadelineProto package metadata says AGPL-3.0-only; existing v3-or-later headers remain untouched, using their common version 3 grant. This supports including the reviewed diffs in the AGPL Shabbat repository; it is not a blanket certification of every contributor, asset or deployment practice. See [third-party notices](../THIRD_PARTY_NOTICES.md) and the [GNU license text](https://www.gnu.org/licenses/agpl-3.0-body.html).

An exact lock prevents accidental version selection; it cannot guarantee an unseen future Telegram/PHP version will stay compatible. A future upgrade requires an explicit constraint/lock change, reviewed version/commit/schema/file signatures, all behavioral tests, a clean install and an isolated target rehearsal. Unknown versions fail closed. Dependency update proposals and CI do not constitute automatic approval to publish or deploy.

No active bot/container, production data or real Telegram message was changed during the investigation. The final applied state and exact complete-suite/archive results are recorded in [implementation status](IMPLEMENTATION_STATUS.md) and the external checks/PUBLICATION_REPORT.md for this preparation. Historical private validation outputs are not bundled. No task commit, local tag or publication is claimed.
