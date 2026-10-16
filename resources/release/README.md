# Pinned corresponding-source inputs

This directory contains release-packaging inputs, not installed dependencies or runtime state.

- [source-manifest.json](source-manifest.json) pins **2,960 runtime dependency files** from the 57 locked Composer packages and **92 preferred-source supplements**.
- The amphp-windows-process-wrapper collection retains 18 original upstream files for the distributed Windows helpers, including their MIT license.
- The madelineproto-build-inputs collection retains 74 pinned generator/build, schema, language and template inputs with their original notices.

The manifest maps each supplementary archive path to its checked-in file below preferred-source/additional-source. The packager copies these bytes to additional-source in the corresponding-source archive and verifies installed runtime package files against the same approved inventory. It rejects a different Composer lock or unknown source bytes. Application files and the final release identity come from the reviewed Git tree, not from the historical artifact identity in this manifest.

These inputs were reverified against the published v3.0.0-dev.9 source asset and its upstream provenance records. Source URLs, revisions, Git blobs and file hashes remain in the manifest. The Git attributes prevent line-ending conversion of the preferred-source files; upstream attributes are stored as .gitattributes.source with the original archive path in the manifest. Do not edit upstream files or license notices to make a hash check pass; review and repin an intentional upstream change.

The two public upstream Amp HTTP Server development TLS private-key examples are omitted by exact approved hash. Their recorded omissions remain in the manifest. Installed development tools, generated Composer autoload/bin metadata, PHP, SQLite and operating-system/base-image source remain outside this runtime source collection.

The Windows helper association is supported by original release/history evidence, but its binaries were not reproducibly rebuilt. The supplied MadelineProto generators were not executed; upstream translation/documentation steps that depend on mutable remote services are not claimed reproducible offline. See [licensing](../../docs/LICENSING.md) and [technical provenance](../../docs/THIRD_PARTY_DETAILS.md) for the exact scope and remaining findings.
