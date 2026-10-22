# MadelineProto 8.11.10 fresh full-peer response compatibility patch

Reviewed: 2026-10-22. The local WizardLoop Shabbat compatibility change is verified against the exact new upstream source, which is byte-identical to the previously reviewed 8.11.1 baseline; it is not an upstream release. Package: `danog/madelineproto` 8.11.10, commit `49d3d8460257c7bfb1a7e12708e1bfdd59e898c1`.

## Source and reason

The immutable [upstream PeerHandler.php](https://github.com/danog/MadelineProto/blob/49d3d8460257c7bfb1a7e12708e1bfdd59e898c1/src/MTProtoTools/PeerHandler.php) was compared byte-for-byte with the installed locked source. Its SHA256 is `6caa058975e7122eeb84409142a7c8241a2da5c63ca4c63e1ec2392c7b3f7314`. Baseline and reviewed output are retained in this directory; the manifest binds the unified diff and both hashes.

Upstream `getFullInfo` refreshes the full information but combines it with an earlier Chat object. Upstream 8.11.10 fixes the earlier minimal-peer re-read defect in `getInfo`, but `getFullInfo` still combines the partial record captured before the full RPC with cached full data. It provides no immediate full-reply provenance to the application. The bot must establish that the default permissions being saved came from the request it just completed.

Direct `channels.getFullChannel` and `messages.getFullChat` namespace calls are blacklisted by the pinned SDK. This patch keeps the public `getFullInfo(mixed $id)` signature and uses its existing internal calls. It does not bypass that API boundary or change dependency versions.

## Change

When the full-info cache lifetime is zero, bypass cached full information even if its timestamp is in the future. Keep the immediate full-peer RPC response in a namespaced `_shabbat_fresh_full_peer_v1` return field for this call only. Do not persist that field in the peer cache. Other helper fields and normal positive-cache-lifetime behavior remain intact. The new official `community` peer branch and invalid-peer exception are preserved. Upstream 8.11.10 still discards the raw group RPC reply, so it does not provide equivalent permission evidence.

The application reader validates the returned native constructor, matching signed peer ID, matching ChatFull object and complete non-minimal Chat. Cached records cannot replace the raw Chat, including when fresh complete data legitimately omits optional default restrictions. Malformed, forbidden, migrated, ambiguous and mismatched responses fail before a permission write. Individual restriction bits, including `manage_topics`, are not silently discarded.

This fixes the reproduced reading defects. It does not prove which historical server event produced the user's broadly permissive dev.6 snapshot. Existing unverified snapshots require explicit administrator resolution; see [permission recovery](../../docs/PERMISSION_RECOVERY.md).

## Installation and source offer

The normal Composer lifecycle and Docker build run `scripts/apply-patches.php` then `scripts/verify-patches.php`; startup verifies only. For `--no-scripts`, run those two scripts explicitly after installation and before starting the bot. Original known bytes apply and verify; approved patched bytes succeed unchanged; unknown version, hash, partial patch or artifact tampering fails the build. No patch is downloaded from a changing URL. Never modify an active container/library tree.

The exact original source, reviewed source, unified diff and manifest accompany the corresponding-source archive. The original upstream copyright/AGPL notices are retained. Local changes are identified here and in the patch comment; the modified source remains under its upstream AGPL grant. See [third-party notices](../../THIRD_PARTY_NOTICES.md). Vendor remains excluded from Git tracking.

## Tests and limitations

`FreshPeerReaderTest` executes the pinned SDK trait with controlled replies, including stale complete/minimal peer records, both basic-group and supergroup RPC paths, disabled/future cache timestamps, missing optional defaults, malformed/missing/minimal/forbidden/duplicate/mismatched replies, cancellation and timeout. `FreshPeerPatchTest` verifies clean apply, repeat, unknown source/version, partial edits and tampered patch evidence. Actual SDK declarations, TL schema and signed-ID decoding are checked separately.

The tests do not authenticate or call Telegram. Real Telegram readback, client behavior and load acceptance must be checked with the isolated bot. Full frozen results and exact output hashes are recorded in the versioned validation report.
