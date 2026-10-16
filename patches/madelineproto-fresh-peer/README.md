# MadelineProto 8.7.0 fresh full-peer response compatibility patch

Reviewed: 2026-10-16. This is a new local WizardLoop Shabbat compatibility change, not a recovered historical patch or an upstream release. Package: `danog/madelineproto` 8.7.0, commit `7b99dd0f2d964a492c377912ffce9f5ce61aa9ed`.

## Source and reason

The immutable [upstream PeerHandler.php](https://github.com/danog/MadelineProto/blob/7b99dd0f2d964a492c377912ffce9f5ce61aa9ed/src/MTProtoTools/PeerHandler.php) was compared byte-for-byte with the installed locked source. Its SHA256 is `3e1af3624f2256f53264fe4256475d0a5f353af8761cc9716133b7f0412538d2`. Baseline and reviewed output are retained in this directory; the manifest binds the unified diff and both hashes.

Upstream `getFullInfo` refreshes the full information but combines it with an earlier Chat object. Re-reading through `getInfo` is insufficient: the pinned minimal-peer branch can return its older local record after refreshing the peer cache. The bot must establish that the default permissions being saved came from the request it just completed.

Direct `channels.getFullChannel` and `messages.getFullChat` namespace calls are blacklisted by the pinned SDK. This patch keeps the public `getFullInfo(mixed $id)` signature and uses its existing internal calls. It does not bypass that API boundary or change dependency versions.

## Change

When the full-info cache lifetime is zero, bypass cached full information even if its timestamp is in the future. Keep the immediate full-peer RPC response in a namespaced `_shabbat_fresh_full_peer_v1` return field for this call only. Do not persist that field in the peer cache. Other helper fields and normal positive-cache-lifetime behavior remain intact.

The application reader validates the returned native constructor, matching signed peer ID, matching ChatFull object and complete non-minimal Chat. Cached records cannot replace the raw Chat, including when fresh complete data legitimately omits optional default restrictions. Malformed, forbidden, migrated, ambiguous and mismatched responses fail before a permission write. Individual restriction bits, including `manage_topics`, are not silently discarded.

This fixes the reproduced reading defects. It does not prove which historical server event produced the user's broadly permissive dev.6 snapshot. Existing unverified snapshots require explicit administrator resolution; see [the dev.8 investigation](../../docs/DEV8_PERMISSIONS.md).

## Installation and source offer

The normal Composer lifecycle and Docker build run `scripts/apply-patches.php` then `scripts/verify-patches.php`; startup verifies only. For `--no-scripts`, run those two scripts explicitly after installation and before starting the bot. Original known bytes apply and verify; approved patched bytes succeed unchanged; unknown version, hash, partial patch or artifact tampering fails the build. No patch is downloaded from a changing URL. Never modify an active container/library tree.

The exact original source, reviewed source, unified diff and manifest accompany the corresponding-source archive. The original upstream copyright/AGPL notices are retained. Local changes are identified here and in the patch comment; the modified source remains under its upstream AGPL grant. See [third-party notices](../../THIRD_PARTY_NOTICES.md). Vendor remains excluded from Git tracking.

## Tests and limitations

`FreshPeerReaderTest` executes the pinned SDK trait with controlled replies, including stale complete/minimal peer records, both basic-group and supergroup RPC paths, disabled/future cache timestamps, missing optional defaults, malformed/missing/minimal/forbidden/duplicate/mismatched replies, cancellation and timeout. `FreshPeerPatchTest` verifies clean apply, repeat, unknown source/version, partial edits and tampered patch evidence. Actual SDK declarations, TL schema and signed-ID decoding are checked separately.

The tests do not authenticate or call Telegram. Real Telegram readback, client behavior and load acceptance must be checked with the isolated bot. Full frozen results and exact output hashes are recorded in the versioned validation report.
