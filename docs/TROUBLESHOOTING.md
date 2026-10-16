# Troubleshooting

* **A previous week's Shabbat appears:** verify provider event dates and cache coverage in group status. Runtime decisions use explicit ranges and UTC snapshots, not `state_open.txt`.
* **A group remained locked:** inspect the stored lock status, snapshot checksum and last error. Reconciliation retries based on current desired state beyond six minutes. A manual permission conflict requires administrator resolution; never reset by enabling all permissions or deleting the snapshot.
* **SQLite locked:** ensure only one runtime writer is active, and maintenance uses the same writer-lock path. Transactions are short; avoid running old and new bots against the same application state.
* **Invalid/missing timezone:** choose an IANA identifier in the location panel; changing the zone does not change latitude/longitude. Missing DST hours are skipped.
* **Inline disabled:** BotFather `/setinline` must be enabled. The private `/city TOKEN query` alternative still works. GPS permission is not needed for name search.
* **GeoNames unavailable:** set the operator's username and enable webservices. HTTP 200 status errors include account/quota failures. Known cities or explicit coordinates remain available.
* **Patch verification fails:** check the exact locked MadelineProto source hash. Do not edit vendor using a broad replacement, skip verification, or apply during runtime. Unknown/partial sources deliberately stop the build.
* **Health failed:** inspect scheduler heartbeat age and database integrity, not only whether PHP exists. Use `/testzmanim` and group status for current calendar state.
* **Unpublished development source:** configure SOURCE_URL only once the exact source package is available. No upstream link is represented as the source of local unpublished changes.

No maintenance command in this task was run against production or Telegram credentials.
