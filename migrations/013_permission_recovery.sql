-- SPDX-License-Identifier: AGPL-3.0-only
-- Original snapshots remain immutable until the next successfully completed cycle.
ALTER TABLE group_lock_state ADD COLUMN recovery_baseline_json TEXT;
ALTER TABLE group_lock_state ADD COLUMN recovery_baseline_sha256 TEXT;
CREATE TABLE permission_operations (
    operation_id INTEGER PRIMARY KEY, group_id TEXT NOT NULL REFERENCES groups(group_id),
    action TEXT NOT NULL, stage TEXT NOT NULL, outcome TEXT NOT NULL,
    before_json TEXT, requested_json TEXT, observed_json TEXT,
    original_json TEXT, original_sha256 TEXT, created_at INTEGER NOT NULL, updated_at INTEGER NOT NULL
);
CREATE INDEX permission_operations_group ON permission_operations(group_id,operation_id);
CREATE TABLE permission_recovery_confirmations (
    token_hash TEXT PRIMARY KEY, group_id TEXT NOT NULL REFERENCES groups(group_id), actor_id TEXT NOT NULL,
    action TEXT NOT NULL CHECK(action IN ('adopt','restore')), binding_json TEXT NOT NULL,
    created_at INTEGER NOT NULL, expires_at INTEGER NOT NULL, consumed_at INTEGER
);
CREATE INDEX permission_recovery_expiry ON permission_recovery_confirmations(expires_at);
