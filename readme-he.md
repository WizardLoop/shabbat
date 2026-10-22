<div dir="rtl">

# 🕯️ בוט שבת

בוט Telegram לניהול שקט בקבוצות בשבתות ובחגים, להצגת מידע מהלוח היהודי ולתזכורות לבחירה. מבוסס על PHP,‏ MadelineProto ו־SQLite.

[English](README.md) | [עברית](readme-he.md)

[![License: AGPL-3.0-only](https://img.shields.io/badge/license-AGPL--3.0--only-blue)](LICENSE)
[![PHP 8.3–8.5](https://img.shields.io/badge/PHP-8.3%E2%80%938.5-777BB4)](composer.json)
[![MadelineProto 8.11.10](https://img.shields.io/badge/MadelineProto-8.11.10-2CA5E0)](composer.lock)

**גרסה 3.1.0.** דרישות השחרור ומגבלות האימות מתוארות ב[מצב המימוש](docs/IMPLEMENTATION_STATUS.md). להתקנה קיימת התחילו ב[מדריך השדרוג בעברית](docs/PRODUCTION_UPGRADE_HE.md) ([English](docs/PRODUCTION_UPGRADE.md)); הפקודות שבהמשך יוצרות מסד נתונים חדש.

[יכולות](#features) · [דרישות](#requirements) · [התקנה](#install) · [הגדרה](#configure) · [שימוש ראשון](#first-use) · [תפעול ועדכון](#operate) · [תיעוד](#documentation) · [רישיון](#license)

<a id="features"></a>
## מה הבוט עושה

- סוגר ופותח קבוצות בשבתות ובחגים, תוך שמירת הרשאות מקור מאומתות והתאוששות מבוקרת.
- מאפשר לבחור לכל קבוצה מיקום, אזור זמן, לוח ישראל/חו״ל ושיטות לנרות ולהבדלה.
- מציג זמני היום ודף יומי; מאפשר לתזמן תזכורות לזמנים, לדף יומי, לתפילין ולעומר.
- מנהל קבוצות דרך פאנל פרטי, עם התאמת הודעות, מדיה וכפתורים.
- כולל מנהלי בוט עם הרשאות נפרדות, שידורים, סטטיסטיקה ותרומות אופציונליות בכוכבי Telegram.

כלולים קטלוגי ממשק בעברית, באנגלית, ברוסית, בצרפתית, בספרדית ובפורטוגזית ברזילאית. תוכן חיצוני וטקסטים ליטורגיים בעברית עשויים להישאר בשפת המקור. לפרטים ראו [הגדרות קבוצה](docs/GROUP_SETTINGS.md).

<a id="requirements"></a>
## לפני שמתחילים

נדרש שרת Linux עם אחסון קבוע, גישה לאינטרנט, **Docker Engine ותוסף Docker Compose**, וכן:

| פריט | מאיפה מקבלים |
|---|---|
| API ID ו־API Hash של Telegram | [ניהול אפליקציות Telegram](https://my.telegram.org/apps) |
| טוקן הבוט | יצירה או בחירה של בוט דרך [BotFather](https://t.me/BotFather) |
| מזהה המנהל | מזהה משתמש Telegram מהימן; מספר מזהים מופרדים בפסיקים |
| שם משתמש GeoNames | [חשבון GeoNames](https://www.geonames.org/login) עם Web Services פעילים, לחיפוש ערים ואזור זמן אוטומטי |

התקינו [Docker Engine לפי הפצת Linux](https://docs.docker.com/engine/install/) ואת [תוסף Compose](https://docs.docker.com/compose/install/linux/), ובדקו:

<div dir="ltr">

~~~sh
docker --version
docker compose version
~~~

</div>

Docker מספק את PHP,‏ Composer,‏ SQLite והתלויות הנעולות. קיימות גם הוראות ל[התקנה ידנית](docs/INSTALLATION.md#manual-installation).

<a id="install"></a>
## 1. חילוץ התקנה חדשה

הורידו את `shabbat-3.1.0.zip` ואת `SHA256SUMS` מ[שחרור GitHub המתאים](https://github.com/WizardLoop/shabbat/releases). הריצו ב־**Bash בשרת**, מתיקיית ההעלאה ועם גישה ל־Docker. אימות הקובץ חייב להחזיר `OK`.

<div dir="ltr">

~~~sh
set -euo pipefail
umask 077
awk '$2 == "shabbat-3.1.0.zip" {
  if (NF != 2 || length($1) != 64 || $1 ~ /[^0-9a-f]/) exit 1
  print; n++
} END { if (n != 1) exit 1 }' SHA256SUMS > deployment.sha256
sha256sum --check deployment.sha256
test ! -e shabbat-3.1.0
unzip -q shabbat-3.1.0.zip -d shabbat-3.1.0
cd shabbat-3.1.0
cp .env.example .env
chmod 600 .env
unset BUILD_COMMIT
~~~

</div>

שמרו את `build-info.json` המצורף. בארכיון שחרור שלא שונה אין להוסיף `BUILD_COMMIT` לקובץ `.env`. אין לחלץ עדכון על התקנה פועלת.

<a id="configure"></a>
## 2. הגדרת הבוט

ערכו את `.env` באופן פרטי. החליפו את פרטי הדוגמה ואת מזהה המנהל:

<div dir="ltr">

~~~dotenv
API_ID=123456
API_HASH=replace_with_your_32_character_api_hash
BOT_TOKEN=replace_with_your_bot_token
ADMIN=123456789
GEONAMES_USERNAME=your_geonames_username
SOURCE_URL=https://github.com/WizardLoop/shabbat/releases/tag/v3.1.0
~~~

</div>

שמרו את שאר ערכי `.env.example` אלא אם נדרשת הגדרה מתקדמת. אין לפרסם את הקובץ או להשתמש בפרטי בוט בדיקה ב־production. קישור המקור חייב להציע את המקור המדויק של ההתקנה, כולל ארכיון Corresponding Source. ראו [כל משתני התצורה](docs/CONFIGURATION.md).

משורש התיקייה שחולצה, בנו ובדקו את התמונה ואתחלו **מסד נתונים חדש וריק**. הפקודות האלה אינן מפעילות את הבוט:

<div dir="ltr">

~~~sh
docker compose build shabbat
docker compose run --rm --no-deps shabbat php bin/self-check.php --release
docker compose run --rm --no-deps shabbat php bin/runtime-permissions.php --require-non-root
docker compose run --rm --no-deps shabbat php bin/migrate.php --writers-stopped --db /app/data/shabbat.sqlite
~~~

</div>

כשתהיו מוכנים לחיבור ל־Telegram, הפעילו:

<div dir="ltr">

~~~sh
docker compose up -d shabbat
docker compose ps
docker compose logs --tail=100 shabbat
docker compose exec shabbat php bin/health.php
~~~

</div>

בדיקת הבריאות עשויה להזדקק לתקופת האתחול הראשונית, שאורכה 90 שניות. שם השירות הוא `shabbat`. אמצעי האחסון `bot-data` ו־`madeline-session` שומרים את נתוני היישום והסשן; שמם המלא כולל את שם פרויקט Compose.

<a id="first-use"></a>
## 3. Telegram והקבוצה הראשונה

1. ב־BotFather אפשרו הצטרפות לקבוצות. הפעילו `/setinline` לחיפוש מיקום ולוח באינליין.
2. שלחו `/start` בפרטי. בדקו את כפתורי הבית, `/preferences` ואת השפה.
3. הוסיפו את הבוט כמנהל בקבוצה עם הרשאה להגביל חברים. מחיקה ונעיצה דורשות את ההרשאות המתאימות.
4. כמנהל קבוצה, שלחו `/add` **בתוך הקבוצה**. קבוצה חדשה נוצרת במצב **כבוי**, וההגדרה נפתחת בפרטי.
5. בדקו מיקום, אזור זמן, לוח, מצב ושיטות נרות והבדלה. הפעילו רק תזכורות רצויות. חוזרים דרך `/groups` בפרטי או `/settings` בקבוצה.

להקמה ראשונה השתמשו ב־`/add`: רישום ישיר דרך `/settings` מקבל כיום ברירת מחדל של נעילה. בדקו את הערכים לפני הפעלת קבוצה אמיתית. הרשאות מנהל הבוט ומנהל הקבוצה נפרדות.

[כל פקודות הבוט](docs/COMMANDS.md) · [הגדרות קבוצה](docs/GROUP_SETTINGS.md) · [מיקום וחיפוש אינליין](docs/INLINE_GEONAMES.md)

<a id="operate"></a>
## תפעול שוטף ועדכון

הריצו **בשרת, משורש ההתקנה**, עם גישה ל־Docker ובאותו שם פרויקט Compose:

| פעולה | פקודה | השפעה |
|---|---|---|
| הפעלה | `docker compose up -d shabbat` | הפעלה וחיבור הבוט |
| עצירה | `docker compose stop shabbat` | עצירת הבוט ושמירת אמצעי האחסון |
| הפעלה מחדש | `docker compose restart shabbat` | הפעלה מחדש של התמונה הנוכחית |
| מצב | `docker compose ps` | הצגת מצב השירות |
| לוגים | `docker compose logs --tail=100 -f shabbat` | מעקב אחר לוגים; Ctrl+C סוגר את התצוגה |
| בריאות | `docker compose exec shabbat php bin/health.php --deep` | בדיקת התזמון ומסד הנתונים של הבוט הפועל |

**עדכון מחייב גיבוי לאחר עצירת הכותבים ובדיקת מיגרציות.** פעלו לפי [מדריך השדרוג](docs/PRODUCTION_UPGRADE_HE.md) ([English](docs/PRODUCTION_UPGRADE.md)) לאימות הנתיבים, הכנת staging, ייבוא או עדכון SQLite, שמירת הסשן וחזרה לאחור. בנייה בלבד אינה מבצעת מיגרציה; `restart` בלבד אינו מתקין תמונה חדשה. אין להריץ `composer update` בעדכון שגרתי או להסיר אמצעי אחסון.

<a id="documentation"></a>
## מידע נוסף

| נושא | מדריך |
|---|---|
| התקנה מפורטת והתקנת PHP ידנית | [התקנה](docs/INSTALLATION.md) |
| משתני סביבה והעדפות | [תצורה](docs/CONFIGURATION.md) |
| פקודות וניהול | [פקודות](docs/COMMANDS.md), [תפעול](docs/OPERATIONS.md), [פאנל מנהלים](docs/ADMIN_PANEL.md) |
| שדרוג, ייבוא ושחזור | [English](docs/PRODUCTION_UPGRADE.md), [עברית](docs/PRODUCTION_UPGRADE_HE.md), [מיפוי ייבוא](docs/MIGRATION.md), [גיבוי ושחזור](docs/BACKUP_RESTORE.md) |
| הרשאות, זמנים ותזכורות | [התאוששות הרשאות](docs/PERMISSION_RECOVERY.md), [שיטות חישוב](docs/ZMANIM_METHODS.md), [בטיחות מסירה](docs/DELIVERY_SAFETY.md) |
| תקלות ואבחון | [פתרון תקלות](docs/TROUBLESHOOTING.md), [אבחון](docs/DIAGNOSTICS.md) |
| פיתוח ובדיקות | [תרומה](.github/CONTRIBUTING.md), [ארכיטקטורה](docs/ARCHITECTURE.md), [מצב הבדיקות](docs/IMPLEMENTATION_STATUS.md) |
| גרסאות ואריזת מקור | [שינויים](CHANGELOG.md), [הערות שחרור](docs/RELEASE_NOTES.md), [תהליך שחרור](docs/RELEASE.md) |

<a id="license"></a>
## רישיון וקרדיטים

היישום מופץ ברישיון **AGPL-3.0-only**. יש לשמור את [הרישיון](LICENSE), הודעות זכויות היוצרים ההיסטוריות ו[הודעות צד שלישי](THIRD_PARTY_NOTICES.md). רישיונות התלויות נפרדים. שם נכלל הייחוס ל־MadelineProto,‏ BroadcastManager,‏ Hebcal,‏ GeoNames ולמקורות המשאבים.

מפעילים חייבים להציע את המקור התואם המדויק של שינויים שהם מפעילים. ארכיון Corresponding Source הנפרד כולל את היישום ואת מקורות הריצה המתועדים, בלי טענה למקור מלא של מערכת ההפעלה או כל כלי הפיתוח. ראו [רישוי](docs/LICENSING.md).

</div>
