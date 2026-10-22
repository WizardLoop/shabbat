<div dir="rtl">

# שדרוג בטוח ל־3.1.0

[English](PRODUCTION_UPGRADE.md) · [README בעברית](../readme-he.md) · [מיפוי הייבוא](MIGRATION.md) · [התאוששות הרשאות](PERMISSION_RECOVERY.md)

זהו מדריך לביצוע ידני; לא בוצעה פריסה. השתמשו בקובצי 3.1.0 שפורסמו ואומתו לאחר שבדיקות החובה עברו. הנתיב המבוקש הוא **/root/shabbat**; בתיעוד קודם הופיע **/root/shabat**. חובה לאמת את הקונטיינר והתיקייה האמיתיים לפני שינוי. אין להשתמש בנתוני בוט הבדיקה.

הפקודות תומכות בהתקנת legacy שמחברת את כל הפרויקט ובאמצעי האחסון הרגילים של 3.0. בדיקת הנתיבים ב־3.0 קוראת תצורה בלבד; היא אינה פותחת API או סשן. שם סשן מותאם או שרידי נעילה ללא תוכן סשן עוצרים את ההליך. חיבורים מותאמים, אחסון חיצוני, קישורים לנתונים פרטיים או כותב נוסף מחייבים גיבוי מותאם מראש. הריצו את בלוקי השרת באותו **חלון Bash כ־root**, עם Docker/Compose,‏ unzip, כלי GNU ומקום פנוי מספיק. הגיבויים נשמרים מחוץ לקוד ולבנייה. רק ההפעלה בסוף מחברת את הבוט החדש ל־Telegram.

## 1. העלאה מהמחשב

הורידו את ארכיון הפריסה ואת SHA256SUMS משחרור GitHub המתאים. במסוף במחשב החליפו SERVER בשם השרת או בכתובת ה־IP. אפשר להעלות את אותם קבצים גם ב־SFTP לאותו יעד פרטי.

<div dir="ltr">

~~~sh
ssh root@SERVER "install -d -m 700 /root/shabbat-upload-3.1.0"
scp shabbat-3.1.0.zip SHA256SUMS root@SERVER:/root/shabbat-upload-3.1.0/
~~~

</div>

## 2. אימות ההתקנה והנתיבים

בשרת הציגו את הקונטיינרים ובחרו את קונטיינר הייצור. החליפו את מזהה הדוגמה ושנו את ORIGINAL_DIR ל־/root/shabat **רק אם זה הנתיב האמיתי שאומת**. הבדיקות עוצרות בנתיב או במבנה אחר. בדקו את החיבורים המוצגים; סודות מהסביבה אינם מודפסים.

<div dir="ltr">

~~~sh
set -euo pipefail
umask 077
docker ps -a --filter label=com.docker.compose.service=shabbat \
  --format '{{.ID}}  {{.Names}}  {{.Status}}'
ORIGINAL_DIR=/root/shabbat
OLD_CONTAINER_ID=REPLACE_WITH_VERIFIED_PRODUCTION_CONTAINER_ID
docker inspect --format '{{range .Mounts}}{{println .Type .Source "->" .Destination}}{{end}}' "$OLD_CONTAINER_ID"
docker inspect --format '{{index .Config.Labels "com.docker.compose.project.working_dir"}}' "$OLD_CONTAINER_ID"
case "$ORIGINAL_DIR" in /root/shabbat|/root/shabat) ;; *) exit 1 ;; esac
test "$(readlink -f -- "$ORIGINAL_DIR")" = "$ORIGINAL_DIR"
test "$(docker inspect --format '{{index .Config.Labels "com.docker.compose.project.working_dir"}}' "$OLD_CONTAINER_ID")" = "$ORIGINAL_DIR"
test "$(docker inspect --format '{{index .Config.Labels "com.docker.compose.service"}}' "$OLD_CONTAINER_ID")" = shabbat
test -f "$ORIGINAL_DIR/.env"
test -f "$ORIGINAL_DIR/docker-compose.yml"
OLD_PROJECT="$(docker inspect --format '{{index .Config.Labels "com.docker.compose.project"}}' "$OLD_CONTAINER_ID")"
OLD_IMAGE_ID="$(docker inspect --format '{{.Image}}' "$OLD_CONTAINER_ID")"
OLD_RESTART_POLICY="$(docker inspect --format '{{.HostConfig.RestartPolicy.Name}}' "$OLD_CONTAINER_ID")"
case "$OLD_RESTART_POLICY" in always|unless-stopped|no) ;; *) echo 'Review nonstandard restart policy first.' >&2; exit 1 ;; esac
APP_MOUNT="$(docker inspect --format '{{range .Mounts}}{{if eq .Destination "/app"}}{{.Type}}:{{.Source}}{{end}}{{end}}' "$OLD_CONTAINER_ID")"
MOUNT_DESTINATIONS="$(docker inspect --format '{{range .Mounts}}{{println .Destination}}{{end}}' "$OLD_CONTAINER_ID" | sed '/^$/d' | sort)"
if [ "$APP_MOUNT" = "bind:$ORIGINAL_DIR" ] && [ "$MOUNT_DESTINATIONS" = /app ]; then
  LAYOUT=legacy
  test -s "$ORIGINAL_DIR/data/DBgroups.txt"
  test ! -e "$ORIGINAL_DIR/data/shabbat.sqlite"
