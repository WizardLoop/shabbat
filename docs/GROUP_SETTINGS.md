# Group settings and personal preferences

Use `/settings` in an existing registered group. Membership listed in `data/DBgroups.txt` is inserted additively into SQLite at startup; an existing group does not need `/add` again. The bot sends the authorized panel to the administrator's private chat. For an anonymous group administrator, open the provided `/start g_GROUP` private link and authenticate as your real account; the bot verifies that account against the group. **My groups** lists known database groups only and rechecks current administration, with search and paging instead of scanning every Telegram dialog.

`/preferences` and the **My preferences** start button edit personal preferences. `/language` and `/lang` in private open your language selector; those commands in a group open its language selector after checking administrator permission. Hebrew, English, Russian, French, Spanish and Brazilian Portuguese are available (he/en/ru/fr/es/pt-br). Private panel labels use your language, group messages/previews use the group's language, and changing either language never changes location or calendar methods.

## Panel and required reference settings

Every group panel displays name, location, IANA timezone, current operation mode and next action. It has language, location/timezone, Shabbat/holidays, daily times, Daf Yomi, tefillin, Omer, message design, upcoming actions and status. The reference's four settings are implemented in group scope: **language**, **location**, **candle lighting** and **havdalah**.

- Candle options are the actual 10, 15, 18, 20, 22, 30 and 40 minutes before sunset, a documented city custom, or a validated custom value. Forty minutes appears as an explicit button; no hidden extra minute is added/subtracted.
- Havdalah is stored as method/value: 8.5 degrees, fixed 42/50/72 minutes after sunset, or validated custom minutes. The 8.5 value remains a float. Fixed 72 minutes is stated explicitly; it is not silently converted from a solar angle.
- Closure/opening offsets are separate operational minutes before candle lighting/after havdalah. The preview separately displays candles, group close, havdalah and group open.
- Operation modes are off, messages only, lock only, lock and messages. Shabbat and festival activity are independently configurable. Consecutive prohibited days form one continuous permission interval.
- The times announcement is independently enabled and scheduled by local time on entry day or minutes before closure.

Location preview and confirm workflows are described in [INLINE_GEONAMES.md](INLINE_GEONAMES.md). Choosing a timezone never changes coordinates. Israel/diaspora calendar convention is an independent explicit choice. **Copy my preferences** is a group-targeted preview and confirmation, not automatic synchronization.

## Module controls

Each new notification module starts disabled for imported/existing groups and remains disabled until explicitly enabled. Its screen offers enable/disable, validated settings, private preview and reset of that module only. Tefillin additionally allows an administrator to pause today's notifications. Reset does not reset another group's preferences or erase a permissions restoration obligation.

Private previews never send to the group. Rich message design has a separate **test** action, an explicit named-group confirmation, a clear TEST prefix and five-minute group rate limit. It is the only UI test-send route; live sending is optional and must be authorized by the operator/user outside this implementation task.

Choose **Settings** inside a module, then a labeled field. Preset buttons cover common times, on/off choices, formats, calendar methods and limits. **Other** asks one question for a custom value; answer with the value itself. Weekdays use translated toggle buttons followed by confirmation. Current values show readable local times, named days and translated options. A missing interval or quiet time is off; the city custom label is reserved for candle lighting. Internal tokens and English setting keys are hidden from the ordinary questions. Each change still previews the named group and requires a one-use confirmation.

The original `/configure TOKEN field=value;field=value` syntax remains an optional advanced compatibility interface. Lists use commas and advanced weekday numbers use 0=Sunday through 6=Saturday. Group settings use local `HH:mm`; the selected IANA zone supplies date/DST behavior. A question opened for another group, cancelled/replaced while authorization waits, or based on an older settings revision cannot apply its old save.

| Module | Supported settings |
|---|---|
| Shabbat/holidays | candle minutes, havdalah method/value, close/open offsets, announcement enabled/time/mode/offset, close/open messages, quiet hours, forum target topic |
| Zmanim | local daily time, sending days, short/full format |
| Daf | local daily time, sending days, template |
| Tefillin | days, fixed times, interval/off, window, sunset offsets, maximum/day, minimum spacing, quiet hours, chol hamoed convention, Tisha B'Av policy, safety margin, template, previous-reminder deletion, acknowledgement toggle/custom label |
| Omer | fixed/nightfall mode, local time, nightfall method/value, offset, ashkenaz/sephard text choice, short/full, day completion, early defer/skip, quiet offset after opening |

