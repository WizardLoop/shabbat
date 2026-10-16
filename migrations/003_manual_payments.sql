-- SPDX-License-Identifier: AGPL-3.0-only
ALTER TABLE group_settings ADD COLUMN manual_override_mode TEXT NOT NULL DEFAULT 'none' CHECK(manual_override_mode IN ('none','lock','open'));
ALTER TABLE group_settings ADD COLUMN manual_override_until INTEGER CHECK(manual_override_until IS NULL OR (typeof(manual_override_until)='integer' AND manual_override_until>=0));
CREATE TABLE payment_intents (
    intent_id INTEGER PRIMARY KEY, user_id TEXT NOT NULL REFERENCES users(user_id),
    payload TEXT NOT NULL UNIQUE CHECK(length(payload) BETWEEN 1 AND 128), nonce TEXT NOT NULL UNIQUE,
    currency TEXT NOT NULL DEFAULT 'XTR' CHECK(currency='XTR'), amount INTEGER NOT NULL CHECK(amount IN (5,25,100,150,250,400)),
    language TEXT NOT NULL CHECK(language IN ('he','en')),
    status TEXT NOT NULL DEFAULT 'created' CHECK(status IN ('created','exported','precheckout_approved','paid','expired','failed')),
    created_at INTEGER NOT NULL, expires_at INTEGER NOT NULL CHECK(expires_at>created_at),
    invoice_url TEXT, precheckout_query_id TEXT UNIQUE, approved_at INTEGER, paid_at INTEGER, last_error TEXT
);
CREATE INDEX payment_intent_expiry ON payment_intents(status,expires_at);
CREATE TABLE donations (
    donation_id INTEGER PRIMARY KEY, intent_id INTEGER NOT NULL UNIQUE REFERENCES payment_intents(intent_id),
    user_id TEXT NOT NULL REFERENCES users(user_id), currency TEXT NOT NULL CHECK(currency='XTR'),
    amount INTEGER NOT NULL CHECK(amount IN (5,25,100,150,250,400)), charge_uid TEXT NOT NULL UNIQUE,
    received_at INTEGER NOT NULL, service_message_id INTEGER
);