elif [ -z "$APP_MOUNT" ] && [ "$MOUNT_DESTINATIONS" = "$(printf '/app/data\n/app/runtime')" ]; then
  LAYOUT=sqlite
  test "$(docker inspect --format '{{range .Mounts}}{{if eq .Destination "/app/data"}}{{.Type}}{{end}}{{end}}' "$OLD_CONTAINER_ID")" = volume
  test "$(docker inspect --format '{{range .Mounts}}{{if eq .Destination "/app/runtime"}}{{.Type}}{{end}}{{end}}' "$OLD_CONTAINER_ID")" = volume
  test "$(cat "$ORIGINAL_DIR/VERSION")" = 3.0.0
  docker exec "$OLD_CONTAINER_ID" php -r '
  require "/app/vendor/autoload.php";
  $paths=Shabbat\Runtime\RuntimePaths::resolve("/app");
  if($paths->dataDirectory!=="/app/data"
    || $paths->databasePath!=="/app/data/shabbat.sqlite"
    || $paths->sessionPath!=="/app/runtime/bot.shabbat") {
    throw new RuntimeException("Custom active data/session paths need a reviewed adaptation.");
  }
  '
else
  echo 'STOP: custom storage layout needs a reviewed backup/cutover.' >&2
  exit 1
fi
VERSION=3.1.0
NEW_PROJECT=shabbat-production-310
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
UPLOAD_DIR=/root/shabbat-upload-3.1.0
RELEASE_DIR="/root/shabbat-stage-$VERSION-$STAMP"
BACKUP_DIR="/root/shabbat-backups/$VERSION-$STAMP"
RETIRED_DIR="$ORIGINAL_DIR.before-$VERSION-$STAMP"
test -n "$OLD_PROJECT"
test "$OLD_PROJECT" != "$NEW_PROJECT"
test ! -e "$RELEASE_DIR"
test ! -e "$BACKUP_DIR"
test ! -e "$RETIRED_DIR"
df -h /root
docker system df
install -d -m 700 "$BACKUP_DIR" "$RELEASE_DIR"
printf '%s\n' "$ORIGINAL_DIR" "$OLD_CONTAINER_ID" "$OLD_PROJECT" "$OLD_IMAGE_ID" "$OLD_RESTART_POLICY" "$LAYOUT" > "$BACKUP_DIR/old-installation.txt"
~~~

</div>

## 3. אימות, הגדרה ובנייה ב־staging

אימות הקובץ חייב לעבור. שמרו את build-info.json שבארכיון. ערכו את .env החדש בפרטיות: העתיקו את API_ID/API_HASH/BOT_TOKEN של אותו בוט ייצור ואת המנהלים המהימנים; שמרו הגדרות יישום מותאמות רלוונטיות והגדירו GeoNames לפי הצורך; נתיבי הקונטיינר נקבעים בהמשך. אין להדפיס סודות או להעתיק vendor מותקן. השאירו את BUILD_COMMIT מחוץ ל־.env.

<div dir="ltr">