Custom minutes must be bounded and whole; hours, weekdays, coordinates, IANA zones, template placeholders and enum choices are validated. Notification intervals are at least 5 minutes and at most 360; daily tefillin messages are bounded at 24. `off` disables the tefillin interval and optional quiet hours/forum topic. Unrecognized or repeated form fields are rejected.

Daily times and Daf expose today, tomorrow, selected date and sharing. Share from a private group view uses a bound snapshot of the named group. Default Share buttons on delivered announcements retain the original public inline queries and use the opening user's preferences; they do not carry group coordinates. Daily times also have full/short display. Missing solar values are labeled unavailable rather than synthesized; fixed 42/50/72 minute nightfall is computed directly from verified sunset. Tefillin's private preview shows calculated eligible times and cutoff, even while the module is disabled, without changing its activation state.

## Permissions, stale actions and recovery

The bot rechecks current administrator permission for every sensitive callback and final save. Flow tokens carry settings revision; an older panel, expired token, other user, replay or forwarded button cannot change settings. Concurrent editors do not overwrite a newer revision. State is scoped to user/group/action; no shared mutable `groupid` is used for new settings.

Permission snapshots are saved durably before restriction, preserved while bot-owned restriction remains active, and restored exactly. A missing/corrupt snapshot or conflicting manual permission edit is a visible issue. Routine notification replanning never deletes an unresolved permission-restoration job. The scheduler reconciles desired state on restart, including an opening missed by more than the former six-minute window.

All automatic sending remains subject to current activation, settings revision and valid delivery window. Omer/Daf progression follows the requested calendar date rather than sent-message count; skipping a Shabbat message does not stop the calendar. Times depend on location, selected method and convention; this software does not provide individual halachic rulings.


Permission RPC quota delays (including FLOOD_WAIT) are stored as UTC retry deadlines in group_lock_state.retry_at. Reads, mutations and confirmation reads respect that deadline after restart; quota retries retain the original snapshot and pending restoration. Lease owner/deadline fencing is checked after awaited RPCs and in the final confirmed-state update, so an expired/replaced owner cannot report a successful change. Current calendar/settings intent is checked again immediately before restriction or restoration, outside database transactions. If a new prohibited interval begins while a permission read is waiting, the obsolete restore is deferred with its snapshot retained. These race/recovery behaviors are covered by PermissionRetryTest with isolated SQLite databases and asynchronous fake gateways.

## Example panel (simulation)

```text
Group One
📍 Jerusalem
🌐 Asia/Jerusalem
Mode: Lock and messages
Next action: [calculated local datetime]

🌐 Language
📍 Location and timezone
🕯 Shabbat and holidays
🕒 Daily times
📖 Daf Yomi
🔔 Tefillin reminders
🌾 Counting the Omer
✉️ Messages and design
🗓 Upcoming actions
🛠 Status and issues
```

This is a simulation, not a production chat capture. Automated fixture evidence is linked from [FEATURE_PARITY.md](FEATURE_PARITY.md). Telegram administration checks, inline client behavior, permission RPCs, topics, actual payments and broadcasts still require opt-in staging validation.
## Original button input compatibility

Open **Messages and design**, choose the message, then **Buttons**. The simple link-button path asks for a label and URL separately. Advanced input includes concrete examples for the existing plaintext parser and JSON rows. Type the body directly into the active private question. Candidate content is previewed before explicit save, and cancellation preserves the stored design.

The original parser remains a callable rather than a duplicated implementation. Its advanced syntax supports `Label - https://example.org - style:bg_success - icon:123`, same-row buttons separated by `&&`, and new lines for rows. Original `alert:text`, `popup:text` and `copy:text` actions remain compatible. Native primary/success/danger styles and existing icon IDs are validated and preserved. Invalid URLs/styles, empty parsed keyboards and callback data over 64 bytes are rejected before storage. Existing `/buttons TOKEN` and `/template TOKEN` commands remain compatibility routes.

