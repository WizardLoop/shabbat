# Offline patch installation

[Review provenance and behavior](../patches/madelineproto/README.md).
Composer lifecycle scripts and Docker install offline, verify exact hashes,
and fail on unknown source. Startup verifies only.

With scripts disabled:

    composer install --no-dev --no-scripts --prefer-dist
    php scripts/apply-patches.php
    php scripts/verify-patches.php
    php bin/self-check.php

Do not bypass manifest failures or edit a live container. Review upgrades on an
isolated installation and prefer a tested official correction when available.

Reference runBot implementations were inspected in importchatsbot/src/bot.php
and SwiftTool/bot.php. Runner reproduces Revolt error-handler installation,
credentials/Settings setup and startAndLoopBot. The typed error callback is rebound on handler attachment, after the SDK installs its own startup callback. It uses narrow typed checks for
completed-operation and ReferenceDatabase errors. It does not swallow every error
whose text says "The operation was cancelled": the explicit cancellation/shutdown
requirements forbid that behavior. Unexpected errors exit 1; shutdown flags stop
new application work. SingleInstance protects the shared runtime directory.

Offline loop tests do not prove behavior against Telegram, real network failure,
or signal delivery in every container runtime. Verify those using an isolated
staging bot and test groups before production deployment.
Mutation also acquires the same APP_DB_PATH + .writer.lock used by Runner and
SQLite maintenance. An active writer makes application fail before a file is
changed; read-only verification and an already-patched no-op remain available.
Stop the isolated bot before applying a required patch. The installer only
creates a lock file on its configured working data path, never starts Telegram.

The same installer requires the reviewed
[BroadcastManager status-localization patch](../patches/broadcastmanager/README.md),
[GeoPoint double-coordinate correction](../patches/madelineproto-geopoint/README.md)
[formatted-date entity correction](../patches/madelineproto-formatted-date/README.md)
and [fresh full-peer response correction](../patches/madelineproto-fresh-peer/README.md).
Each manifest identifies its locked package/commit, target, baseline, diff and
final hashes. All five patch sets are applied before runtime and verified by
Runner/self-check; missing required evidence or unknown source fails the build.
Baseline hashes remain checked when installed vendor source is already patched.

The formatted-date set changes the entity factory and adds one independently
written class; both must match the approved set. Its pinned TL schema and original
AGPL license are also checked. A partial factory/class installation fails closed.
The located reference script is not redistributed: its exports lack usable Git
history and a consistent standalone license grant. This is a new reviewed
implementation, documented in [the formatted-date review](MADELINE_FORMATTED_DATE.md).

The [GNU AGPLv3](https://www.gnu.org/licenses/agpl-3.0-body.html) permits conveying
covered modifications, including diffs, with retained notices, identified changes,
AGPL licensing and applicable source offers. Exact grants and reference limitations
are recorded in [third-party notices](../THIRD_PARTY_NOTICES.md). Preparing these
artifacts does not publish them or authorize deployment.

RuntimePaths resolves DATA_DIR, APP_DB_PATH, SESSION_PATH and HEARTBEAT_PATH once
before startup and exports them for the preserved legacy handlers. Maintenance
commands use that same DB default and retain explicit --db overrides.

## Native SDK boundaries

The runtime rebinds the typed Revolt error handler in onStart after MadelineProto
installs its own callback. The gateway uses the declared messages.getForumTopicsByID
method with peer/topics. Offline tests check reachable gateway, invoice, and menu
methods against the locked SDK declarations, effective blacklist, and TL schema.

Fresh permissions use the supported getFullInfo helper with fullInfoCacheTime=0
and the reviewed per-call raw response field. The reader requires complete matching
Chat/ChatFull evidence; it never substitutes a second cached getInfo result. Direct
channels.getFullChannel/messages.getFullChat namespace calls are blacklisted and
are not used. A nonzero cache policy fails closed. Public full-info helper calls
have the official RPC setting's five-second minimum automatic flood wait; longer
waits propagate as typed scheduler retries. Direct namespace calls use the
per-call floodWaitLimit=0. Permission leases and desired state are fenced again
after awaited reads.

Decimal random_id, query_id and keyboard style.icon values are validated within
signed int64 and converted to native integers at the outbound boundary. The
locked TL serializer interprets eight-byte strings as packed binary; real
serializer regressions cover eight-digit decimals and signed limits. Stored
identifiers remain decimal strings, preserving database identity.

Fresh permissions use the supported getFullInfo path with full-info caching disabled and verified per-call raw response evidence. See the fresh-peer patch README and dev.8 investigation for the reproduced stale-cache limitation of the earlier two-helper path. Direct full-info namespace calls are blacklisted in the locked SDK. Public full-info helpers use the official five-second minimum flood-wait setting; longer RateLimitError-family waits become typed retries. Ordinary namespace RPCs retain the verified per-call zero-wait option. Native decimal int64 query/random/icon IDs are validated and converted at the outbound boundary; persisted IDs remain decimal strings.
