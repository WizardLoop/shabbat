-- SPDX-License-Identifier: AGPL-3.0-only
-- Run under Database::migrate's transaction with foreign_keys enabled.
-- Copy both sides before dropping the old FK child, then its parent.
CREATE TABLE payment_intents_v11 (
    intent_id INTEGER PRIMARY KEY, user_id TEXT NOT NULL REFERENCES users(user_id),
    payload TEXT NOT NULL UNIQUE CHECK(length(payload) BETWEEN 1 AND 128), nonce TEXT NOT NULL UNIQUE,
    currency TEXT NOT NULL DEFAULT 'XTR' CHECK(currency='XTR'),
    amount INTEGER NOT NULL CHECK(typeof(amount)='integer' AND amount BETWEEN 1 AND 10000),
    language TEXT NOT NULL CHECK(language IN ('he','en','ru','fr','es','pt-br')),
    status TEXT NOT NULL DEFAULT 'created' CHECK(status IN ('created','exported','precheckout_approved','paid','expired','failed')),
    created_at INTEGER NOT NULL, expires_at INTEGER NOT NULL CHECK(expires_at>created_at),
    invoice_url TEXT, precheckout_query_id TEXT UNIQUE, approved_at INTEGER, paid_at INTEGER, last_error TEXT
);
CREATE TABLE donations_v11 (
    donation_id INTEGER PRIMARY KEY, intent_id INTEGER NOT NULL UNIQUE REFERENCES payment_intents_v11(intent_id),
    user_id TEXT NOT NULL REFERENCES users(user_id), currency TEXT NOT NULL CHECK(currency='XTR'),
    amount INTEGER NOT NULL CHECK(typeof(amount)='integer' AND amount BETWEEN 1 AND 10000),
    charge_uid TEXT NOT NULL UNIQUE, received_at INTEGER NOT NULL, service_message_id INTEGER
);
INSERT INTO payment_intents_v11(intent_id,user_id,payload,nonce,currency,amount,language,status,created_at,expires_at,invoice_url,precheckout_query_id,approved_at,paid_at,last_error)
 SELECT intent_id,user_id,payload,nonce,currency,amount,language,status,created_at,expires_at,invoice_url,precheckout_query_id,approved_at,paid_at,last_error FROM payment_intents;
INSERT INTO donations_v11(donation_id,intent_id,user_id,currency,amount,charge_uid,received_at,service_message_id)
 SELECT donation_id,intent_id,user_id,currency,amount,charge_uid,received_at,service_message_id FROM donations;
DROP TABLE donations;
DROP TABLE payment_intents;
ALTER TABLE payment_intents_v11 RENAME TO payment_intents;
ALTER TABLE donations_v11 RENAME TO donations;
CREATE INDEX payment_intent_expiry ON payment_intents(status,expires_at);
