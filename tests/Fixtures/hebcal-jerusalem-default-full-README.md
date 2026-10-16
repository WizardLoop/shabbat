# Complete default Jerusalem Hebcal regression responses

These complete public responses were retrieved on 2026-10-15 using the exact
application default coordinates (31.76904, 35.21633) and Asia/Jerusalem. Attribution:
Hebcal.com, CC-BY-4.0 (https://creativecommons.org/licenses/by/4.0/). The response
text was decoded and saved as UTF-8 without a BOM. All response fields and values
are retained, with no projection, normalization, recalculation or invented time.
Request URLs and SHA-256 hashes are recorded in
hebcal-jerusalem-default-full-provenance.json.

The date range 2026-09-23 through 2026-12-10 is the actual October CalendarService
monthly request. The full solar response includes 42 fields: 35 complete daily
solar series, two empty elevation-only series (seaLevelSunrise, seaLevelSunset)
and five sparse lunar series (molad, tchilasZmanKidushLevana3Days,
tchilasZmanKidushLevana7Days, sofZmanKidushLevanaBetweenMoldos,
sofZmanKidushLevana15Days). Lunar entries occur only on their applicable dates.
They do not provide a time for every day. The current application does not consume
either the elevation-only or lunar solar response fields.

Before the fix, HebcalProvider incorrectly required every field to cover every
date, rejecting the full valid response on seaLevelSunrise for 2026-09-23. The
earlier preceding-night regression inputs retained only selected fields and did
not include this real provider shape.

HebcalProvider now explicitly recognizes the supported daily fields. Every
present recognized daily field still requires complete date coverage and strict
timestamp, civil-date and IANA offset validation; sunset remains mandatory.
Explicit null polar results remain null. The guarded preceding-night rule for
chatzotNight remains in place. Unsupported elevation-only, lunar and future
extension fields do not become operational times and do not control daily
coverage. Missing operational values still fail in the calendar engine rather
than changing group permissions using invented times.

Reference: https://www.hebcal.com/home/1663/zmanim-halachic-times-api

The fixtures contain public API data only, with no Telegram credentials or
production data. They are offline regression inputs rather than schedules to
publish. Successful API access from this development machine does not establish
the deployment server's network connectivity.
