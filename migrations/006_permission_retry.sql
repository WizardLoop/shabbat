-- SPDX-License-Identifier: AGPL-3.0-only
-- Permission RPC quota deadlines survive restart without discarding recovery snapshots.
ALTER TABLE group_lock_state ADD COLUMN retry_at INTEGER CHECK(retry_at IS NULL OR (typeof(retry_at)='integer' AND retry_at>=0));
