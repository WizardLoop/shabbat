-- SPDX-License-Identifier: AGPL-3.0-only
-- Application-owned administration state. BroadcastManager's independent
-- delivery backend is not converted by this additive migration.
CREATE TABLE admin_state (
    user_id TEXT NOT NULL,
    state_key TEXT NOT NULL CHECK(state_key IN (
        'grs1.txt','messagetodelete.txt','txt.txt','ent.txt','media.txt',
        'BUTTONS.txt','broadcastsend.txt','pinmessage.txt',
        'startline_cat.txt','endline_cat.txt','page_var.txt','ids.txt','idsnew.txt'
    )),
    value_kind TEXT NOT NULL CHECK(value_kind IN ('text','json','media','ids')),
    value_text TEXT NOT NULL CHECK(length(value_text) <= 1048576),
    updated_at INTEGER NOT NULL,
    PRIMARY KEY(user_id,state_key)
);
CREATE INDEX admin_state_updated ON admin_state(updated_at);
CREATE TABLE admin_directories (
    user_id TEXT PRIMARY KEY,
    created_at INTEGER NOT NULL
);
