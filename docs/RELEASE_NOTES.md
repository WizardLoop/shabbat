# Shabbat 3.2.0

- My Groups lists manageable groups, five per page, and only offers a next page when more results exist.
- Permission recovery can refresh current evidence before confirmation. The bot checks the administrator, group rights and allowed time window before changes.
- Authorized group /settings opens the private panel, confirms delivery in the group, and attempts to remove the command.
- The Telegram group command list advertises /settings to administrators and owners. An operator can refresh the old group-wide menus for every supported language with /setcommands all all in private.
- Separate Shabbat and Yom Tov opening and closing templates. Migration 017 copies existing customizations. Verified transitions and their configured notices are recorded together.
- MadelineProto 8.23.1 (Telegram layer 234), updated locked dependencies, and refreshed PHP, Composer and GitHub Actions build pins.

Existing SQLite deployments apply migration 017 with the service stopped and retain both named volumes. [Existing deployment update](UPDATE_3_2.md) · [Backup and restore](BACKUP_RESTORE.md) · [Earlier releases](../CHANGELOG.md). See the release checksums and manifest for corresponding source and build identity.
