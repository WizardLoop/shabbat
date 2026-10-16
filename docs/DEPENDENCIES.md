# Dependency evidence

[Machine-readable inventory](dependencies.json) is generated from composer.lock
and installed license files with php scripts/dependencies.php. Each package has
version, commit permalink, direct/indirect runtime/dev scope, SPDX metadata,
license paths/SHA256 and unresolved findings. LICENSE_TEXT_PRESENT is evidence
of a matching distributed text, not a complete legal compatibility certification.

MadelineProto has the exact Composer constraint `8.7.0`, resolved to commit
`7b99dd0f2d964a492c377912ffce9f5ce61aa9ed`, for reviewed patch/source semantics.
The [official release](https://github.com/danog/MadelineProto/releases/tag/8.7.0)
is marked pre-release, not stable. On 2026-10-14 the checked official v8 head
`b1a4c5efee60a87cdbe7bc2b1eb542166ec7e2e3` still lacked the formatted-date wrapper;
no tested official replacement was selected. See the
[formatted-date review](MADELINE_FORMATTED_DATE.md) for the exact source comparison.
BroadcastManager uses ~3.2.2, resolved to 3.2.2, allowing maintenance fixes without
the previous unrestricted * or an unreviewed 3.3 API change. Lock is real Composer
output. Direct use of Amp, Amp File, Amp HTTP Client and Revolt is explicit.
PHP 8.3/8.4/8.5, PDO SQLite and mbstring are required. Composer plugins are disabled.

Reproduce:

    composer validate --strict --no-check-all
    composer show --locked --format=json
    composer licenses --format=json
    composer audit --locked --format=json
    composer check-platform-reqs
    php scripts/dependencies.php

`--no-check-all` omits Composer's advice against intentionally exact constraints;
`--strict` and the normal lock/schema checks remain enabled. It does not disable
lock consistency validation. The 2026-10-14 pin used the supported metadata-only
`composer update --lock --no-install --no-scripts --no-plugins --no-audit --no-interaction --no-progress`.
All 83 package versions and source/dist references stayed unchanged, and a byte
comparison showed only Composer's `content-hash` changed in `composer.lock`.
No installation or dependency lifecycle scripts ran for this metadata refresh.

Security audit is separate from license review. [Patch provenance](PATCHES.md)
and [third-party notices](../THIRD_PARTY_NOTICES.md) describe preserved notices.
MadelineProto package metadata says AGPL-3.0-only while source grants version 3
or later: retained and reported. NOT VERIFIED entries require release review.

Hebcal/GeoNames data, texts and graphics have independent terms in third-party
notices; application AGPL does not relicense them.
Composer 2.8.8 does not implement licenses --locked. The attempted invocation
failed; composer-licenses.json records the supported licenses --format=json
command against the installation made from composer.lock. composer-show.json
and composer-audit.json record the locked inventory and advisory check.

## SQLite runtime evidence

The Windows test host loads SQLite 3.45.2 through PHP PDO, not a separate CLI.
Its version is older than the [official WAL-reset fix](https://sqlite.org/wal.html).
This blocks release-platform approval even though isolated single-writer tests pass.
The fix is present in 3.51.3+ (specific backports 3.44.6 and 3.50.7 are recognized).
Docker builds 3.51.3 from a fixed publisher archive and verifies SHA256
81f5be397049b0cae1b167f2225af7646fc0f82e4a9b3c48c9ea3a533e21d77a before
compilation, then tests the PHP-loaded version with self-check --release.
The image build is not locally tested because Docker is unavailable on this host.
Runtime source is public-domain SQLite; keep its source/terms separate from AGPL.

## Quality tooling and scope

The locked development tools include PHPUnit, PHPStan, and PHP_CodeSniffer
4.0.4 (BSD-3-Clause). The inventory includes their transitive dependencies and
distributed license texts. `composer style` enforces PSR-12 errors in new `src`,
`bin`, `scripts`, and `tests` PHP files. The reviewed immutable patch baseline and
synthetic fixtures are excluded. `composer style-review` additionally reports
advisory PSR-12 line-length warnings. No error baseline is applied.
The preserved legacy `bot.php` is syntax checked, while integration fixtures,
authorization tests, and manual review cover its changed boundaries.

The publication corresponding-source archive contains the application and the
57 locked runtime Composer dependency source trees, with their distributed
licenses and local patch bases/diffs. The 26 development-tool packages are not
included; in particular, a PHPStan PHAR is not represented as preferred source.
PHP, SQLite, and operating-system base-image source are outside this collection;
the Dockerfile retains the pinned upstream build inputs. See
[publication scope](PUBLICATION_STATUS.md) and the archive manifest.

Amp HTTP Server's public development TLS example keys `tools/tls/localhost.pem`
and `tools/tls/localhost.key.pem` are omitted by exact reviewed path and SHA-256.
They are unused by this bot. Their provenance and hashes are retained in the
archive manifest. Other private-key material or Telegram token patterns fail
the archive scan. This omission never changes the installed vendor directory.
