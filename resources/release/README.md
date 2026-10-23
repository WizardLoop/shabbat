# Pinned corresponding-source inputs

This directory holds release source inputs, not installed dependencies or runtime state.

[source-manifest.json](source-manifest.json) pins **4,095 runtime files from 83 locked Composer packages** and **105 preferred-source files**. The supplements contain 18 upstream Windows helper files, 84 MadelineProto 8.11.10 generator/current-schema/language inputs, the pinned RTC AV README, and the official igbinary 3.2.16 source archive with its original BSD-3-Clause license, preserving original licenses and Git-blob/SHA-256 evidence.

The runtime inventory was compared with each exact locked Composer distribution. Only the fresh-peer and BroadcastManager outputs differ, matching their approved manifests. It excludes two public Amp TLS example private keys by exact hash, installed development tools and generated Composer autoload/bin metadata. Unknown, missing or changed runtime files fail packaging.

Each supplementary archive path maps to its checked-in file below preferred-source/additional-source. Upstream attributes are stored as .gitattributes.source to prevent them overriding immutable checkout bytes, then restored to their original archive name. Do not edit upstream bytes to satisfy a hash check; review and repin intentional changes.

The two approved dependency documentation symlinks map docs/index.md to ../README.md inside danog/async-orm and danog/telegram-entities. Packaging validates exact target bytes whether represented as native symlinks or Windows materialized pointers, then retains Unix mode 120777. Unlisted/escaping links are rejected.

The Windows helper source association is established, but no reproducible binary rebuild is claimed. TL232/current file-reference-map and language inputs are included; full historical schema/doc generation and mutable RPC responses are not claimed reproducible. No generator was run. PHP, SQLite, optional native FFmpeg libraries and system/base-image source are outside this scope.

See [licensing](../../docs/LICENSING.md) and [technical provenance](../../docs/THIRD_PARTY_DETAILS.md) for metadata/license conflicts and remaining provenance findings.