Text, entities and media edits can inherit default module buttons. An explicitly custom keyboard replaces defaults; explicitly removing buttons selects **None**. Tefillin acknowledgement settings and inherited/custom/none behavior are documented in [TEFILLIN_ACKNOWLEDGEMENTS.md](TEFILLIN_ACKNOWLEDGEMENTS.md). Private preview and TEST acknowledgement controls are inert.

## Operator menus and broadcasts

The private operator interface uses the same six selected languages as personal preferences. Group administrator permission does not grant system operator permission. Operator callbacks require a private chat and a fresh current database capability check; configured recovery owners remain supported, and unrelated callbacks are ignored by the profile handler.

The translated menus retain subscribers and profile details, paginated lists, statistics, rich text/media drafts, custom buttons, pin options, eight audiences (users, channels, groups, all, groups/channels and three explicitly authorized chat variants), private preview, progress, pause/resume/cancel, and deletion/unpin actions. Draft text, entities, media, buttons, pin, audience and list page are scoped to each operator in SQLite. Slash commands are never swallowed by an open draft editor. Existing reviewed draft data remains importable.

The send button opens a recipient-count confirmation. Its expiring one-use token binds the operator, current personal settings revision, full draft digest and selected recipients. A changed draft, another operator, replay, expiry, revoked role or stale revision cannot start that send. Before dispatch, recipients are intersected with current known eligible audiences, permission recovery is prioritized, and shutdown/current operator permission are rechecked. Private preview edits the original operator panel and preserves native button styles and reusable media. See [ADMIN_PANEL.md](ADMIN_PANEL.md) for searchable profiles, explicit audience authorization, scheduled sends, self-destruct and job cancellation.

BroadcastManager's verified public controls keep their original `bm:pause/resume/cancel:ID` callback IDs behind private operator authorization. A separately packaged, version-verified local library patch provides per-instance status/control formatters; all six operator locales have real progress labels. The manager's independent delivery history and scheduled-job files remain a third-party backend under `DATA_DIR`; they require the documented stopped-backup procedure alongside the application SQLite database. No claim of exactly-once BroadcastManager delivery is made.

Offline evidence is in `UIOperatorTest`: all six frames and stable callbacks, separate operators, preserved rich media/buttons/entities, slash-command escape, invalid/empty payloads, atomic SQL rollback, role loss, user mismatch, changed digest, expiry, stale revision and replay. No Telegram broadcast, live payment or live deletion was executed during implementation.

## Group restrictions and message cleanup

