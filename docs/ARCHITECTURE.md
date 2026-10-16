Current dev.9 runtime and migration changes: [delivery safety, independent scheduling, queue retention and verification limits](DEV9_DELIVERY_SAFETY.md). Historical release-specific numbers below apply to their named versions.

# Architecture

The existing `Shabbat` MadelineProto event handler and project identity remain. `Runtime/BotIntegration` connects new commands to services; existing Stars and BroadcastManager capabilities are preserved during integration. All new Telegram effects use the MTProto gateway. Unit tests substitute a fake gateway and fixed UTC clock.

`Calendar/HebcalProvider` obtains explicitly bounded civil-date calendar and batch solar data independently. Calendar Israel/diaspora selection is not inferred from coordinates. `CalendarEngine` merges adjacent prohibited days, computes exact candle/nightfall methods, and separates operational lock/open offsets. `CalendarService` supplies one snapshot to display and planning.

`Storage/Database` uses foreign keys, WAL and FULL synchronous persistence. Typed group and personal settings are independent and revisioned. Selection flows persist scoped tokens. `LockCoordinator` persists and checksums permission snapshots before RPC, verifies readback, keeps original permissions through restarts, and reports conflicts rather than enabling all permissions.

`ReminderPlanner` generates UTC jobs using IANA local times. Missing DST times are skipped; repeated times select the first occurrence once. Outbox jobs have unique logical event keys, stable random IDs, leases, revisions and expiry. Settings changes invalidate routine future jobs while preserving permission restoration debt. Telegram uncertain-response retries cannot promise absolute exactly-once delivery.

The SQLite database stores application data; MadelineProto session storage remains independent. Maintenance and runtime acquire the same instance-writer lock. Migration does not run destructively at startup. Only previously absent registrations from the configured legacy list are imported automatically.

Provider HTTP is asynchronous with HTTPS, body limits and timeouts. Shared and persisted raw caches prevent identical refreshes across groups. Calendar consumers validate actual date coverage. Provider failure is visible and leaves permissions unchanged; it is never interpreted as no event.
