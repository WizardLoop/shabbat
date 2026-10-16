# Public calendar and help screens

The Shabbat and holiday view is a single current/upcoming overview. It does not expose previous/next buttons or page numbers. Old calendar callbacks remain compatible and refresh the current overview. The private panel is edited in place; share and back actions remain available.

The primary period is the current or nearest upcoming verified period containing Shabbat. It shows its civil and Hebrew dates, Torah portion, Mevarchim and verified observance names, then every candle-lighting time and the final havdalah. Torah/Haftara passages and descriptive provider memos are omitted from the concise card. Candle labels use the actual local candle-lighting date, rather than the following holy day's date (for example, יום שישי). Displayed timestamps use `Y-m-d H:i` in the selected IANA timezone, including daylight-saving transitions, without numeric UTC offset suffixes. The IANA timezone remains visible in the header. The existing curated bilingual Jerusalem label is used only when its identity, coordinates, timezone and standard name match; custom names and unrelated places remain unchanged.

Verified calendar and solar coverage extends from eight days before the current month through forty days after its end (76–79 days in total). The display does not invent or fetch months-ahead festival times outside this horizon. The overview also lists up to five current/upcoming calendar observances from the current local date within verified coverage. A separate weekday Yom Tov before the nearest Shabbat remains in that list with its verified entrance and final exit. A currently active festival is retained, including after sunset on its eve. A continuous period already shown in the main Shabbat section is not duplicated. It does not repeat the next three Shabbat periods. A Shabbat that is part of a continuous upcoming Yom Tov period remains included in that period's final exit. Festival start/end times require Hebcal's strict `yomtov: true` classification and a matching verified calendar period. Hanukkah and other non-Yom-Tov dates do not acquire festival closing times merely because they are listed as holidays. Consecutive days retain all candle lightings and only their final exit; missing verified festival times are explicitly unavailable. An optional future period ending at the coverage boundary is not shown as a final exit, because the following unverified day might continue the festival. Optional metadata is bounded to protect the primary period and Telegram's message limit.

[Hebcal's calendar API](https://www.hebcal.com/home/195/jewish-calendar-rest-api) documents holiday objects with the structured `yomtov` field. The formatter consumes the same CalendarEngine periods used by scheduling; it does not independently calculate or guess religious times.

Commands, private menu refreshes, inline results, group views, scheduled default `times_alert` messages and group announcement/template previews use the same overview. A template that changes only buttons retains the generated body; intentionally customized text and entities remain respected. Configured group close/open offsets are displayed only when they differ from the candle-lighting/final-havdalah times.

The displayed candle and exit methods reflect the saved settings. The requested fixed-50-minute example does not change the default 8.5-degree policy. Captured Jerusalem data for 2026-10-17 gives 18:41 with 8.5 degrees and 18:55 with fixed 50 minutes; the regression suite verifies both choices in every supported language and every default output path.

No source/update footer is displayed on the Shabbat card. Internal coverage, fetched-at metadata, provider validation and stale-data warnings remain intact. Provider attribution and a Hebcal link remain in the calendar information screen in all six languages and in licensing documentation. Existing animated-emoji presentation and ordinary-emoji fallback apply to the concise card.

## Group permissions

Telegram permits bots to update default permissions in both basic groups and supergroups. The bot needs administrator status and permission to restrict members. Default restrictions apply to ordinary members; administrator messages are not prevented by those defaults.

- [MTProto messages.editChatDefaultBannedRights](https://core.telegram.org/method/messages.editChatDefaultBannedRights) documents group, supergroup and channel peers and permits bots.
- [Bot API setChatPermissions](https://core.telegram.org/bots/api#setchatpermissions) explicitly supports groups and supergroups and requires can_restrict_members.

Actual availability still depends on the peer type, granted rights and the server response. The information panel explains permission requirements, restoring saved permissions, continuous holy-day periods and separately controlled message cleanup.

## Commands and source

Publishing group and group-administrator command menus with `/setcommands` replaces those scopes with only `/settings`, in all six supported languages and the default-language fallback. Existing private commands remain available. The repository's old and current publishers use global group scopes, so replacing them removes the previously published group menu entries. Private-only operator scopes are not removed. Any independently installed external peer-specific menus are outside this repository's known publication history.

The public menu includes one `/source` command. The source card includes the official [WizardLoop/shabbat repository](https://github.com/WizardLoop/shabbat), running version, commit, application license and the operator's matching build source URL. The official repository is explicitly distinguished from a locally modified build's corresponding source. `/version` and `/license` remain compatible aliases of that complete card.

## Offline verification

The regression suite uses captured official Hebcal responses for Jerusalem in October, the Shavuot-to-Shabbat sequence and a separate weekday Pesach before Shabbat. Additional tests cover Yom Tov classification, final-exit preservation, missing verified festival data, bounded metadata, legacy page offsets, six-language help, same-message edits, exact group command scopes and source aliases. These tests do not send Telegram messages or replace a live bot.
