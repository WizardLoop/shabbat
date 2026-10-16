-- SPDX-License-Identifier: AGPL-3.0-only
-- Additive directory observations: Telegram metadata is optional, never invented.
CREATE TABLE subscriber_profiles (
 peer_id TEXT PRIMARY KEY REFERENCES known_broadcast_peers(peer_id),
 username TEXT, first_name TEXT, last_name TEXT, language_code TEXT,
 private_chat_access INTEGER CHECK(private_chat_access IN (0,1)),
 is_bot INTEGER CHECK(is_bot IN (0,1)), is_deleted INTEGER CHECK(is_deleted IN (0,1)),
 is_premium INTEGER CHECK(is_premium IN (0,1)), is_contact INTEGER CHECK(is_contact IN (0,1)),
 first_activity_at INTEGER, last_activity_at INTEGER, activity_count INTEGER NOT NULL DEFAULT 0,
 start_count INTEGER NOT NULL DEFAULT 0, last_start_at INTEGER,
 broadcast_authorized INTEGER NOT NULL DEFAULT 0 CHECK(broadcast_authorized IN (0,1)),
 metadata_at INTEGER NOT NULL
);
CREATE INDEX subscriber_recent_activity ON subscriber_profiles(last_activity_at DESC,peer_id);
CREATE INDEX subscriber_username ON subscriber_profiles(username COLLATE NOCASE);
CREATE TABLE subscriber_group_admins (
 user_id TEXT NOT NULL, group_id TEXT NOT NULL REFERENCES groups(group_id),
 is_admin INTEGER NOT NULL CHECK(is_admin IN (0,1)), role TEXT NOT NULL,
 checked_at INTEGER NOT NULL, PRIMARY KEY(user_id,group_id)
);
CREATE TABLE subscriber_directory_views (
 viewer_id TEXT PRIMARY KEY, search TEXT NOT NULL DEFAULT '', sort TEXT NOT NULL DEFAULT 'activity',
 filter TEXT NOT NULL DEFAULT 'all', page INTEGER NOT NULL DEFAULT 0, prompt TEXT,
 updated_at INTEGER NOT NULL
);
CREATE TABLE operator_broadcast_options (
 user_id TEXT NOT NULL REFERENCES users(user_id),
 state_key TEXT NOT NULL CHECK(state_key IN ('broadcast_operation.txt','selfdestructhours.txt','scheduledat.txt')),
 value_text TEXT NOT NULL, updated_at INTEGER NOT NULL,
 PRIMARY KEY(user_id,state_key)
);

-- Only the pre-upgrade private registry is treated as a legacy private audience.
INSERT OR IGNORE INTO known_broadcast_peers(peer_id,peer_type,title,created_at,updated_at)
 SELECT user_id,'user','',created_at,updated_at FROM users;
INSERT INTO subscriber_profiles(peer_id,private_chat_access,metadata_at)
 SELECT peer_id,1,updated_at FROM known_broadcast_peers WHERE peer_type IN ('user','bot') OR peer_id IN (SELECT user_id FROM users)
 ON CONFLICT(peer_id) DO UPDATE SET private_chat_access=1;
