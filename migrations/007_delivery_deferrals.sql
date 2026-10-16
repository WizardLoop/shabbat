-- SPDX-License-Identifier: AGPL-3.0-only
-- Eligibility deferrals do not consume the transport retry budget.
ALTER TABLE scheduled_jobs ADD COLUMN deferrals INTEGER NOT NULL DEFAULT 0 CHECK(deferrals >= 0);
