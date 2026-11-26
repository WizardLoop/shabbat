# Update an existing SQLite installation to 3.2.0

Use Bash as root on the Linux Docker host. The production installation previously identified in this project runs from /root/shabat, under Compose project shabbat-production-310 with service shabbat. Keep its actual project name. For another installation, pass its own directory and project name. Schedule the brief stop away from a closing or opening transition.

Download and verify the two assets before extracting or executing the upgrade script. Run these commands on the host:

~~~bash
(
  set -eu
  umask 077
  assets=$(mktemp -d /root/shabbat-upload-320.XXXXXX)
  cd "$assets"
  url=https://github.com/WizardLoop/shabbat/releases/download/v3.2.0
  curl -fL "$url/shabbat-3.2.0.zip" -o shabbat-3.2.0.zip
  curl -fL "$url/SHA256SUMS" -o SHA256SUMS
  awk '$2 == "shabbat-3.2.0.zip" && NF == 2 && length($1) == 64 && $1 !~ /[^0-9a-f]/ { print; n++ } END { if (n != 1) exit 1 }' SHA256SUMS > deployment.sha256
  sha256sum -c deployment.sha256
  unzip -p shabbat-3.2.0.zip scripts/upgrade-compose-3.2.0.sh > upgrade.sh
  bash upgrade.sh /root/shabat shabbat-production-310 "$assets"
)
~~~

Alternatively, upload shabbat-3.2.0.zip and SHA256SUMS from the release to a private directory on the server and run the verification, extraction and Bash lines above from that directory using its absolute path for the third argument.

The script checks the current service and its two named volumes, preserves the old image, copies your private configuration into fresh source, sets the matching SOURCE_URL, and builds before stopping the old process. With writers stopped it backs up data, session and library state, verifies the archive, runs the application backup/migration and offline checks, and starts the replacement on those same volumes. It prints the backup path. Keep this private backup and the old image. Existing SQLite groups do not require legacy import.

After the first scheduler cycles, run:

~~~bash
cd /root/shabat
docker compose -p shabbat-production-310 ps
docker compose -p shabbat-production-310 logs --tail=80 shabbat
docker compose -p shabbat-production-310 exec -T shabbat php bin/health.php --deep
~~~

Confirm healthy=true and version 3.2.0, then inspect /source privately. Verify /settings and a Shabbat and holiday template in a test group. If a step fails after stopping the bot, use the printed backup path to inspect both the old image and the saved state before restart. For recovery, use the [backup and restore guide](BACKUP_RESTORE.md); take only one writer online. Telegram actions already accepted cannot be undone by restoring SQLite.
