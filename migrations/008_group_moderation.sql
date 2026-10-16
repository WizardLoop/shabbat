-- SPDX-License-Identifier: AGPL-3.0-only
-- Group-only controls; original lock snapshots and all earlier rows remain intact.
ALTER TABLE group_settings ADD COLUMN lock_block_flags TEXT NOT NULL DEFAULT '["send_messages","send_media","send_stickers","send_gifs","send_games","send_inline","embed_links","send_polls","change_info","invite_users","pin_messages","manage_topics","send_photos","send_videos","send_roundvideos","send_audios","send_voices","send_docs","send_plain","edit_rank","send_reactions"]' CHECK(json_valid(lock_block_flags) AND json_type(lock_block_flags)='array' AND json_array_length(lock_block_flags)<=22);
ALTER TABLE group_settings ADD COLUMN delete_holy_user_messages INTEGER NOT NULL DEFAULT 0 CHECK(delete_holy_user_messages IN (0,1));
ALTER TABLE group_settings ADD COLUMN delete_holy_bot_messages INTEGER NOT NULL DEFAULT 0 CHECK(delete_holy_bot_messages IN (0,1));
ALTER TABLE group_settings ADD COLUMN tefillin_delete_previous INTEGER NOT NULL DEFAULT 0 CHECK(tefillin_delete_previous IN (0,1));
-- While a changed mask is in flight, either known bot-owned mask can require restoration.
ALTER TABLE group_lock_state ADD COLUMN previous_applied_json TEXT;
-- A confirmed send is never resent because subsequent cleanup bookkeeping failed.
ALTER TABLE scheduled_jobs ADD COLUMN module_delivery_processed_at INTEGER;
CREATE TABLE message_deletions (
    deletion_id INTEGER PRIMARY KEY,
    group_id TEXT NOT NULL REFERENCES groups(group_id),
    message_id INTEGER NOT NULL CHECK(message_id BETWEEN 1 AND 2147483647),
    purpose TEXT NOT NULL CHECK(purpose IN ('holy_message','tefillin_previous')),
    original_timestamp INTEGER NOT NULL CHECK(original_timestamp >= 0),
    sender_id TEXT, sender_is_bot INTEGER NOT NULL CHECK(sender_is_bot IN (0,1)),
    sender_is_self INTEGER NOT NULL DEFAULT 0 CHECK(sender_is_self IN (0,1) AND (sender_is_self=0 OR sender_is_bot=1)),
    settings_revision INTEGER CHECK(settings_revision IS NULL OR settings_revision>0),
    origin_delivery_key TEXT,
    due_at INTEGER NOT NULL CHECK(due_at>=0), expires_at INTEGER CHECK(expires_at IS NULL OR expires_at>=due_at),
    status TEXT NOT NULL DEFAULT 'pending' CHECK(status IN ('pending','leased','deleted','cancelled','failed','expired')),
    attempts INTEGER NOT NULL DEFAULT 0 CHECK(attempts>=0), retry_at INTEGER,
    lease_owner TEXT, lease_until INTEGER, last_error TEXT,
    created_at INTEGER NOT NULL, updated_at INTEGER NOT NULL, completed_at INTEGER,
    UNIQUE(group_id,message_id,purpose),
    CHECK(purpose<>'holy_message' OR expires_at IS NOT NULL),
    CHECK(purpose<>'tefillin_previous' OR (sender_is_bot=1 AND sender_is_self=1 AND origin_delivery_key IS NOT NULL))
);
CREATE INDEX deletions_due ON message_deletions(status,retry_at,due_at,lease_until);
CREATE TABLE module_last_messages (
    group_id TEXT NOT NULL REFERENCES groups(group_id), module TEXT NOT NULL CHECK(length(module) BETWEEN 1 AND 32),
    message_ids_json TEXT NOT NULL CHECK(json_valid(message_ids_json) AND json_type(message_ids_json)='array' AND json_array_length(message_ids_json) BETWEEN 1 AND 100),
    max_message_id INTEGER NOT NULL CHECK(max_message_id BETWEEN 1 AND 2147483647),
    delivery_key TEXT NOT NULL CHECK(length(delivery_key) BETWEEN 1 AND 128),
    original_timestamp INTEGER NOT NULL CHECK(original_timestamp>=0), delivery_completed_at INTEGER NOT NULL CHECK(delivery_completed_at>=0), source_job_id INTEGER REFERENCES scheduled_jobs(job_id), updated_at INTEGER NOT NULL,
    PRIMARY KEY(group_id,module)
);
-- Separate rich announcement template while retaining existing template identifiers and metadata.
CREATE TABLE message_templates_moderation (
    template_id INTEGER PRIMARY KEY, group_id TEXT REFERENCES groups(group_id), user_id TEXT REFERENCES users(user_id),
    kind TEXT NOT NULL CHECK(kind IN ('open','close','times_alert','zmanim','daf','tefillin','omer','broadcast')),
    text TEXT NOT NULL DEFAULT '', entities_json TEXT NOT NULL DEFAULT '[]', media_json TEXT, buttons_json TEXT NOT NULL DEFAULT '[]',
    revision INTEGER NOT NULL DEFAULT 1, updated_at INTEGER NOT NULL, imported_at INTEGER, CHECK((group_id IS NULL) != (user_id IS NULL))
);
INSERT INTO message_templates_moderation SELECT * FROM message_templates;
-- The previous upgrade shared zmanim with announcements: preserve that announcement content once.
INSERT INTO message_templates_moderation(group_id,user_id,kind,text,entities_json,media_json,buttons_json,revision,updated_at,imported_at)
SELECT group_id,NULL,'times_alert',text,entities_json,media_json,buttons_json,revision,updated_at,imported_at FROM message_templates WHERE kind='zmanim' AND group_id IS NOT NULL;
DROP TABLE message_templates;
ALTER TABLE message_templates_moderation RENAME TO message_templates;
CREATE UNIQUE INDEX templates_group_kind ON message_templates(group_id,kind) WHERE group_id IS NOT NULL;
CREATE UNIQUE INDEX templates_user_kind ON message_templates(user_id,kind) WHERE user_id IS NOT NULL;
