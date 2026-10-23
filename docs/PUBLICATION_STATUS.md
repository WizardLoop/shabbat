# Publication and release status

## 3.1.1 source and release requirements

Version 3.1.1 is being prepared in a reviewed PR. The final tag and stable assets must follow the verified merge commit; no pending branch artifact is the final release. This patch fixes Daf HTML escaping, shares the private/scheduled card formatter and conservatively handles eligible prepared retries. Composer dependencies and database schema are unchanged. Docker includes pinned igbinary 3.2.16 for existing serialized sessions and checks synthetic serialization before startup.

The application retains MadelineProto 8.11.10 at the exact commit in `composer.lock`.
Only the rebased Fresh Peer patch and the reviewed BroadcastManager patch remain
active. Date/location compatibility now uses official SDK implementations;
inline keyboards are normalized at the transport boundary for TL Layer 232.

Publication requires mandatory PHP, static-analysis, source-inventory, packaging,
Docker and shutdown compatibility checks to pass on the exact final commit.
The matching GitHub Actions run and release manifest identify the tested source
and artifacts. Historical results from another version do not replace those
checks; a stable tag or release must not bypass a failed mandatory gate.

The runtime baseline requires PHP **8.3.36+, 8.4.27+ or 8.5.12+** within those
branches; Docker pins **8.4.27**. The [dated security baseline](INSTALLATION.md#php-security-baseline)
records the upstream fixes reviewed on 22 October 2026. Earlier checks on an older
interpreter are compatibility evidence, not approval of that deployment runtime.

Shutdown coverage combines the official loop contract with application-owned
writer exclusion through SDK disconnect/finalization. Loop `stop()` prevents
rescheduling after the current body returns. Offline fixtures use an in-memory
transport and modeled flush; they do not establish real session serialization,
Telegram traffic, IPC-daemon shutdown or Linux-signal acceptance.

## Archives and source scope

The release packager reads a clean committed Git tree and verifies exact locked
runtime sources plus preferred-source supplements before producing
`shabbat-3.1.1.zip`, `shabbat-3.1.1-corresponding-source.zip`, `SHA256SUMS`
and `RELEASE_MANIFEST.json`. Archive `build-info.json` records the exact commit
and source fingerprint. No real configuration, session, database or log belongs
in an archive. Unpublished archives are for review and isolated testing; release
assets must correspond to the final commit whose mandatory gates passed.

The source archive contains application source, active patch baselines/diffs,
locked runtime dependency sources and their original licenses, plus the pinned
supplements listed in `resources/release/source-manifest.json`. Development
binary tools and full operating-system/container source trees are outside its
scope. See [licensing](LICENSING.md), [third-party notices](../THIRD_PARTY_NOTICES.md)
and the source manifest for exact inclusions, exclusions and provenance limits.

## Deployment boundary

Publication does not deploy or restart a bot. No live Telegram acceptance or
production migration is performed by CI. The maintainer must first test the new
release with a separate bot, then verify the actual production path before
using the [Hebrew cutover guide](PRODUCTION_UPGRADE_HE.md) or
[English cutover guide](PRODUCTION_UPGRADE.md). Historical 3.0.0 source and its
retired patch provenance remain in that version's Git history and release.
