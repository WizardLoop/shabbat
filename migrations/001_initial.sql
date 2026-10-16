-- SPDX-License-Identifier: AGPL-3.0-only
CREATE TABLE groups (
    group_id TEXT PRIMARY KEY CHECK(length(group_id) BETWEEN 1 AND 24),
    title TEXT NOT NULL DEFAULT '', active INTEGER NOT NULL DEFAULT 1 CHECK(active IN (0,1)),
    created_at INTEGER NOT NULL, updated_at INTEGER NOT NULL
);
CREATE TABLE locations (
    location_id INTEGER PRIMARY KEY, geoname_id INTEGER UNIQUE, display_name TEXT NOT NULL,
    country TEXT NOT NULL DEFAULT '', administrative_area TEXT NOT NULL DEFAULT '',
    latitude REAL NOT NULL CHECK(latitude BETWEEN -90 AND 90), longitude REAL NOT NULL CHECK(longitude BETWEEN -180 AND 180),
    timezone_id TEXT NOT NULL, source TEXT NOT NULL, verified_at INTEGER
);
CREATE TABLE group_settings (
    group_id TEXT PRIMARY KEY REFERENCES groups(group_id), revision INTEGER NOT NULL DEFAULT 1 CHECK(revision > 0),
    location_name TEXT NOT NULL DEFAULT 'Jerusalem', country TEXT NOT NULL DEFAULT 'IL', geoname_id INTEGER DEFAULT 281184,
    latitude REAL NOT NULL DEFAULT 31.76904 CHECK(latitude BETWEEN -90 AND 90), longitude REAL NOT NULL DEFAULT 35.21633 CHECK(longitude BETWEEN -180 AND 180),
    timezone_id TEXT NOT NULL DEFAULT 'Asia/Jerusalem', location_source TEXT NOT NULL DEFAULT 'default',
    israel INTEGER NOT NULL DEFAULT 1 CHECK(israel IN (0,1)), language TEXT NOT NULL DEFAULT 'he' CHECK(language IN ('he','en')),
    candle_minutes INTEGER DEFAULT NULL CHECK(candle_minutes BETWEEN 1 AND 120),
    havdalah_method TEXT NOT NULL DEFAULT 'degrees' CHECK(havdalah_method IN ('degrees','minutes')),
    havdalah_value REAL NOT NULL DEFAULT 8.5 CHECK(havdalah_value > 0 AND havdalah_value <= 120),
    close_before_candles_minutes INTEGER NOT NULL DEFAULT 0 CHECK(close_before_candles_minutes BETWEEN 0 AND 180),
    open_after_havdalah_minutes INTEGER NOT NULL DEFAULT 0 CHECK(open_after_havdalah_minutes BETWEEN 0 AND 180),
    mode TEXT NOT NULL DEFAULT 'lock' CHECK(mode IN ('off','messages','lock','lock_messages')),
    shabbat_enabled INTEGER NOT NULL DEFAULT 1 CHECK(shabbat_enabled IN (0,1)), holidays_enabled INTEGER NOT NULL DEFAULT 1 CHECK(holidays_enabled IN (0,1)),
    times_alert_enabled INTEGER NOT NULL DEFAULT 0 CHECK(times_alert_enabled IN (0,1)), times_alert_time TEXT NOT NULL DEFAULT '13:30',
    times_alert_mode TEXT NOT NULL DEFAULT 'local_time' CHECK(times_alert_mode IN ('local_time','before_close')),
    times_alert_before_minutes INTEGER NOT NULL DEFAULT 30 CHECK(times_alert_before_minutes BETWEEN 0 AND 1440),
    close_message_enabled INTEGER NOT NULL DEFAULT 0 CHECK(close_message_enabled IN (0,1)), open_message_enabled INTEGER NOT NULL DEFAULT 0 CHECK(open_message_enabled IN (0,1)),
    target_topic_id INTEGER, zmanim_enabled INTEGER NOT NULL DEFAULT 0 CHECK(zmanim_enabled IN (0,1)), zmanim_time TEXT NOT NULL DEFAULT '08:00',
    zmanim_days TEXT NOT NULL DEFAULT '[0,1,2,3,4,5]', zmanim_format TEXT NOT NULL DEFAULT 'short',
    daf_enabled INTEGER NOT NULL DEFAULT 0 CHECK(daf_enabled IN (0,1)), daf_time TEXT NOT NULL DEFAULT '08:00', daf_days TEXT NOT NULL DEFAULT '[0,1,2,3,4,5]', daf_template TEXT NOT NULL DEFAULT '',
    tefillin_enabled INTEGER NOT NULL DEFAULT 0 CHECK(tefillin_enabled IN (0,1)), tefillin_days TEXT NOT NULL DEFAULT '[0,1,2,3,4,5]',
    tefillin_fixed_times TEXT NOT NULL DEFAULT '["09:00"]', tefillin_interval_minutes INTEGER DEFAULT NULL CHECK(tefillin_interval_minutes >= 5),
    tefillin_window_start TEXT NOT NULL DEFAULT '08:00', tefillin_window_end TEXT NOT NULL DEFAULT '17:00', tefillin_sunset_offsets TEXT NOT NULL DEFAULT '[60,30,15]',
    tefillin_max_per_day INTEGER NOT NULL DEFAULT 3 CHECK(tefillin_max_per_day BETWEEN 1 AND 24),
    tefillin_min_spacing_minutes INTEGER NOT NULL DEFAULT 30 CHECK(tefillin_min_spacing_minutes >= 5),
    quiet_start TEXT DEFAULT NULL, quiet_end TEXT DEFAULT NULL, tefillin_suspended_date TEXT DEFAULT NULL,
    tefillin_chol_hamoed INTEGER NOT NULL DEFAULT 0 CHECK(tefillin_chol_hamoed IN (0,1)),
    tefillin_tisha_bav_policy TEXT NOT NULL DEFAULT 'off' CHECK(tefillin_tisha_bav_policy IN ('off','mincha')),
    tefillin_sunset_safety_minutes INTEGER NOT NULL DEFAULT 10 CHECK(tefillin_sunset_safety_minutes BETWEEN 1 AND 120), tefillin_template TEXT NOT NULL DEFAULT '',
    omer_enabled INTEGER NOT NULL DEFAULT 0 CHECK(omer_enabled IN (0,1)), omer_time TEXT NOT NULL DEFAULT '21:00',
    omer_mode TEXT NOT NULL DEFAULT 'fixed' CHECK(omer_mode IN ('fixed','nightfall')),
    omer_after_nightfall_minutes INTEGER NOT NULL DEFAULT 15 CHECK(omer_after_nightfall_minutes BETWEEN 0 AND 240),
    omer_nightfall_method TEXT NOT NULL DEFAULT 'degrees' CHECK(omer_nightfall_method IN ('degrees','minutes')), omer_nightfall_value REAL NOT NULL DEFAULT 8.5,
    omer_nusach TEXT NOT NULL DEFAULT 'ashkenaz', omer_format TEXT NOT NULL DEFAULT 'short' CHECK(omer_format IN ('short','full')),
    omer_day_completion INTEGER NOT NULL DEFAULT 0 CHECK(omer_day_completion IN (0,1)), omer_early_policy TEXT NOT NULL DEFAULT 'defer' CHECK(omer_early_policy IN ('defer','skip')),
    omer_after_open_minutes INTEGER NOT NULL DEFAULT 5 CHECK(omer_after_open_minutes BETWEEN 0 AND 180),
    updated_at INTEGER NOT NULL, updated_by TEXT, imported_at INTEGER
);
CREATE TABLE users (user_id TEXT PRIMARY KEY, created_at INTEGER NOT NULL, updated_at INTEGER NOT NULL);
CREATE TABLE user_preferences (
    user_id TEXT PRIMARY KEY REFERENCES users(user_id), revision INTEGER NOT NULL DEFAULT 1,
    location_name TEXT NOT NULL DEFAULT 'Jerusalem', country TEXT NOT NULL DEFAULT 'IL', geoname_id INTEGER DEFAULT 281184,
    latitude REAL NOT NULL DEFAULT 31.76904 CHECK(latitude BETWEEN -90 AND 90), longitude REAL NOT NULL DEFAULT 35.21633 CHECK(longitude BETWEEN -180 AND 180),
    timezone_id TEXT NOT NULL DEFAULT 'Asia/Jerusalem', location_source TEXT NOT NULL DEFAULT 'default',
    israel INTEGER NOT NULL DEFAULT 1 CHECK(israel IN (0,1)), language TEXT NOT NULL DEFAULT 'he' CHECK(language IN ('he','en')),
    candle_minutes INTEGER DEFAULT NULL, havdalah_method TEXT NOT NULL DEFAULT 'degrees', havdalah_value REAL NOT NULL DEFAULT 8.5, updated_at INTEGER NOT NULL, imported_at INTEGER
);
CREATE TABLE message_templates (
    template_id INTEGER PRIMARY KEY, group_id TEXT REFERENCES groups(group_id), user_id TEXT REFERENCES users(user_id),
    kind TEXT NOT NULL CHECK(kind IN ('open','close','zmanim','daf','tefillin','omer','broadcast')),
    text TEXT NOT NULL DEFAULT '', entities_json TEXT NOT NULL DEFAULT '[]', media_json TEXT, buttons_json TEXT NOT NULL DEFAULT '[]',
    revision INTEGER NOT NULL DEFAULT 1, updated_at INTEGER NOT NULL, imported_at INTEGER, CHECK((group_id IS NULL) != (user_id IS NULL))
);
CREATE UNIQUE INDEX templates_group_kind ON message_templates(group_id,kind) WHERE group_id IS NOT NULL;
CREATE UNIQUE INDEX templates_user_kind ON message_templates(user_id,kind) WHERE user_id IS NOT NULL;
CREATE TABLE notification_rules (
    rule_id INTEGER PRIMARY KEY, group_id TEXT NOT NULL REFERENCES groups(group_id), module TEXT NOT NULL,
    enabled INTEGER NOT NULL DEFAULT 0 CHECK(enabled IN (0,1)), kind TEXT NOT NULL CHECK(kind IN ('fixed','interval','relative')),
    local_time TEXT, interval_minutes INTEGER, reference TEXT, offset_minutes INTEGER, days_json TEXT NOT NULL DEFAULT '[0,1,2,3,4,5]',
    settings_revision INTEGER NOT NULL, UNIQUE(group_id,module,kind,local_time,reference,offset_minutes)
);
CREATE TABLE calendar_cache (
    cache_key TEXT PRIMARY KEY, provider TEXT NOT NULL, payload_json TEXT NOT NULL, fetched_at INTEGER NOT NULL, expires_at INTEGER NOT NULL, coverage_start TEXT, coverage_end TEXT
);
CREATE TABLE scheduled_jobs (
    job_id INTEGER PRIMARY KEY, group_id TEXT NOT NULL REFERENCES groups(group_id), module TEXT NOT NULL, event_key TEXT NOT NULL,
    run_at INTEGER NOT NULL, expires_at INTEGER NOT NULL CHECK(expires_at >= run_at), settings_revision INTEGER NOT NULL,
    payload_json TEXT NOT NULL, payload_prepared_at INTEGER, random_id TEXT NOT NULL, priority INTEGER NOT NULL DEFAULT 100,
    status TEXT NOT NULL DEFAULT 'pending' CHECK(status IN ('pending','leased','sent','cancelled','expired','failed')),
    attempts INTEGER NOT NULL DEFAULT 0, retry_at INTEGER, lease_owner TEXT, lease_until INTEGER, last_error TEXT, created_at INTEGER NOT NULL, completed_at INTEGER,
    UNIQUE(group_id,module,event_key), UNIQUE(random_id)
);
CREATE INDEX jobs_due ON scheduled_jobs(status,priority,retry_at,run_at,lease_until);
CREATE TABLE group_lock_state (
    group_id TEXT PRIMARY KEY REFERENCES groups(group_id), event_key TEXT NOT NULL,
    status TEXT NOT NULL CHECK(status IN ('prepared','locked','restoring','restored','prelocked','recovery_required','conflict','error')),
    snapshot_json TEXT, applied_json TEXT, snapshot_sha256 TEXT, prepared_at INTEGER NOT NULL, locked_at INTEGER, restored_at INTEGER,
    last_error TEXT, lease_owner TEXT, lease_until INTEGER, revision INTEGER NOT NULL DEFAULT 1
);
CREATE TABLE delivery_log (
    delivery_id INTEGER PRIMARY KEY, job_id INTEGER NOT NULL REFERENCES scheduled_jobs(job_id), attempt INTEGER NOT NULL, random_id TEXT NOT NULL,
    started_at INTEGER NOT NULL, finished_at INTEGER, outcome TEXT NOT NULL CHECK(outcome IN ('attempting','sent','retry','failed','uncertain')),
    response_json TEXT, error TEXT, UNIQUE(job_id,attempt)
);
CREATE TABLE conversation_states (
    token_hash TEXT PRIMARY KEY, user_id TEXT NOT NULL REFERENCES users(user_id), scope TEXT NOT NULL CHECK(scope IN ('user','group','share')),
    group_id TEXT REFERENCES groups(group_id), settings_revision INTEGER NOT NULL, action TEXT NOT NULL, payload_json TEXT NOT NULL DEFAULT '{}',
    created_at INTEGER NOT NULL, expires_at INTEGER NOT NULL, consumed_at INTEGER, revoked_at INTEGER,
    CHECK((scope='user' AND group_id IS NULL) OR (scope IN ('group','share') AND group_id IS NOT NULL))
);
CREATE INDEX conversation_expiry ON conversation_states(expires_at,consumed_at);
CREATE TABLE audit_log (
    audit_id INTEGER PRIMARY KEY, actor_id TEXT, group_id TEXT REFERENCES groups(group_id), action TEXT NOT NULL, details_json TEXT NOT NULL, created_at INTEGER NOT NULL
);
CREATE TABLE legacy_imports (source_path TEXT PRIMARY KEY, sha256 TEXT NOT NULL, imported_at INTEGER NOT NULL, details_json TEXT NOT NULL);