~~~sh
(
  cd "$UPLOAD_DIR"
  awk '$2 == "shabbat-3.1.0.zip" {
    if (NF != 2 || length($1) != 64 || $1 ~ /[^0-9a-f]/) exit 1
    print; n++
  } END { if (n != 1) exit 1 }' SHA256SUMS > deployment.sha256
  sha256sum --check deployment.sha256
)
unzip -q "$UPLOAD_DIR/shabbat-3.1.0.zip" -d "$RELEASE_DIR"
test "$(cat "$RELEASE_DIR/VERSION")" = "$VERSION"
test -f "$RELEASE_DIR/build-info.json"
cp "$RELEASE_DIR/.env.example" "$RELEASE_DIR/.env"
chmod 600 "$RELEASE_DIR/.env"
~~~

</div>

הגדירו ב־.env החדש את נתיבי הקונטיינר הציבוריים האלה לצד פרטי ההתחברות הפרטיים:

<div dir="ltr">

~~~dotenv
APP_DB_PATH=/app/data/shabbat.sqlite
DATA_DIR=/app/data
SESSION_PATH=/app/runtime/bot.shabbat
HEARTBEAT_PATH=/app/data/scheduler-heartbeat.json
LEGACY_DATA_PATH=.
SOURCE_URL=https://github.com/WizardLoop/shabbat/releases/tag/v3.1.0
~~~

</div>

לאחר העריכה בנו ובצעו בדיקות לא מקוונות. לפרויקט החדש אסור שיהיו אמצעי אחסון קיימים; אם נמצא ניסיון קודם, בדקו אותו במקום למחוק. הפקודות אינן מפעילות את הבוט. ודאו מקום לתיקייה הישנה, לנתונים ולסשן, לתמונה הישנה ולתמונה החדשה.

<div dir="ltr">

~~~sh
new_compose() {
  docker compose --project-name "$NEW_PROJECT" --project-directory "$RELEASE_DIR" \
    --file "$RELEASE_DIR/docker-compose.yml" "$@"
}
if docker volume inspect "$NEW_PROJECT"_bot-data >/dev/null 2>&1 \
  || docker volume inspect "$NEW_PROJECT"_madeline-session >/dev/null 2>&1; then
  echo 'STOP: destination volumes already exist.' >&2
  exit 1
fi
unset BUILD_COMMIT
new_compose config --quiet
new_compose build shabbat
new_compose run --rm --no-deps shabbat php scripts/verify-patches.php
new_compose run --rm --no-deps shabbat php bin/self-check.php --release
new_compose run --rm --no-deps shabbat php bin/runtime-permissions.php --require-non-root
~~~

</div>

## 4. עצירת הכותב הישן וגיבוי מלא

בחרו חלון תחזוקה ביום חול, מחוץ לזמני הסגירה, עם מנהל קבוצה זמין. בדקו שידורים ומחיקות מתוזמנים ועצרו מנהלי תהליכים וכותבים נוספים. מכאן הייצור מושבת. שמרו את הקונטיינר, התמונה ואמצעי האחסון הישנים. עצירה כפויה או OOM מחייבות בירור לפני המשך.

<div dir="ltr">

~~~sh
docker update --restart=no "$OLD_CONTAINER_ID"
docker stop --time 120 "$OLD_CONTAINER_ID"
test "$(docker inspect --format '{{.State.Running}}' "$OLD_CONTAINER_ID")" = false
test "$(docker inspect --format '{{.State.Restarting}}' "$OLD_CONTAINER_ID")" = false
test "$(docker inspect --format '{{.State.OOMKilled}}' "$OLD_CONTAINER_ID")" = false
test "$(docker inspect --format '{{.State.ExitCode}}' "$OLD_CONTAINER_ID")" != 137
install -d -m 700 "$BACKUP_DIR/source" "$BACKUP_DIR/data" "$BACKUP_DIR/session"
cp -a -- "$ORIGINAL_DIR/." "$BACKUP_DIR/source/"
(
  cd "$ORIGINAL_DIR"
  find . -type f -print0 | sort -z | xargs -0 sha256sum
) > "$BACKUP_DIR/source.sha256"
(cd "$BACKUP_DIR/source"; sha256sum --check "$BACKUP_DIR/source.sha256") > "$BACKUP_DIR/source-check.txt"
docker cp "$OLD_CONTAINER_ID:/app/data/." "$BACKUP_DIR/data/"
if [ "$LAYOUT" = legacy ]; then
  set -- "$BACKUP_DIR/source"/bot.shabbat*
  test -e "$1"
  cp -a -- "$@" "$BACKUP_DIR/session/"
else
  docker cp "$OLD_CONTAINER_ID:/app/runtime/." "$BACKUP_DIR/session/"
  test -s "$BACKUP_DIR/data/shabbat.sqlite"
