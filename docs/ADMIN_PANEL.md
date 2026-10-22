# Bot administration

The private `/admin` panel includes statistics, subscriber browsing, broadcasts, managers and permissions, system links and tracking links. Capabilities are checked again when an action runs. Telegram group administrators only receive group management rights and never become bot managers.

## Managers and permissions

Configured `ADMIN` user IDs and usernames resolved by MadelineProto remain immutable recovery administrators. Unresolved names do not grant access. The SDK report-peer user list is kept separate from removable SQLite managers. The orderly application `/restart` handler remains available only to these configured recovery users; dynamic full managers and the system-maintenance capability do not grant SDK restart access. Command menus and the panel show `/restart` only to configured recovery users. View only panels hide the mutating `/setcommands`, `/closegroups` and `/opengroups` commands. Dynamic managers are stored in SQLite by migration `009_admin_roles_links.sql`; they are not added to the server configuration. Existing legacy manager IDs can be imported once as full managers, and removing an imported manager remains effective after restart.

Full managers have all bot administration capabilities. Limited managers receive selected capabilities and may be marked view only. The capabilities are statistics, subscribers, broadcasts, command menu setup, system links, tracking links, adding managers, changing/removing managers, manual group operations and system maintenance. View only managers can inspect permitted information, but cannot send broadcasts, change permissions or links, perform manual lock operations or publish command menus.

From **Managers and permissions**, choose **Add manager**, send a numeric Telegram user ID or forward a message with a visible user ID, select the full/limited role, view only mode and individual permissions, then save. Telegram privacy can hide the original sender of a forwarded message; use the numeric ID in that case. Manager profiles show the complete permission set. Editing or removing a manager requires the corresponding capability. A limited manager cannot grant permissions they lack or manage a manager with wider authority. Nobody can change their own access or edit/remove a configured recovery administrator through the panel.

Editors are actor bound, expire after ten minutes and are replaced when another management editor opens. Save/removal checks the current actor permissions and target revision inside an immediate SQLite transaction. A changed target must be reopened. Manager changes and the audit entry commit together.

## System links

**System links** lists the supported fixed `?start=` routes in pages with native copy buttons. Public links cover the home screen, preferences, language, groups, Shabbat/holidays, daily zmanim, Daf Yomi, Omer, information, support, source, license and version. Private operator routes cover the panel, statistics, subscribers, broadcasts, managers, system links, tracking links and command setup.

Links navigate to a screen; they do not grant a role or immediately send a broadcast, change a group or publish command menus. Every privileged route checks the current bot capability. Group-context and location-selection links retain their original checks.

## Tracking links

**Tracking links** supports creation, pagination, details, copy, refresh, resetting counters and deleting a link. Creation first previews the URL and lets the manager select a public destination menu before explicit save. Supply an unused 1-64 character start parameter using ASCII letters, digits, hyphens or underscores; the first and last character must be alphanumeric. An optional internal label follows a space. Fixed routes and internal selection prefixes are reserved.

Only valid private Telegram `/start parameter` messages count. A URL click that never starts the bot cannot be observed. Counts include total activations, unique users, repeat activations, creation/first/last timestamps and daily activity for the last seven UTC days. Per-link counters distinguish campaign/source URLs. Repeated delivery of the same or older Telegram message ID does not increase counters.

Visitor identifiers in tracking tables are installation-keyed HMAC-SHA256 values; raw visitor IDs and individual click histories are not stored there. The private key remains in the application database and is never displayed in the panel. The ordinary bot settings/subscriber database still contains the IDs it needs to operate. Daily aggregate rows older than 90 days are pruned on the next activation of that link; lifetime total/unique counters remain until reset or deletion. Exact unique tracking grows with campaign visitors. A maximum of 10,000 managed links is enforced. Reset and delete require an explicit actor-bound, expiring confirmation and matching record revision. Deleting a link cascades its hashes and daily aggregates.

Tracking is auxiliary. If tracking storage is unavailable, the runtime should log only the error class and still open the ordinary user home screen. It must not expose raw database errors, tokens or visitor IDs.

## Installation and validation

Stop application writers, back up the database using the documented deployment maintenance workflow, then run migrations before starting this version. Previously applied migrations 001-008 are unchanged. Do not copy a live SQLite file, remove volumes or reset settings.

Tests cover immutable recovery owners, one-time legacy imports, full/limited/view only behavior, delegation restrictions, concurrent revisions, revoked roles, transaction rollback, private context, expiring/replaced/replayed confirmations, reserved links, tracking destinations, redelivered starts, unique/repeat counters, visitor-ID hashing, reset/delete and all six admin languages.

The local reference mapping used `Desktop/newsearch-v2/src/AdminAccessStore.php`, `src/DeepLinkTrackerStore.php` and its manager/system-link/tracker handlers in `bot.php`. The Shabbat implementation uses the existing transactional SQLite database and native MadelineProto markup; unrelated search/payment features are not introduced.


## Scheduled broadcasts

Native scheduled broadcasts are attributed to the creating bot manager by an audit record. Before a scheduler tick runs the native queue, the current creator must still hold broadcast mutation permission. Unattributed, revoked or view only due jobs are cancelled; the native queue is re-read and no queue runner executes when cancellation is unconfirmed. An unattributed future job blocks the bulk runner until it is attributed or cancelled, preventing it from becoming due inside a yielded bulk run. Native file persistence and status transitions remain with the pinned BroadcastManager.

