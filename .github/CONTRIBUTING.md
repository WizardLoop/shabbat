# Contributing to WizardLoop Shabbat

Work on a separate branch and preserve the existing MadelineProto bot identity and session. Use the issue templates for reports; never include tokens, API credentials, session files, real databases or private group/user lists.

Install the real Composer lock, then run `composer validate --strict --no-check-all`, `composer check-platform-reqs`, `composer lint`, `composer style`, `composer test`, `composer analyse`, `composer check-locales`, `composer check-docs`, `composer dependencies` and `composer self-check`. PSR-12 errors are mandatory for new services/scripts/tests; `composer style-review` also reports advisory line-length warnings. The original legacy handler layout is retained during incremental migration.

Tests use isolated SQLite, injected clocks, fake Telegram and provider fixtures. Live tests are explicitly opt-in in a separate staging bot/group. Do not run production migrations, broadcast to real subscribers, or publish releases as part of tests. Review dependency scripts/plugins and patch provenance before updating the lock. Unknown or partial MadelineProto patch sources must fail verification.

Translations must include every key and identical placeholders in all six catalogs: he, en, ru, fr, es and pt-br. Command/callback IDs remain stable across languages. Every sensitive group write must recheck current admin authority and consume a user/group/revision-bound token.

Follow [CODE_OF_CONDUCT.md](CODE_OF_CONDUCT.md). Explain the problem, resulting behavior, actual validation and remaining limits in a pull request. Preserve SPDX and third-party attribution, including historical MIT grants. Corresponding source for deployed changes must match the actual build.

Existing project contact: [@WizardLoop on Telegram](https://t.me/WizardLoop).

Composer validation uses --strict --no-check-all because MadelineProto is intentionally pinned to the exact reviewed source version. This retains schema and lock validation; only generic version-range recommendations are omitted.