fi
if [ -d "$BACKUP_DIR/session/bot.shabbat" ]; then
  test -s "$BACKUP_DIR/session/bot.shabbat/safe.php"
elif [ -f "$BACKUP_DIR/session/bot.shabbat" ]; then
  test -s "$BACKUP_DIR/session/bot.shabbat.safe.php"
else
  echo 'STOP: canonical session payload is missing; do not create a new session.' >&2
  exit 1
fi
for part in data session; do
  (cd "$BACKUP_DIR/$part"; find . -type f -print0 | sort -z | xargs -0 sha256sum) > "$BACKUP_DIR/$part.sha256"
done
docker image save --output "$BACKUP_DIR/old-image.tar" "$OLD_IMAGE_ID"
sha256sum "$BACKUP_DIR/old-image.tar" > "$BACKUP_DIR/old-image.tar.sha256"
~~~

</div>

הגיבוי כולל .env, תצורה, תלויות מותקנות, data, מסד ו־WAL/SHM, סשן, קובצי מצב בשורש ונתוני BroadcastManager. שמרו גם עותק פרטי מחוץ לשרת. גיבוי SQLite או DBgroups.txt לבדם אינו מספיק. הבוט והסשן החדשים עדיין לא הופעלו.

## 5. העתקה לאחסון חדש והכנת המסד

הגיבוי מחובר לקריאה בלבד. root משמש רק להעתקה ולתחזוקה לא מקוונת; הבוט ירוץ כ־UID/GID 10001. אימות SHA256 בודק את העתקת הנתונים והסשן לפני מיגרציה.

<div dir="ltr">

~~~sh
new_compose run --rm --no-deps --user 0:0 \
  --volume "$BACKUP_DIR:/review:ro" shabbat sh -ec '
  test -z "$(find /app/data -mindepth 1 -maxdepth 1 -print -quit)"
  test -z "$(find /app/runtime -mindepth 1 -maxdepth 1 -print -quit)"
  cp -a /review/data/. /app/data/
  cp -a /review/session/. /app/runtime/
  (cd /app/data; sha256sum --check /review/data.sha256)
  (cd /app/runtime; sha256sum --check /review/session.sha256)
  test ! -e /app/data/upgrade-3.1.0
  mkdir -m 700 /app/data/upgrade-3.1.0
' > "$BACKUP_DIR/copied-state-check.txt"
inventory() {
  new_compose run --rm --no-deps --user 0:0 shabbat php -r '
  require "/app/vendor/autoload.php";
  $db=Shabbat\Storage\Database::open("/app/data/shabbat.sqlite");
  $result=[];
  $tables=$db->pdo()->prepare("SELECT name FROM sqlite_master WHERE type=? ORDER BY name");
  $tables->execute(["table"]);
  foreach($tables->fetchAll(PDO::FETCH_COLUMN) as $name) {
    if($name==="schema_migrations" || str_starts_with($name,"sqlite_")) continue;
    if(!preg_match("/^[a-z_][a-z0-9_]*$/D",$name)) throw new RuntimeException("Unexpected table name");
    $columns=$db->pdo()->query("PRAGMA table_info(\"".$name."\")")->fetchAll(PDO::FETCH_ASSOC);
    $order=implode(",",array_map(fn($c)=>"\"".str_replace("\"","\"\"",$c["name"])."\"",$columns));
    $rows=$db->pdo()->query("SELECT * FROM \"".$name."\" ORDER BY ".$order);
    $hash=hash_init("sha256"); $count=0;
    while($row=$rows->fetch(PDO::FETCH_ASSOC)) {
      hash_update($hash,json_encode($row,JSON_THROW_ON_ERROR)."\n"); $count++;
    }
    $result[$name]=["rows"=>$count,"sha256"=>hash_final($hash)];
  }
  echo json_encode($result,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR),PHP_EOL;
  '
}
if [ "$LAYOUT" = sqlite ]; then
  inventory > "$BACKUP_DIR/database-before.json"
fi
new_compose run --rm --no-deps --user 0:0 shabbat \
  php bin/migrate.php --writers-stopped --db /app/data/shabbat.sqlite \
  --backup-dir /app/data/upgrade-3.1.0 > "$BACKUP_DIR/migrate.json"
