-- SPDX-License-Identifier: AGPL-3.0-only
CREATE TABLE bot_admins (
 user_id TEXT PRIMARY KEY, role TEXT NOT NULL CHECK(role IN ('full','limited')),
 view_only INTEGER NOT NULL CHECK(view_only IN (0,1)), permissions_json TEXT NOT NULL,
 revision INTEGER NOT NULL DEFAULT 1, created_at INTEGER NOT NULL, created_by TEXT NOT NULL,
 updated_at INTEGER NOT NULL, updated_by TEXT NOT NULL
);
CREATE TABLE admin_panel_flows (
 actor_id TEXT PRIMARY KEY, nonce TEXT NOT NULL, kind TEXT NOT NULL,
 payload_json TEXT NOT NULL, expires_at INTEGER NOT NULL
);
CREATE TABLE admin_meta (meta_key TEXT PRIMARY KEY, meta_value TEXT NOT NULL);
CREATE TABLE tracking_links (
 id INTEGER PRIMARY KEY AUTOINCREMENT, param TEXT NOT NULL COLLATE NOCASE UNIQUE,
 label TEXT NOT NULL, destination TEXT NOT NULL, created_by TEXT NOT NULL, created_at INTEGER NOT NULL,
 revision INTEGER NOT NULL DEFAULT 1, total_clicks INTEGER NOT NULL DEFAULT 0,
 unique_users INTEGER NOT NULL DEFAULT 0, first_click_at INTEGER, last_click_at INTEGER
);
CREATE TABLE tracking_link_users (
 tracker_id INTEGER NOT NULL REFERENCES tracking_links(id) ON DELETE CASCADE,
 user_hash TEXT NOT NULL, last_message_id INTEGER NOT NULL,
 PRIMARY KEY(tracker_id,user_hash)
) WITHOUT ROWID;
CREATE TABLE tracking_link_days (
 tracker_id INTEGER NOT NULL REFERENCES tracking_links(id) ON DELETE CASCADE,
 day TEXT NOT NULL, clicks INTEGER NOT NULL DEFAULT 0, PRIMARY KEY(tracker_id,day)
) WITHOUT ROWID;
CREATE INDEX admin_action_audit ON audit_log(action,audit_id);
