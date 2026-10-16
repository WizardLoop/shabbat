# UpdateLoop cancellation patch

Reviewed 2026-10-13. Locked MadelineProto **8.7.0** at
[7b99dd0f2d964a492c377912ffce9f5ce61aa9ed](https://github.com/danog/MadelineProto/tree/7b99dd0f2d964a492c377912ffce9f5ce61aa9ed).

Original local implementation: local-reference/newsearch-v2/docker/patch-madeline-update-loop.php,
also identical in local-reference/psy new/release-r21-work/docker/patch-madeline-update-loop.php.
Both SHA256: 1ec2691498e878d28ca8e9cde94b00c803d9ccba789096ffc8471156dfb429e9.
The original script is preserved in original-reference.php. It adds the Amp
CancelledException import and two union catches guarded by getPrevious() instanceof
TimeoutException. It rethrows other cancellation. Historical source was a script;
original-reference.patch is a reproduced unified diff, not a historical diff.

The applied update-loop-timeout-cancellation.patch is a **new reviewed derivative**.
Both difference branches retain those guards, check isRunning()/authorization,
and return the existing 10-second interval on a transient timeout. This lets the
parent loop honor stop() and prevents immediate retry/report loops. A stopped
loop returns STOP; unrelated cancellation while running is rethrown.

The reviewed locked version is 8.7.0; the recorded GitHub release is a prerelease. MTProtoTools/CallHandler delegates to
MTProtoSession/CallHandler, which creates TimeoutCancellation and awaits the send
future using it. Amp emits CancelledException with TimeoutException as previous.
No conversion exists there; upstream UpdateLoop still has two TimeoutException-only
catches. danog/loop 1.1.1 stop() marks running=false, completing after loop() returns.
No official fixed release is currently approved in the manifest.

The manifest pins exact version, commit, baseline/output/patch SHA256. Installation
applies exact unified-diff context, then verifies output SHA256 and syntax.
Unknown files, partial patches and unknown commits fail. No dynamic patch downloads,
no broad source regex replacements, no silent skipping. Already patched succeeds
without a write. A future approved mode=official entry checks its exact sha256 and
applies no patch; no such version is listed today.

Composer invokes apply then verify. For --no-scripts:

    composer install --no-scripts
    php scripts/apply-patches.php
    php scripts/verify-patches.php
    php bin/self-check.php

Never patch an active process/container. Runner only verifies. Build a new image.
Tests exercise first/repeated application, unknown version/file, partial patch,
direct timeout, timeout-derived cancellation, deliberate cancellation, shutdown,
and recovery in both actual loop branches using a fake Telegram transport.
They do not contact Telegram. See [operational details](../../docs/PATCHES.md).

All baseline copyright/license notices are unmodified. Upstream metadata says
AGPL-3.0-only; source headers permit version 3 or later. Preserve and report this
discrepancy. Baseline, original script, diffs and manifest ship in source archives.

Regenerate against the fixed baseline with php patches/madelineproto/generate-artifacts.php.
Regeneration does not approve an upgrade; review source and rerun behavioral tests.
Historical local-reference paths are logical project/file identifiers, not public download URLs or installation requirements.