if [ "$LAYOUT" = sqlite ]; then
  inventory > "$BACKUP_DIR/database-after.json"
  cmp "$BACKUP_DIR/database-before.json" "$BACKUP_DIR/database-after.json"
fi
~~~

</div>

בשדרוג SQLite מ־3.0, השוואת שורות המסד חייבת להתאים ואין לבצע ייבוא legacy; המשיכו לשלב 7. במערכת legacy המשיכו לשלב הבא. ב־3.1.0 אין שינוי בסכמת SQL; כל הבדל בלתי צפוי מחייב בירור לפני הפעלה.

## 6. רק ל־legacy: בדיקה וייבוא תואם לדוח

בדקו כל קבוצה רשומה, הגדרה, תבנית, הודעה, התראה, טיוטה ואזהרת הרשאות. כפילויות מדווחות ומנוכות. תיקיות קבוצה שאינן ברשימה מדווחות ואינן נרשמות אוטומטית, כי ייתכן שאינן פעילות. אם צריך לייבאן, התאימו עותק ייבוא נפרד לאחר בירור, שמרו את הגיבוי המקורי והפיקו דוח חדש. הייבוא מכסה את [השדות המתועדים](MIGRATION.md); הרחבות מקומיות מחייבות בדיקה.

<div dir="ltr">

~~~sh
test "$LAYOUT" = legacy
new_compose run --rm --no-deps --user 0:0 \
  --volume "$BACKUP_DIR:/review:ro" shabbat \
  php bin/import-legacy.php --db /app/data/shabbat.sqlite \
  --legacy /review/source --dry-run > "$BACKUP_DIR/import-preview.json"
~~~

</div>

קראו את דוח JSON הפרטי ואשרו כל הבדל בין המקור ליעד לפני הבלוק הבא. האפשרות --expect-report עוצרת אם המקור או רשומות היעד הרלוונטיות השתנו. הגדרות ותבניות קיימות נשמרות. קבוצות מיובאות פעילות, עם ברירות המחדל המתועדות של ירושלים, הזמנים וההתראות הישנים; מודולי התזכורות החדשים נשארים כבויים. בדקו מיקום ומצב לפני עבודה רגילה.

הייבוא אינו טרנזקציה אחת כוללת. בכשל משאירים את הבוט כבוי, שומרים ראיות ובודקים או משחזרים את הגיבוי המאומת שנוצר לפני הייבוא לפני ניסיון נוסף. אין לעקוף את התאמת הדוח.

<div dir="ltr">

~~~sh
new_compose run --rm --no-deps --user 0:0 \
  --volume "$BACKUP_DIR:/review:ro" shabbat \
  php bin/import-legacy.php --db /app/data/shabbat.sqlite --legacy /review/source \
  --apply --writers-stopped --backup-dir /app/data/upgrade-3.1.0 \
  --expect-report /review/import-preview.json > "$BACKUP_DIR/import-apply.json"
new_compose run --rm --no-deps --user 0:0 \
  --volume "$BACKUP_DIR:/review:ro" shabbat php -r '
  $r=json_decode(file_get_contents("/review/import-apply.json"),true,512,JSON_THROW_ON_ERROR);
  if(($r["verification"]["status"]??null)!=="passed") throw new RuntimeException("Import verification failed");
  echo "Import comparison passed.",PHP_EOL;
'
~~~

</div>

צילומי chatb1.json וקובצי נעילה ישנים הם ראיות, ולא הרשאות נוכחיות מאומתות. ייתכן שנדרשת בדיקת מנהל גם אם הקבוצה נראית פתוחה. אין למחוק מצב התאוששות או לשחזר אוטומטית צילום ישן ומתירני. ראו [התאוששות הרשאות](PERMISSION_RECOVERY.md).

## 7. בדיקה, גיבוי ותרגול שחזור

פקודות התחזוקה בודקות סכמה ושלמות, יוצרות גיבוי SQLite חדש בלי למחוק גיבויים ישנים, מייצאות אותו בפרטיות ומשחזרות לנתיב זמני נפרד. הן אינן פותחות סשן Telegram. בריאות דורשת פעימות של בוט פועל; כאן משתמשים בבדיקות מבנה ואחרי ההפעלה בבדיקת בריאות אמיתית.

<div dir="ltr">

