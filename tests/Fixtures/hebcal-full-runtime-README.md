# Complete Hebcal runtime regression inputs

The files listed in [hebcal-full-runtime-provenance.json](hebcal-full-runtime-provenance.json)
are complete unchanged public API response bodies retrieved on 2026-10-15.
No JSON properties, time series, calendar events or optional metadata were removed.
Exact URLs and SHA-256 file hashes are recorded in the provenance file.
Attribution: Hebcal.com, licensed under [CC-BY-4.0](https://creativecommons.org/licenses/by/4.0/).

Solar requests use the application's actual default coordinates, latitude 31.76904
and longitude 35.21633, with Asia/Jerusalem. April and May match the exact monthly
CalendarService request ranges. April and October calendars cover Hebrew,
English, Russian, French, Spanish and Brazilian Portuguese using the provider's
he, s, ru, fr, es and pt aliases. May includes separate Israel and diaspora
calendar responses; coordinates and the Israel/diaspora holiday policy are
independent settings.

CalendarFullResponseIntegrationTest replays these files through the real
provider, CalendarService, default database settings, private commands and group
views. It checks active and inactive Omer season, all six languages, sunset,
twilight, nightfall, dawn, the first and last Omer counts, Shabbat quiet periods,
custom nightfall methods, full-response persistent cache reuse, and closure and
restoration across the actual 2026 Shavuot-to-Shabbat sequence. Telegram calls
use an in-memory fake and never reach a real account or group.

The paired complete October Hebrew/default-coordinate fixtures are maintained
with their own provenance in
[hebcal-jerusalem-default-full-provenance.json](hebcal-jerusalem-default-full-provenance.json).
The earlier preceding-night fixtures deliberately retained only a few daily
series. They remain useful for midnight semantics but do not establish that an
entire API response satisfies the consumer contract. The complete fixtures
retain both empty elevation-only series and sparse lunar event maps so that
this previously missed contract is exercised.

The response bodies contain public astronomical and calendar data only. Their
embedded response-generation timestamps remain unchanged. They are immutable
regression inputs and must not be presented as current network availability or
freshly calculated end-user times. A successful request from a development
computer does not prove connectivity from the deployment server.
