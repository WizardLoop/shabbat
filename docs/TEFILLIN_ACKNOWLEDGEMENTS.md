# Tefillin acknowledgements and inherited buttons

Group tefillin reminders receive a blue native **הנחתי (0)** button by default, with the supplied tefillin custom emoji icon. When animated icons are unavailable, the fallback shows a basic 🔔 before the same current label/count. The active custom label remains unchanged. The label follows the group's language in Hebrew, English, Russian, French, Spanish or Brazilian Portuguese. Each identified Telegram account can add its acknowledgement and press again to remove it. The public count belongs to that exact delivered reminder message. A private callback response tells the person whether their own mark was added or removed.

Group managers can switch acknowledgements on/off and set a plain one-line label of up to 48 UTF-16 units. The count is appended automatically. A null/default label selects the current group language. Labels and other keyboard buttons are preserved for an already delivered round; settings changes apply to subsequently prepared reminders.

## Button inheritance

Migration `012_tefillin_acknowledgements.sql` adds `message_templates.buttons_mode`:

- `inherit`: use the module's default buttons while preserving customized text, entities and media.
- `custom`: use the saved custom keyboard; do not add the default acknowledgement button.
- `none`: explicitly remove buttons; do not restore defaults implicitly.

Existing empty button arrays migrate to `inherit`. Every existing nonempty keyboard, including native markup objects, migrates to `custom`. Recognized full native/Bot API keyboard wrappers are normalized to row arrays when read or imported, preserving every button action/style/icon and the original stored/source bytes where already present. Malformed recognized wrappers fail explicitly. The template editor must write the mode explicitly for custom/remove/default actions. Text-only templates may inherit default buttons. Contradictory nonempty custom buttons with an inherit/none mode are rejected by the repository.

## Delivery binding and persistence

Preparing a persisted tefillin job creates one random callback token and stores the original keyboard, language, label and settings revision. Retrying the same prepared job uses the same token. A legitimately replanned unsent job with a changed settings revision receives a fresh token; an already delivered round cannot change identity.

A round becomes clickable only after the successful delivery is durably recorded. Registration re-reads the actual job and its successful delivery log and verifies the outgoing message IDs with `SentMessageIds`. It also verifies that the stored prepared payload contains the round token. A job with several verified message IDs gets independent counters for each message. Missing/wrong-peer/incoming/unknown message IDs cannot create a binding.

Votes, callback replay protection and counter revisions commit in one SQLite immediate transaction. The unique key prevents a user from being counted twice for the same round and message. A redelivered Telegram callback is idempotent, while a new click toggles the user's vote. Tokens are additionally bound to the group and message. Bot/self, anonymous, unidentified and inline actors are rejected. Group removal or disabling the module/acknowledgements stops further voting.

The database stores the account IDs required for unique voting and hashed Telegram callback IDs. It does not publish a voter list. Counts survive normal process restart and database restore. The usual database backup/retention policy applies to this operational data.

## Markup edits and recovery

An edit changes only reply markup; the reminder's text, entities, caption and media stay intact. The service updates its own counter button and preserves every other keyboard entry. Per-message edits are coalesced across service instances sharing the same process/database. A newer count is sent only after the older in-flight edit finishes, preventing old completion order from overwriting the newest count.

The normal application single-instance writer lock remains required; do not run two live bot processes against the same database/session. No database transaction is held during a Telegram request.

Confirmed edits persist the rendered revision. A restart can flush pending revisions without losing votes. Flood-wait and other retry deadlines remain in effect even when more users click. Automatic retries are bounded to five attempts with backoff; a later valid user action can resume an exhausted update after its deadline. Intentional Amp cancellation propagates and leaves pending work recoverable. Each call sends at most three successive coalesced updates, then leaves any remaining work for the next ordinary tick.

The runtime should call pending edits only after priority permission work and must honor shutdown before a markup RPC. Do not send a replacement reminder when a count edit fails.

## Tests

The focused tests use actual scheduled jobs and durable delivery logs. They cover six languages, custom labels, custom/removed/inherited keyboard modes, rich message preservation, previous-schema migration, stable token preparation, revision replanning, verified outgoing IDs, independent messages/rounds, unique toggles, callback redelivery and cross-context rejection, invalid actors, disabled groups, transaction rollback, asynchronous concurrent edits, flood waits, bounded errors, cancellation, file-backed restart and separate SQLite connections.

No real Telegram group, message or callback was changed during these tests. Visual custom emoji entitlement and actual group delivery remain staging checks.
