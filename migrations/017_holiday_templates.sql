-- SPDX-License-Identifier: AGPL-3.0-only
-- Existing festival announcements used the shared Shabbat content. Preserve it once;
-- subsequent edits to Shabbat and festival messages are independent.
CREATE TABLE message_templates_new (
    template_id INTEGER PRIMARY KEY, group_id TEXT REFERENCES groups(group_id), user_id TEXT REFERENCES users(user_id),
    kind TEXT NOT NULL CHECK(kind IN ('open','close','holiday_open','holiday_close','times_alert','zmanim','daf','tefillin','omer','broadcast')),
    text TEXT NOT NULL DEFAULT '', entities_json TEXT NOT NULL DEFAULT '[]', media_json TEXT, buttons_json TEXT NOT NULL DEFAULT '[]',
    revision INTEGER NOT NULL DEFAULT 1, updated_at INTEGER NOT NULL, imported_at INTEGER, buttons_mode TEXT NOT NULL DEFAULT 'inherit' CHECK(buttons_mode IN ('inherit','custom','none')),
    CHECK((group_id IS NULL) != (user_id IS NULL))
);
INSERT INTO message_templates_new(template_id,group_id,user_id,kind,text,entities_json,media_json,buttons_json,revision,updated_at,imported_at,buttons_mode)
SELECT template_id,group_id,user_id,kind,text,entities_json,media_json,buttons_json,revision,updated_at,imported_at,buttons_mode FROM message_templates;
INSERT INTO message_templates_new(group_id,user_id,kind,text,entities_json,media_json,buttons_json,revision,updated_at,imported_at,buttons_mode)
SELECT group_id,user_id,'holiday_' || kind,text,entities_json,media_json,buttons_json,revision,updated_at,imported_at,buttons_mode
FROM message_templates WHERE kind IN ('open','close') AND group_id IS NOT NULL;
DROP TABLE message_templates;
ALTER TABLE message_templates_new RENAME TO message_templates;
CREATE UNIQUE INDEX templates_group_kind ON message_templates(group_id,kind) WHERE group_id IS NOT NULL;
CREATE UNIQUE INDEX templates_user_kind ON message_templates(user_id,kind) WHERE user_id IS NOT NULL;
