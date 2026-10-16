> Historical implementation record. Referenced `dist/VALIDATION-*` reports belong to earlier private development deliveries and are not bundled here. Current publication checks and limits are described in [publication status](PUBLICATION_STATUS.md).

# Dev.8 permission reading and existing snapshots

## Evidence and limits

The supplied dev.6 database row was in `conflict`. Its original snapshot allowed all listed actions except editing a member's own tag; its checksum matched. The saved mask therefore explains why restoring it would allow media, invitations and pins even though the administrator reported earlier restrictions. A checksum establishes consistency of saved bytes, not whether the initial Telegram read was accurate. The literal missing-rights fallback cannot by itself produce that row's explicit `edit_rank=true` and `until_date=2147483647`. The exact server-side cause of this older snapshot has not been established.

Independent offline investigation found a real read-integrity defect in the pinned SDK/helper path: `getFullInfo` combines a refreshed full object with earlier peer data, and `getInfo` can return its earlier minimal record after refreshing the peer cache. Re-reading that cache did not establish that permissions came from the just-completed request. An absent optional restriction object in verified complete data can legitimately mean no default restrictions; absence in incomplete or unverified data is not proof of that state.

The separate `manage_topics` discrepancy does not justify ignoring this restriction for non-forum groups. No server normalization rule was established. Actual readback remains subject to comparison and unresolved evidence is retained.

## Implemented protection

MadelineProto remains exactly **8.7.0** at source commit `7b99dd0f2d964a492c377912ffce9f5ce61aa9ed`, with the existing Composer lock. A fifth, separately guarded local patch exposes the fresh raw full-peer response through the existing supported `getFullInfo` helper when caching is disabled. It does not call blacklisted namespace methods, change the public helper signature, or run patches while the bot is active. Its immutable source, diff, reviewed output, manifest and tests accompany the corresponding source.

The permission reader validates matching requested peer, full-peer type/ID and complete non-minimal Chat from that response. It rejects malformed, absent, duplicate, forbidden or mismatched records. It does not substitute cached rights. Both manual and scheduled operations use this reader for capture and verification, and restore the original restriction object rather than constructing a general open mask. Cancellation and Telegram retry deadlines still propagate.

Migration 015 records snapshot provenance. Existing unresolved snapshots from older versions remain intact and are treated as unverified; automatic or explicit restoration of those snapshots is blocked. A new week, restart or matching checksum does not make an older snapshot trustworthy. Completed cycles may take a new verified baseline at the next closure. Fresh captures preserve their original bytes, checksum and capture evidence through the cycle.

The group recovery panel explains the legacy state in all six languages. Restore remains available for verified snapshots. A legacy snapshot requires the administrator to set the intended normal rights in Telegram, refresh the panel and explicitly confirm adoption, outside a protected period. The old snapshot remains as evidence. The diagnostics panel/export reports snapshot provenance without dumping JSON into messages.

## Existing affected test group

1. Deploy dev.8 with the same isolated test bot, `.env` and named volumes, after stopping writers and taking the migration backup.
2. Outside Shabbat/Yom Tov and any active lock, use Telegram's group permissions to set the actual intended normal state. Do not copy the broadly permissive older snapshot.
3. Open the bot's group settings, **Resolve permission problem**, then **Refresh**. Check the named group and the current permissions, then choose **Use current permissions** and confirm.
4. Test a new manual close/open cycle in a disposable group with media, invitations and pinning deliberately restricted beforehand. Verify those exact restrictions after reopening and after a restart. Also check the automatic path at a controlled test boundary.

Do not delete lock rows or adopt a currently locked mask as the normal state. An active close override, religious period, unavailable calendar or uncertain read prevents recovery. A readback conflict after an update can mean Telegram already changed permissions; it is not evidence that nothing happened.

## Validation and live acceptance

The versioned `dist/VALIDATION-3.0.0-dev.8.json` identifies the frozen source, full suite, quality gates, migration tests and extracted archive checks. Native SDK tests use the installed pinned trait with controlled RPC responses; they do not contact Telegram. Patch tests include clean apply, repeat, mismatch and partial installation. Database upgrade tests preserve older snapshots and prove that they cannot trigger unverified restoration.

Docker is unavailable on the Windows development host. Its older local SQLite is intentionally rejected by the release platform gate; the pinned Docker runtime must pass that gate on the server. Live Telegram permissions, Telegram client rendering and actual group behavior still need isolated acceptance. No production data, bot sessions or real groups were used for development tests. No commit, tag, pull request or publication is part of this update.

See [recovery](PERMISSION_RECOVERY.md), [deployment](SERVER_TEST_DEPLOYMENT.md), [patches](PATCHES.md) and the official [restriction flags](https://core.telegram.org/constructor/chatBannedRights), [default-rights method](https://core.telegram.org/method/messages.editChatDefaultBannedRights) and [MadelineProto helper requirement](https://docs.madelineproto.xyz/API_docs/methods/channels.getFullChannel.html).
