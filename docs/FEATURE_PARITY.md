# Feature parity and source audit

The interface reference is [WizardLoop/JewishPulse](https://github.com/WizardLoop/JewishPulse/tree/d7b06eb1acb206543dcff35a7c14ebce4760b4e5), reviewed on 2026-10-13. Shabbat retains its package name, bot identity, MTProto session, Stars donations, operator tools and BroadcastManager integration. Reference ideas were reimplemented; the unsafe reference location parser, implicit extra candle minute, truncated 8.5 angle and mutable global timezone were not imported.

`UI` below means `src/UI/BotController.php`. Its panel labels use the administrator's personal language; calendar content uses the selected scope's language. Every settings write requires an unexpired user-bound flow and revision, current Telegram administrator permission for a group, and a one-use server-side confirmation. A public inline query never grants group authority.

| Reference source and function | Existing behavior | Implemented equivalent | Scope | Automated evidence |
|---|---|---|---|---|
| `src/Handlers/Handlers.php::starthandle` | Private welcome and navigation | `UI::start` / `/start` | Personal | `UITest::testMenusAreBilingualWithStableCallbacksAndNativeColors` |
| `Handlers.php::BackStart` | Return to welcome | `sb:start` → `UI::start` | Personal | Menu callback inventory in `UITest` |
| `Handlers.php::SettingsMenu` | Four personal settings | `UI::panel` (personal and group) | Personal / group admin | Personal/group isolation and language tests |
| `Handlers.php::SettingsLanguage` | Language selector | `UI::screen(language)`, `/language`, `/lang` | Personal / group admin | `testLanguageCommandAndGroupLanguageAreIndependent` |
| `src/JewishPulse.php::handleSetLanguage` | Save personal language file | `sb:s:token:l:he/en/ru/fr/es/pt-br` + one-use confirmation | Personal / group admin | `testLanguageCommandAndGroupLanguageAreIndependent` |
| `src/Locales/Lang.php::getUserLang`, `setUserLang` | Personal `lang.txt` | `SettingsRepository::getUser/updateUser`; group language separate | Personal / group | Isolation tests |
| `Lang.php::loadTranslations` | HE fallback | `src/UI/Lang::text/validate` | Display | `testTranslationKeysPlaceholdersEscapingAndFallback` |
| `Lang.php::getAvailableLanguages`, `getLanguageButtons` | Files scanned for two choices | Explicit he/en/ru/fr/es/pt-br registry; six real catalogs and stable identifiers | Display | Menu language test |
| `Handlers.php::SettingsLocation` | `keyboardButtonSwitchInline`, same peer | `UI::locationScreen`, `GeoNames::search` with token | Personal / group admin | `testTwoGroupLocationFlowsSelectedInReverseOrder` |
| `JewishPulse.php::onUpdateBotInlineQuery` city branch | Search every non-Shabbat string; IDs `geo_0` | Explicit `loc`, UTF-8 normalization, stable query-bound IDs, paging | Public read / authorized selection | `LocationTest`, two-flow UI test |
| `JewishPulse.php::handleSaveLocationFromMessage` | Parse forgeable localized text and save | Replaced with `location_choice` → private preview → one-use `save` | Personal / group admin | Forged text, wrong user, replay, expiry and admin-loss tests |
| `Storage/UserLocation.php::getGeonameId/setGeonameId` | Per-user JSON with implicit Jerusalem | SQLite scope settings + complete coordinates and IANA zone | Personal / group | Personal/group isolation, timezone tests |
| `UserLocation.php::searchGeoNames` | Unencrypted HTTP, errors returned as no results | `src/Location/GeoNames`, verified HTTPS, typed errors, selected-place validation | Provider | All `LocationTest` cases |
| `Handlers.php::SettingsCandles` | Display 10/15/18/20/22/30/40, save one more | Seven exact values including 40; city custom and validated form | Personal / group admin | `testAllSevenCandleOptionsAreStoredExactly` |
| `JewishPulse.php::handleSetCandles`; `UserLocation::getCandleSetting/setCandleSetting` | Personal JSON with default 41 | Typed `candle_minutes`; no hidden adjustment | Personal / group | Exact seven-value test |
| `Handlers.php::SettingsHavdalah` | 8.5°, 42, 72 | Separate method/value; 8.5°, 42, 50, 72 and custom minutes | Personal / group admin | `testDegreesRemainFloatAndFiftyMinutesAreAvailable` |
| `JewishPulse.php::handleSetHavdalah`; `UserLocation::getHavdalahSetting/setHavdalahSetting` | Float saved, later cast to integer | Float preserved end to end | Personal / group | Exact methods test; `CalendarTest` |
| `Handlers.php::ShabbatTimes` | Private upcoming times | `UI::renderView(shabbat)` from one common calendar | Personal / group preview | Rendering, calendar tests |
| `Handlers.php::Shabbat_Times`; `JewishPulse.php::shabatCommand` | `/shabat` and share button | `/shabat`, `/shabbat`; group settings on group display | Personal / group | Calendar/UI tests; real Telegram routing requires staging |
| `Handlers/ShabatTimes.php::parseShabatData` | `/shabbat` current-week response formatting | `CalendarService::snapshot` explicit coverage/current/next event | Calendar | `CalendarTest` before/during/after, Sunday, adjacent holidays |
| `ShabatTimes.php::getNextShabbatDates` | Date calculation changes global timezone | UTC instants, explicit per-group IANA zones | Calendar | DST and concurrent-zone tests |
| `JewishPulse.php::onUpdateBotInlineQuery` times branch | Share personal Shabbat times | Reserved aliases plus zmanim/daf/omer; authorized group share snapshot | Read-only | `testGroupShareUsesAuthorizedSnapshotInsteadOfPersonalLocation` |
| `Handlers.php::DailyPage`; `ShabatTimes.php::getDailyPage` | Today's Daf from Hebcal; mostly hardcoded Hebrew | `/daf` with explicit today/tomorrow/date and trusted provider link | Personal / group | Fixture calendar checks; no real Telegram test |
| `Handlers.php::AllCommands` | Help categories | `UI` help menu and actual command list | All users | Help translations/key validation |
| `Handlers.php::BasicRules` | `/`, `!`, `.` prefixes | `UI::handleMessage` supports these prefixes | All users | Parser exercised by UI tests |
| `Handlers.php::SettingsInfo` | Explain four personal options | Actual personal/group screens and `GROUP_SETTINGS.md` | All users / group admin | Screens and form tests |
| `Handlers.php::CommandsAllUsers` | Commands and inline help | Localized implemented help commands | All users | Locale validation |
| `Handlers.php::CloseMsgCommand` | Delete private menu message | Existing Shabbat close handler retained; back/cancel in new screens | Personal | Live deletion not tested |
| `Handlers.php::StatsGroups`; `JewishPulse.php::StatsGroups` | Dialog scan/count | Known-group SQLite stats plus retained operator statistics | Public / system admin | Storage tests; live dialogs not tested |
| `Handlers/AdminPanel.php::handle`, `admin_back` | Operator admin panel | Localized OperatorController + guarded legacy routing | System admin only | UIOperatorTest six-language frames and fresh role checks; no live operation |
| `AdminPanel.php::statUsers` | Dialog statistics | SQLite group counts + known-peer type statistics | System admin only | Six-language statistics frames tested; live peer metadata not tested |
| `AdminPanel.php::admin_broadcast`, `broadcast_back`, `handle_broadcast`, `back_broadcast` | Compose text/media broadcast | OperatorDraft rich scoped SQLite editor + retained BroadcastManager sending | System admin only | Atomic draft/digest/token/media/style tests; no live broadcast |
| `AdminPanel.php::type_users`, `typemode1/2/3/4` | Users/channels/groups/all filters | Scoped users/channels/groups/all audience and pin controls | System admin only | Independent operator options and stable callbacks tested; no live broadcast |
| `JewishPulse.php::handleAdminButtons`, `send_broadcast`, `handleBroadcastProgress` | Dispatch, broadcast and progress | Fresh-role private callbacks + one-use send + verified status formatter patch | System admin only | UIOperatorTest and library patch regression tests; no live broadcast |
| `Payments/Payments.php::sendDonationOptions`, `handlePreCheckout`, `sendMessageToAdmins`; `JewishPulse.php::Payments`, `onupdateBotPrecheckoutQuery` | Stars support and payment reporting | Validated Stars intents/payment handlers, localized offers and duplicate-safe private operator notices | Personal / system admin report | DonationsTest offline; no real payment was submitted |

## New capability evidence

| New capability | Implementation | Evidence and practical limit |
|---|---|---|
| Group language, location, timezone, Israel/diaspora | UI scopes, SettingsRepository and FlowTokenRepository | All six locales, different groups, reversed selection, personal separation, stale/replayed flow tests |
| Native colored buttons | MTProto `keyboardButtonStyle` in `UI::keyboard` | Installed `TL_telegram_v225.tl` constructors validated; primary/success/danger tested; rendering depends on Telegram client |
| Full/short zmanim, today/tomorrow/date | `UI::view/renderView`, `CalendarService::zmanim` | Explicit date and missing values tested; polar calculations refuse guesses |
| Tefillin times preview | `UI::renderView(tefillin)`, `ReminderPlanner` | Calculated jobs without enabling/sending; cutoff, dedup and holiday fixtures in calendar tests |
| Omer, all 49 deterministic counts | `CalendarService::omer`, `Content/OmerText`, ReminderPlanner | Bundled 49-count and calendar fixtures; actual prayer recitation remains an individual responsibility |
| Module enable/edit/reset/pause | Scoped UI forms and one-use confirms | Form validation, exact settings, replay/admin loss tests |
| Rich group message editing and test send | `Content/TemplateEditor`, `TemplateRepository`, `TemplateRenderer` | Separate explicit test confirmation and five-minute rate limit; no production Telegram send |
| UTC scheduling, recovery, snapshots, outbox | `Scheduling/`, `Runtime/Application` | Fake-gateway storage/scheduler tests; no claim of absolute exactly-once delivery |
| Legacy membership carried forward | `LegacyImporter::importGroupListAutomatically` | Additive startup group registration; `/settings` opens a known group's panel without re-adding |

## Six-language and operator verification

The canonical locale registry is he, en, ru, fr, es and pt-br. All six catalogs have the same keys, nonempty translations and placeholder multisets. Telegram command language codes use pt for Brazilian Portuguese while application storage retains pt-br. UI tests exercise all six private menus and group saves without changing coordinates, stable callback IDs after language changes, and group content language independent from the administrator panel language.

OperatorController and OperatorDraft replace active Hebrew-only operator frames while preserving the original guarded operation handlers. Subscribers use known database audiences, including separately imported broadcast peers; an explicitly removed Shabbat group is excluded. The original pure custom-button parser is bound through Application, preserving native colors/icons/copy/alerts. See GROUP_SETTINGS.md for confirmed broadcasts, per-operator state and the separate BroadcastManager delivery backend.

## Source and infrastructure findings

JewishPulse's inspected tree declares AGPL-3.0-only. Its README claims GitHub Actions CI, but no workflow files exist in that commit. Its `.github/ISSUE_TEMPLATE ` directory has a trailing space and cannot be checked out normally on Windows. Its Dockerfile uses an unpinned MadelineProto image and copies the complete checkout; these practices were not imported. Its graphic assets and external thumbnail were not reused because this upgrade needs no new graphic asset and their independent provenance was not established.

The original Shabbat runtime was a single 7,195-line `bot.php`, MIT metadata, `danog/madelineproto:^8.6`, unrestricted BroadcastManager `*`, and no lock or automated tests. New documentation does not assert a live feature test where only source was reviewed. Retained donation/operator/broadcast features require an opt-in staging account; no actual Telegram group, active session or production data was changed.

## Original Shabbat function inventory

Each original function is listed below, including helpers. This is an inventory of source and its replacement/retained responsibility, not a statement that Telegram-side behavior was tested.

| Original source function (`bot.php`) | Equivalent responsibility | Scope | Verification |
|---|---|---|---|
| `getTestShabbatLockTimes` | CalendarService + Runtime/Application diagnostics | calendar / operator | CalendarTest; live diagnostics not run |
| `getAlertTestTime` | CalendarService + Runtime/Application diagnostics | calendar / operator | CalendarTest; live diagnostics not run |
| `getZmanimForCities` | CalendarService + Runtime/Application diagnostics | calendar / operator | CalendarTest; live diagnostics not run |
| `getShabbatLockTimes` | CalendarService + Runtime/Application diagnostics | calendar / operator | CalendarTest; live diagnostics not run |
| `getShabbatCheckLock` | CalendarService + Runtime/Application diagnostics | calendar / operator | CalendarTest; live diagnostics not run |
| `isShabbatOrHolidayNow` | CalendarService + Runtime/Application diagnostics | calendar / operator | CalendarTest; live diagnostics not run |
| `getReportPeers` | Retained bot/operator integration | system admin / runtime | Live Telegram not run |
| `onStart` | Retained bot/operator integration | system admin / runtime | Live Telegram not run |
| `ChannelsLeave` | Retained bot/operator integration | system admin / runtime | Live Telegram not run |
| `StartCommand` | UI::start/handleMessage/handleCallback/handleInline/renderView | personal / group read | UITest + CalendarTest |
| `BackCommand` | UI::start/handleMessage/handleCallback/handleInline/renderView | personal / group read | UITest + CalendarTest |
| `ShabbatTimes` | UI::start/handleMessage/handleCallback/handleInline/renderView | personal / group read | UITest + CalendarTest |
| `AllCommands` | UI::start/handleMessage/handleCallback/handleInline/renderView | personal / group read | UITest + CalendarTest |
| `Rules` | UI::start/handleMessage/handleCallback/handleInline/renderView | personal / group read | UITest + CalendarTest |
| `CommandForAdmins` | UI::start/handleMessage/handleCallback/handleInline/renderView | personal / group read | UITest + CalendarTest |
| `CommandForAll` | UI::start/handleMessage/handleCallback/handleInline/renderView | personal / group read | UITest + CalendarTest |
| `shabatCommand` | UI::start/handleMessage/handleCallback/handleInline/renderView | personal / group read | UITest + CalendarTest |
| `onUpdateBotInlineQuery` | UI::start/handleMessage/handleCallback/handleInline/renderView | personal / group read | UITest + CalendarTest |
| `closecommand` | Retained bot/operator integration | system admin / runtime | Live Telegram not run |
| `addgroupCommand` | SQLite membership + UI::panel/screen/changes/moduleDefaults | group admin | UITest + StorageTest; live Telegram not run |
| `removegroupCommand` | SQLite membership + UI::panel/screen/changes/moduleDefaults | group admin | UITest + StorageTest; live Telegram not run |
| `grupsettingsCommand` | SQLite membership + UI::panel/screen/changes/moduleDefaults | group admin | UITest + StorageTest; live Telegram not run |
| `ifNotCommands` | Content/TemplateEditor + TemplateRepository + TemplateRenderer | group admin | Fake Telegram template tests; live not run |
| `backtosettings` | SQLite membership + UI::panel/screen/changes/moduleDefaults | group admin | UITest + StorageTest; live Telegram not run |
| `defaultset` | SQLite membership + UI::panel/screen/changes/moduleDefaults | group admin | UITest + StorageTest; live Telegram not run |
| `defaultset1` | SQLite membership + UI::panel/screen/changes/moduleDefaults | group admin | UITest + StorageTest; live Telegram not run |
| `infotimes` | SQLite membership + UI::panel/screen/changes/moduleDefaults | group admin | UITest + StorageTest; live Telegram not run |
| `infomessages` | SQLite membership + UI::panel/screen/changes/moduleDefaults | group admin | UITest + StorageTest; live Telegram not run |
| `TimesON` | SQLite membership + UI::panel/screen/changes/moduleDefaults | group admin | UITest + StorageTest; live Telegram not run |
| `TimesOFF` | SQLite membership + UI::panel/screen/changes/moduleDefaults | group admin | UITest + StorageTest; live Telegram not run |
| `MessagesON` | SQLite membership + UI::panel/screen/changes/moduleDefaults | group admin | UITest + StorageTest; live Telegram not run |
| `MessagesOFF` | SQLite membership + UI::panel/screen/changes/moduleDefaults | group admin | UITest + StorageTest; live Telegram not run |
| `parseButtonsold` | Content/TemplateEditor + TemplateRepository + TemplateRenderer | group admin | Fake Telegram template tests; live not run |
| `parseButtons` | Content/TemplateEditor + TemplateRepository + TemplateRenderer | group admin | Fake Telegram template tests; live not run |
| `buttonCallbacks` | Content/TemplateEditor + TemplateRepository + TemplateRenderer | group admin | Fake Telegram template tests; live not run |
| `validateButtonsInput` | Content/TemplateEditor + TemplateRepository + TemplateRenderer | group admin | Fake Telegram template tests; live not run |
| `openMessageGroup` | Content/TemplateEditor + TemplateRepository + TemplateRenderer | group admin | Fake Telegram template tests; live not run |
| `closeGroupMessage` | Content/TemplateEditor + TemplateRepository + TemplateRenderer | group admin | Fake Telegram template tests; live not run |
| `backGroupMessage` | Content/TemplateEditor + TemplateRepository + TemplateRenderer | group admin | Fake Telegram template tests; live not run |
| `GroupTextSet1` | Content/TemplateEditor + TemplateRepository + TemplateRenderer | group admin | Fake Telegram template tests; live not run |
| `RemoveText1` | Content/TemplateEditor + TemplateRepository + TemplateRenderer | group admin | Fake Telegram template tests; live not run |
| `RemoveText1_1` | Content/TemplateEditor + TemplateRepository + TemplateRenderer | group admin | Fake Telegram template tests; live not run |
| `GroupTextView1` | Content/TemplateEditor + TemplateRepository + TemplateRenderer | group admin | Fake Telegram template tests; live not run |
| `GroupMsgMedia1` | Content/TemplateEditor + TemplateRepository + TemplateRenderer | group admin | Fake Telegram template tests; live not run |
| `GroupMsgMediaView1` | Content/TemplateEditor + TemplateRepository + TemplateRenderer | group admin | Fake Telegram template tests; live not run |
| `RemoveMedia1` | Content/TemplateEditor + TemplateRepository + TemplateRenderer | group admin | Fake Telegram template tests; live not run |
| `RemoveMedia1_1` | Content/TemplateEditor + TemplateRepository + TemplateRenderer | group admin | Fake Telegram template tests; live not run |
| `buttonsmanageviewgroup1` | Content/TemplateEditor + TemplateRepository + TemplateRenderer | group admin | Fake Telegram template tests; live not run |
| `GroupMsgButtonSet1` | Content/TemplateEditor + TemplateRepository + TemplateRenderer | group admin | Fake Telegram template tests; live not run |
| `RemoveButtons1` | Content/TemplateEditor + TemplateRepository + TemplateRenderer | group admin | Fake Telegram template tests; live not run |
| `RemoveButtons1_1` | Content/TemplateEditor + TemplateRepository + TemplateRenderer | group admin | Fake Telegram template tests; live not run |
| `HandleGroupMsgSet1` | Content/TemplateEditor + TemplateRepository + TemplateRenderer | group admin | Fake Telegram template tests; live not run |
| `view_welcomeMessage_full` | Content/TemplateEditor + TemplateRepository + TemplateRenderer | group admin | Fake Telegram template tests; live not run |
| `StatsGroups` | SQLite group/service/lock statistics | read-only | Storage fixture assertions |
| `shabatCronClose` | Runtime/Application::tick and Scheduling lock/outbox state | runtime | CalendarTest + SchedulingTest |
| `shabatCronOpen` | Runtime/Application::tick and Scheduling lock/outbox state | runtime | CalendarTest + SchedulingTest |
| `shabatCronAlert` | Runtime/Application::tick and Scheduling lock/outbox state | runtime | CalendarTest + SchedulingTest |
| `Payments` | Retained Stars donation and payment update handlers | personal / system admin | Source reviewed; real payment not run |
| `onupdateBotPrecheckoutQuery` | Retained Stars donation and payment update handlers | personal / system admin | Source reviewed; real payment not run |
| `onUpdateNewMessage` | Retained Stars donation and payment update handlers | personal / system admin | Source reviewed; real payment not run |
| `closeGroupManually` | Authorized manual permission reconciliation with durable snapshots | system admin | Fake-gateway permission tests; live not run |
| `openGroupManually` | Authorized manual permission reconciliation with durable snapshots | system admin | Fake-gateway permission tests; live not run |
| `testzmanim` | CalendarService + Runtime/Application diagnostics | calendar / operator | CalendarTest; live diagnostics not run |
| `setcommands` | Retained bot/operator integration | system admin / runtime | Live Telegram not run |
| `getAdminKeyboard` | Retained bot/operator integration | system admin / runtime | Live Telegram not run |
| `admincommand` | Retained bot/operator integration | system admin / runtime | Live Telegram not run |
| `addsohe1hazor` | Retained bot/operator integration | system admin / runtime | Live Telegram not run |
| `addsohe1hazor2` | Retained bot/operator integration | system admin / runtime | Live Telegram not run |
| `getPlugins` | Retained bot/operator integration | system admin / runtime | Live Telegram not run |
| `getPluginPaths` | Retained bot/operator integration | system admin / runtime | Live Telegram not run |
| `reshimamishtamshim` | Retained bot/operator integration | system admin / runtime | Live Telegram not run |
| `reshimamishtamshim2` | Retained bot/operator integration | system admin / runtime | Live Telegram not run |
| `reshimamishtamshim3` | Retained bot/operator integration | system admin / runtime | Live Telegram not run |
| `var_cat_command` | Retained bot/operator integration | system admin / runtime | Live Telegram not run |
| `adnimhandle` | Retained bot/operator integration | system admin / runtime | Live Telegram not run |
| `StatsUsers` | Retained bot/operator integration | system admin / runtime | Live Telegram not run |
| `closeBroadcastMsg` | Retained bot/operator integration | system admin / runtime | Live Telegram not run |
| `broadcastCommand` | Retained bot/operator integration | system admin / runtime | Live Telegram not run |
| `deleteLastBroadcast` | Retained bot/operator integration | system admin / runtime | Live Telegram not run |
| `deleteAllBroadcast` | Retained bot/operator integration | system admin / runtime | Live Telegram not run |
| `cancelPinned` | Retained bot/operator integration | system admin / runtime | Live Telegram not run |
| `LastBrodDATA` | Retained bot/operator integration | system admin / runtime | Live Telegram not run |
| `setBroadcast` | Retained bot/operator integration | system admin / runtime | Live Telegram not run |
| `handlebroadcast1` | Retained bot/operator integration | system admin / runtime | Live Telegram not run |
| `hazarashidur` | Retained bot/operator integration | system admin / runtime | Live Telegram not run |
| `addsoheshidur1forneitza1` | Retained bot/operator integration | system admin / runtime | Live Telegram not run |
| `addsoheshidur1forneitza2` | Retained bot/operator integration | system admin / runtime | Live Telegram not run |
| `broadsetsenders` | Retained bot/operator integration | system admin / runtime | Live Telegram not run |
| `broadsetsenders1` | Retained bot/operator integration | system admin / runtime | Live Telegram not run |
| `broadsetsenders2` | Retained bot/operator integration | system admin / runtime | Live Telegram not run |
| `broadsetsenders3` | Retained bot/operator integration | system admin / runtime | Live Telegram not run |
| `broadsetsenders4` | Retained bot/operator integration | system admin / runtime | Live Telegram not run |
| `hosafkaf` | Retained bot/operator integration | system admin / runtime | Live Telegram not run |
| `handlebuttons` | Retained bot/operator integration | system admin / runtime | Live Telegram not run |
| `parseButtons` | Content/TemplateEditor + TemplateRepository + TemplateRenderer | group admin | Fake Telegram template tests; live not run |
| `buttonsmanageview` | Retained bot/operator integration | system admin / runtime | Live Telegram not run |
| `loadButtons` | Retained bot/operator integration | system admin / runtime | Live Telegram not run |
| `buttonsmanageview2` | Retained bot/operator integration | system admin / runtime | Live Telegram not run |
| `loadButtons` | Retained bot/operator integration | system admin / runtime | Live Telegram not run |
| `comingsoon` | Legacy placeholder omitted from new navigation | none | No new inactive menu button |
| `RunBot` | Runtime/Runner + configured error event handler and graceful stop | runtime | Runner/patch tests; no live bot startup |

## Additional automated integration evidence

`UITemplateTest` verifies private-only previews; a distinct named-group TEST confirmation; five-minute test-send rate limiting; user and administrator rechecks; replay and forwarded callback rejection; UTF-16 formatting entity clipping including emoji; media and buttons preservation; removal of one message component without resetting the rest; independent English/Hebrew editor menus; and rollback of both token consumption and settings revision on template CAS/SQLite write failure. Its gateway deliberately rejects permission RPCs and live HTTP.

`UITest` additionally verifies reply-bound GPS selection for two groups completed in reverse order, wrong-user/unreplied/non-bot prompt rejection, private-panel language versus group-content language, full/short daily views, clearly missing solar values and tefillin jobs calculated in a private preview while the module stays disabled. These fixtures do not establish live Telegram behavior.
## dev.4 cumulative interface and administration additions

These are functional adaptations of the named local reference projects, with the current bot's authorization and storage boundaries. Visual identity is Shabbat; this table does not claim byte-identical output or live-client acceptance.

| Request/reference | Current implementation | Automated evidence |
| --- | --- | --- |
| Exact opening, categorized help, original edit-based navigation | BotController, Lang, Presentation, CallbackUiGateway | PresentationTest, UiNavigationTest, AdminCrossFlowNavigationTest |
| Twelve supplied custom emoji IDs and native colored buttons, owner-eligibility fallback | Presentation and MadelineGateway including BroadcastManager transport | CustomEmojiSerializationTest, CustomEmojiFallbackTest, PresentationTest, TefillinAckGatewayTest |
| Simple group settings including all tefillin fields | SettingsWizard and BotController guided picker/input/confirmation | SettingsWizardTest, GuidedSettingsTest |
| Upcoming complete holy sequences and holiday metadata | ShabbatTimesFormatter plus UI period navigation | ShabbatOverviewTest and existing calendar/formatter tests |
| Custom Stars amount 1-10000 and old payment preservation | Donations, DonationController, migration 011 | DonationCustomAmountTest, CustomStarsMigrationTest, DonationsTest |
| Delegated managers and immutable configured recovery owners (newsearch-v2 style) | AdminAccessRepository, AdminController, OperatorController | AdminRolesLinksTest and native bridge navigation tests |
| Full known subscriber profile, user/group/channel/all filters, search/paging (psy-new style) | SubscriberDirectory, SubscriberController, AdminDirectoryText | SubscriberDirectoryTest, AdminCrossFlowNavigationTest |
| Expanded rich broadcast drafts, audiences, pin, edit-last, timing, self-destruct and confirmations | BroadcastController, OperatorDraft, AdminIntegration, BroadcastJobs | BroadcastAdvancedTest, BroadcastJobsTest, UIOperatorTest, BroadcastPatchTest |
| System deep links and tracking-link counters | SystemLinks, TrackingLinkRepository, AdminController | AdminRolesLinksTest |
| Six-language role-scoped command menus | CommandMenus and current per-target capability filter | CommandMenusTest |
| Native rich custom messages and independent button modes | TemplateEditor, TemplateRepository, TemplateRenderer, Application | GuidedTemplateTest, UITemplateTest, GroupMessageButtonsIntegrationTest |
| Default delivered-group share actions | GroupMessageButtons: original shabat/zmanim/daf inline shortcuts | GroupMessageButtonsIntegrationTest; public results use the sharer's preferences |
| Default blue tefillin count and unique reversible user votes | TefillinAcknowledgements, group callback/cron hooks and migration 012 | TefillinAcknowledgementsTest, TefillinAckGatewayTest, GroupMessageButtonsIntegrationTest |
| Legacy full keyboard wrapper compatibility | Recognized read-time normalization in TemplateRepository, LegacyImporter and OperatorDraft | LegacyAdminImportTest, BroadcastAdvancedTest, TefillinAcknowledgementsTest |

Private group-view sharing remains a distinct authorized read-only group snapshot. Default public announcement sharing keeps the original personal inline-query semantics. Preview acknowledgement buttons are inert; only verified delivered reminders create countable message bindings.

All rows have offline implementation tests; real Telegram administration, appearance, payments, sending and Docker execution remain separate isolated acceptance work. Current aggregate results are in the version-matching validation report.

## dev.5 test feedback

The [eleven-item report](DEV5_TEST_FEEDBACK.md) maps the latest corrections to code behavior and tests. It supersedes dev.4's period-pagination description: the Shabbat/holiday screen is now a single overview. Group command scopes contain only settings. The fully preserved native rights snapshot has22flags, while Telegram-compatible group-wide selection has21 and excludes view_messages. Detailed manual-operation diagnosis, restart/IPC lifecycle, contextual settings return, grouped statistics and guided broadcast editing are implemented without changing migrations, locked dependencies or patch files.

## dev.6 follow-up corrections

The current [feedback report](DEV6_TEST_FEEDBACK.md) records conservative native-permission equivalence, recovery of compatible saved conflicts, actual SDK callback routing for public statistics, and a consistent nearest-period Shabbat message for public/inline/scheduled defaults. The previous next-three-Shabbat display is superseded by one nearest Shabbat-containing period plus a current/upcoming holiday list; separate weekday Yom Tov before Shabbat retains its verified entrance and final exit. Existing calendar policy, rich custom templates, library patches and all twelve migrations remain intact. New boundary tests exercise the actual handler, not only controller helpers.

## Dev.7 recovery and diagnostics

The [permission recovery flow](PERMISSION_RECOVERY.md) adds conservative automatic recovery and the two explicitly requested administrator choices without discarding original snapshots. The private [testzmanim panel](DIAGNOSTICS.md) replaces JSON message flooding with editable navigation, search, filters, quick page entry and JSON-file export. Both features include all six languages and additive migrations 013-014. Prior calendar, group settings, statistics, custom messages and pinned-library behavior remain covered by the complete regression suite.
