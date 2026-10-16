# Calendar and permission diagnostics

`/testzmanim` is a private bot-manager command guarded by the current **statistics** capability. Use `/testzmanim GROUP_ID` to inspect a registered group. Without a group selection, it uses the manager's personal location/settings. Diagnostics do not lock or open a group.

The command opens one bounded, readable panel. Next and Previous edit that message. The middle **page / total** button accepts a page number for quick navigation. Search and category/status filters narrow the cached report; clearing them restores the complete view. Prompts accept plain text without visible workflow tokens and can be cancelled. Refresh explicitly collects a new report.

Report sections include calendar decisions, configured timing/location, delivery/recovery status and, for a selected group, permission diagnostics. Provider or Telegram failures are shown as unavailable sections instead of hiding the rest. The manager sees when the snapshot was collected; cached pages are not described as a live reading.

Navigation, search, filters and page jumps use the same expiring snapshot. They do not repeat the provider and Telegram requests on each button click. Snapshots, query length, page size and JSON export size are bounded. The original panel and current actor authorization are checked on every action. Expired or deleted panels do not trigger replacement-message flooding.

**Export JSON** sends one `.json` document with a short caption only after an explicit click. It does not print JSON in chat. Duplicate/stale export actions and rapid repeats are guarded. Exports contain diagnostic data, never credentials, session contents or private configuration files. Group IDs, locations and permission state remain operational information, so exports are sent only to the authorized private manager.

Dev.7 adds migration 014 for expiring diagnostic snapshots and interaction/export state. Run the stopped-writer backup/migration process from [test deployment](SERVER_TEST_DEPLOYMENT.md). The six supported UI languages include all new actions, prompts and errors.

Transport uses the locked MadelineProto implementation of [messages.sendMedia](https://docs.madelineproto.xyz/API_docs/methods/messages.sendMedia.html) with an in-memory JSON upload. Offline tests verify native payload construction and actual callback/message routes; they do not claim a real Telegram upload or flood-limit acceptance test.

Dev.8 adds snapshot-verification status to the selected group's permissions section and JSON export. The indicator checks the original bytes and capture provenance; a legacy snapshot or checksum failure is not displayed as verified. See [the affected-group procedure](DEV8_PERMISSIONS.md).
