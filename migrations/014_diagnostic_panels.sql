-- SPDX-License-Identifier: AGPL-3.0-only
CREATE TABLE diagnostic_panels (
    viewer_id TEXT PRIMARY KEY REFERENCES users(user_id),
    token TEXT NOT NULL UNIQUE CHECK(length(token)=24),
    message_id INTEGER,
    group_id TEXT REFERENCES groups(group_id),
    report_json TEXT NOT NULL DEFAULT '{}',
    page INTEGER NOT NULL DEFAULT 0,
    category TEXT NOT NULL DEFAULT 'all',
    search TEXT NOT NULL DEFAULT '',
    prompt TEXT,
    created_at INTEGER NOT NULL,
    expires_at INTEGER NOT NULL,
    busy_until INTEGER NOT NULL DEFAULT 0,
    busy_owner TEXT,
    export_queries_json TEXT NOT NULL DEFAULT '[]',
    export_at INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX diagnostic_panels_expiry ON diagnostic_panels(expires_at);
