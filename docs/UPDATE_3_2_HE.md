# עדכון בוט קיים לגרסה 3.2.0

הפקודות הבאות מיועדות לשרת Linux שעליו רץ הבוט ב־/root/shabat, בפרויקט Docker Compose בשם shabbat-production-310. הריצו כ־root בחלון תחזוקה מחוץ למועד סגירת הקבוצות או פתיחתן. אם שם הפרויקט או נתיב ההתקנה שונים אצלכם, החליפו אותם בשורת ההפעלה. [English](UPDATE_3_2.md)

הבלוק הבא מוריד את ארכיון הפריסה והחתימות, מאמת את הארכיון, ומפעיל מתוכו את סקריפט העדכון. אפשר גם להעלות לשרת את שני הקבצים בהעברה פרטית, ואז להריץ מתיקיית ההעלאה את שלבי האימות וההפעלה.

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

הסקריפט בונה את התמונה החדשה בזמן שהבוט הישן עדיין רץ. לאחר העצירה הוא מגבה את שני כרכי הנתונים, כולל מסד SQLite והסשן, ובודק את הגיבוי. הוא שומר את התצורה הפרטית, מעדכן את קישור המקור, מאמת את התלויות ומפעיל את מיגרציה 017 לפני הפעלת הבוט החדש. הקבוצות נשארות במסד הקיים. שמרו את נתיב הגיבוי המודפס ואת התמונה הקודמת. אם העדכון נעצר בשגיאה אחרי העצירה, בדקו את הפלט ואת הגיבוי לפני הפעלה חוזרת. [הוראות שחזור](BACKUP_RESTORE.md)

אחרי מחזור תזמון מלא בדקו את הבוט בשרת.

~~~bash
cd /root/shabat
docker compose -p shabbat-production-310 ps
docker compose -p shabbat-production-310 logs --tail=80 shabbat
docker compose -p shabbat-production-310 exec -T shabbat php bin/health.php --deep
~~~

חפשו בתוצאה healthy=true וגרסה 3.2.0. בבוט עצמו פתחו את /source. מחשבון מנהל הבוט שלחו בפרטי את הפקודה /setcommands all all ובדקו שהעדכון הסתיים בהצלחה. פעולה זו מעדכנת גם תפריטים שטלגרם שמרה מהגרסה הקודמת. פתחו מחדש את רשימת הפקודות בקבוצת בדיקה, פעם כחבר רגיל ופעם כמנהל. /settings אמורה להופיע למנהל ולבעלים.

לקבוצה שרוצים שתשלח הודעה אחרי הפתיחה, פתחו את הגדרות שבת וחגים ובחרו במצב הודעות ונעילה והפעילו את הודעת הפתיחה. בהודעות ועיצוב אפשר לערוך בנפרד את נוסח שבת ואת נוסח החג.