Permission work has priority over scheduled sends and destruction work. Shutdown and reentrant ticks cannot start a second native queue run. Intentional Amp cancellation propagates. The guard rechecks at scheduler admission; an already running native broadcast keeps the library cancellation mechanism. The current guard does not claim to recall messages already sent or provide atomic remote revocation across Telegram requests.


## Subscriber directory and statistics

The directory combines the existing known-peer registry, registered groups and known user preferences. It supports **All**, **Users**, **Groups**, **Channels** and **Bots**, name/username/ID search, clear search, four sorts (activity, joining, name, ID), previous/next navigation and direct page entry. Search, filter, sort, page and the pending question belong to the viewing operator. Changing another operator's list never changes this view.

Selecting an entry opens the known bot profile: identifier/type/name, optional Telegram username and account metadata, first/last observed activity, observed actions/starts, personal language/location/timezone, registered group state and observed group roles. Missing Telegram metadata and unverified administrator status remain unknown. Group-role observations have their own complete pagination; they are never used to authorize a setting change. Advanced details page every stored profile/preference/group-setting field and provide separate pages for long values, keeping output within Telegram limits.

A user record created during group authorization is not sufficient for a new private broadcast. An actual incoming private update records private-chat eligibility. Migration 010 preserves the pre-upgrade private user registry for compatibility; newly created group-only identities remain ineligible until a private interaction. Unknown peer types are not inferred from signed identifiers. Registered groups retain a generic group category until Telegram identifies the exact subtype.

The statistics panel separates users, bots, basic groups, supergroups, generic registered groups, channels and unknown metadata, plus known total, activity in 24 hours/7 days/30 days, observed starts/actions, group registration/activation/protection/closure/recovery, and pending/failed application jobs. Activity begins with the new observations; missing old history is not reconstructed.

## Broadcast editor and audiences

The general broadcast controls inspected in Desktop/newsearch-v2 are implemented against the existing pinned BroadcastManager backend. The subscriber browsing reference was Desktop/psy new/release-r21-work/src/AdminInsightsTrait.php. This is functional adaptation to the Shabbat data model; unrelated search/marketplace functions are not included and visual identity is not claimed to be a byte-for-byte copy.

Each operator's draft preserves text, UTF-16 entities, reusable media, validated buttons/styles, pin choice, operation, audience, self-destruct hours and schedule. The editor offers new send, editing the last recorded broadcast, private preview, recipient-count confirmation, last data/progress, pause/resume/cancel, delete last/all and unpin. Sending, editing, deletion and job cancellation recheck current mutation capability.

The eight audiences are users, groups, channels, all known eligible peers, groups plus channels, authorized channels, authorized groups, and authorized groups plus channels. Authorized chat audiences start denied and require an explicit manager-controlled flag on a known chat. The profile's authorization button opens a one-use confirmation; the flag, audit and flow consumption commit together. Default group-member rights and Telegram administrator powers are independent of this broadcast audience flag.

Automatic deletion supports off, 1/6/12/24/48-hour presets or another whole number from 1 through 48. Scheduling supports immediate send, +10/+30/+60/+360/+1440 minutes and a custom number of minutes or strict local date. Custom dates use the operator's selected IANA timezone in YYYY-MM-DD HH:MM, DD/MM/YYYY HH:MM or DD.MM.YYYY HH:MM form. Impossible or ambiguous local DST times, past times and times beyond one year are rejected. An expired scheduled choice cannot silently become an immediate send.

Scheduling and automatic deletion apply to new sends. Selecting edit-last clears those new-send options. The confirmation snapshot binds operation, payload, settings, exact recipient snapshot and current personal preference revision. Changes, expiry, replay and lost role invalidate it. Drafts and other input modes remain operator-local; opening another menu cancels its competing question while retaining saved draft content.

The jobs view pages native scheduled sends and self-destruct work, with guarded cancellation. The current creator's capability is checked at scheduled admission as described above. Preview and configuration never broadcast. Native history and queue persistence remain in the documented runtime data directory and must be backed up together with the application database.

## dev.5 navigation and runtime

Broadcast drafts show their content, recipient audience, pin, scheduled time, automatic deletion and button count before final confirmation. The link-button editor asks for a label and then a HTTPS/Telegram URL; it appends two per row. Advanced keyboard input remains optional. Editing content preserves the current keyboard; removing it is explicit. Navigation cancels the short input flow while keeping the saved draft.

Public /stats provides only aggregate group/reminder information. Administrative statistics use audience, activity, group and delivery sections, each with refresh and return controls. A statistics-only manager can read those sections without access to subscriber identities.

The auxiliary MadelineProto broadcast client is shared per event handler. Owner-only /restart acknowledges the request and stops new application work before the reviewed SDK restart path. Shutdown preserves the writer lock while managed work and SDK finalizers drain. This lifecycle must be revalidated when upgrading MadelineProto; offline regression does not replace a restart test with the separate staging bot.

## Paged calendar diagnostics

`/testzmanim` is private and requires the current statistics capability. Its compact panel supports next/previous, a clickable page counter for numeric entry, search, category/problem filters and an explicit JSON document export. Permission details are fetched only for the selected registered group; navigation uses the displayed cached snapshot. Exports are bounded, privately authorized and guarded against duplicate callbacks. See [diagnostics](DIAGNOSTICS.md).
