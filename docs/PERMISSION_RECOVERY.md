# Permission recovery

The group settings panel provides **Resolve permission problem**. Open it from the group's main settings or its Shabbat/permissions category. The private panel names the group, shows the saved state and a bounded comparison of current versus original restrictions, and offers a reviewed confirmation for either **Use current permissions as normal** or **Restore permissions saved before the problem**. Navigation edits the same message.

## Fresh reads and older snapshots (dev.8)

All permission reads use the exact matching full-peer response from the supported helper, with a separate immutable patch to pinned MadelineProto 8.7.0. Cached or minimal records cannot authorize capture or readback. Migration 015 preserves older snapshots but labels them unverified. Any unresolved older cycle requires administrator resolution: automatic opening and the restore button cannot use that snapshot. The six-language panel explains how to configure the intended normal permissions in Telegram, refresh and explicitly adopt them outside protected periods. Do not delete the saved row or automatically accept an already opened group. Completed cycles may capture a fresh baseline at the next lock. See [the investigation and recovery steps](DEV8_PERMISSIONS.md).

## Automatic recovery

A completed lock cycle permits a fresh baseline before the next closure. Legitimate weekday changes, such as disabling media, therefore survive the following Shabbat and reopening.

The coordinator recognizes verified equivalent Telegram representations, a current original baseline, and known bot-applied masks. It also reconciles additional restrictions on known permission fields that the bot did not alter, preserving those external restrictions in a separate checksummed recovery baseline. Relaxations remain explicit-review cases because they can also represent a partial unlock. The original snapshot remains available. Changes to bot-controlled fields, unknown flags, expiry discrepancies and partially applied masks do not authorize a guessed reset. A first lock that the durable journal proves was deferred before any Telegram write can retire that unexecuted attempt and begin from fresh normal permissions; this requires matching original/requested evidence and no prior recovery debt. A new week or process restart alone never discards unresolved state.

Permission attempts retain bounded before/requested/observed evidence, stage and outcome. A failed confirmation does not establish that Telegram made no change. The same coordinator and recovery rules serve manual and scheduled operations.

## Administrator resolution

Both choices require a freshly verified group administrator and a short-lived, single-use confirmation bound to the actor, group, settings revision, lock state and observed permissions. If any of those change, refresh and review again. The bot retains the previous evidence for diagnosis.

Adopting current permissions establishes an explicitly approved normal state; it does not send a restriction update to Telegram. A currently closed group cannot silently become an ordinary open-group baseline. Restoration is available only for a verified snapshot and sends the preserved original permissions and verifies a fresh response before marking recovery complete. Neither action resets unrelated group preferences or enables reminders.

Recovery is blocked during religious Shabbat/Yom Tov, configured operational closure windows, active manual closure or shutdown. An operator-open override does not bypass the religious guard. Unknown calendar data cannot grant recovery. A failed manual close can still have an active override until its displayed expiry; resolve that intent before confirming recovery.

A verified calendar deadline also fences the final remote read synchronously, without another asynchronous authorization gap before commit. Per-group leases and revision checks protect against simultaneous scheduler work, changed remote permissions and replayed confirmations. Network calls occur outside database transactions. Rate limits retain their retry deadline instead of sleeping the whole bot. Telegram does not offer a compare-and-swap operation for default restrictions; an administrator change after the final read or during an in-flight update cannot be made atomic with this process. The bot checks the observed result and retains unresolved evidence when verification fails.

## Upgrade and acceptance

Dev.7 adds migration 013 for recovery state and evidence. Existing migrations 001-012 and the MadelineProto 8.7.0 dependency/patch lock remain unchanged. Stop all writers and take the verified migration backup before upgrading. See [test deployment](SERVER_TEST_DEPLOYMENT.md).

Offline tests cover safe automatic recovery, preserved original snapshots, repeated cycles, administrator changes, stale/forged confirmations, role loss, cancellation, timing boundaries and migration preservation. Actual Telegram readback and both recovery choices must also be checked in the isolated test bot. See the versioned validation report for the exact checks that ran.

Native restrictions follow Telegram's [chatBannedRights constructor](https://core.telegram.org/constructor/chatBannedRights) and [default group-rights method](https://core.telegram.org/method/messages.editChatDefaultBannedRights). Individual true flags represent restrictions, so the UI translates them to permitted/blocked rather than showing raw booleans.

Dev.8 adds migration 015 and a fifth fresh-peer patchset. The first 14 migrations, Composer lock and earlier four patchsets remain unchanged. Follow the current deployment instructions rather than historical version-specific notes.

## Final permission-write checks

Ordinary manual and scheduled changes re-read current permissions after any asynchronous desired-state/calendar evaluation. An administrator change during that wait prevents the write and retains evidence. A synchronous final check verifies the same settings, manual-override expiry, next operational time boundary and shutdown state before dispatch. This reduces stale writes; Telegram still has no compare-and-swap API, so a change after the final read or during the request is not atomic. Readback is required and post-write mismatches are explicitly reported as unverified changes, even when the confirmed-change counter is zero.
