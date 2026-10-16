# Privacy

The application stores Telegram user/group identifiers, group titles, settings, chosen locations, message templates (including entities/media identifiers/buttons), jobs, permission snapshots, delivery/audit records and transient conversation tokens. MadelineProto separately maintains the session needed to access Telegram.

City queries are sent to GeoNames with the operator's account. Coordinates/timezone/date requests are sent to Hebcal. Public inline output identifies the displayed location; private group-flow tokens do not expose group IDs or titles in the inline query. Bot operators control storage and provider accounts.

Snapshots remain until restoration or authorized conflict resolution. Removing a group never silently removes an active restoration obligation. Backups contain private application data and require operator access restrictions. Release packaging excludes runtime data, sessions, .env, logs and private backups.

Retention and deletion require operator policy. Audit/delivery rows are currently retained for recovery and diagnosis; no automatic legal-compliance or fixed-retention claim is made. Operators should document their chosen retention, access and deletion process before public deployment.

Stars accounting stores exact payer ID, currency/amount and private unique charge/intent identifiers. These are not included in public statistics or archive fixtures. A confirmed new donation sends one private thank-you and an operator report; duplicate service updates do not repeat the notification. The individual donation language is retained independently of future preference changes.

BroadcastManager retains its own internal job/delivery records in the configured durable DATA_DIR. Application draft/options and subscriber registrations are SQLite; the application's VACUUM INTO backup does not replace a stopped-writer backup of those third-party records or the separate MadelineProto session. Protect all three state sources and document retention before deployment.

## Optional message cleanup metadata

Opt-in Shabbat/festival cleanup stores only the authenticated group/message/sender IDs, original message timestamp, sender type, settings revision, expiry and retry/lease status. The cleanup queue does not store incoming message text or scrape group history. Tefillin replacement stores the IDs of the last confirmed bot delivery and its prior cleanup jobs; it does not identify a prior message by text. These application tables are included in SQLite backups. Disable the relevant group switches to stop pending cleanup, and follow the existing private backup/log retention policy. Telegram must deliver an update before moderation is possible; other-bot visibility requires the documented BotFather settings.

## dev.4 directory, manager and interaction data

The subscriber directory stores only observed/available Telegram peer metadata (ID, title/name/username, language/type and relevant account flags), first/last observation/activity and whether a private chat was actually observed. It stores limited group-administration observations for the known-group profile; these observations are not authorization. Manager capabilities and their changes are kept for access control/audit. Subscriber browsing and private details require the corresponding current bot-manager permission.

Tracking links record aggregate starts and daily unique/repeat counters. Visitor identities use the repository's per-installation HMAC key; replay markers suppress repeated service delivery. Link labels, destination and optional expiry are operator-controlled. They are included in private SQLite backups, never release archives.

The optional tefillin acknowledgement stores the native group/message IDs and selected Telegram user IDs necessary to enforce one vote per user, plus callback deduplication hashes and edit retry metadata. A second click removes that user's vote; public messages show the aggregate count, not a list of voters. It is a voluntary acknowledgement, not proof of religious observance. Disabling/removing the feature stops future interaction, but does not itself erase existing database/backup rows. No automatic retention/purge policy is claimed; operators must define and perform authorized deletion/retention with referential integrity and recovery needs in mind.

Custom invoice amounts do not add profile/customer data to the existing Stars ledger. Pending template/button edits and donation inputs are user-bound and expiring. Archive generation excludes these live states. No public analytics service is added.