The **Group restrictions and cleanup** screen is group-only. A checked restriction means that action is blocked while the bot owns an active closure. The 21 global restriction fields from the locked Telegram layer are selectable. The full 22-field native snapshot is retained for validation and restoration. Telegram explicitly forbids `view_messages` in global default permissions ([official rights documentation](https://core.telegram.org/api/rights)); it is therefore excluded from selection and rejected before any RPC:

| Native field | Member action |
|---|---|
| send_messages | Send messages |
| send_media | Send media |
| send_stickers | Send stickers |
| send_gifs | Send GIFs |
| send_games | Send games |
| send_inline | Send inline results |
| embed_links | Show link previews |
| send_polls | Send polls |
| change_info | Change group information |
| invite_users | Invite users |
| pin_messages | Pin messages |
| manage_topics | Manage topics |
| send_photos | Send photos |
| send_videos | Send videos |
| send_roundvideos | Send video notes |
| send_audios | Send audio |
| send_voices | Send voice messages |
| send_docs | Send documents |
| send_plain | Send plain text |
| edit_rank | Edit the member's own custom tag (rank) |
| send_reactions | Send reactions |

Default selection blocks all 21 actions except viewing messages. **All**, **None** and **Default** create previews requiring final one-use confirmation. All and Default both exclude the unsupported `view_messages` flag. An older stored mask containing that flag must be reset using Default, All or None before it can be applied; it is never silently discarded. A selection never clears an existing manual restriction or replaces the original restoration snapshot. Changes and resets are isolated to the current group. The Shabbat module reset restores the default mask and switches both holy-period cleanup options off.

Default permission readback is compared by meaning within narrow, verified rules: `until_date=0` and `2147483647` both mean permanent restrictions; arbitrary finite expiries stay distinct. Legacy `send_media` and `send_messages` aggregate bits are redundant only when every corresponding granular send restriction is explicitly blocked on both sides. An individual flag change, including `send_reactions` or `edit_rank`, is still a conflict. Selecting an aggregate for a new lock explicitly includes its granular restrictions. Original native snapshots and their checksums are never rewritten by this comparison.

These rules follow the [official TDLib permanent-default-rights reader](https://github.com/tdlib/td/blob/ad2178896533ff92831accdf56ea7edd8393f2ca/td/telegram/DialogParticipant.cpp#L220-L228) and [granular-rights serializer](https://github.com/tdlib/td/blob/ad2178896533ff92831accdf56ea7edd8393f2ca/td/telegram/DialogParticipant.cpp#L306-L314). The pinned MadelineProto 8.7.0 adapter refreshes full peer information with a zero cache lifetime and reads the updated peer again after the response. Both basic-group and supergroup refresh paths have offline tests using the actual pinned SDK method.

After upgrading from dev.5, a retained prepared/error/conflict/restoring state can recover on the next manual operation or scheduled reconciliation only if fresh current rights match the original snapshot or a known bot-owned mask under those narrow rules. No database reset or new snapshot is needed for that case. Missing/corrupt snapshots, unmatched finite expiries, unknown restrictions and meaningful administrator changes remain protected. A genuine mismatch stops automatic changes and shows a bounded list of differing permission names; it does not establish who changed them. Normalization does not authorize the bot to overwrite that mismatch. A legacy aggregate-only snapshot without explicit granular rights stays strict because its equivalence cannot be proved.

Separate default-off toggles control deletion of received members' messages and received other-bot messages during an active Shabbat/festival closure. This is additional moderation, distinct from changing default member rights. It does not scan old history. Unknown/anonymous sender types are not guessed, and announcements sent by this bot are excluded. The runtime verifies current deletion rights and current closure intent before deletion; missing delivery or permissions can prevent cleanup.

Telegram requires the receiving bot to be an administrator, with **Group Privacy Mode** disabled and **Bot-to-Bot Communication Mode** enabled in BotFather to receive general messages from other bots. The panel states this dependency. A bot cannot delete messages Telegram did not deliver to it. These account settings are not changed by the application or offline tests. See the [official Telegram bot-to-bot documentation](https://core.telegram.org/api/bots/bot-to-bot).

The **Bot administrator rights** screen displays a read-only diagnostic of all 17 native administrator rights, current owner/admin status and effective deletion capability. The result is tied to the requested group and followed by fresh administrator authorization. Unsupported adapters, failed reads or malformed results are displayed as unavailable. Rights are granted in Telegram's group administration settings; the bot cannot promote itself. No menu button grants administrator rights.

The Tefillin screen has a separate default-off **Delete previous reminder after sending a new one** switch and a direct **Messages and design** button. Text, entities, media, custom buttons and native primary/success/danger styles use the same secured rich editor. Resetting Tefillin switches its previous-reminder cleanup off without changing the holy-period cleanup switches.

Stable form identifiers are lock_block_flags=send_messages,send_reactions, delete_holy_user_messages=true, delete_holy_bot_messages=true, and delete_previous=true in the Tefillin form. Duplicate/unknown flags and nonboolean values are rejected. Personal preference forms never expose or accept these group-only settings.

## Shabbat/festival announcement timing and content

The Shabbat screen links directly to an independent announcement screen. Presets are **10:00, 11:00, 12:00, 12:30, 13:00, 13:30 and 14:00**, interpreted in the group's IANA timezone. A preset sets local-time scheduling but does not silently enable announcements. Enablement is a separate confirmed action. For another time, choose the time field and **Other**, then answer with a local time such as **12:17**. To schedule relative to closure, select the minutes-before-closure mode and choose its offset, such as **45**. Each question supplies a preview and confirmation without a visible configure token. The optional advanced form remains compatible.

The private preview uses the group's language and location. The pure ShabbatTimesFormatter renders only this group's civil/Hebrew dates, all candle-lighting times in a consecutive holy sequence, final havdalah and, for locking modes, initial close and final reopen times. Each timestamp carries its actual date and UTC offset, including across daylight-saving transitions. The configured candle offset is identified as a setting; later festival candle lighting is shown at its separately calculated time rather than described universally as before sunset.

The formatter takes the exact scheduled event when provided, so an announcement for a later event cannot display a currently active holiday. Its validated provider metadata includes applicable holidays, Torah portion, Shabbat Mevarchim, Hebrew dates, provider notes and Torah/haftarah references. Unrelated dates are excluded. Missing data is explicit; no four-city schedule or fixed-region table is substituted. Provider strings are escaped and outgoing text is bounded to Telegram's UTF-16 limit. Labels are translated in all six locales; provider notes remain attributed provider content.

Hebcal's documented s=on and mvch=on request Torah portions and Shabbat Mevarchim. See the [official Jewish calendar API](https://www.hebcal.com/home/195/jewish-calendar-rest-api). Calendar data remains separate from operational restrictions and the exact configured candle/havdalah calculations.

Announcement design has its own times_alert rich template, separate from daily zmanim. Migration 008 copies an existing daily-times design once to retain the previous announcement appearance; subsequent edits are independent. Both announcement and Tefillin screens open their exact rich editor directly. Private preview never sends to the group; the explicit TEST route retains named-group confirmation and its rate limit.

Offline coverage is in UITest, UITemplateTest and ShabbatTimesFormatterTest: all native flag labels, all six locales, group-only authorization, role loss during diagnostics, one-use/stale confirmations, independent cleanup switches/designs, all seven presets/custom hours, disabled sending after preset selection, holy sequences, exact scheduled-event selection, timezone transitions, missing data and escaped/bounded metadata. No live deletion or Telegram delivery was performed.

## Navigation and presentation

Callback buttons update the existing private bot panel. Opening a fresh command creates a panel; moving between categories, settings, previews and confirmation edits that panel. A callback failure is answered without sending another menu. Information categories cover personal preferences, calendar, groups, notifications, message design and privacy; private operator controls require the configured operator role.

Primary blue identifies main actions and selected choices, neutral buttons provide routine navigation/information, and danger red marks cancellation, disabled states or destructive actions. User-designed primary/success/danger styles are preserved. Group-specific Shabbat previews and announcements share the grouped formatter, including actual timestamp offsets, intermediate candles, relevant calendar metadata and a visible stale-cache warning.

Simple location prompts hide internal tokens; see [inline and location setup](INLINE_GEONAMES.md). The original advanced commands remain compatible.


## Tefillin acknowledgement settings

Acknowledgements are enabled by default for newly delivered tefillin reminders, while the tefillin notification module itself remains opt-in. The module settings expose an acknowledgement on/off toggle and an optional custom one-line label. The default label follows the group's selected language (Hebrew: **הנחתי**); the per-message count is added automatically. A custom label is limited to 48 UTF-16 units and may be reset to the language default.

Users toggle their own mark on an actual delivered reminder. Redelivery of the same callback is idempotent; another deliberate click can remove the mark. State survives restart. The service changes only reply markup and preserves the reminder text, entities, media and other buttons. Verified group/message delivery IDs and the current group's activation/settings guard each counter. Preview/TEST controls do not create a votable round. These are self-reported marks.

A custom keyboard and **None** override inherited acknowledgement buttons. See [TEFILLIN_ACKNOWLEDGEMENTS.md](TEFILLIN_ACKNOWLEDGEMENTS.md) for persistence, transport retries, concurrency and explicit button modes.

## Dev.7 permission recovery

The main group panel and Shabbat/permission categories include **Resolve permission problem**. A private named-group preview compares current/original permissions and offers an explicit choice to adopt the current normal state or restore the preserved original. Each action requires fresh group-admin authority, a five-minute single-use confirmation and unchanged settings/state/remote rights. Active religious or operational closure, manual closure, unavailable calendar verification and shutdown block recovery. See [permission recovery](PERMISSION_RECOVERY.md).

Automatic recovery preserves only verified known results, proven never-dispatched first attempts and additional restrictions on known fields untouched by the bot. Original snapshot bytes remain available. Ambiguous bot-controlled, relaxed, unknown or expiry differences retain administrator review; a new week alone cannot reset them.

Current default Shabbat/holiday output uses local `Y-m-d H:i` without a numeric UTC suffix or source/update footer, as described in [calendar presentation](CALENDAR_PRESENTATION.md). This supersedes older presentation notes above; internal timezone/freshness metadata remains available in diagnostics.
