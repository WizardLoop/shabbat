# User experience in dev.7

## Opening and language

The Hebrew welcome text is:

> **✡️ שומר שבת**  
> זמני שבת וחג, זמני היום, דף יומי וספירת העומר, לפי המיקום שלך.
>
> בחרו מה להציג, או פתחו את ההעדפות שלכם לניהול השפה והמיקום.
>
> מנהלי קבוצות יכולים לנהל כל קבוצה דרך ״הקבוצות שלי״.

The same menu is available in Hebrew, English, Russian, French, Spanish and Brazilian Portuguese. **My preferences** changes the private user's language, location and calendar methods. A group's language and location are configured independently. **My groups** lists registered groups for which the current user passes a fresh administrator check.

Buttons navigate by editing the originating private bot panel. A fresh command can open a panel; callback navigation, questions and previews continue in that panel. A deleted or unavailable panel produces a callback notice without a replacement-message loop. Opening another task cancels competing text prompts, so a later ordinary message cannot accidentally become a former broadcast draft, subscriber search or donation amount.

## Simple settings

Open a category, choose a setting, then select a preset or **Other**. A custom value uses one plain question. Time inputs use the group's local clock; day choices use translated weekday names. Current values use readable on/off, city custom, named methods and translated permission labels.

The Shabbat mode button opens four choices. Timings, message design and announcements have their own submenus. Saving or cancelling returns to the originating category or editor page. Settings still require a named-context preview and confirmation. Internal flow tokens stay out of the ordinary questions. Existing advanced commands remain available for compatibility.

The tefillin category exposes days, fixed times, optional intervals, the permitted window, sunset offsets, maximum per day, spacing, chol hamoed, Tisha B'Av policy, sunset safety, quiet hours, rich message design, previous-reminder deletion and acknowledgement settings. An absent interval or quiet time is displayed as off. The documented city custom applies to candle lighting.

See [group settings](GROUP_SETTINGS.md) for the complete settings and safety behavior.

## Locations and sharing

City searches accept ordinary city names in the inline bot. The private location menu also supports a place identifier, coordinates, a timezone search and reply-bound Telegram GPS sharing. A location or timezone preview names the personal or group context before confirmation. A timezone change preserves coordinates.

The **Share** button opened from an administrator's private group-calendar view creates a temporary read-only snapshot through inline mode. That snapshot uses the named group's location, language, calendar convention and selected date. Its flow is bound to the initiating user and checked group revision, and the shared text names the group.

The default Share buttons attached to delivered group announcements retain the original public inline queries (shabat/zmanim/daf). Those public queries use the person who opens them and that person's preferences; they do not carry the group's coordinates. Use the private group view's Share action when the shared result must preserve group settings. Neither path saves settings or sends an administrative instruction.

## Calendar screens

Shabbat and holiday notices group relevant calendar information around the selected local sequence: civil and Hebrew dates, Torah portion, available Shabbat Mevarchim and holiday metadata, every candle-lighting event, final havdalah and applicable group close/reopen times. Consecutive festival/Shabbat dates preserve one continuous closure interval. Each timestamp retains its actual local date and UTC offset.

Shabbat and holiday times use a single overview with no previous/next controls, the nearest Shabbat-containing period and verified current/upcoming observances starting at the current local date. A separate weekday Yom Tov before Shabbat remains visible with its verified entrance and final exit. Later Shabbat summaries, scripture-reference rows and source/update footers are omitted; local dates use Y-m-d H:i without numeric UTC offsets. The selected candle/exit policy remains visible. Only Hebcal yomtov events receive festival entrance/exit times. Daily times and Daf Yomi have date navigation and sharing. Daily times have short/full views. Omer is an inactive successful result outside the counting season. Provider failures remain explicit; missing operational times are never guessed.

## Message design and buttons

The rich editor preserves text, Telegram entities, reusable media and validated native button styles. Guided questions keep the original private panel. Candidate content is previewed before explicit save, and cancelling leaves the stored design unchanged. The button editor offers a simple label/URL path plus examples for advanced original-parser and JSON input.

A named-group **TEST** action has a separate confirmation and rate limit. Its preview/test acknowledgement control is inert. A delivered tefillin reminder's counter is bound to verified delivery IDs and cannot be activated by a preview.

Text-only designs can inherit module buttons. **Custom** uses the saved keyboard. **None** explicitly removes buttons. See [tefillin acknowledgements](TEFILLIN_ACKNOWLEDGEMENTS.md) for persistence, concurrency and button inheritance.

## Animated emoji and colors

The supplied twelve custom emoji identifiers are registered centrally by menu purpose: brand, settings, support, information, source, groups, add-to-group, Shabbat/holidays, tefillin, daily times, Daf and Omer. Text uses native custom-emoji entities and supported buttons use native style icons. Main actions use primary blue, routine navigation is neutral, and red marks cancellation, disabled states or destructive actions. Valid user-designed styles remain supported.

Telegram entitlement and client support determine actual animation. The transport resolves official basic-emoji alternatives and retries once after a definite custom-emoji/Premium rejection, preserving request identities and message content. The operator can set CUSTOM_EMOJI_ENABLED=0 for basic emoji from startup. Calendar calculations and permissions do not depend on animation.

## Support and administration

Support retains the six Stars presets and adds **Other** for whole amounts from 1 through 10,000. The amount prompt edits the current panel. Invoice intents bind payer, exact amount and XTR currency; authenticated payment updates record each charge once. Navigating away while invoice export is waiting prevents an old result from repainting the new screen.

The administration panel adds delegated capabilities, a searchable subscriber directory, statistics, advanced broadcast settings, system links and tracking links. Group-administrator status alone does not grant access. See [administration](ADMIN_PANEL.md).

Tefillin acknowledgement counts describe users' own per-message marks. They do not verify a physical action. Default controls appear only after verified reminder delivery, follow the group's language, and respect explicit custom/removed button modes.

## Testing and deployment

Automated checks use SQLite, actual native message/query types, recorded provider replies and fake transports, including real Amp suspension points for races. They cover role revocation, old/replaced/cancelled flows, wrong contexts, UTF-16 formatting, payment replay, migration rollback and competing-prompt cleanup.

Actual Telegram client appearance, animation entitlement, live payments, real broadcasts and the Docker image still require isolated server acceptance. Use the [test deployment guide](SERVER_TEST_DEPLOYMENT.md); dev.6 retains all twelve dev.5 migrations. Keep stopped-writer backup/schema verification; installations older than dev.4 apply any missing migrations 009-012.


## Recovery and manager diagnostics

The group panel includes a reviewed [permission recovery flow](PERMISSION_RECOVERY.md). The private manager command has an editable [diagnostics panel](DIAGNOSTICS.md), with next/previous, a clickable page counter, search, filters and JSON document export. These controls are available in all six languages.
