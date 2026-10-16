-- SPDX-License-Identifier: AGPL-3.0-only
-- Static six-language migration generated during development from audited schema 001-003.
-- Copy all typed fields, IDs, payment bindings and manual overrides before replacing tables.
CREATE TABLE group_settings_v4 (
    group_id TEXT PRIMARY KEY REFERENCES groups(group_id), revision INTEGER NOT NULL DEFAULT 1 CHECK(revision > 0),
    location_name TEXT NOT NULL DEFAULT 'Jerusalem', country TEXT NOT NULL DEFAULT 'IL', geoname_id INTEGER DEFAULT 281184,
    latitude REAL NOT NULL DEFAULT 31.76904 CHECK(latitude BETWEEN -90 AND 90), longitude REAL NOT NULL DEFAULT 35.21633 CHECK(longitude BETWEEN -180 AND 180),
    timezone_id TEXT NOT NULL DEFAULT 'Asia/Jerusalem', location_source TEXT NOT NULL DEFAULT 'default',
    israel INTEGER NOT NULL DEFAULT 1 CHECK(israel IN (0,1)), language TEXT NOT NULL DEFAULT 'he' CHECK(language IN ('he','en','ru','fr','es','pt-br')),
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
, manual_override_mode TEXT NOT NULL DEFAULT 'none' CHECK(manual_override_mode IN ('none','lock','open')), manual_override_until INTEGER CHECK(manual_override_until IS NULL OR (typeof(manual_override_until)='integer' AND manual_override_until>=0)));
CREATE TABLE user_preferences_v4 (
    user_id TEXT PRIMARY KEY REFERENCES users(user_id), revision INTEGER NOT NULL DEFAULT 1,
    location_name TEXT NOT NULL DEFAULT 'Jerusalem', country TEXT NOT NULL DEFAULT 'IL', geoname_id INTEGER DEFAULT 281184,
    latitude REAL NOT NULL DEFAULT 31.76904 CHECK(latitude BETWEEN -90 AND 90), longitude REAL NOT NULL DEFAULT 35.21633 CHECK(longitude BETWEEN -180 AND 180),
    timezone_id TEXT NOT NULL DEFAULT 'Asia/Jerusalem', location_source TEXT NOT NULL DEFAULT 'default',
    israel INTEGER NOT NULL DEFAULT 1 CHECK(israel IN (0,1)), language TEXT NOT NULL DEFAULT 'he' CHECK(language IN ('he','en','ru','fr','es','pt-br')),
    candle_minutes INTEGER DEFAULT NULL, havdalah_method TEXT NOT NULL DEFAULT 'degrees', havdalah_value REAL NOT NULL DEFAULT 8.5, updated_at INTEGER NOT NULL, imported_at INTEGER
);
CREATE TABLE payment_intents_v4 (
    intent_id INTEGER PRIMARY KEY, user_id TEXT NOT NULL REFERENCES users(user_id),
    payload TEXT NOT NULL UNIQUE CHECK(length(payload) BETWEEN 1 AND 128), nonce TEXT NOT NULL UNIQUE,
    currency TEXT NOT NULL DEFAULT 'XTR' CHECK(currency='XTR'), amount INTEGER NOT NULL CHECK(amount IN (5,25,100,150,250,400)),
    language TEXT NOT NULL CHECK(language IN ('he','en','ru','fr','es','pt-br')),
    status TEXT NOT NULL DEFAULT 'created' CHECK(status IN ('created','exported','precheckout_approved','paid','expired','failed')),
    created_at INTEGER NOT NULL, expires_at INTEGER NOT NULL CHECK(expires_at>created_at),
    invoice_url TEXT, precheckout_query_id TEXT UNIQUE, approved_at INTEGER, paid_at INTEGER, last_error TEXT
);
CREATE TABLE donations_v4 (
    donation_id INTEGER PRIMARY KEY, intent_id INTEGER NOT NULL UNIQUE REFERENCES payment_intents_v4(intent_id),
    user_id TEXT NOT NULL REFERENCES users(user_id), currency TEXT NOT NULL CHECK(currency='XTR'),
    amount INTEGER NOT NULL CHECK(amount IN (5,25,100,150,250,400)), charge_uid TEXT NOT NULL UNIQUE,
    received_at INTEGER NOT NULL, service_message_id INTEGER
);
INSERT INTO group_settings_v4("group_id","revision","location_name","country","geoname_id","latitude","longitude","timezone_id","location_source","israel","language","candle_minutes","havdalah_method","havdalah_value","close_before_candles_minutes","open_after_havdalah_minutes","mode","shabbat_enabled","holidays_enabled","times_alert_enabled","times_alert_time","times_alert_mode","times_alert_before_minutes","close_message_enabled","open_message_enabled","target_topic_id","zmanim_enabled","zmanim_time","zmanim_days","zmanim_format","daf_enabled","daf_time","daf_days","daf_template","tefillin_enabled","tefillin_days","tefillin_fixed_times","tefillin_interval_minutes","tefillin_window_start","tefillin_window_end","tefillin_sunset_offsets","tefillin_max_per_day","tefillin_min_spacing_minutes","quiet_start","quiet_end","tefillin_suspended_date","tefillin_chol_hamoed","tefillin_tisha_bav_policy","tefillin_sunset_safety_minutes","tefillin_template","omer_enabled","omer_time","omer_mode","omer_after_nightfall_minutes","omer_nightfall_method","omer_nightfall_value","omer_nusach","omer_format","omer_day_completion","omer_early_policy","omer_after_open_minutes","updated_at","updated_by","imported_at","manual_override_mode","manual_override_until") SELECT "group_id","revision","location_name","country","geoname_id","latitude","longitude","timezone_id","location_source","israel","language","candle_minutes","havdalah_method","havdalah_value","close_before_candles_minutes","open_after_havdalah_minutes","mode","shabbat_enabled","holidays_enabled","times_alert_enabled","times_alert_time","times_alert_mode","times_alert_before_minutes","close_message_enabled","open_message_enabled","target_topic_id","zmanim_enabled","zmanim_time","zmanim_days","zmanim_format","daf_enabled","daf_time","daf_days","daf_template","tefillin_enabled","tefillin_days","tefillin_fixed_times","tefillin_interval_minutes","tefillin_window_start","tefillin_window_end","tefillin_sunset_offsets","tefillin_max_per_day","tefillin_min_spacing_minutes","quiet_start","quiet_end","tefillin_suspended_date","tefillin_chol_hamoed","tefillin_tisha_bav_policy","tefillin_sunset_safety_minutes","tefillin_template","omer_enabled","omer_time","omer_mode","omer_after_nightfall_minutes","omer_nightfall_method","omer_nightfall_value","omer_nusach","omer_format","omer_day_completion","omer_early_policy","omer_after_open_minutes","updated_at","updated_by","imported_at","manual_override_mode","manual_override_until" FROM group_settings;
INSERT INTO user_preferences_v4("user_id","revision","location_name","country","geoname_id","latitude","longitude","timezone_id","location_source","israel","language","candle_minutes","havdalah_method","havdalah_value","updated_at","imported_at") SELECT "user_id","revision","location_name","country","geoname_id","latitude","longitude","timezone_id","location_source","israel","language","candle_minutes","havdalah_method","havdalah_value","updated_at","imported_at" FROM user_preferences;
INSERT INTO payment_intents_v4("intent_id","user_id","payload","nonce","currency","amount","language","status","created_at","expires_at","invoice_url","precheckout_query_id","approved_at","paid_at","last_error") SELECT "intent_id","user_id","payload","nonce","currency","amount","language","status","created_at","expires_at","invoice_url","precheckout_query_id","approved_at","paid_at","last_error" FROM payment_intents;
INSERT INTO donations_v4("donation_id","intent_id","user_id","currency","amount","charge_uid","received_at","service_message_id") SELECT "donation_id","intent_id","user_id","currency","amount","charge_uid","received_at","service_message_id" FROM donations;
DROP TABLE donations;
DROP TABLE payment_intents;
DROP TABLE group_settings;
DROP TABLE user_preferences;
ALTER TABLE group_settings_v4 RENAME TO group_settings;
ALTER TABLE user_preferences_v4 RENAME TO user_preferences;
ALTER TABLE payment_intents_v4 RENAME TO payment_intents;
ALTER TABLE donations_v4 RENAME TO donations;
CREATE INDEX payment_intent_expiry ON payment_intents(status,expires_at);
