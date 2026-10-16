-- SPDX-License-Identifier: AGPL-3.0-only
-- Known delivery audiences are separate from opt-in Shabbat group registration.
CREATE TABLE known_broadcast_peers (
    peer_id TEXT PRIMARY KEY CHECK(length(peer_id) BETWEEN 1 AND 24),
    peer_type TEXT NOT NULL DEFAULT 'unknown' CHECK(peer_type IN ('unknown','user','bot','chat','supergroup','channel')),
    title TEXT NOT NULL DEFAULT '',
    created_at INTEGER NOT NULL, updated_at INTEGER NOT NULL, imported_at INTEGER
);
CREATE INDEX known_broadcast_peer_type ON known_broadcast_peers(peer_type,peer_id);
