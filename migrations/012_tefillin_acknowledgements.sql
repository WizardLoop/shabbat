-- SPDX-License-Identifier: AGPL-3.0-only
ALTER TABLE group_settings ADD COLUMN tefillin_ack_enabled INTEGER NOT NULL DEFAULT 1 CHECK(tefillin_ack_enabled IN (0,1));
ALTER TABLE group_settings ADD COLUMN tefillin_ack_label TEXT DEFAULT NULL;
ALTER TABLE user_preferences ADD COLUMN tefillin_ack_enabled INTEGER NOT NULL DEFAULT 1 CHECK(tefillin_ack_enabled IN (0,1));
ALTER TABLE user_preferences ADD COLUMN tefillin_ack_label TEXT DEFAULT NULL;
ALTER TABLE message_templates ADD COLUMN buttons_mode TEXT NOT NULL DEFAULT 'inherit' CHECK(buttons_mode IN ('inherit','custom','none'));
UPDATE message_templates SET buttons_mode=CASE WHEN EXISTS(SELECT 1 FROM json_each(message_templates.buttons_json)) THEN 'custom' ELSE 'inherit' END;
CREATE TABLE tefillin_ack_rounds (
 round_token TEXT PRIMARY KEY, job_id INTEGER NOT NULL UNIQUE REFERENCES scheduled_jobs(job_id) ON DELETE CASCADE,
 group_id TEXT NOT NULL REFERENCES groups(group_id), label TEXT NOT NULL, language TEXT NOT NULL,
 settings_revision INTEGER NOT NULL, markup_json TEXT NOT NULL, created_at INTEGER NOT NULL
);
CREATE TABLE tefillin_ack_messages (
 group_id TEXT NOT NULL REFERENCES groups(group_id), message_id INTEGER NOT NULL CHECK(message_id BETWEEN 1 AND 2147483647),
 round_token TEXT NOT NULL REFERENCES tefillin_ack_rounds(round_token) ON DELETE CASCADE,
 revision INTEGER NOT NULL DEFAULT 0, rendered_revision INTEGER NOT NULL DEFAULT 0, retry_at INTEGER, edit_attempts INTEGER NOT NULL DEFAULT 0, last_error_code TEXT,
 updated_at INTEGER NOT NULL, PRIMARY KEY(group_id,message_id), UNIQUE(round_token,message_id)
);
CREATE TABLE tefillin_ack_votes (
 round_token TEXT NOT NULL, message_id INTEGER NOT NULL, user_id TEXT NOT NULL, created_at INTEGER NOT NULL,
 PRIMARY KEY(round_token,message_id,user_id),
 FOREIGN KEY(round_token,message_id) REFERENCES tefillin_ack_messages(round_token,message_id) ON DELETE CASCADE
) WITHOUT ROWID;
CREATE TABLE tefillin_ack_callbacks (
 callback_hash TEXT PRIMARY KEY, round_token TEXT NOT NULL, message_id INTEGER NOT NULL,
 user_id TEXT NOT NULL, created_at INTEGER NOT NULL,
 FOREIGN KEY(round_token,message_id) REFERENCES tefillin_ack_messages(round_token,message_id) ON DELETE CASCADE
) WITHOUT ROWID;
CREATE INDEX tefillin_ack_pending ON tefillin_ack_messages(rendered_revision,revision,updated_at);