~~~sh
new_compose run --rm --no-deps --user 0:0 shabbat php -r '
require "/app/vendor/autoload.php";
$db=Shabbat\Storage\Database::open("/app/data/shabbat.sqlite");
$db->assertCurrentSchema();
$checks=$db->integrity();
if($checks["integrity_check"]!==["ok"] || $checks["foreign_key_check"]!==[]) throw new RuntimeException("Database check failed");
$result=(new Shabbat\Storage\BackupService())->backup($db,"/app/data/upgrade-3.1.0/ready.sqlite");
echo Shabbat\Storage\Database::encode($result),PHP_EOL;
' > "$BACKUP_DIR/ready-backup.json"
install -d -m 700 "$BACKUP_DIR/sqlite"
new_compose run --rm --no-deps --user 0:0 \
  --volume "$BACKUP_DIR/sqlite:/export" shabbat sh -ec '
  cp -a /app/data/upgrade-3.1.0/. /export/
  chown -R 10001:10001 /app/data /app/runtime
'
new_compose run --rm --no-deps shabbat php bin/restore.php \
  --backup /app/data/upgrade-3.1.0/ready.sqlite \
  --db /tmp/restore-rehearsal.sqlite --writers-stopped > "$BACKUP_DIR/restore-rehearsal.json"
new_compose run --rm --no-deps shabbat php scripts/verify-patches.php
new_compose run --rm --no-deps shabbat php bin/self-check.php --release
new_compose run --rm --no-deps shabbat php bin/runtime-permissions.php --require-non-root
~~~

</div>

## 8. החלפת הקוד והפעלת מופע יחיד

המשיכו לאחר קבלת הדוחות, הגיבוי והשחזור ותוכנית ההרשאות. התיקייה הישנה נשמרת בשלמותה בשם אחר, ללא דריסה. אם שינוי השם השני נכשל, עצרו והחזירו את התיקייה השמורה רק אחרי בדיקה ש־ORIGINAL_DIR חסר. הקונטיינר הישן נשאר כבוי כשנתיב הקוד המקורי מכיל את הגרסה החדשה.

<div dir="ltr">

~~~sh
test "$(docker inspect --format '{{.State.Running}}' "$OLD_CONTAINER_ID")" = false
test "$(docker inspect --format '{{.HostConfig.RestartPolicy.Name}}' "$OLD_CONTAINER_ID")" = no
case "$ORIGINAL_DIR" in /root/shabbat|/root/shabat) ;; *) exit 1 ;; esac
test "$(readlink -f -- "$ORIGINAL_DIR")" = "$ORIGINAL_DIR"
test "$RELEASE_DIR" = "/root/shabbat-stage-$VERSION-$STAMP"
test "$RETIRED_DIR" = "$ORIGINAL_DIR.before-$VERSION-$STAMP"
test ! -e "$RETIRED_DIR"
mv -T -- "$ORIGINAL_DIR" "$RETIRED_DIR"
mv -T -- "$RELEASE_DIR" "$ORIGINAL_DIR"
RELEASE_DIR="$ORIGINAL_DIR"
cd "$ORIGINAL_DIR"
new_compose config --quiet
printf '%s\n' "$RETIRED_DIR" "$NEW_PROJECT" > "$BACKUP_DIR/new-installation.txt"
new_compose up -d --no-build shabbat
new_compose ps
new_compose logs --tail=120 shabbat
~~~

</div>

ההפעלה עשויה לפעול בקבוצות פעילות, לשלוח הודעת אתחול ולעבד משימות ספרייה. שידורים ישנים ללא שיוך מנהל עלולים להיחסם או להתבטל; משימות מחיקה מועתקות עלולות להתבצע. בדקו את מצב BroadcastManager השמור; אין להניח ששידור שנקטע בטוח לשליחה מחדש.

לאחר האתחול בדקו בריאות. ב־Telegram בדקו /start, מעברי הבית וההגדרות, callbacks, זהות /source, מספר קבוצות, מיקום ואזור זמן, תבניות ודגלי התראות. בקבוצת ניסוי עם מגבלות ידועות בדקו סגירה, פתיחה ו־restart; אין להשתמש ב־/closegroups או /opengroups ההמוניות כבדיקת עשן.

<div dir="ltr">

~~~sh
new_compose exec -T shabbat php bin/health.php --deep
new_compose ps
~~~

</div>

