> Historical implementation record. Referenced `dist/VALIDATION-*` reports belong to earlier private development deliveries and are not bundled here. Current publication checks and limits are described in [publication status](PUBLICATION_STATUS.md).

# dev.5 - requested test feedback

| Request | Implementation | Verification |
|---|---|---|
| 1. Manual close/open failure | Valid explicit override no longer depends on a provider fetch; bounded per-group diagnostics distinguish confirmed, pending, protected pre-lock and failures | Offline override/provider failure, permission restoration/races, error formatter and batch isolation tests |
| 2. Group commands | Only settings for both all-groups and group-admin scopes, in six languages | Command scope and localized publishing tests; no commands published to Telegram here |
| 3. Single Shabbat overview | No paging controls; actual candle-eve weekday, Hebrew/civil dates, parsha/Mevarchim, continuous exit, nearby observances and next three Shabbat summaries | Recorded complete Hebcal replies, holy-sequence fixtures, bounded UTF-16 rendering |
| 4. Logic and help | Fresh authorization, verified times and role-appropriate expanded help | Full regression suite and six-language help/navigation tests |
| 5. Statistics | Public aggregate overview/reminders; admin audience/activity/groups/delivery tabs | No identity leakage; statistics-only role can navigate tabs without subscriber-directory access |
| 6. Group settings | Four-mode chooser, subcategories, current effective candle setting and contextual return after save/cancel; paired rich-message editor | All six languages, custom input/page return, stale-flow and permissions tests |
| 7. Source | Correct project URL plus license/version/commit card and optional exact build source link | Group/private language tests; existing version/license aliases preserved |
| 8. Broadcast | Guided link buttons, preserved designed keyboard after text edits, resumable draft, explicit detailed final confirmation | URL rejection, actor isolation, expiry, cancellation, stale confirmation, rich entity retention |
| 9. Telegram group support | Basic groups and supergroups supported; bot must have administrative restriction rights | Pinned SDK/native permission regressions and official docs |
| 10. Languages | Hebrew, English, Russian, French, Spanish, Brazilian Portuguese | Identical locale keys/placeholders and localized output tests |
| 11. Restart/load | Shared auxiliary IPC client and owner-only orderly restart lifecycle; permission work remains prioritized | Offline lifecycle/IPC reuse, concurrent/large group batches, shutdown and recovery tests |

## What the supplied log establishes

The 918-line supplied extract has one update-loop start and 44 auxiliary IPC connection/destruction cycles. It contains no permission RPC exception and no explicit restart failure trace. The repeated connection cycle matches the previous minute-by-minute broadcast-manager construction. This was corrected in application code. The exact Telegram error behind the reported manual 0-success/1-failure result cannot be established from that extract alone; the new diagnostic message makes the next result actionable without exposing arbitrary exception payloads.

An explicit manual override is deliberately independent of provider availability until its expiry, while fresh group settings/activation, shutdown state and durable original-rights safeguards remain enforced. Expired overrides return control to verified calendar logic.

## Sources and calendar boundaries

Telegram documents [messages.editChatDefaultBannedRights](https://core.telegram.org/method/messages.editChatDefaultBannedRights) for groups and supergroups and explicitly rejects view_messages as a default restriction. [Bot API setChatPermissions](https://core.telegram.org/bots/api#setchatpermissions) likewise permits group and supergroup default permissions and requires restrict_members.

Holiday categorization uses [Hebcal JSON yomtov](https://www.hebcal.com/home/195/jewish-calendar-rest-api), not holiday-name guesses. The verified calendar horizon remains the selected month minus 8 days through its last day plus 40 days. Unavailable distant holiday timings are not invented. Hanukkah observances do not create festival locking intervals. See [calendar presentation](CALENDAR_PRESENTATION.md).

## Acceptance boundaries

Final evidence and archive identity are in the versioned dist/VALIDATION-3.0.0-dev.5.json report. Local tests do not establish Telegram throughput, client appearance, actual server restart, command installation, or Docker image operation. Docker is unavailable on this development host. The loaded local SQLite version intentionally fails the release-platform gate; the pinned Docker SQLite must pass that gate on the isolated test server.

No production data, active bot, real group, commit, tag, pull request or publication was changed. All earlier dev.4 features and the pinned MadelineProto 8.7.0 patches remain included.
