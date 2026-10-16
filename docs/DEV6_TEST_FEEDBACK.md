> Historical implementation record. Referenced `dist/VALIDATION-*` reports belong to earlier private development deliveries and are not bundled here. Current publication checks and limits are described in [publication status](PUBLICATION_STATUS.md).

# Dev.6 - permission conflicts, Shabbat messages and statistics

## 1. Manual and automatic close/open

The reported conflict message is produced by the bot's saved-state comparison, not by a Telegram RPC error proving that an administrator changed permissions. A concrete representation mismatch was reproduced: requests use until_date=0 for permanent default restrictions while Telegram's official TDLib describes the returned permanent default restrictions with INT32_MAX (2147483647). TDLib also serializes granular send restrictions with the legacy send_messages/send_media aggregate flags cleared. Raw comparison could therefore reject a successful close and retain that debt as a later conflict.

The fix uses a reviewed, conservative equivalence for both close and restoration in the shared coordinator. Only the two permanent sentinels are equal; finite expiries remain distinct. Aggregate flags can be redundant only when their corresponding granular restrictions are all explicitly present. Individual differences, reactions/member-tag differences, unknown flags, missing/corrupt snapshots and real administrator changes remain protected. Original snapshot JSON and its checksum are retained byte-for-byte. Compatible persisted dev.5 conflicts can recover on reconciliation without deleting records or guessing the original permissions.

The supplied message alone does not contain current/saved permission values. It establishes the conflict branch, but cannot prove which representation differs on that particular live group. Safe differing-field diagnostics and updated wording distinguish a mismatch from a proven external actor. The same coordinator serves manual and automatic transitions.

Evidence: [Telegram rights](https://core.telegram.org/api/rights), [native constructor](https://core.telegram.org/constructor/chatBannedRights), and the immutable [official TDLib implementation](https://github.com/tdlib/td/blob/ad2178896533ff92831accdf56ea7edd8393f2ca/td/telegram/DialogParticipant.cpp).

## 2. One consistent Shabbat/holiday presentation

Default command, menu, inline and group announcement output shows the nearest verified Shabbat-containing period in the main section. The holiday list starts at the current local date, so a separate Yom Tov before Shabbat remains visible with its verified entrance and final exit. Only strict Yom Tov events receive festival entrance/exit times; continuous Shabbat/festival periods retain one final exit. Later Shabbat summaries, scripture-reference rows, visible source/update footers and numeric timezone offsets are omitted. Times use the selected IANA timezone and Y-m-d H:i. The configured candle offset and havdalah policy are shown; a fixed 50-minute sample does not overwrite existing preferences or the default 8.5-degree policy.

Actual Yom Tov boundaries and every required intermediate candle remain subject to verified data and continuous-period rules. Hanukkah is an observance, not a Yom Tov locking interval. Source attribution remains in the relevant information/license documentation, and internal provenance/freshness validation is unchanged. Custom templates remain administrator-owned; the update does not rewrite their saved text.

Calendar day classification accepts both documented fixture forms of the original Chol Hamoed marker, (CH'M) and (CH''M). This keeps reminder policy aligned with the configured Chol Hamoed setting without promoting those dates to Yom Tov or adding lock periods. An active Yom Tov whose verified final exit falls after civil midnight remains visible until that exit, even when its calendar date is yesterday.
## 3. Statistics callbacks

Home and statistics screens emitted ps:overview / ps:modules, but the production upgradedCallbacks prefix allowlist omitted ps. Existing controller tests bypassed that adapter boundary and therefore did not detect the missing route. The allowlist is corrected. New regressions use native pinned SDK query wrappers and the actual update-handler method with callbacks read from generated markup, including non-manager private users, navigation, denied contexts and cancellation.

Edits target the original private panel. A missing/deleted panel produces a localized callback response without new-message flooding. Group and inline callbacks cannot edit a private user's statistics panel.

## 4. Release and verification

All previous cumulative features remain included. No dependency or schema update is required. The direct /root/shabbat-test upload instructions exclude uploaded ZIP/checksum files from Docker context. Exact tests, fingerprints, archive contents and platform limits belong to dist/VALIDATION-3.0.0-dev.6.json.

No production data, active bot, real Telegram group, commit, tag, push or publication was changed. Docker and Telegram acceptance were not executed locally. The host's older SQLite intentionally fails the release-platform gate; the Docker-pinned reviewed version must pass on the test server before startup. Automated tests establish the covered behavior, not a guarantee of indefinite uptime.
