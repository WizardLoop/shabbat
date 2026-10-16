-- SPDX-License-Identifier: AGPL-3.0-only
-- Older snapshots retain their bytes but must not be treated as verified read provenance.
ALTER TABLE group_lock_state ADD COLUMN snapshot_provenance TEXT NOT NULL DEFAULT 'legacy_unverified' CHECK(snapshot_provenance IN ('legacy_unverified','verified_native_v1'));
ALTER TABLE group_lock_state ADD COLUMN snapshot_evidence_json TEXT;