מנהל קבוצה צריך לטפל בכל צילום לא מאומת מחוץ לזמנים מוגנים: לקבוע ב־Telegram הרשאות רגילות רצויות, לרענן את פאנל ההתאוששות של הקבוצה ולאשר במפורש את אימוץ ההרשאות הנוכחיות. פתיחה כללית אינה בהכרח ברירת המחדל הרצויה. עקבו אחר שתי הפעימות, התורים, ההרשאות והתזכורות לאורך מחזור שבת/חג אמיתי ראשון. בדיקות 3.0 אינן אישור ל־3.1.0, לתזמון בחג או להרצה ממושכת. שמרו חומרי חזרה לאחור עד לקבלת התוצאה.

פקודות עתידיות מריצים מ־ORIGINAL_DIR המאומת עם **--project-name shabbat-production-310**. שומרים את שם הפרויקט ואת שני אמצעי האחסון. מגבים יחד קוד תואם, .env, מסד, סשן ומצב BroadcastManager.

## 9. חזרה לאחור

**לפני שינויי השם וההפעלה:** הקוד והמצב הישנים לא השתנו. אם השירות החדש אינו פועל ו־RETIRED_DIR לא קיים, החזירו את מדיניות האתחול השמורה והפעילו את הקונטיינר הישן:

<div dir="ltr">

~~~sh
test ! -e "$RETIRED_DIR"
test "$(readlink -f -- "$ORIGINAL_DIR")" = "$ORIGINAL_DIR"
test -z "$(docker ps --filter label=com.docker.compose.project="$NEW_PROJECT" --filter label=com.docker.compose.service=shabbat --quiet)"
docker update --restart="$OLD_RESTART_POLICY" "$OLD_CONTAINER_ID"
docker start "$OLD_CONTAINER_ID"
~~~

</div>

במסלול זה עצרו כאן. **אחרי החלפה והפעלה**, בדקו קודם השפעות ב־Telegram: קבצים אינם מבטלים הרשאות, הודעות ושידורים. טפלו בחובות הרשאות שיצר הבוט החדש לפני הוצאתו משימוש, ואז עצרו ושמרו את מצבו:

<div dir="ltr">

~~~sh
NEW_CONTAINER_ID="$(new_compose ps -a -q shabbat)"
if [ -n "$NEW_CONTAINER_ID" ]; then
  docker update --restart=no "$NEW_CONTAINER_ID"
fi
new_compose stop --timeout 120 shabbat
if [ -n "$NEW_CONTAINER_ID" ]; then
  test "$(docker inspect --format '{{.State.Running}}' "$NEW_CONTAINER_ID")" = false
fi
test "$(docker inspect --format '{{.State.Running}}' "$OLD_CONTAINER_ID")" = false
test ! -e "$BACKUP_DIR/failed-new-state"
install -d -m 700 "$BACKUP_DIR/failed-new-state"
new_compose run --rm --no-deps --user 0:0 \
  --volume "$BACKUP_DIR/failed-new-state:/export" shabbat sh -ec '
  tar -cpf /export/data.tar -C /app/data .
  tar -cpf /export/session.tar -C /app/runtime .
'
sha256sum "$BACKUP_DIR/failed-new-state/"*.tar > "$BACKUP_DIR/failed-new-state/SHA256SUMS"
FAILED_DIR="$ORIGINAL_DIR.failed-$VERSION-$STAMP"
test "$RETIRED_DIR" = "$ORIGINAL_DIR.before-$VERSION-$STAMP"
test -d "$RETIRED_DIR"
test ! -e "$FAILED_DIR"
case "$ORIGINAL_DIR" in /root/shabbat|/root/shabat) ;; *) exit 1 ;; esac
mv -T -- "$ORIGINAL_DIR" "$FAILED_DIR"
mv -T -- "$RETIRED_DIR" "$ORIGINAL_DIR"
test -f "$ORIGINAL_DIR/.env"
docker update --restart="$OLD_RESTART_POLICY" "$OLD_CONTAINER_ID"
docker start "$OLD_CONTAINER_ID"
docker logs --tail=100 "$OLD_CONTAINER_ID"
~~~

</div>

התיקייה הישנה מוחזרת לפני הפעלת הקונטיינר שמחובר לנתיב המקורי; אמצעי האחסון, התמונה והסשן הישנים נשמרו. אין לדרוס את הגיבוי הישן במסד או בסשן החדשים. גם אחרי חזרה לאחור בודקים תאימות סשן ומצב ב־Telegram. אין למחוק מצבי נתונים, אמצעי אחסון או תמונות שמורות בתהליך.

</div>
