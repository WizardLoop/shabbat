# Dependency evidence

[dependencies.json](dependencies.json) is generated from the exact Composer lock and installed license files by php scripts/dependencies.php. It records 83 runtime and 26 development packages, versions, commits, runtime/dev scope, metadata, original license hashes and unresolved findings. LICENSE_TEXT_PRESENT is text evidence, not an authorship or legal-compatibility certificate.

MadelineProto is constrained by ^8.11.10 and locked at **8.11.10**, commit **49d3d8460257c7bfb1a7e12708e1bfdd59e898c1**, with TL layer 232. BroadcastManager ~3.2.2 resolves to **3.2.2**, commit f2792a383416b66bb68e55f09414563e540538d6. Install from composer.lock; a normal upgrade does not run composer update. [PATCHES](PATCHES.md) describes the two active reviewed modifications.

## Reproduce the evidence

Run in an isolated repository root with the locked dependencies installed, using the documented PHP requirements and Composer 2.10.3:

    composer validate --strict --no-check-all
    composer show --locked --format=json
    composer licenses --format=json
    composer audit --locked --format=json
    composer check-platform-reqs
    php scripts/dependencies.php

The first five commands inspect configuration, inventory, advisories or platform support. The final command rewrites docs/dependencies.json; no bot or Telegram session is started. Composer plugins are disabled. The --no-check-all validation option suppresses constraint-style advice while lock/schema checks and --strict remain enabled.

[composer-show.json](composer-show.json) is the locked inventory; [composer-licenses.json](composer-licenses.json) records the exact installed package metadata using the supported licenses command. Neither substitutes for reading original license files. The project version in Composer metadata may be the checkout branch, while release identity comes from VERSION and the reviewed release commit. Security audit results are separate from license evidence; rerun the audit for the release commit.

## Licensing and source scope

MadelineProto metadata says AGPL-3.0-only while source headers allow version 3 or later; both remain unchanged. The new php-rtc-av metadata says MIT but its distributed LICENSE/PHP headers state BSD-3-Clause. The inventory leaves the declared MIT grant NOT VERIFIED. Checked-in AV FFI declarations are included verbatim; no native FFmpeg binary or generator output is inferred from that metadata, and external derivation provenance is not established.

The corresponding-source collection contains all 83 locked runtime package trees, with two exact public Amp TLS example-key omissions, approved local patch outputs, original notices and 103 preferred-source supplements. It excludes the 26 development packages and generated Composer autoload/bin metadata. PHPStan's PHAR is not called preferred source. External PHP, SQLite, optional FFmpeg and operating-system/container source are outside this collection. See [LICENSING](LICENSING.md), [THIRD_PARTY_DETAILS](THIRD_PARTY_DETAILS.md) and the [source manifest](../resources/release/source-manifest.json).

The omitted Amp HTTP Server tools/tls/localhost.pem and tools/tls/localhost.key.pem are public development examples unused by the bot; exact paths and SHA-256 remain recorded. This packaging omission does not change installed vendor. All other unknown runtime files and credential patterns fail source packaging.

## SQLite and local validation limits

The current Windows test PHP loads SQLite 3.45.2. This local runtime predates the [official WAL-reset fix](https://sqlite.org/wal.html), so local tests do not approve it as the release runtime. Docker builds pinned SQLite 3.51.3, verifies its publisher SHA-256 and checks the PHP-loaded version with self-check --release. The source hash is 81f5be397049b0cae1b167f2225af7646fc0f82e4a9b3c48c9ea3a533e21d77a. Docker is unavailable locally; exact release-platform evidence must come from the actual hosted build/checks. SQLite retains its public-domain terms.

Locked development tools include PHPUnit, PHPStan and PHP_CodeSniffer. Style checks apply to application PHP, scripts and tests; original dependency baselines and deliberately synthetic fixtures retain their existing exclusions. [Publication status](PUBLICATION_STATUS.md) identifies release evidence and live-acceptance limits.
