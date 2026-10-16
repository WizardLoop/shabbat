# Security Policy

## Reporting a Vulnerability

If you find a security issue, **do not open an issue on GitHub**.  
Please report privately to [@WizardLoop on Telegram](https://t.me/WizardLoop) or via email: contact@wizardloop.com

---

We will address all reports promptly and responsibly.

## Safe reproduction information

Include the affected build version/commit, PHP-loaded SQLite version and a minimal synthetic reproduction. Redact bot tokens, API credentials, GeoNames account details, session files, private user/group identifiers, payment payloads/charges and real database/backups. Use a staging bot or the offline fixture tests; do not send test messages to real groups.

Dependency advisory checks and license/source review are separate. Report unknown patch hashes or partial vendor changes; do not bypass verification or edit a running container. The 3.0.0-dev snapshot is not a stable release. See [acceptance status](../docs/IMPLEMENTATION_STATUS.md) for checks not yet performed.
