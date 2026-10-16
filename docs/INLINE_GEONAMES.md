# Simple location selection and inline search

GeoNames supplies place records and IANA timezone identifiers. Hebcal remains the calendar/solar source. Search never replaces a timezone with a current numeric offset, invents solar times, or saves a place merely because an inline result was shared.

## Operator setup and diagnosis

Set `GEONAMES_USERNAME` to your own [GeoNames](https://www.geonames.org/login) account with web services enabled. Empty and `demo` usernames are rejected. Keep the private environment file out of Git/images/archives. The default endpoint is verified HTTPS `https://secure.geonames.org`; TLS verification is not disabled.

Enable the test bot's inline mode with `/setinline` in BotFather, selecting the actual test bot and a friendly placeholder such as “City, Shabbat, daily times or Daf”. [Telegram documents this prerequisite](https://core.telegram.org/bots/inline). City search does not require inline location permission or chosen-result feedback.

If Telegram never opens the inline results list, check BotFather inline mode. If the bot shows provider unavailable/configuration missing, check the enabled GeoNames username, account quota, endpoint/network and bot logs privately. HTTP-200 provider status errors are failures rather than empty city lists. Do not publish logs containing credentials.

The old handler recognized `loc CITY` but returned no results for a bare city name. The revised handler also accepts `@BOT_USERNAME ירושלים` and other plain city names. This was verified with fixtures; no live account or client result is claimed.

## Setting a named group or personal location

1. Open **My preferences**, or **My groups → selected group → Location and timezone**. Group authority is checked again.
2. Choose city search, place identifier, coordinates, timezone or GPS. The prompt names the current destination. Workflow tokens stay in server state and button data.
3. Follow the short prompt: type a city, a positive GeoNames ID, coordinates with an IANA zone, or a timezone search. City inline buttons insert a simple `location ` prefix; type the city after it.
4. Select a result and inspect the named destination, coordinates, place ID, IANA zone and current UTC offset. Location/calendar convention remain separate settings.
5. Confirm saving. One-use confirmation binds user, destination, action, revision and expiry; the bot revalidates selected provider data and group administration.

A newly opened natural-input prompt replaces the previous natural-input prompt for that user. Expired prompts and changed settings cannot save. Use the displayed destination before confirmation; navigating another group does not retarget an existing result. The original tokenized commands remain compatibility routes for advanced workflows.

Timezone-only selection preserves coordinates and warns about geographic mismatch. Coordinates with an explicit IANA zone do not need GeoNames; automatic zone resolution does. Curated locations remain available if city search is unavailable.

## Sharing GPS safely

Open the GPS action, then use Telegram **Reply** on that exact bot prompt and attach a location. The simplified prompt's authenticated private message ID is stored with its user, destination, revision and expiry; no long token must be copied. The raw bridge verifies the actual bot sender and private dialog. A random/unreplied location, cross-dialog quote, stale prompt or lost group role is rejected. The existing legacy `GPS TOKEN` reply route remains supported.

## Public inline results and bounds

Bare city or `loc CITY` searches are public read-only information, with city/country/region and GeoNames attribution. They never inherit a group target or save settings. A `location CITY` setup search requires the active owned private city prompt; its result carries a destination-bound choice and explicit private confirmation. Group calendar shares use separate read-only snapshot flows.

GeoNames searches use provider-supported languages he/en/ru/fr/es, with pt-br mapped to pt, normalized UTF-8, ten-result pages and bounded one-hour caches. Selected-place details are fetched only on selection; timezone lookup occurs when necessary. Per-user uncached requests are throttled; cancellation and shared HTTP requests avoid busy retries. The provider's [terms](https://www.geonames.org/export/) and [status errors](https://www.geonames.org/export/webservice-exception.html) apply.

Localized public results use a personal cache; bound results use cache_time=0 and private=true. Recognized calendar queries remain shabat/shabbat/שבת/zmanim/daf/omer. Public and localized outputs are separated appropriately so Telegram cannot share one user's setup context with another.

## Attribution and evidence

GeoNames data is attributed under CC-BY-4.0; see [upstream readme](https://download.geonames.org/export/dump/readme.txt) and [third-party notices](../THIRD_PARTY_NOTICES.md). No provider logo is reused.

Location, UI navigation, flow and GPS bridge tests use isolated SQLite state and fake Telegram/provider transport. They cover wrong users, expiry, stale revision, admin loss, public sharing, exact reply ownership, callbacks and explicit confirmation. Real inline/client/GPS delivery remains a staging acceptance check.

Public timezone search accepts `tz Jerusalem` or an exact identifier such as `Asia/Jerusalem`. The timezone setup button uses `timezone ` with the active private timezone prompt; confirmation changes only the timezone. GeoNames IDs and coordinates are supported through the simple private buttons and reply-bound GPS action.
