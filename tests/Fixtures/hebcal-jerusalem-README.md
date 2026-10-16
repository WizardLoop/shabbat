# Hebcal Jerusalem preceding-night regression data

These public API response excerpts were retrieved on 2026-10-14. Attribution:
Hebcal.com, CC-BY-4.0 (https://creativecommons.org/licenses/by/4.0/).
Exact request URLs, retained fields and SHA-256 hashes are recorded in
`hebcal-jerusalem-provenance.json`. JSON formatting was normalized; unused fields
were removed. All retained provider values are unchanged.

The October fixtures cover the actual monthly CalendarService request range,
including the Israeli autumn clock change and the winter dates fetched ahead of
the current day. July covers summer. March covers the spring clock change.
`HebcalMidnightRegressionTest` replays these fixtures offline.

Hebcal `chatzotNight` is the midpoint of the night between the **previous day's**
sunset and the nominal day's sunrise. It can therefore fall on the previous
civil date; for example the 2026-10-26 entry is 2026-10-25T23:23:00+02:00. Its
original epoch is preserved. The compatibility check is specific to this field,
keeps its exact IANA offset validation, bounds it to the preceding night and
checks sunset/sunrise ordering when those values are available. Other solar
fields retain their existing strict nominal-date validation.

Provider references:

- https://www.hebcal.com/home/1663/zmanim-halachic-times-api
- https://hebcal.github.io/api/core/classes/Zmanim.html#nighthour
- https://hebcal.github.io/api/core/classes/Zmanim.html#chatzotnight

The fixtures contain public location/calendar data only, with no Telegram data,
credentials or production data. They are regression inputs, not published times
for end users. An API request from this development computer does not establish
network connectivity from the deployment server.
