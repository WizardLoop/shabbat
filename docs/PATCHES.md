# Offline patch installation

Only two patch sets are active in 3.1.0:

| Package | Local change | Reviewed source |
|---|---|---|
| MadelineProto 8.11.10 | Fresh full-peer response evidence for safe group permission snapshots | [Provenance and tests](../patches/madelineproto-fresh-peer/README.md) |
| BroadcastManager 3.2.2 | Application delivery hooks and localized status | [Provenance and tests](../patches/broadcastmanager/README.md) |

Composer lifecycle scripts and Docker builds apply local immutable diffs and
verify exact source/target hashes. Startup verifies only. Unknown versions,
partial patches or changed artifacts fail closed; an already patched approved
file succeeds without mutation. Baselines remain verified after application.

With Composer scripts disabled, run from the stopped installation root:

```bash
composer install --no-dev --no-scripts --prefer-dist
php scripts/apply-patches.php
php scripts/verify-patches.php
php bin/self-check.php
```

Patch mutation acquires the same `APP_DB_PATH + .writer.lock` as Runner and
SQLite maintenance. An active writer prevents changes. Read-only verification
and an already patched no-op remain available. Do not modify an active container,
ignore a verification error, download a floating patch, or use `composer update`
as a routine deployment command.

## Official compatibility in 8.11.10

The old local UpdateLoop, GeoPoint and FormattedDate patch trees were removed.
Official date entities and fractional coordinates now have direct compatibility
tests. Date payload validation stays at the application template boundary.
The native update loop recognizes timeout cancellation, but that alone does not
prove safe shutdown. `UpdateLoopCompatibilityTest` executes the official loop
and its real parent with controlled RPC boundaries, checking recovery,
intentional cancellation and a stop concurrent with timeout. These tests exercise one failure followed by recovery; they do not establish
a global retry/log-rate bound. Parent stop prevents rescheduling but does not cancel
an in-flight loop body. A separate SDK disconnect/finalize fixture keeps writer
ownership through a modeled session flush and rejects stale socket reuse.
`SdkWorkerIsolationTest` exercises the official IPC worker descriptors with a local
PHP child: Linux checks that it cannot retain the parent lock or listening socket.

`Runner` keeps typed cancellation handling, quiesces application work, and retains
the process writer lock during SDK session finalization. It does not swallow every
exception containing the word cancellation. SDK-level retry and shutdown behavior
is checked separately from application drain and single-writer tests.

Offline tests do not authenticate to Telegram or establish real network, IPC,
signal or production-load acceptance. Use a separate staging bot after all gates
pass. Retired patch history remains available in the 3.0.0 Git tag/source release.

## Licensing and corresponding source

Each active manifest binds package version, commit, baseline, reviewed output,
diff and hashes. The source offer includes these artifacts and modified runtime
sources with original rights notices. Local modifications are identified without
changing upstream grants; see [third-party notices](../THIRD_PARTY_NOTICES.md)
and [licensing](LICENSING.md). Installed vendor is excluded from Git.
