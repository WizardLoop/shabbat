<?php 

declare(strict_types=1);

/**
 * Copyright WizardLoop (C)
 * This file is Written by wizardloop!
 * @author    wizardloop 
 * @copyright wizardloop
 * @license   https://opensource.org/license/mit MIT License
 * @link wizardloop => https://wizardloop.t.me 
 */

$autoload = __DIR__.'/vendor/autoload.php';
if (!file_exists($autoload)) {
    die("Autoload file not found. Please run 'composer install'.");
}
require_once $autoload;

use danog\MadelineProto\Broadcast\Filter;
use danog\MadelineProto\Broadcast\Progress;
use danog\MadelineProto\Broadcast\Status;
use danog\MadelineProto\EventHandler\Attributes\Cron;
use danog\MadelineProto\EventHandler\Attributes\Handler;
use danog\MadelineProto\EventHandler\Filter\FilterCommandCaseInsensitive;
use danog\MadelineProto\EventHandler\Message;
use danog\MadelineProto\EventHandler\Message\ChannelMessage;
use danog\MadelineProto\EventHandler\Message\PrivateMessage;
use danog\MadelineProto\EventHandler\Message\GroupMessage;
use danog\MadelineProto\EventHandler\SimpleFilter\FromAdmin;
use danog\MadelineProto\EventHandler\SimpleFilter\Incoming;
use danog\MadelineProto\ParseMode;
use danog\MadelineProto\Settings;
use danog\MadelineProto\SimpleEventHandler;
use danog\MadelineProto\BotApiFileId;
use danog\MadelineProto\EventHandler\CallbackQuery;
use danog\MadelineProto\EventHandler\Filter\FilterButtonQueryData;
use danog\MadelineProto\EventHandler\Filter\Combinator\FiltersOr;
use danog\MadelineProto\EventHandler\Filter\FilterIncoming;
use danog\MadelineProto\EventHandler\Update;
use Amp\File;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
use danog\MadelineProto\EventHandler\SimpleFilter\IsNotEdited;
use BroadcastTool\BroadcastManager;

class Shabbat extends SimpleEventHandler
{

/*
* test mode - change to true to check the cron
*/
private bool $testMode = false;
private function getTestShabbatLockTimes(): array
{
    return [

        /*
        |--------------------------------------------------------------------------
        | זמן סגירה לבדיקה
        |--------------------------------------------------------------------------
        */

        'close_datetime' => '23/05/2026 19:00',

        /*
        |--------------------------------------------------------------------------
        | זמן פתיחה לבדיקה
        |--------------------------------------------------------------------------
        */

        'open_datetime' => '23/05/2026 19:10',

        'close_time' => '19:00',

        'open_time' => '19:10',

        'close_date' => '23/05/2026',

        'open_date' => '23/05/2026',
    ];
}
private function getAlertTestTime(): string
{
    return '18:30';
}

/*
* text - ברירת מחדל כניסה ויציאת שבת וחג
*/
public const CLOSER = "הקבוצה שלנו שומרת שבת וחג! 🇮🇱\nותהיה סגורה עד זמן הבדלה/יציאה 🕯"; 
public const OPENER = "הקבוצה פתוחה לכתיבת הודעות! 🇮🇱";	

/*
 * זמני שבת
 */
private function getZmanimForCities(): string
{
    date_default_timezone_set('Asia/Jerusalem');

    $geonameIds = [
        'ירושלים'  => 281184,
        'חיפה'     => 294801,
        'תל אביב' => 293397,
        'באר שבע' => 295530,
    ];

    $zmanim = "⌚️ <u><b>זמני כניסת ויציאת השבת:</b></u>\n\n";

    $candleTimes   = [];
    $havdalahTimes = [];
    $holidays      = [];

    $date           = '';
    $parashaText    = '';
    $mevarchimText  = '';
    $mevarchimMemo  = '';

    $client = HttpClientBuilder::buildDefault();

    foreach ($geonameIds as $location => $geonameId) {

        $url = "https://www.hebcal.com/shabbat?cfg=json&geonameid=$geonameId&ue=off&b=18&M=on&lg=he-x-NoNikud&tgt=_top";

        $response = $client->request(new Request($url));
        $body     = $response->getBody()->buffer();

        $json = json_decode($body, true);

        if (!$json || !isset($json['items'])) {
            $zmanim .= "⚠️ לא ניתן היה לשלוף את זמני השבת עבור: $location\n";
            continue;
        }

        $candles  = null;
        $havdalah = null;

        foreach ($json['items'] as $item) {

            switch ($item['category']) {

                case 'candles':

                    $itemDate   = new \DateTime($item['date']);
                    $dayOfWeek  = (int)$itemDate->format('w'); // 5 = Friday
                    $holidayName = $item['memo'] ?? null;

                    // שמירת זמני חגים
                    if ($holidayName) {
                        $holidays[$holidayName]['candles'][$location] =
                            $itemDate->format('H:i');
                    }

                    // כניסת שבת = כל הדלקת נרות ביום שישי
                    if ($dayOfWeek === 5) {
                        $candles = $item;
                    }

                break;

                case 'havdalah':

                    $itemDate   = new \DateTime($item['date']);
                    $dayOfWeek  = (int)$itemDate->format('w'); // 6 = Saturday
                    $holidayName = $item['memo'] ?? null;

                    // שמירת זמני חגים
                    if ($holidayName) {
                        $holidays[$holidayName]['havdalah'][$location] =
                            $itemDate->format('H:i');
                    }

                    // יציאת שבת = כל הבדלה בשבת
                    if ($dayOfWeek === 6) {
                        $havdalah = $item;
                    }

                break;

                case 'parashat':

                    $parashaText = $item['hebrew'];

                break;

                case 'holiday':

                    if ($location === 'ירושלים') {

                        $hDate  = substr($item['date'], 0, 10);
                        $hTitle = $item['hebrew'];

                        if (!isset($holidays[$hTitle])) {
                            $holidays[$hTitle] = [];
                        }

                        $holidays[$hTitle]['date'] = $hDate;
                    }

                break;

                case 'mevarchim':

                    if ($location === 'ירושלים') {

                        $mevarchimText = $item['hebrew'];
                        $mevarchimMemo = $item['memo'] ?? '';
                    }

                break;
            }
        }

        $candleTimes[$location] = isset($candles['date'])
            ? (new \DateTime($candles['date']))->format('H:i')
            : 'לא ידוע';

        $havdalahTimes[$location] = isset($havdalah['date'])
            ? (new \DateTime($havdalah['date']))->format('H:i')
            : 'לא ידוע';

        if (empty($date) && isset($havdalah['date'])) {
            $date = (new \DateTime($havdalah['date']))->format('d/m/Y');
        }
    }

    $zmanim .= "🗓 <u>תאריך:</u> $date\n";

    if ($parashaText) {
        $zmanim .= "📖 <u>פרשת השבוע:</u> $parashaText\n";
    }

    if ($mevarchimText) {

        $memo = $mevarchimMemo
            ? " ($mevarchimMemo)"
            : '';

        $zmanim .= "🌒 <u>מברכים:</u> $mevarchimText$memo\n";
    }

    $zmanim .= "\n🕯 <u>כניסת שבת:</u>\n";

    foreach ($candleTimes as $loc => $time) {
        $zmanim .= "$loc: <code>$time</code>\n";
    }

    $zmanim .= "\n🍷 <u>יציאת שבת:</u>\n";

    foreach ($havdalahTimes as $loc => $time) {
        $zmanim .= "$loc: <code>$time</code>\n";
    }

    if ($holidays) {

        uasort(
            $holidays,
            fn($a, $b) =>
                strtotime($a['date'] ?? '') <=> strtotime($b['date'] ?? '')
        );

        $zmanim .= "\n🎉 <u>חגים קרובים:</u>\n";

        foreach ($holidays as $title => $info) {

            $hDateFormatted = isset($info['date'])
                ? (new \DateTime($info['date']))->format('d/m/Y')
                : '---';

            $zmanim .= "• $title ($hDateFormatted)\n";

            if (!empty($info['candles'])) {

                $zmanim .= "   🕯 כניסה:\n";

                foreach ($info['candles'] as $loc => $time) {
                    $zmanim .= "   $loc: <code>$time</code>\n";
                }
            }

            if (!empty($info['havdalah'])) {

                $zmanim .= "   🍷 יציאה:\n";

                foreach ($info['havdalah'] as $loc => $time) {
                    $zmanim .= "   $loc: <code>$time</code>\n";
                }
            }
        }
    }

    return $zmanim;
}

/*
 * זמני סגירה ופתיחה בלבד
 */
private int $closeBeforeMinutes = 10; # 10 דקות לפני שבת
private function getShabbatLockTimes(): array
{
    date_default_timezone_set('Asia/Jerusalem');

    $geonameId = 281184; // ירושלים

    $url = "https://www.hebcal.com/shabbat?cfg=json&geonameid={$geonameId}&ue=off&b=18&M=on&lg=he-x-NoNikud&tgt=_top";

    $client = HttpClientBuilder::buildDefault();

    $response = $client->request(new Request($url));

    $body = $response->getBody()->buffer();

    $json = json_decode($body, true);

    if (!$json || !isset($json['items'])) {

        return [
            'close_datetime' => null,
            'open_datetime'  => null,
            'close_time'     => null,
            'open_time'      => null,
            'close_date'     => null,
            'open_date'      => null,
        ];
    }

    $now = new \DateTime();

    $candles = [];
    $havdalahs = [];

    foreach ($json['items'] as $item) {

        if (
            !isset($item['category']) ||
            !isset($item['date'])
        ) {
            continue;
        }

        try {

            $date = new \DateTime($item['date']);

        } catch (\Throwable $e) {
            continue;
        }

        if ($date <= $now) {
            continue;
        }

        if ($item['category'] === 'candles') {
            $date->modify("-{$this->closeBeforeMinutes} minutes");
            $candles[] = $date;
        }

        if ($item['category'] === 'havdalah') {
            $havdalahs[] = $date;
        }
    }

    usort(
        $candles,
        fn($a, $b) => $a->getTimestamp() <=> $b->getTimestamp()
    );

    usort(
        $havdalahs,
        fn($a, $b) => $a->getTimestamp() <=> $b->getTimestamp()
    );

    $closeDateTime = $candles[0] ?? null;

    $openDateTime = null;

    if ($closeDateTime) {

        foreach ($havdalahs as $havdalah) {

            if ($havdalah > $closeDateTime) {

                $openDateTime = $havdalah;
                break;
            }
        }
    }

    return [

        'close_datetime' => $closeDateTime
            ? $closeDateTime->format('d/m/Y H:i')
            : null,

        'open_datetime' => $openDateTime
            ? $openDateTime->format('d/m/Y H:i')
            : null,

        'close_time' => $closeDateTime
            ? $closeDateTime->format('H:i')
            : null,

        'open_time' => $openDateTime
            ? $openDateTime->format('H:i')
            : null,

        'close_date' => $closeDateTime
            ? $closeDateTime->format('d/m/Y')
            : null,

        'open_date' => $openDateTime
            ? $openDateTime->format('d/m/Y')
            : null,
    ];
}

/*
* מנהלים
*/
public function getReportPeers() {
    return array_map('trim', explode(',', parse_ini_file(__DIR__.'/.env')['ADMIN']));
}

public function onStart(): void {
try {
    $this->sendMessageToAdmins("<b>The system has been restarted!</b>",parseMode: ParseMode::HTML);
} catch (\Throwable $e) {}
}

/*
* עוזב ערוצים
*/
#[FilterIncoming]
public function ChannelsLeave(ChannelMessage $message): void {
try {
$this->channels->leaveChannel(channel: $message->chatId );
} catch (Throwable $e) {}
}

/* ================ main handlers ================ */

#[FilterCommandCaseInsensitive('start')]
public function StartCommand(Incoming & PrivateMessage & IsNotEdited $message): void {
try {
$senderid = $message->senderId;
$messageid = $message->id;
$User_Full = $this->getInfo($message->senderId);
$first_name = $User_Full['User']['first_name']?? null;
if($first_name == null){
$first_name = "null";
}

$me = $this->getSelf();
$me_username = $me['username'];

$txtbot = "היי <a href='mention:$senderid'>$first_name</a>, ברוך הבא 👋
הרובוט שישמור את השבת בקבוצה שלך!

🕯 <u>הרובוט בקוד פתוח בגיטהאב:</u>
github.com/wizardloop/shabbat";

$bot_API_markup[] = [['text'=>"זמני כניסת השבת 🕯",'callback_data'=>"זמנישבת"]];
$bot_API_markup[] = [['text'=>"הוסף אותי לקבוצה ➕",'url'=>"https://t.me/$me_username?startgroup&admin=restrict_members"]];
$bot_API_markup[] = [['text'=>"📖 כל הפקודות 💡",'callback_data'=>"כלהפקודות"]];
$bot_API_markup = [ 'inline_keyboard'=> $bot_API_markup,];

$inputReplyToMessage = ['_' => 'inputReplyToMessage', 'reply_to_msg_id' => $messageid];
$this->messages->sendMessage(no_webpage: true, peer: $message->senderId, reply_to: $inputReplyToMessage, message: "$txtbot", reply_markup: $bot_API_markup, parse_mode: 'HTML');

    if (!file_exists(__DIR__."/data")) {
mkdir(__DIR__."/data");
}
    if (!file_exists(__DIR__."/data/$senderid")) {
mkdir(__DIR__."/data/$senderid");
}
    if (file_exists(__DIR__."/data/$senderid/grs1.txt")) {
unlink(__DIR__."/data/$senderid/grs1.txt");
}
} catch (Throwable $e) {
}
}

#[FilterButtonQueryData('חזרה')]
public function BackCommand(callbackQuery $query) {
try {
$userid = $query->userId;    
$User_Full = $this->getInfo($userid);
$first_name = $User_Full['User']['first_name']?? null;
if($first_name == null){
$first_name = "null";
}

$me = $this->getSelf();
$me_username = $me['username'];

$txtbot = "היי <a href='mention:$userid'>$first_name</a>, ברוך הבא 👋
הרובוט שישמור את השבת בקבוצה שלך!

🕯 <u>הרובוט בקוד פתוח בגיטהאב:</u>
github.com/wizardloop/shabbat";

$bot_API_markup[] = [['text'=>"זמני כניסת השבת 🕯",'callback_data'=>"זמנישבת"]];
$bot_API_markup[] = [['text'=>"הוסף אותי לקבוצה ➕",'url'=>"https://t.me/$me_username?startgroup&admin=restrict_members"]];
$bot_API_markup[] = [['text'=>"📖 כל הפקודות 💡",'callback_data'=>"כלהפקודות"]];
$bot_API_markup = [ 'inline_keyboard'=> $bot_API_markup,];

$query->editText($message = "$txtbot", $replyMarkup = $bot_API_markup, ParseMode::HTML, $noWebpage = true, $scheduleDate = NULL);
    if (file_exists(__DIR__."/"."data/$userid/grs1.txt")) {
unlink(__DIR__."/"."data/$userid/grs1.txt");
}
} catch (Throwable $e) {
}
}

#[FilterButtonQueryData('זמנישבת')]
public function ShabbatTimes(callbackQuery $query) {	
try {
$bot_API_markup[] = [['text'=>"חזרה",'callback_data'=>"חזרה"]];
$bot_API_markup = [ 'inline_keyboard'=> $bot_API_markup,];

$editer = $query->editText($message = "⌛️", $replyMarkup = null, ParseMode::HTML, $noWebpage = false, $scheduleDate = NULL);

$ShabatTimes = $this->getZmanimForCities();

$editer2 = $query->editText($message = $ShabatTimes, $replyMarkup = $bot_API_markup, ParseMode::HTML, $noWebpage = false, $scheduleDate = NULL);
} catch (Throwable $e) {
$this->messages->sendMessage(peer: $query->userId, message: $e->getMessage());
}
}

#[FilterButtonQueryData('כלהפקודות')]
public function AllCommands(callbackQuery $query) {
try {
$txtbot = "<b>ברוכים הבאים לתפריט העזרה!</b> 🆘
בתפריט זה תמצאו את כל הפקודות והמידע";

$bot_API_markup[] = [['text'=>"כללי יסוד",'callback_data'=>"כללייסוד"]];
$bot_API_markup[] = [['text'=>"פקודות למנהלים",'callback_data'=>"פקודותלמנהלים"]];
$bot_API_markup[] = [['text'=>"פקודות לכל המשתמשים",'callback_data'=>"פקודותלכלהמשתמשים"]];
$bot_API_markup[] = [['text'=>"חזרה",'callback_data'=>"חזרה"]];
$bot_API_markup = [ 'inline_keyboard'=> $bot_API_markup,];

$query->editText($message = "$txtbot", $replyMarkup = $bot_API_markup, ParseMode::HTML, $noWebpage = false, $scheduleDate = NULL);
} catch (Throwable $e) {
}
}

#[FilterButtonQueryData('כללייסוד')]
public function Rules(callbackQuery $query) {
try {
$txtbot = "<b>(הרובוט הזה עובד רק בסופר קבוצה)</b>

🕯 על מנת שאני יוכל לסגור את הקבוצה בשבתות וחגים, יש להוסיף אותי לקבוצה שלך כמנהל עם הרשאה לחסימת משתמשים.

לאחר ההוספה חובה לשלוח בקבוצה את הפקודה <code>/add</code> אחרת אני לא אשמור את השבת אצלך בקבוצה...

אתה יכול להשתמש בסימנים: /, !, . כדי להפעיל כל פקודה.

<i>טיפ: רוצה שאני רק אשלח את זמני השבת מבלי לסגור את הקבוצה? כתוב /add > הפעל התראות שבת > תסיר לי הרשאות ניהול(שאני לא יוכל לסגור את הקבוצה, אפשר גם כחבר רגיל בקבוצה ללא אדמין)</i>

<b>חדש: הבוט פעיל גם בחגים!</b>

זכור: אתה צריך להשתמש בפקודות בתוך הקבוצה, אלא אם כן הם תוכננו במיוחד עבור כל צ'אט (ראה 'פקודות לכל המשתמשים').";

$me = $this->getSelf();
$me_username = $me['username'];

$bot_API_markup[] = [['text'=>"הוסף אותי לקבוצה ➕",'url'=>"https://t.me/$me_username?startgroup&admin=restrict_members"]];
$bot_API_markup[] = [['text'=>"חזרה",'callback_data'=>"כלהפקודות"]];
$bot_API_markup = [ 'inline_keyboard'=> $bot_API_markup,];

$query->editText($message = "$txtbot", $replyMarkup = $bot_API_markup, ParseMode::HTML, $noWebpage = false, $scheduleDate = NULL);
} catch (Throwable $e) {
}
}

#[FilterButtonQueryData('פקודותלמנהלים')]
public function CommandForAdmins(callbackQuery $query) {
try {
$txtbot = "💡 <b>רשימת פקודות זמינות:</b>
/add - שליחת פקודה זו בקבוצה תוסיף את הקבוצה לבסיס נתונים על מנת שהיא תסגר בשבתות וחגים!
/remove - הסרת הקבוצה מהבסיס נתונים... הקבוצה לא תסגר!
/settings - התאם אישית את הרובוט בקבוצה שלך. 

⚙️ <b>מה אפשר לעשות בהגדרות?</b>
באפשרותכם להגדיר האם הקבוצה תקבל מידי יום שישי / יום חג (בשעה 13:30) הודעה עם זמני כניסה ויציאה!
כמו כן באפשרותכם להגדיר הודעה מותאמת אישית שתשלח כשהקבוצה נסגרת!
והודעה מותאמת אישית שתשלח בזמן הבדלה כשהקבוצה נפתחת!

<b>הקבוצה תיסגר לפי זמן:</b> ירושלים 
כניסה: 18 דקות לפני השקיעה.
יציאה: 8.5 מעלות

⌚️ הקבוצה תסגר 10 דק' לפני הזמן!!

<i>פקודות אלו יש לשלוח בקבוצה בלבד</i>";

$bot_API_markup[] = [['text'=>"חזרה",'callback_data'=>"כלהפקודות"]];
$bot_API_markup = [ 'inline_keyboard'=> $bot_API_markup,];

$query->editText($message = "$txtbot", $replyMarkup = $bot_API_markup, ParseMode::HTML, $noWebpage = false, $scheduleDate = NULL);
} catch (Throwable $e) {
}
}

#[FilterButtonQueryData('פקודותלכלהמשתמשים')]
public function CommandForAll(callbackQuery $query) {
try {
$me = $this->getSelf();
$me_username = '@'.$me['username'];

$txtbot = "💡 <b>רשימת פקודות זמינות:</b>
/shabat - הצגת זמני כניסת ויציאה.
( גם /shabbat )
/stats - כמה קבוצות שומרות שבת/חג.
/donate - תמיכה ברובוט ⭐️

<b>ניתן גם להשתמש במצב אינליין:</b>
<code>$me_username shabat</code>
או:
<code>$me_username shabbat</code>
או:
<code>$me_username שבת</code>

<i>פקודות אלו ניתן לשלוח בכל צ'אט</i>";

$bot_API_markup[] = [['text'=>"חזרה",'callback_data'=>"כלהפקודות"]];
$bot_API_markup = [ 'inline_keyboard'=> $bot_API_markup,];

$query->editText($message = "$txtbot", $replyMarkup = $bot_API_markup, ParseMode::HTML, $noWebpage = false, $scheduleDate = NULL);
} catch (Throwable $e) {
}
}

#[FiltersOr(new FilterCommandCaseInsensitive('shabat'), new FilterCommandCaseInsensitive('shabbat'))]
public function shabatCommand(Incoming & IsNotEdited $message): void {
try {
$senderid = $message->senderId;
$messageid = $message->id;
$chatid = $message->chatId;

$inputReplyToMessage = ['_' => 'inputReplyToMessage', 'reply_to_msg_id' => $messageid];
$sentMessage = $this->messages->sendMessage(peer: $chatid, reply_to: $inputReplyToMessage, message: "⌛️", parse_mode: 'HTML');
$sentMessage2 = $this->extractMessageId($sentMessage);

$ShabatTimes = $this->getZmanimForCities();

$me = $this->getSelf();
$me_username = $me['username'];

$inlineQueryPeerTypePM = ['_' => 'inlineQueryPeerTypePM'];
$inlineQueryPeerTypeChat = ['_' => 'inlineQueryPeerTypeChat'];
$inlineQueryPeerTypeBotPM = ['_' => 'inlineQueryPeerTypeBotPM'];
$inlineQueryPeerTypeMegagroup = ['_' => 'inlineQueryPeerTypeMegagroup'];
$inlineQueryPeerTypeBroadcast = ['_' => 'inlineQueryPeerTypeBroadcast'];

$keyboardButtonSwitchInline = ['_' => 'keyboardButtonSwitchInline', 'same_peer' => false, 'text' => 'לשיתוף זמני השבת 🕯', 'query' => 'shabat', 'peer_types' => [$inlineQueryPeerTypePM, $inlineQueryPeerTypeChat, $inlineQueryPeerTypeBotPM, $inlineQueryPeerTypeMegagroup, $inlineQueryPeerTypeBroadcast]];
$keyboardButtonRow1 = ['_' => 'keyboardButtonRow', 'buttons' => [$keyboardButtonSwitchInline]];
$bot_API_markup = ['_' => 'replyInlineMarkup', 'rows' => [$keyboardButtonRow1]];

$this->messages->editMessage(peer: $message->chatId, id: $sentMessage2, message: "$ShabatTimes", reply_markup: $bot_API_markup, parse_mode: 'HTML');

} catch (Throwable $e) {
$this->messages->sendMessage(peer: $message->chatId, message: $e->getMessage());
}
}
	
public function onUpdateBotInlineQuery($update) {
try {

$ShabatTimes = $this->getZmanimForCities();

$me = $this->getSelf();
$me_username = $me['username'];

$inlineQueryPeerTypePM = ['_' => 'inlineQueryPeerTypePM'];
$inlineQueryPeerTypeChat = ['_' => 'inlineQueryPeerTypeChat'];
$inlineQueryPeerTypeBotPM = ['_' => 'inlineQueryPeerTypeBotPM'];
$inlineQueryPeerTypeMegagroup = ['_' => 'inlineQueryPeerTypeMegagroup'];
$inlineQueryPeerTypeBroadcast = ['_' => 'inlineQueryPeerTypeBroadcast'];

$keyboardButtonSwitchInline = ['_' => 'keyboardButtonSwitchInline', 'same_peer' => false, 'text' => 'לשיתוף זמני השבת 🕯', 'query' => 'shabat', 'peer_types' => [$inlineQueryPeerTypePM, $inlineQueryPeerTypeChat, $inlineQueryPeerTypeBotPM, $inlineQueryPeerTypeMegagroup, $inlineQueryPeerTypeBroadcast]];
$keyboardButtonRow1 = ['_' => 'keyboardButtonRow', 'buttons' => [$keyboardButtonSwitchInline]];
$bot_API_markup = ['_' => 'replyInlineMarkup', 'rows' => [$keyboardButtonRow1]];

$documentAttributeImageSize = ['_' => 'documentAttributeImageSize', 'w' => 475, 'h' => 475];
$inputWebDocument = ['_' => 'inputWebDocument', 'url' => 'https://telegra.ph/file/0b06390cc0e5236a5bd05-0fc4534fa4021ecb33.jpg', 'size' => 98166, 'mime_type' => 'image/jpeg', 'attributes' => [$documentAttributeImageSize]];

$botInlineMessageText = ['_' => 'inputBotInlineMessageText', 'message' => "$ShabatTimes", 'parse_mode'=> 'HTML', 'reply_markup' => $bot_API_markup];
$inputBotInlineResult = ['_' => 'botInlineResult', 'id' => '0', 'type' => 'article', 'title' => 'זמני כניסת השבת', 'description' => 'לחץ כאן לשיתוף זמני השבת!', 'thumb' => $inputWebDocument,'send_message' => $botInlineMessageText];
		  
        $this->logger("Got query ".$update['query']);
        try {
            $result = ['query_id' => $update['query_id'], 'results' => [$inputBotInlineResult], 'cache_time' => 0];


            if ($update['query'] === 'shabat' || $update['query'] === 'shabbat' || $update['query'] === 'שבת') {
$this->messages->setInlineBotResults($result);
            } else {
$this->messages->setInlineBotResults($result);
            }
        } catch (Throwable $e) {
            try {
$this->messages->sendMessage(['peer' => $update['user_id'], 'message' => $e->getCode().': '.$e->getMessage().PHP_EOL.$e->getTraceAsString()]);
} catch (RPCErrorException $e) {
$this->logger($e);
} catch (Exception $e) {
$this->logger($e);
}

}

} catch (Throwable $e) {
$sentMessage = $this->messages->sendMessage(peer: $update['query_id'], message: $e->getMessage());
}
}
	
/* ================ group handlers ================ */

#[FilterButtonQueryData('סגור')]
public function closecommand(callbackQuery $query) {
	try {
$this->messages->deleteMessages(revoke: true, id: [$query->messageId]); 
} catch (Throwable $e) {
$query->answer($message = "אני לא יכול לסגור את ההודעה, סגור אותה בעצמך..", $alert = false, $url = null, $cacheTime = 0);		
}
}

#[FilterCommandCaseInsensitive('add')]
public function addgroupCommand(Incoming & GroupMessage & IsNotEdited $message): void {
try {
$senderid = $message->senderId;
$messageid = $message->id;
$chatid = $message->chatId;
$me = $this->getSelf();
$me_name = $me['first_name'];
$me_id = $me['id'];

$Chat_Full = $this->getInfo($message->chatId);
$title = $Chat_Full['Chat']['title']?? null;
if($title == null){
$title = "(null)";
}

$admrgh = $Chat_Full['Chat']['admin_rights']['ban_users']?? null;

$type = $Chat_Full['type'];

if($type != "supergroup"){
$txtbot = "<b>אני פועל רק בקבוצות-על(supergroup)</b>";
$this->messages->sendMessage(peer: $message->chatId, message: "$txtbot", parse_mode: 'HTML');
}

if($type == "supergroup"){

if($message->senderId == $message->chatId){
$txtbot = "<b>הינך מנהל אנונימי.</b>
רק מנהל לא אנונימי יכול להוסיף את הקבוצה לבסיס נתונים!";
$this->messages->sendMessage(peer: $message->chatId, message: "$txtbot", parse_mode: 'HTML');
}else{

try {
$channelpart = $this->channels->getParticipant(['channel' => $message->chatId, 'participant' => $message->senderId]);
if(isset($channelpart['participant']['_'])&& ($channelpart['participant']['_'] == 'channelParticipantAdmin' || $channelpart['participant']['_'] == 'channelParticipantCreator'))  $isadmin = true;
else $isadmin = false;
} catch (Throwable $e) {
$isadmin = false;
}

if($isadmin != false){
	
try {
$channelpart2 = $this->channels->getParticipant(['channel' => $chatid, 'participant' => $me_id ]);
if(isset($channelpart2['participant']['_'])&& ($channelpart2['participant']['_'] == 'channelParticipantAdmin' || $channelpart2['participant']['_'] == 'channelParticipantCreator'))  $isadmin2 = true;
else $isadmin2 = false;	
} catch (Throwable $e) {
$isadmin2 = false;
}

if($isadmin2 != false){

if($admrgh == null){
$txtbot = "<b>אין לי הרשאות ניהול מתאימות.</b>
(הרשאות לחסימת משתמשים ושינוי הרשאות)";
$this->messages->sendMessage(peer: $message->chatId, message: "$txtbot", parse_mode: 'HTML');
}
if($admrgh != null){
	
	



if (file_exists(__DIR__."/"."data/DBgroups.txt")) {
$filex = Amp\File\read(__DIR__."/"."data/DBgroups.txt");  
$user1 = array_map('trim', explode("\n", $filex));

if (!in_array((string)$chatid, $user1, true)) {
if($filex != null){
$filex = $filex."\n"; 
Amp\File\write(__DIR__."/"."data/DBgroups.txt", "$filex"."$chatid");
$txtbot = "<b>הקבוצה נוספה לבסיס נתונים!</b>";
$this->messages->sendMessage(peer: $message->chatId, message: "$txtbot", parse_mode: 'HTML');
    if (!file_exists(__DIR__."/"."data/$chatid")) {
mkdir(__DIR__."/"."data/$chatid");
}
}
if($filex == null){
$filex = null; 
Amp\File\write(__DIR__."/"."data/DBgroups.txt", "$filex"."$chatid");
$txtbot = "<b>הקבוצה נוספה לבסיס נתונים!</b>";
$this->messages->sendMessage(peer: $message->chatId, message: "$txtbot", parse_mode: 'HTML');
    if (!file_exists(__DIR__."/"."data/$chatid")) {
mkdir(__DIR__."/"."data/$chatid");
}
}
}
if (in_array((string)$chatid, $user1, true)) {
$txtbot = "<b>הקבוצה כבר בבסיס נתונים!</b>";
$this->messages->sendMessage(peer: $message->chatId, message: "$txtbot", parse_mode: 'HTML');
}	
}	

if (!file_exists(__DIR__."/"."data/DBgroups.txt")) {
$filex = null; 
Amp\File\write(__DIR__."/"."data/DBgroups.txt", "$filex"."$chatid");
$txtbot = "<b>הקבוצה נוספה לבסיס נתונים!</b>";
$this->messages->sendMessage(peer: $message->chatId, message: "$txtbot", parse_mode: 'HTML');
    if (__DIR__."/".!file_exists("data/$chatid")) {
mkdir(__DIR__."/"."data/$chatid");
}
}


}
}
if($isadmin2 != true){
$txtbot = "<b>אני לא מנהל בקבוצה.</b>
(יש להוסיף אותי כמנהל)";
$this->messages->sendMessage(peer: $message->chatId, message: "$txtbot", parse_mode: 'HTML');
}

}
if($isadmin != true){
$txtbot = "<b>אינך מנהל או יוצר בקבוצה.</b>
רק מנהלים יכולים להוסיף את הקבוצה לבסיס נתונים!";
$this->messages->sendMessage(peer: $message->chatId, message: "$txtbot", parse_mode: 'HTML');
}

}

}
} catch (Throwable $e) {
$error = $e->getMessage();
$sentMessage = $this->messages->sendMessage(peer: $message->chatId, message: $error);
}
	}
	
#[FilterCommandCaseInsensitive('remove')]
public function removegroupCommand(Incoming & GroupMessage & IsNotEdited $message): void {
try {
$senderid = $message->senderId;
$messageid = $message->id;
$chatid = $message->chatId;
$me = $this->getSelf();
$me_name = $me['first_name'];
$me_id = $me['id'];
$Chat_Full = $this->getInfo($message->chatId);
$title = $Chat_Full['Chat']['title']?? null;
if($title == null){
$title = "(null)";
}

$admrgh = $Chat_Full['Chat']['admin_rights']['ban_users']?? null;

$type = $Chat_Full['type'];

if($type != "supergroup"){
$txtbot = "<b>אני פועל רק בקבוצות-על(supergroup)</b>";
$this->messages->sendMessage(peer: $message->chatId, message: "$txtbot", parse_mode: 'HTML');
}


if($type == "supergroup"){

if($message->senderId == $message->chatId){
$txtbot = "<b>הינך מנהל אנונימי.</b>
רק מנהל לא אנונימי יכול להוסיף את הקבוצה לבסיס נתונים!";
$this->messages->sendMessage(peer: $message->chatId, message: "$txtbot", parse_mode: 'HTML');
}else{
	
try {
$channelpart = $this->channels->getParticipant(['channel' => $chatid, 'participant' => $message->senderId ]);
if(isset($channelpart['participant']['_'])&& ($channelpart['participant']['_'] == 'channelParticipantAdmin' or $channelpart['participant']['_'] == 'channelParticipantCreator'))  $isadmin = true;
else $isadmin = false;
}catch (\danog\MadelineProto\Exception $e) {
$estring = (string) $e;
if(preg_match("/USER_NOT_PARTICIPANT/",$estring)){
$isadmin = false;
}else{
$isadmin = false;
}

} catch (\danog\MadelineProto\RPCErrorException $e) {
$estring = (string) $e;

    if ($e->rpc === 'USER_NOT_PARTICIPANT') {
$isadmin = false;
}else{
$isadmin = false;
}
}


if($isadmin != false){
	
try {
$channelpart2 = $this->channels->getParticipant(['channel' => $chatid, 'participant' => $me_id ]);
if(isset($channelpart2['participant']['_'])&& ($channelpart2['participant']['_'] == 'channelParticipantAdmin' or $channelpart2['participant']['_'] == 'channelParticipantCreator'))  $isadmin2 = true;
else $isadmin2 = false;	
} catch (Throwable $e) {
$isadmin2 = false;
}


if($isadmin2 != false){

if($admrgh == null){
$txtbot = "<b>אין לי הרשאות ניהול מתאימות.</b>
(הרשאות לחסימת משתמשים ושינוי הרשאות)";
$this->messages->sendMessage(peer: $message->chatId, message: "$txtbot", parse_mode: 'HTML');
}
if($admrgh != null){
	
	



if (file_exists(__DIR__."/"."data/DBgroups.txt")) {
$filex = Amp\File\read(__DIR__."/"."data/DBgroups.txt");  
$user1 = array_map('trim', explode("\n", $filex));

if (in_array((string)$chatid, $user1, true)) {
$filex = Amp\File\read(__DIR__."/"."data/DBgroups.txt");  
$chatidstring = (string) $chatid;
$result = str_replace($chatidstring,"",$filex);
Amp\File\write(__DIR__."/"."data/DBgroups.txt", $result);

$filex2 = Amp\File\read(__DIR__."/"."data/DBgroups.txt");  
$result2 = preg_replace('/^[ \t]*[\r\n]+/m', '', $filex2);
Amp\File\write(__DIR__."/"."data/DBgroups.txt", $result2);

$txtbot = "<b>הקבוצה הוסרה בהצלחה! אני עוזב את הקבוצה...</b>";
$this->messages->sendMessage(peer: $message->chatId, message: "$txtbot", parse_mode: 'HTML');
$this->channels->leaveChannel(channel: $message->chatId );


if (file_exists(__DIR__."/"."data/$chatidstring/alertshabat.txt")) {
unlink(__DIR__."/"."data/$chatidstring/alertshabat.txt");
}
if (file_exists(__DIR__."/"."data/$chatidstring/msgclosermotan.txt")) {
unlink(__DIR__."/"."data/$chatidstring/msgclosermotan.txt");
}


}
if (!in_array((string)$chatid, $user1, true)) {
$txtbot = "<b>הקבוצה כבר הוסרה מבסיס נתונים!</b>";
$this->messages->sendMessage(peer: $message->chatId, message: "$txtbot", parse_mode: 'HTML');
}	
}	

if (!file_exists(__DIR__."/"."data/DBgroups.txt")) {
$txtbot = "<b>הקבוצה כבר הוסרה מבסיס נתונים!</b>";
$this->messages->sendMessage(peer: $message->chatId, message: "$txtbot", parse_mode: 'HTML');
}


}
}
if($isadmin2 != true){
$txtbot = "<b>אני לא מנהל בקבוצה.</b>
(יש להוסיף אותי כמנהל)";
$this->messages->sendMessage(peer: $message->chatId, message: "$txtbot", parse_mode: 'HTML');
}

}
if($isadmin != true){
$txtbot = "<b>אינך מנהל או יוצר בקבוצה.</b>
רק מנהלים יכולים להוסיף את הקבוצה לבסיס נתונים!";
$this->messages->sendMessage(peer: $message->chatId, message: "$txtbot", parse_mode: 'HTML');
}



}

}

} catch (Throwable $e) {
$error = $e->getMessage();
$sentMessage = $this->messages->sendMessage(peer: $message->chatId, message: $error);

}
	}
	
#[FilterCommandCaseInsensitive('settings')]
public function grupsettingsCommand(Incoming & GroupMessage & IsNotEdited $message): void {
	try {
$senderid = $message->senderId;
$messageid = $message->id;
$chatid = $message->chatId;
$me = $this->getSelf();
$me_name = $me['first_name'];
$me_id = $me['id'];
$me_username = $me['username'];

$Chat_Full = $this->getInfo($message->chatId);
$title = $Chat_Full['Chat']['title']?? null;
if($title == null){
$title = "(null)";
}

$admrgh = $Chat_Full['Chat']['admin_rights']['ban_users']?? null;

$type = $Chat_Full['type'];

if($type != "supergroup"){
$txtbot = "<b>אני פועל רק בקבוצות-על(supergroup)</b>";
$this->messages->sendMessage(peer: $message->chatId, message: "$txtbot", parse_mode: 'HTML');
}


if($type == "supergroup"){

if($message->senderId == $message->chatId){
$txtbot = "<b>הינך מנהל אנונימי.</b>
רק מנהל לא אנונימי יכול לפתוח תפריט הגדרות!";
$this->messages->sendMessage(peer: $message->chatId, message: "$txtbot", parse_mode: 'HTML');
}else{
	
try {
$channelpart = $this->channels->getParticipant(['channel' => $chatid, 'participant' => $message->senderId ]);
if(isset($channelpart['participant']['_'])&& ($channelpart['participant']['_'] == 'channelParticipantAdmin' or $channelpart['participant']['_'] == 'channelParticipantCreator'))  $isadmin = true;
else $isadmin = false;
} catch (Throwable $e) {
$isadmin = false;
}

if($isadmin != false){
	

try {
$channelpart2 = $this->channels->getParticipant(['channel' => $chatid, 'participant' => $me_id ]);
if(isset($channelpart2['participant']['_'])&& ($channelpart2['participant']['_'] == 'channelParticipantAdmin' or $channelpart2['participant']['_'] == 'channelParticipantCreator'))  $isadmin2 = true;
else $isadmin2 = false;	
}catch (\danog\MadelineProto\Exception $e) {
$estring = (string) $e;
if(preg_match("/USER_NOT_PARTICIPANT/",$estring)){
$isadmin2 = false;
}else{
$isadmin2 = false;
}

} catch (\danog\MadelineProto\RPCErrorException $e) {
$estring = (string) $e;

    if ($e->rpc === 'USER_NOT_PARTICIPANT') {
$isadmin2 = false;
}else{
$isadmin2 = false;
}
}


if($isadmin2 != false){

if($admrgh == null){
$txtbot = "<b>אין לי הרשאות ניהול מתאימות.</b>
(הרשאות לחסימת משתמשים ושינוי הרשאות)";
$this->messages->sendMessage(peer: $message->chatId, message: "$txtbot", parse_mode: 'HTML');
}
if($admrgh != null){

if (file_exists(__DIR__."/"."data/DBgroups.txt")) {
$filex = Amp\File\read(__DIR__."/"."data/DBgroups.txt");  
$user1 = array_map('trim', explode("\n", $filex));

if (!in_array((string)$chatid, $user1, true)) {
$txtbot = "<b>הקבוצה לא נוספה לבסיס נתונים!</b>
שלח את הפקודה <code>/add</code>";
$this->messages->sendMessage(peer: $message->chatId, message: "$txtbot", parse_mode: 'HTML');
}

if (in_array((string)$chatid, $user1, true)) {
	
$txtbot2 = "<b>התאם אישית את הרובוט בקבוצה:</b>";
if (!file_exists(__DIR__."/"."data/$chatid/alertshabat.txt")) {
$bot_API_markup[] = [['text'=>"OFF ❌",'callback_data'=>"שלחזמני"],['text'=>"שלח זמנים:",'callback_data'=>"הסברזמנישבת"]];
}
if (file_exists(__DIR__."/"."data/$chatid/alertshabat.txt")) {
$bot_API_markup[] = [['text'=>"ON ✅",'callback_data'=>"שלחזמני1"],['text'=>"שלח זמנים:",'callback_data'=>"הסברזמנישבת"]];
}
if (!file_exists(__DIR__."/"."data/$chatid/alertshabat2.txt")) {
$bot_API_markup[] = [['text'=>"OFF ❌",'callback_data'=>"הודעותלפניואחרי"],['text'=>"הודעות סגירה/פתיחה:",'callback_data'=>"הסברהודעותלפאח"]];
}
if (file_exists(__DIR__."/"."data/$chatid/alertshabat2.txt")) {
$bot_API_markup[] = [['text'=>"ON ✅",'callback_data'=>"הודעותלפניואחרי1"],['text'=>"הודעות סגירה/פתיחה:",'callback_data'=>"הסברהודעותלפאח"]];
}
$bot_API_markup[] = [['text'=>"הודעה לפני סגירה ✏️",'callback_data'=>"הודעתסגירה"]];
$bot_API_markup[] = [['text'=>"הודעה לאחר פתיחה ✏️",'callback_data'=>"הודעתפתיחה"]];
$bot_API_markup[] = [['text'=>"↪️ החזר לברירת מחדל",'callback_data'=>"החזרברירתמחדל"]];
$bot_API_markup[] = [['text'=>"סגור ✖️",'callback_data'=>"סגור"]];
$bot_API_markup = [ 'inline_keyboard'=> $bot_API_markup,];
try {
$this->messages->sendMessage(peer: $senderid, message: "$txtbot2", reply_markup: $bot_API_markup, parse_mode: 'HTML');
$txtbot = "<b>פאנל ההגדרות נשלח אליך בהודעה פרטית.</b>";
$bot_API_markup2[] = [['text'=>"לחץ כאן למעבר ⚙️",'url'=>"https://t.me/$me_username"]];
$bot_API_markup2 = [ 'inline_keyboard'=> $bot_API_markup2,];
$this->messages->sendMessage(peer: $message->chatId, message: "$txtbot", reply_markup: $bot_API_markup2, parse_mode: 'HTML');
} catch (Throwable $e) {
$error = $e->getMessage();
$sentMessage = $this->messages->sendMessage(peer: $message->chatId, message: $error);
}

    if (!file_exists(__DIR__."/"."data/$senderid")) {
mkdir(__DIR__."/"."data/$senderid");
}
Amp\File\write(__DIR__."/"."data/$senderid/groupid.txt", "$chatid");
}
}

if (!file_exists(__DIR__."/"."data/DBgroups.txt")) {
$txtbot = "<b>הקבוצה לא נוספה לבסיס נתונים!</b>\nשלח את הפקודה <code>/add</code>";
$this->messages->sendMessage(peer: $message->chatId, message: "$txtbot", parse_mode: 'HTML');
}
}
}
if($isadmin2 != true){
$txtbot = "<b>אני לא מנהל בקבוצה.</b>
(יש להוסיף אותי כמנהל)";
$this->messages->sendMessage(peer: $message->chatId, message: "$txtbot", parse_mode: 'HTML');
}

}
if($isadmin != true){
$txtbot = "<b>אינך מנהל או יוצר בקבוצה.</b>
רק מנהלים יכולים לפתוח פאנל הגדרות!";
$this->messages->sendMessage(peer: $message->chatId, message: "$txtbot", parse_mode: 'HTML');
}



}

}

} catch (Throwable $e) {
$error = $e->getMessage();
$sentMessage = $this->messages->sendMessage(peer: $message->chatId, message: $error);

}
	}
	
#[FiltersOr(new FilterCommandCaseInsensitive('add'), new FilterCommandCaseInsensitive('remove'), new FilterCommandCaseInsensitive('settings'))]
public function ifNotCommands(Incoming & PrivateMessage & IsNotEdited $message): void {
try {
$messageid = $message->id;

$this->messages->deleteMessages(revoke: true, id: [$messageid]); 

$sentMessage = $this->messages->sendMessage(peer: $message->senderId, message: "❌ <i>פקודה זו יש לשלוח בקבוצה בלבד!</i>", parse_mode: 'HTML');
$sentMessage2 = $this->extractMessageId($sentMessage);

$this->sleep(3);
$this->messages->deleteMessages(revoke: true, id: [$sentMessage2]); 
} catch (Throwable $e) {
}
}

#[FilterButtonQueryData('חזרהלהגדרות')]
public function backtosettings(callbackQuery $query) {
try {
$userid = $query->userId;   
$chatid = $query->chatId; 
$User_Full = $this->getInfo($userid);
$first_name = $User_Full['User']['first_name']?? null;
if($first_name == null){
$first_name = "null";
}

if (file_exists(__DIR__."/"."data/$userid/groupid.txt")) {
$filex = Amp\File\read(__DIR__."/"."data/$userid/groupid.txt");  
}else{
$filex = "NULL";  	
}

$txtbot = "<b>התאם אישית את הרובוט בקבוצה:</b>";
if (!file_exists(__DIR__."/"."data/$filex/alertshabat.txt")) {
$bot_API_markup[] = [['text'=>"OFF ❌",'callback_data'=>"שלחזמני"],['text'=>"שלח זמנים:",'callback_data'=>"הסברזמנישבת"]];
}
if (file_exists(__DIR__."/"."data/$filex/alertshabat.txt")) {
$bot_API_markup[] = [['text'=>"ON ✅",'callback_data'=>"שלחזמני1"],['text'=>"שלח זמנים:",'callback_data'=>"הסברזמנישבת"]];
}
if (!file_exists(__DIR__."/"."data/$filex/alertshabat2.txt")) {
$bot_API_markup[] = [['text'=>"OFF ❌",'callback_data'=>"הודעותלפניואחרי"],['text'=>"הודעות סגירה/פתיחה:",'callback_data'=>"הסברהודעותלפאח"]];
}
if (file_exists(__DIR__."/"."data/$filex/alertshabat2.txt")) {
$bot_API_markup[] = [['text'=>"ON ✅",'callback_data'=>"הודעותלפניואחרי1"],['text'=>"ההודעות סגירה/פתיחה:",'callback_data'=>"הסברהודעותלפאח"]];
}
$bot_API_markup[] = [['text'=>"הודעה לפני סגירה ✏️",'callback_data'=>"הודעתסגירה"]];
$bot_API_markup[] = [['text'=>"הודעה לאחר פתיחה ✏️",'callback_data'=>"הודעתפתיחה"]];
$bot_API_markup[] = [['text'=>"↪️ החזר לברירת מחדל",'callback_data'=>"החזרברירתמחדל"]];
$bot_API_markup[] = [['text'=>"סגור ✖️",'callback_data'=>"סגור"]];
$bot_API_markup = [ 'inline_keyboard'=> $bot_API_markup,];

$query->editText($message = "$txtbot", $replyMarkup = $bot_API_markup, ParseMode::HTML, $noWebpage = false, $scheduleDate = NULL);
} catch (Throwable $e) {
}
}

#[FilterButtonQueryData('החזרברירתמחדל')]
public function defaultset(callbackQuery $query) {
try {
$userid = $query->userId;   
$chatid = $query->chatId; 
$User_Full = $this->getInfo($userid);
$first_name = $User_Full['User']['first_name']?? null;
if($first_name == null){
$first_name = "null";
}

$txtbot = "<b>האם הינך בטוח?</b>
בלחיצה על כן, ההגדרות יאופסו!
(פעולה זו לא ניתנת לשחזור)";

$bot_API_markup[] = [['text'=>"כן, אני בטוח!",'callback_data'=>"החזרברירתמחדלאישור"]];
$bot_API_markup[] = [['text'=>"ביטול",'callback_data'=>"חזרהלהגדרות"]];

$bot_API_markup = [ 'inline_keyboard'=> $bot_API_markup,];
$query->editText($message = "$txtbot", $replyMarkup = $bot_API_markup, ParseMode::HTML, $noWebpage = false, $scheduleDate = NULL);

} catch (Throwable $e) {}
}

#[FilterButtonQueryData('החזרברירתמחדלאישור')]
public function defaultset1(callbackQuery $query) {
try {
$userid = $query->userId;   
$chatid = $query->chatId; 
$User_Full = $this->getInfo($userid);
$first_name = $User_Full['User']['first_name']?? null;
if($first_name == null){
$first_name = "null";
}

$txtbot = "<b>ההגדרות אופסו לברירת מחדל</b> ⚙️";
$bot_API_markup[] = [['text'=>"חזרה להגדרות",'callback_data'=>"חזרהלהגדרות"]];
$bot_API_markup = [ 'inline_keyboard'=> $bot_API_markup,];
$query->editText($message = "$txtbot", $replyMarkup = $bot_API_markup, ParseMode::HTML, $noWebpage = false, $scheduleDate = NULL);

if (file_exists(__DIR__."/"."data/$userid/groupid.txt")) {
$filex = Amp\File\read(__DIR__."/"."data/$userid/groupid.txt");  

if (file_exists(__DIR__."/"."data/$filex/alertshabat.txt")) {
unlink(__DIR__."/"."data/$filex/alertshabat.txt");
}
if (file_exists(__DIR__."/"."data/$filex/alertshabat2.txt")) {
unlink(__DIR__."/"."data/$filex/alertshabat2.txt");
}
if (file_exists(__DIR__."/"."data/$filex/msgclosermotan2.txt")) {
unlink(__DIR__."/"."data/$filex/msgclosermotan2.txt");
}
if (file_exists(__DIR__."/"."data/$filex/msgclosermotan.txt")) {
unlink(__DIR__."/"."data/$filex/msgclosermotan.txt");
}

### הודעת פתיחה ####
if (file_exists(__DIR__."/"."data/$filex/MsgOpenerMedia.txt")) {
unlink(__DIR__."/"."data/$filex/MsgOpenerMedia.txt");  
}
if (file_exists(__DIR__."/"."data/$filex/MsgOpener.txt")) {
unlink(__DIR__."/"."data/$filex/MsgOpener.txt"); 
}
if (file_exists(__DIR__."/"."data/$filex/MsgOpener2.txt")) {
unlink(__DIR__."/"."data/$filex/MsgOpener2.txt");  	
}
if (file_exists(__DIR__."/"."data/$filex/MsgOpenerButtons.txt")) {
unlink(__DIR__."/"."data/$filex/MsgOpenerButtons.txt");  
}
####################

### הודעת סגירה ####
if (file_exists(__DIR__."/"."data/$filex/MsgCloserMedia.txt")) {
unlink(__DIR__."/"."data/$filex/MsgCloserMedia.txt");  
}
if (file_exists(__DIR__."/"."data/$filex/MsgCloser.txt")) {
unlink(__DIR__."/"."data/$filex/MsgCloser.txt"); 	
}
if (file_exists(__DIR__."/"."data/$filex/MsgCloser2.txt")) {
unlink(__DIR__."/"."data/$filex/MsgCloser2.txt");  	
}
if (file_exists(__DIR__."/"."data/$filex/MsgCloserButtons.txt")) {
unlink(__DIR__."/"."data/$filex/MsgCloserButtons.txt");  
}
####################

}

} catch (Throwable $e) {}
}

#[FilterButtonQueryData('הסברזמנישבת')]
public function infotimes(callbackQuery $query) {
try {
$query->answer($message = "האם הקבוצה תקבל מידי יום שישי / יום חג (בשעה 13:30) הודעה עם זמני כניסת השבת/חג!", $alert = true, $url = null, $cacheTime = 0);		
} catch (Throwable $e) {
}
}

#[FilterButtonQueryData('הסברהודעותלפאח')]
public function infomessages(callbackQuery $query) {
try {
$query->answer($message = "האם הקבוצה תקבל מידי יום שישי/יום חג הודעה שתשלח בערב הכניסה(כשהקבוצה נסגרת) ובזמן הבדלה(יציאה) כשהקבוצה נפתחת!
• ניתן להשתמש בברירת מחדל.
• וניתן להגדיר הודעה מותאמת אישית.", $alert = true, $url = null, $cacheTime = 0);	
} catch (Throwable $e) {
}	
}

#[FilterButtonQueryData('שלחזמני')]
public function TimesON(callbackQuery $query) {
try {
$userid = $query->userId;   
$chatid = $query->chatId; 
$User_Full = $this->getInfo($userid);
$first_name = $User_Full['User']['first_name']?? null;
if($first_name == null){
$first_name = "null";
}

if (file_exists(__DIR__."/"."data/$userid/groupid.txt")) {
$filex = Amp\File\read(__DIR__."/"."data/$userid/groupid.txt");  
Amp\File\write(__DIR__."/"."data/$filex/alertshabat.txt", "on");
}

$bot_API_markup[] = [['text'=>"חזרה",'callback_data'=>"חזרהלהגדרות"]];
$bot_API_markup = [ 'inline_keyboard'=> $bot_API_markup,];
$query->editText($message = "<b>הקבוצה תקבל מידי יום שישי/חג (בשעה 13:30) הודעה עם הזמנים! ✅</b>", $replyMarkup = $bot_API_markup, ParseMode::HTML, $noWebpage = false, $scheduleDate = NULL);
} catch (Throwable $e) {
}
}

#[FilterButtonQueryData('שלחזמני1')]
public function TimesOFF(callbackQuery $query) {
try {
$userid = $query->userId;   
$chatid = $query->chatId; 
$User_Full = $this->getInfo($userid);
$first_name = $User_Full['User']['first_name']?? null;
if($first_name == null){
$first_name = "null";
}

if (file_exists(__DIR__."/"."data/$userid/groupid.txt")) {
$filex = Amp\File\read(__DIR__."/"."data/$userid/groupid.txt");  
if (file_exists(__DIR__."/"."data/$filex/alertshabat.txt")) {
unlink(__DIR__."/"."data/$filex/alertshabat.txt");
}
}

$bot_API_markup[] = [['text'=>"חזרה",'callback_data'=>"חזרהלהגדרות"]];
$bot_API_markup = [ 'inline_keyboard'=> $bot_API_markup,];
$query->editText($message = "<b>הקבוצה לא תקבל מידי יום שישי/חג הודעה עם הזמנים! ❌</b>", $replyMarkup = $bot_API_markup, ParseMode::HTML, $noWebpage = false, $scheduleDate = NULL);
} catch (Throwable $e) {
}
}


/*
* parser group buttons
*/
private function parseButtonsold(string $input): array {
    $keyboard = [];

    $lines = preg_split('/\r\n|\r|\n/', trim($input));

    foreach ($lines as $line) {

        $line = trim($line);

        if ($line === '') {
            continue;
        }

        /*
        |--------------------------------------------------------------------------
        | כמה כפתורים באותה שורה
        |--------------------------------------------------------------------------
        */

        $buttonsInRow = explode('&&', $line);

        $row = [];

        foreach ($buttonsInRow as $buttonRaw) {

            $buttonRaw = trim($buttonRaw);

            /*
            |--------------------------------------------------------------------------
            | text - action - options
            |--------------------------------------------------------------------------
            */

            $parts = array_map(
                'trim',
                explode(' - ', $buttonRaw)
            );

            if (count($parts) < 2) {
                continue;
            }

            $text = $parts[0];

            $action = $parts[1];

            $button = [
                'text' => $text,
            ];

            /*
            |--------------------------------------------------------------------------
            | URL
            |--------------------------------------------------------------------------
            */

            if (preg_match('/^https?:\/\//i', $action)) {

                $button['url'] = $action;
            }

            /*
            |--------------------------------------------------------------------------
            | alert:
            |--------------------------------------------------------------------------
            */

            elseif (str_starts_with($action, 'alert:')) {

                $button['callback_data'] =
                    'alert:' .
                    base64_encode(
                        mb_substr(
                            substr($action, 6),
                            0,
                            180
                        )
                    );
            }

            /*
            |--------------------------------------------------------------------------
            | popup:
            |--------------------------------------------------------------------------
            */

            elseif (str_starts_with($action, 'popup:')) {

                $button['callback_data'] =
                    'popup:' .
                    base64_encode(
                        mb_substr(
                            substr($action, 6),
                            0,
                            180
                        )
                    );
            }

            /*
            |--------------------------------------------------------------------------
            | copy:
            |--------------------------------------------------------------------------
            */

            elseif (str_starts_with($action, 'copy:')) {

                $button['copy_text'] = [
                    '_' => 'copyTextButton',
                    'text' => substr($action, 5),
                ];
            }

            /*
            |--------------------------------------------------------------------------
            | style
            |--------------------------------------------------------------------------
            */

            $style = [];

            foreach ($parts as $index => $part) {

                if ($index < 2) {
                    continue;
                }

                /*
                |--------------------------------------------------------------------------
                | style:bg_success
                |--------------------------------------------------------------------------
                */

                if (str_starts_with($part, 'style:')) {

                    $styleValue = substr($part, 6);

                    if ($styleValue === 'bg_success') {
                        $style['bg_success'] = true;
                    }

                    if ($styleValue === 'bg_primary') {
                        $style['bg_primary'] = true;
                    }

                    if ($styleValue === 'bg_danger') {
                        $style['bg_danger'] = true;
                    }
                }

                /*
                |--------------------------------------------------------------------------
                | icon:
                |--------------------------------------------------------------------------
                */

                if (str_starts_with($part, 'icon:')) {

                    $style['icon'] =
                        (int) trim(
                            substr($part, 5)
                        );
                }
            }

            if (!empty($style)) {

                $button['style'] = array_merge(
                    [
                        '_' => 'keyboardButtonStyle'
                    ],
                    $style
                );
            }

            $row[] = $button;
        }

        if (!empty($row)) {
            $keyboard[] = $row;
        }
    }

    return $keyboard;
}

private function parseButtons(string $input): array {
    $rows = [];

    $lines = preg_split(
        '/\r\n|\r|\n/',
        trim($input)
    );

    foreach ($lines as $line) {

        $line = trim($line);

        if ($line === '') {
            continue;
        }

        /*
        |--------------------------------------------------------------------------
        | כמה כפתורים באותה שורה
        |--------------------------------------------------------------------------
        */

        $buttonsInRow = explode(
            '&&',
            $line
        );

        $buttons = [];

        foreach ($buttonsInRow as $buttonRaw) {

            $buttonRaw = trim($buttonRaw);

            /*
            |--------------------------------------------------------------------------
            | text - action - options
            |--------------------------------------------------------------------------
            */

            $parts = array_map(
                'trim',
                explode(' - ', $buttonRaw)
            );

            if (count($parts) < 2) {
                continue;
            }

            $text = $parts[0];

            $action = $parts[1];

            /*
            |--------------------------------------------------------------------------
            | style
            |--------------------------------------------------------------------------
            */

            $style = [];

            foreach ($parts as $index => $part) {

                if ($index < 2) {
                    continue;
                }

                /*
                |--------------------------------------------------------------------------
                | style:
                |--------------------------------------------------------------------------
                */

                if (
                    str_starts_with(
                        $part,
                        'style:'
                    )
                ) {

                    $styleValue = trim(
                        substr($part, 6)
                    );

                    if ($styleValue === 'bg_success') {
                        $style['bg_success'] = true;
                    }

                    if ($styleValue === 'bg_primary') {
                        $style['bg_primary'] = true;
                    }

                    if ($styleValue === 'bg_danger') {
                        $style['bg_danger'] = true;
                    }
                }

                /*
                |--------------------------------------------------------------------------
                | icon:
                |--------------------------------------------------------------------------
                */

                if (
                    str_starts_with(
                        $part,
                        'icon:'
                    )
                ) {

                    $style['icon'] = (int) trim(
                        substr($part, 5)
                    );
                }
            }

            /*
            |--------------------------------------------------------------------------
            | URL button
            |--------------------------------------------------------------------------
            */

            if (
                preg_match(
                    '/^https?:\/\//i',
                    $action
                )
            ) {

                $button = [
                    '_' => 'keyboardButtonUrl',

                    'text' => $text,

                    'url' => $action,
                ];
            }

            /*
            |--------------------------------------------------------------------------
            | alert:
            |--------------------------------------------------------------------------
            */

            elseif (
                str_starts_with(
                    $action,
                    'alert:'
                )
            ) {

                $button = [
                    '_' => 'keyboardButtonCallback',

                    'text' => $text,

                    'data' =>
                        'alert:' .
                        base64_encode(
                            mb_substr(
                                substr($action, 6),
                                0,
                                180
                            )
                        ),
                ];
            }

            /*
            |--------------------------------------------------------------------------
            | popup:
            |--------------------------------------------------------------------------
            */

            elseif (
                str_starts_with(
                    $action,
                    'popup:'
                )
            ) {

                $button = [
                    '_' => 'keyboardButtonCallback',

                    'text' => $text,

                    'data' =>
                        'popup:' .
                        base64_encode(
                            mb_substr(
                                substr($action, 6),
                                0,
                                180
                            )
                        ),
                ];
            }

            /*
            |--------------------------------------------------------------------------
            | copy:
            |--------------------------------------------------------------------------
            */

            elseif (
                str_starts_with(
                    $action,
                    'copy:'
                )
            ) {

                $button = [
                    '_' => 'keyboardButtonCopy',

                    'text' => $text,

                    'copy_text' => substr(
                        $action,
                        5
                    ),
                ];
            }

            else {
                continue;
            }

            /*
            |--------------------------------------------------------------------------
            | style
            |--------------------------------------------------------------------------
            */

            if (!empty($style)) {

                $button['style'] = array_merge(
                    [
                        '_' => 'keyboardButtonStyle'
                    ],
                    $style
                );
            }

            $buttons[] = $button;
        }

        if (!empty($buttons)) {

            $rows[] = [
                '_' => 'keyboardButtonRow',
                'buttons' => $buttons,
            ];
        }
    }

    return [
        '_' => 'replyInlineMarkup',
        'rows' => $rows,
    ];
}

/*
* group buttons callback
*/
#[Handler]
public function buttonCallbacks(CallbackQuery $query): void {
try {
    $data = $query->data;

    if (str_starts_with($data, 'alert:')) {

        $text = base64_decode(
            substr($data, 6)
        );

        $query->answer(
            message: $text,
            alert: false
        );

        return;
    }

    if (str_starts_with($data, 'popup:')) {

        $text = base64_decode(
            substr($data, 6)
        );

        $query->answer(
            message: $text,
            alert: true
        );

        return;
    }
} catch (Throwable $e) {}
}

/*
* group buttons validate input
*/
private function validateButtonsInput(string $input): bool {

    $lines = preg_split(
        '/\r\n|\r|\n/',
        trim($input)
    );

    foreach ($lines as $line) {

        $line = trim($line);

        if ($line === '') {
            continue;
        }

        /*
        |--------------------------------------------------------------------------
        | כמה כפתורים בשורה
        |--------------------------------------------------------------------------
        */

        $buttons = explode(
            '&&',
            $line
        );

        foreach ($buttons as $button) {

            $button = trim($button);

            $parts = array_map(
                'trim',
                explode(' - ', $button)
            );

            /*
            |--------------------------------------------------------------------------
            | חייב לפחות:
            | text - action
            |--------------------------------------------------------------------------
            */

            if (count($parts) < 2) {
                return false;
            }

            $action = $parts[1];

            /*
            |--------------------------------------------------------------------------
            | URL
            |--------------------------------------------------------------------------
            */

            if (
                preg_match(
                    '/^https?:\/\//i',
                    $action
                )
            ) {
                continue;
            }

            /*
            |--------------------------------------------------------------------------
            | alert:
            |--------------------------------------------------------------------------
            */

            if (
                str_starts_with(
                    $action,
                    'alert:'
                )
            ) {
                continue;
            }

            /*
            |--------------------------------------------------------------------------
            | popup:
            |--------------------------------------------------------------------------
            */

            if (
                str_starts_with(
                    $action,
                    'popup:'
                )
            ) {
                continue;
            }

            /*
            |--------------------------------------------------------------------------
            | copy:
            |--------------------------------------------------------------------------
            */

            if (
                str_starts_with(
                    $action,
                    'copy:'
                )
            ) {
                continue;
            }

            return false;
        }
    }

    return true;
}


#[FilterButtonQueryData('הודעותלפניואחרי')]
public function MessagesON(callbackQuery $query) {
try {
$userid = $query->userId;   
$chatid = $query->chatId; 
$User_Full = $this->getInfo($userid);
$first_name = $User_Full['User']['first_name']?? null;
if($first_name == null){
$first_name = "null";
}

if (file_exists(__DIR__."/"."data/$userid/groupid.txt")) {
$filex = Amp\File\read(__DIR__."/"."data/$userid/groupid.txt");  
Amp\File\write(__DIR__."/"."data/$filex/alertshabat2.txt", "on");
}

$bot_API_markup[] = [['text'=>"חזרה",'callback_data'=>"חזרהלהגדרות"]];
$bot_API_markup = [ 'inline_keyboard'=> $bot_API_markup,];

$query->editText($message = "<b>הקבוצה תקבל מידי יום שישי/חג הודעה שתשלח בכניסה(כשהקבוצה נסגרת) וביציאה(כשהקבוצה נפתחת)!</b> ✅", $replyMarkup = $bot_API_markup, ParseMode::HTML, $noWebpage = false, $scheduleDate = NULL);
} catch (Throwable $e) {
}
}

#[FilterButtonQueryData('הודעותלפניואחרי1')]
public function MessagesOFF(callbackQuery $query) {
try {
$userid = $query->userId;   
$chatid = $query->chatId; 
$User_Full = $this->getInfo($userid);
$first_name = $User_Full['User']['first_name']?? null;
if($first_name == null){
$first_name = "null";
}

if (file_exists(__DIR__."/"."data/$userid/groupid.txt")) {
$filex = Amp\File\read(__DIR__."/"."data/$userid/groupid.txt");  
if (file_exists(__DIR__."/"."data/$filex/alertshabat2.txt")) {
unlink(__DIR__."/"."data/$filex/alertshabat2.txt");
}
}

$bot_API_markup[] = [['text'=>"חזרה",'callback_data'=>"חזרהלהגדרות"]];
$bot_API_markup = [ 'inline_keyboard'=> $bot_API_markup,];

$query->editText($message = "<b>הקבוצה לא תקבל מידי יום שישי/חג הודעת פתיחה/סגירה!</b> ❌", $replyMarkup = $bot_API_markup, ParseMode::HTML, $noWebpage = false, $scheduleDate = NULL);
} catch (Throwable $e) {
}
}

#[FilterButtonQueryData('הודעתפתיחה')]
public function OpenMessage(callbackQuery $query) {
try {
$userid = $query->userId;   
$chatid = $query->chatId; 
$User_Full = $this->getInfo($userid);
$first_name = $User_Full['User']['first_name']?? null;
if($first_name == null){
$first_name = "null";
}

$bot_API_markup[] = [['text'=>"מדיה 🖼",'callback_data'=>"הגדרמדיה_1"],['text'=>"👀 צפה",'callback_data'=>"צפהבמדיה_1"]];
$bot_API_markup[] = [['text'=>"טקסט 🔤",'callback_data'=>"הגדרטקסט_1"],['text'=>"👀 צפה",'callback_data'=>"צפהבטקסט_1"]];
$bot_API_markup[] = [['text'=>"כפתורים ⌨️",'callback_data'=>"הגדרכפתורים_1"],['text'=>"👀 צפה",'callback_data'=>"צפהבכפתורים_1"]];
$bot_API_markup[] = [['text'=>"תצוגה מקדימה מלאה 👁",'callback_data'=>"תצוגהמקדימהפתיחה_1"]];
$bot_API_markup[] = [['text'=>"חזרה להגדרות",'callback_data'=>"חזרהלהגדרות"]];
$bot_API_markup = [ 'inline_keyboard'=> $bot_API_markup,];

$query->editText($message = "כאן תוכל להגדיר הודעת פתיחה מותאמת אישית שתשלח במוצאי שבת/חג כשהקבוצה נפתחת!", $replyMarkup = $bot_API_markup, ParseMode::HTML, $noWebpage = false, $scheduleDate = NULL);
    if (file_exists(__DIR__."/"."data/$userid/grs1.txt")) {
unlink(__DIR__."/"."data/$userid/grs1.txt");
}
} catch (Throwable $e) {}
}

#[FilterButtonQueryData('חזרההודעתפתיחה')]
public function OpenMessage2(callbackQuery $query) {
try {
$userid = $query->userId;   
$chatid = $query->chatId; 
$msgqutryid = $query->messageId;
$User_Full = $this->getInfo($userid);
$first_name = $User_Full['User']['first_name']?? null;
if($first_name == null){
$first_name = "null";
}

$bot_API_markup[] = [['text'=>"מדיה 🖼",'callback_data'=>"הגדרמדיה_1"],['text'=>"👀 צפה",'callback_data'=>"צפהבמדיה_1"]];
$bot_API_markup[] = [['text'=>"טקסט 🔤",'callback_data'=>"הגדרטקסט_1"],['text'=>"👀 צפה",'callback_data'=>"צפהבטקסט_1"]];
$bot_API_markup[] = [['text'=>"כפתורים ⌨️",'callback_data'=>"הגדרכפתורים_1"],['text'=>"👀 צפה",'callback_data'=>"צפהבכפתורים_1"]];
$bot_API_markup[] = [['text'=>"תצוגה מקדימה מלאה 👁",'callback_data'=>"תצוגהמקדימהפתיחה_1"]];
$bot_API_markup[] = [['text'=>"חזרה להגדרות",'callback_data'=>"חזרהלהגדרות"]];
$bot_API_markup = [ 'inline_keyboard'=> $bot_API_markup,];

$this->messages->sendMessage(peer: $userid, message: "כאן תוכל להגדיר הודעת פתיחה מותאמת אישית שתשלח במוצאי שבת/חג כשהקבוצה נפתחת!", reply_markup: $bot_API_markup, parse_mode: 'HTML');

			try {
$this->messages->deleteMessages(revoke: true, id: [$msgqutryid]); 
} catch (Throwable $e) {}

    if (file_exists(__DIR__."/"."data/$userid/grs1.txt")) {
unlink(__DIR__."/"."data/$userid/grs1.txt");
}
} catch (Throwable $e) {}
}

#[FilterButtonQueryData('הגדרטקסט_1')] 
public function GroupTextSet1(callbackQuery $query) {
	try {
$userid = $query->userId;    
$User_Full = $this->getInfo($userid);
$first_name = $User_Full['User']['first_name']?? null;
if($first_name == null){
$first_name = "null";
}

if (file_exists(__DIR__."/"."data/$userid/groupid.txt")) {
$filex = Amp\File\read(__DIR__."/"."data/$userid/groupid.txt");  
}else{
$filex = "null"; 	
}

if (file_exists(__DIR__."/"."data/$filex/MsgOpener.txt")) {	
$bot_API_markup[] = [['text'=>"חזור 🔙",'callback_data'=>"הודעתפתיחה"],['text'=>"🗑 הסר את הטקסט",'callback_data'=>"מחקטקסט_1"]];
$bot_API_markup = [ 'inline_keyboard'=> $bot_API_markup,];
}else{
$bot_API_markup[] = [['text'=>"חזור 🔙",'callback_data'=>"הודעתפתיחה"]];
$bot_API_markup = [ 'inline_keyboard'=> $bot_API_markup,];
}

$query->editText($message = "<b>שלח את הודעת הפתיחה:</b>
<i>עד 1024 תווים, ניתן להשתמש בכל סיגנונות העיצוב.</i>
", $replyMarkup = $bot_API_markup, ParseMode::HTML, $noWebpage = false, $scheduleDate = NULL);
Amp\File\write(__DIR__."/data/$userid/grs1.txt", 'opener_text_1');
$msgqutryid = $query->messageId;
Amp\File\write(__DIR__."/data/$userid/messagetodelete.txt", "$msgqutryid");
} catch (Throwable $e) {}
}

#[FilterButtonQueryData('מחקטקסט_1')] 
public function RemoveText1(callbackQuery $query) {
	try {
$userid = $query->userId;    
$User_Full = $this->getInfo($userid);
$first_name = $User_Full['User']['first_name']?? null;
if($first_name == null){
$first_name = "null";
}

$bot_API_markup[] = [['text'=>"לא ❌",'callback_data'=>"הגדרטקסט_1"],['text'=>"כן ✅",'callback_data'=>"מחקטקסט_1אישור"]];
$bot_API_markup = [ 'inline_keyboard'=> $bot_API_markup,];

$query->editText($message = "האם אתה באמת בטוח שאתה רוצה למחוק את הטקסט של הודעת הפתיחה?", $replyMarkup = $bot_API_markup, ParseMode::HTML, $noWebpage = false, $scheduleDate = NULL);
} catch (Throwable $e) {}
}

#[FilterButtonQueryData('מחקטקסט_1אישור')] 
public function RemoveText1_1(callbackQuery $query) {
	try {
$userid = $query->userId;    
$User_Full = $this->getInfo($userid);
$first_name = $User_Full['User']['first_name']?? null;
if($first_name == null){
$first_name = "null";
}

if (file_exists(__DIR__."/"."data/$userid/groupid.txt")) {
$filex = Amp\File\read(__DIR__."/"."data/$userid/groupid.txt");  
}else{
$filex = "null"; 	
}

if (file_exists(__DIR__."/"."data/$filex/MsgOpener.txt")) {	
unlink(__DIR__."/"."data/$filex/MsgOpener.txt");
}
if (file_exists(__DIR__."/"."data/$filex/MsgOpener2.txt")) {	
unlink(__DIR__."/"."data/$filex/MsgOpener2.txt");
}

$bot_API_markup[] = [['text'=>"🔙 חזור 🔙",'callback_data'=>"הודעתפתיחה"]];
$bot_API_markup = [ 'inline_keyboard'=> $bot_API_markup,];

$query->editText($message = "<b>הטקסט הוסר</b> 🗑", $replyMarkup = $bot_API_markup, ParseMode::HTML, $noWebpage = false, $scheduleDate = NULL);
} catch (Throwable $e) {}
}

#[FilterButtonQueryData('צפהבטקסט_1')] 
public function GroupTextView1(callbackQuery $query) {
	try {
$userid = $query->userId;    
$msgqutryid = $query->messageId;
$User_Full = $this->getInfo($userid);
$first_name = $User_Full['User']['first_name']?? null;
if($first_name == null){
$first_name = "null";
}

if (file_exists(__DIR__."/"."data/$userid/groupid.txt")) {
$filex = Amp\File\read(__DIR__."/"."data/$userid/groupid.txt");  
}else{
$filex = "null"; 	
}

if (file_exists(__DIR__."/"."data/$filex/MsgOpener.txt")) {
$TXT = Amp\File\read(__DIR__."/"."data/$filex/MsgOpener.txt"); 
if($TXT != null){
if (file_exists(__DIR__."/"."data/$filex/MsgOpener2.txt")) {
$ENT = json_decode(Amp\File\read(__DIR__."/"."data/$filex/MsgOpener2.txt"),true);  
}else{
$ENT = null; 	
}

$bot_API_markup[] = [['text'=>"🔙 חזור 🔙",'callback_data'=>"הודעתפתיחה"]];
$bot_API_markup = [ 'inline_keyboard'=> $bot_API_markup,];
$this->messages->editMessage(no_webpage: true, peer: $userid, id: $msgqutryid, message: $TXT, reply_markup: $bot_API_markup, entities: $ENT);
}else{
$query->answer($message = "ההודעה לא מוגדרת.", $alert = true, $url = null, $cacheTime = 0);
}
}else{
$query->answer($message = "ההודעה לא מוגדרת.", $alert = true, $url = null, $cacheTime = 0);
}
} catch (Throwable $e) {}
}

#[FilterButtonQueryData('הגדרמדיה_1')] 
public function GroupMsgMedia1(callbackQuery $query) {
	try {
$userid = $query->userId;    
$User_Full = $this->getInfo($userid);
$first_name = $User_Full['User']['first_name']?? null;
if($first_name == null){
$first_name = "null";
}

if (file_exists(__DIR__."/"."data/$userid/groupid.txt")) {
$filex = Amp\File\read(__DIR__."/"."data/$userid/groupid.txt");  
}else{
$filex = "null"; 	
}

if (file_exists(__DIR__."/"."data/$filex/MsgOpenerMedia.txt")) {	
$bot_API_markup[] = [['text'=>"חזור 🔙",'callback_data'=>"הודעתפתיחה"],['text'=>"🗑 הסר את המדיה",'callback_data'=>"מחקמדיה_1"]];
$bot_API_markup = [ 'inline_keyboard'=> $bot_API_markup,];
}else{
$bot_API_markup[] = [['text'=>"חזור 🔙",'callback_data'=>"הודעתפתיחה"]];
$bot_API_markup = [ 'inline_keyboard'=> $bot_API_markup,];
}

$query->editText($message = "<b>שלח את המדיה החדשה לפתיחה:</b>
<i>מדיה מותרת: תמונות, סרטונים, קבצים, מדבקות, קובצי GIF, אודיו, הודעות קוליות, סרטונים עגולים ועוד..(כל סוגי המדיה הנתמכים)</i>
", $replyMarkup = $bot_API_markup, ParseMode::HTML, $noWebpage = false, $scheduleDate = NULL);
Amp\File\write(__DIR__."/data/$userid/grs1.txt", 'opener_media_1');
$msgqutryid = $query->messageId;
Amp\File\write(__DIR__."/data/$userid/messagetodelete.txt", "$msgqutryid");
} catch (Throwable $e) {}
}

#[FilterButtonQueryData('צפהבמדיה_1')] 
public function GroupMsgMediaView1(callbackQuery $query) {
	try {
$userid = $query->userId;    
$msgqutryid = $query->messageId;
$User_Full = $this->getInfo($userid);
$first_name = $User_Full['User']['first_name']?? null;
if($first_name == null){
$first_name = "null";
}

if (file_exists(__DIR__."/"."data/$userid/groupid.txt")) {
$filex = Amp\File\read(__DIR__."/"."data/$userid/groupid.txt");  
}else{
$filex = "null"; 	
}

if (file_exists(__DIR__."/"."data/$filex/MsgOpenerMedia.txt")) {
$MEDIA = Amp\File\read(__DIR__."/"."data/$filex/MsgOpenerMedia.txt");  

$bot_API_markup[] = [['text'=>"🔙 חזרה 🔙",'callback_data'=>"חזרההודעתפתיחה"]];
$bot_API_markup = [ 'inline_keyboard'=> $bot_API_markup,];

			try {
$sentMessage = $this->messages->sendMedia(peer: $userid, media: $MEDIA, reply_markup: $bot_API_markup);
} catch (Throwable $e) {
$sentMessage = $this->messages->sendMessage(peer: $userid, message: $e->getMessage(), reply_markup: $bot_API_markup);
}

			try {
$this->messages->deleteMessages(revoke: true, id: [$msgqutryid]); 
} catch (Throwable $e) {}

}else{
$query->answer($message = "ההודעה לא מוגדרת.", $alert = true, $url = null, $cacheTime = 0);
}

} catch (Throwable $e) {}
}

#[FilterButtonQueryData('מחקמדיה_1')] 
public function RemoveMedia1(callbackQuery $query) {
	try {
$userid = $query->userId;    
$User_Full = $this->getInfo($userid);
$first_name = $User_Full['User']['first_name']?? null;
if($first_name == null){
$first_name = "null";
}

$bot_API_markup[] = [['text'=>"לא ❌",'callback_data'=>"הגדרמדיה_1"],['text'=>"כן ✅",'callback_data'=>"מחקמדיה_1אישור"]];
$bot_API_markup = [ 'inline_keyboard'=> $bot_API_markup,];

$query->editText($message = "האם אתה באמת בטוח שאתה רוצה למחוק את המדיה של הודעת הפתיחה?", $replyMarkup = $bot_API_markup, ParseMode::HTML, $noWebpage = false, $scheduleDate = NULL);
} catch (Throwable $e) {}
}

#[FilterButtonQueryData('מחקמדיה_1אישור')] 
public function RemoveMedia1_1(callbackQuery $query) {
	try {
$userid = $query->userId;    
$User_Full = $this->getInfo($userid);
$first_name = $User_Full['User']['first_name']?? null;
if($first_name == null){
$first_name = "null";
}

if (file_exists(__DIR__."/"."data/$userid/groupid.txt")) {
$filex = Amp\File\read(__DIR__."/"."data/$userid/groupid.txt");  
}else{
$filex = "null"; 	
}

if (file_exists(__DIR__."/"."data/$filex/MsgOpenerMedia.txt")) {
unlink(__DIR__."/"."data/$filex/MsgOpenerMedia.txt");  
}

$bot_API_markup[] = [['text'=>"🔙 חזור 🔙",'callback_data'=>"הודעתפתיחה"]];
$bot_API_markup = [ 'inline_keyboard'=> $bot_API_markup,];

$query->editText($message = "<b>המדיה הוסרה</b> 🗑", $replyMarkup = $bot_API_markup, ParseMode::HTML, $noWebpage = false, $scheduleDate = NULL);
} catch (Throwable $e) {}
}

#[FilterButtonQueryData('צפהבכפתורים_1')] 
public function buttonsmanageviewgroup1(callbackQuery $query) {
	try {
$userid = $query->userId;    
$User_Full = $this->getInfo($userid);
$first_name = $User_Full['User']['first_name']?? null;
if($first_name == null){
$first_name = "null";
}
$username = $User_Full['User']['username']?? null;

if (file_exists(__DIR__."/"."data/$userid/groupid.txt")) {
$filex = Amp\File\read(__DIR__."/"."data/$userid/groupid.txt");  
}else{
$filex = "null"; 	
}

    if (file_exists(__DIR__."/"."data/$filex/MsgOpenerButtons.txt")) {
$BUTTONS = Amp\File\read(__DIR__."/"."data/$filex/MsgOpenerButtons.txt");
$bot_API_markup = $this->parseButtons($BUTTONS);
} else {

    $bot_API_markup = [
        '_' => 'replyInlineMarkup',
        'rows' => [],
    ];
}

$bot_API_markup['rows'][] = [

    '_' => 'keyboardButtonRow',

    'buttons' => [

        [
            '_' => 'keyboardButtonCallback',

            'text' => '🔙 חזור 🔙',

            'data' => 'הודעתפתיחה',
        ]
    ]
];

$query->editText($message = "צפייה בכפתורים:", $replyMarkup = $bot_API_markup, ParseMode::HTML, $noWebpage = false, $scheduleDate = NULL);
} catch (Throwable $e) {
$query->editText($message = $e->getMessage());
}
}

#[FilterButtonQueryData('הגדרכפתורים_1')] 
public function GroupMsgButtonSet1(callbackQuery $query) {
	try {
$userid = $query->userId;    
$User_Full = $this->getInfo($userid);
$first_name = $User_Full['User']['first_name']?? null;
if($first_name == null){
$first_name = "null";
}

if (file_exists(__DIR__."/"."data/$userid/groupid.txt")) {
$filex = Amp\File\read(__DIR__."/"."data/$userid/groupid.txt");  
}else{
$filex = "null"; 	
}

if (file_exists(__DIR__."/"."data/$filex/MsgOpenerButtons.txt")) {	
$bot_API_markup[] = [['text'=>"חזור 🔙",'callback_data'=>"הודעתפתיחה"],['text'=>"🗑 הסר כפתורים",'callback_data'=>"מחקכפתורים_1"]];
$bot_API_markup = [ 'inline_keyboard'=> $bot_API_markup,];
}else{
$bot_API_markup[] = [['text'=>"חזור 🔙",'callback_data'=>"הודעתפתיחה"]];
$bot_API_markup = [ 'inline_keyboard'=> $bot_API_markup,];
}

$query->editText($message = "<b>שלח את הכפתורים שתרצה להוסיף בפורמט הבא:</b>
<pre>Button text 1 - http://www.example.com/ \nButton text 2 - http://www.example2.com/</pre>

<b>🔘 שלח כפתורים בפורמט:</b>

<pre>טקסט - פעולה</pre>

<b>📌 פעולות נתמכות:</b>
<pre>https://example.com
alert:טקסט
popup:טקסט
copy:טקסט</pre>

<b>📌 כמה כפתורים באותה שורה:</b>

<pre>כפתור 1 - https://t.me/test1 &amp;&amp; כפתור 2 - https://t.me/test2</pre>

<b>📌 עיצוב נתמך:</b>
<pre>style:bg_primary
style:bg_success
style:bg_danger</pre>

<b>📌 אייקון:</b>
<pre>icon:123456 </pre>

<b>📌 דוגמאות:</b>
<pre>כניסה - https://t.me/test

התראה - alert:שבת שלום - style: bg_danger

פופאפ - popup:הקבוצה תיפתח במוצ&quot;ש

העתקה - copy:https://t.me/test

אישור - https://t.me/test - style:bg_success

קבוצה - https://t.me/test - style:bg_primary - icon:5424972470023104089

אתר - https://google.com &amp;&amp; תמיכה - https://t.me/support</pre>

<b>⚠️ הערות:</b>
<pre>• כל שורה חדשה = שורת כפתורים חדשה
• &amp;&amp; = כמה כפתורים באותה שורה
• ניתן לשלב style + icon יחד
• ניתן להשתמש בלי עיצוב או אייקון</pre>", $replyMarkup = $bot_API_markup, ParseMode::HTML, $noWebpage = false, $scheduleDate = NULL);
Amp\File\write(__DIR__."/data/$userid/grs1.txt", 'opener_buttons_1');
$msgqutryid = $query->messageId;
Amp\File\write(__DIR__."/data/$userid/messagetodelete.txt", "$msgqutryid");
} catch (Throwable $e) {}
}

#[FilterButtonQueryData('מחקכפתורים_1')] 
public function RemoveButtons1(callbackQuery $query) {
	try {
$userid = $query->userId;    
$User_Full = $this->getInfo($userid);
$first_name = $User_Full['User']['first_name']?? null;
if($first_name == null){
$first_name = "null";
}

$bot_API_markup[] = [['text'=>"לא ❌",'callback_data'=>"הגדרכפתורים_1"],['text'=>"כן ✅",'callback_data'=>"מחקכפתורים_1אישור"]];
$bot_API_markup = [ 'inline_keyboard'=> $bot_API_markup,];

$query->editText($message = "האם אתה באמת בטוח שאתה רוצה למחוק את הכפתורים של הודעת הפתיחה?", $replyMarkup = $bot_API_markup, ParseMode::HTML, $noWebpage = false, $scheduleDate = NULL);
} catch (Throwable $e) {}
}

#[FilterButtonQueryData('מחקכפתורים_1אישור')] 
public function RemoveButtons1_1(callbackQuery $query) {
	try {
$userid = $query->userId;    
$User_Full = $this->getInfo($userid);
$first_name = $User_Full['User']['first_name']?? null;
if($first_name == null){
$first_name = "null";
}

if (file_exists(__DIR__."/"."data/$userid/groupid.txt")) {
$filex = Amp\File\read(__DIR__."/"."data/$userid/groupid.txt");  
}else{
$filex = "null"; 	
}

if (file_exists(__DIR__."/"."data/$filex/MsgOpenerButtons.txt")) {
unlink(__DIR__."/"."data/$filex/MsgOpenerButtons.txt");  
}

$bot_API_markup[] = [['text'=>"🔙 חזור 🔙",'callback_data'=>"הודעתפתיחה"]];
$bot_API_markup = [ 'inline_keyboard'=> $bot_API_markup,];

$query->editText($message = "<b>הכפתורים הוסרו</b> 🗑", $replyMarkup = $bot_API_markup, ParseMode::HTML, $noWebpage = false, $scheduleDate = NULL);
} catch (Throwable $e) {}
}

#[Handler]
public function HandleGroupMsgSet1(Incoming & PrivateMessage $message): void {
		try {
$messagetext = $message->message;
$entities = $message->entities;
$messagefile = $message->media;
$grouped_id = $message->groupedId;
$messageid = $message->id;
$senderid = $message->senderId;
$User_Full = $this->getInfo($message->senderId);
$first_name = $User_Full['User']['first_name']?? null;
if($first_name == null){
$first_name = "null";
}
$last_name = $User_Full['User']['last_name']?? null;
if($last_name == null){
$last_name = "null";
}
$username = $User_Full['User']['username']?? null;
if($username == null){
$username = "null";
}

if(!preg_match('/^\/([Ss]tart)/',$messagetext)){  

    if (file_exists(__DIR__."/data/$senderid/grs1.txt")) {
$check = Amp\File\read(__DIR__."/data/$senderid/grs1.txt");    

if (file_exists(__DIR__."/"."data/$senderid/groupid.txt")) {
$filex = Amp\File\read(__DIR__."/"."data/$senderid/groupid.txt");  
}else{
$filex = "null"; 	
}

if($check == "opener_text_1"){ 

if($grouped_id != null){
$bot_API_markup[] = [['text'=>"❌ ביטול ❌",'callback_data'=>"הודעתפתיחה"]];
$bot_API_markup = [ 'inline_keyboard'=> $bot_API_markup,];
$this->messages->sendMessage(peer: $senderid, message: "<b>נא לשלוח הודעת טקסט בלבד!</b>", reply_markup: $bot_API_markup, parse_mode: 'HTML');

			try {
$this->messages->deleteMessages(revoke: true, id: [$messageid]); 
} catch (Throwable $e) {}

 if (file_exists(__DIR__."/data/$senderid/messagetodelete.txt")) {
$filexmsgid = Amp\File\read(__DIR__."/data/$senderid/messagetodelete.txt");  

			try {
$this->messages->deleteMessages(revoke: true, id: [$filexmsgid]); 
} catch (Throwable $e) {}

}
}else{
	
if($messagefile){

$bot_API_markup[] = [['text'=>"❌ ביטול ❌",'callback_data'=>"הודעתפתיחה"]];
$bot_API_markup = [ 'inline_keyboard'=> $bot_API_markup,];
$this->messages->sendMessage(peer: $senderid, message: "<b>נא לשלוח הודעת טקסט בלבד!</b>", reply_markup: $bot_API_markup, parse_mode: 'HTML');

			try {
$this->messages->deleteMessages(revoke: true, id: [$messageid]); 
} catch (Throwable $e) {}

 if (file_exists(__DIR__."/data/$senderid/messagetodelete.txt")) {
$filexmsgid = Amp\File\read(__DIR__."/data/$senderid/messagetodelete.txt");  

			try {
$this->messages->deleteMessages(revoke: true, id: [$filexmsgid]); 
} catch (Throwable $e) {}

}

}

if(!$messagefile){
$messageLength = mb_strlen($messagetext);

if($messageLength > 1024) {

$bot_API_markup[] = [['text'=>"❌ ביטול ❌",'callback_data'=>"הודעתפתיחה"]];
$bot_API_markup = [ 'inline_keyboard'=> $bot_API_markup,];
$this->messages->sendMessage(peer: $senderid, message: "<b>נא לשלוח טקסט עד 1024 תווים</b>
כמות התווים ששלחת: $messageLength", reply_markup: $bot_API_markup, parse_mode: 'HTML');

			try {
$this->messages->deleteMessages(revoke: true, id: [$messageid]); 
} catch (Throwable $e) {}

 if (file_exists(__DIR__."/data/$senderid/messagetodelete.txt")) {
$filexmsgid = Amp\File\read(__DIR__."/data/$senderid/messagetodelete.txt");  
			try {
$this->messages->deleteMessages(revoke: true, id: [$filexmsgid]); 
} catch (Throwable $e) {}

}

} 
else 
{
unlink(__DIR__."/data/$senderid/grs1.txt");

$bot_API_markup[] = [['text'=>"חזרה",'callback_data'=>"הודעתפתיחה"]];
$bot_API_markup = [ 'inline_keyboard'=> $bot_API_markup,];
$this->messages->sendMessage(peer: $senderid, message: "<b>ההודעה נשמרה בהצלחה! ✔️</b>", reply_markup: $bot_API_markup, parse_mode: 'HTML');

			try {
$this->messages->deleteMessages(revoke: true, id: [$messageid]); 
} catch (Throwable $e) {}

 if (file_exists(__DIR__."/data/$senderid/messagetodelete.txt")) {
$filexmsgid = Amp\File\read(__DIR__."/data/$senderid/messagetodelete.txt");  
			try {
$this->messages->deleteMessages(revoke: true, id: [$filexmsgid]); 
} catch (Throwable $e) {}

}

Amp\File\write(__DIR__."/"."data/$filex/MsgOpener.txt", $messagetext);
Amp\File\write(__DIR__."/"."data/$filex/MsgOpener2.txt", json_encode(array_map(static fn($e) => $e->toMTProto(),$entities,)));

}


	
}


}

}

if($check == "opener_media_1"){
 
if($grouped_id != null){
$bot_API_markup[] = [['text'=>"❌ ביטול ❌",'callback_data'=>"הודעתפתיחה"]];
$bot_API_markup = [ 'inline_keyboard'=> $bot_API_markup,];
$this->messages->sendMessage(peer: $senderid, message: "<b>אין תמיכה באלבומים!</b>", reply_markup: $bot_API_markup, parse_mode: 'HTML');

			try {
$this->messages->deleteMessages(revoke: true, id: [$messageid]); 
} catch (Throwable $e) {}

 if (file_exists(__DIR__."/data/$senderid/messagetodelete.txt")) {
$filexmsgid = Amp\File\read(__DIR__."/data/$senderid/messagetodelete.txt");  

			try {
$this->messages->deleteMessages(revoke: true, id: [$filexmsgid]); 
} catch (Throwable $e) {}

}
}else{
	
if(!$messagefile){
			try {
$this->messages->deleteMessages(revoke: true, id: [$messageid]); 
}catch (\danog\MadelineProto\Exception $e) {
$estring = (string) $e;
if(preg_match("/MESSAGE_DELETE_FORBIDDEN/",$estring)){
}

} catch (\danog\MadelineProto\RPCErrorException $e) {
    if ($e->rpc === 'MESSAGE_DELETE_FORBIDDEN') {	
}
}

 if (file_exists(__DIR__."/data/$senderid/messagetodelete.txt")) {
$filexmsgid = Amp\File\read(__DIR__."/data/$senderid/messagetodelete.txt");  
			try {
$this->messages->deleteMessages(revoke: true, id: [$filexmsgid]); 
}catch (\danog\MadelineProto\Exception $e) {
$estring = (string) $e;
if(preg_match("/MESSAGE_DELETE_FORBIDDEN/",$estring)){
}

} catch (\danog\MadelineProto\RPCErrorException $e) {
    if ($e->rpc === 'MESSAGE_DELETE_FORBIDDEN') {	
}
}

}

$bot_API_markup[] = [['text'=>"❌ ביטול ❌",'callback_data'=>"הודעתפתיחה"]];
$bot_API_markup = [ 'inline_keyboard'=> $bot_API_markup,];
$this->messages->sendMessage(peer: $senderid, message: "<b>נא לשלוח מדיה בלבד!</b>", reply_markup: $bot_API_markup, parse_mode: 'HTML');

}

if($messagefile){

unlink(__DIR__."/data/$senderid/grs1.txt");

$bot_API_markup[] = [['text'=>"חזרה",'callback_data'=>"הודעתפתיחה"]];
$bot_API_markup = [ 'inline_keyboard'=> $bot_API_markup,];
$this->messages->sendMessage(peer: $senderid, message: "<b>המדיה נשמרה בהצלחה! ✔️</b>", reply_markup: $bot_API_markup, parse_mode: 'HTML');

			try {
$this->messages->deleteMessages(revoke: true, id: [$messageid]); 
} catch (Throwable $e) {}

 if (file_exists(__DIR__."/data/$senderid/messagetodelete.txt")) {
$filexmsgid = Amp\File\read(__DIR__."/data/$senderid/messagetodelete.txt");  
			try {
$this->messages->deleteMessages(revoke: true, id: [$filexmsgid]); 
} catch (Throwable $e) {}

}

$botApiFileId = $message->media->botApiFileId;
Amp\File\write(__DIR__."/"."data/$filex/MsgOpenerMedia.txt", $botApiFileId);
}


	
}

}

if($check == "opener_buttons_1"){

if ($this->validateButtonsInput($messagetext)) {
unlink(__DIR__."/data/$senderid/grs1.txt");

Amp\File\write(__DIR__."/"."data/$filex/MsgOpenerButtons.txt", $messagetext);

			try {
$this->messages->deleteMessages(revoke: true, id: [$messageid]); 
} catch (Throwable $e) {}

 if (file_exists(__DIR__."/data/$senderid/messagetodelete.txt")) {
$filexmsgid = Amp\File\read(__DIR__."/data/$senderid/messagetodelete.txt");  
$bot_API_markup[] = [['text'=>"חזרה",'callback_data'=>"הודעתפתיחה"]];
$bot_API_markup = [ 'inline_keyboard'=> $bot_API_markup,];
$Updates = $this->messages->editMessage(peer: $senderid, id: $filexmsgid, message: "<b>הכפתורים נשמרו בהצלחה! ✔️</b>", reply_markup: $bot_API_markup, parse_mode: 'HTML');

}


} else {
			try {
$this->messages->deleteMessages(revoke: true, id: [$messageid]); 
} catch (Throwable $e) {}

 if (file_exists(__DIR__."/data/$senderid/messagetodelete.txt")) {
$filexmsgid = Amp\File\read(__DIR__."/data/$senderid/messagetodelete.txt");  
$bot_API_markup[] = [['text'=>"❌ ביטול ❌",'callback_data'=>"הודעתפתיחה"]];
$bot_API_markup = [ 'inline_keyboard'=> $bot_API_markup,];

			try {
$Updates = $this->messages->editMessage(peer: $senderid, id: $filexmsgid, message: "<b>נא שלח את הכפתורים שתרצה להוסיף בפורמט הנכון!</b>", reply_markup: $bot_API_markup, parse_mode: 'HTML');
} catch (Throwable $e) {}


}
}


}

}
}
} catch (Throwable $e) {}
}

#[FilterButtonQueryData('תצוגהמקדימהפתיחה_1')] 
public function view_welcomeMessage_full(callbackQuery $query) {
	try {
$userid = $query->userId;    
$msgqutryid = $query->messageId;
$User_Full = $this->getInfo($userid);
$first_name = $User_Full['User']['first_name']?? null;
if($first_name == null){
$first_name = "null";
}

if (file_exists(__DIR__."/"."data/$userid/groupid.txt")) {
$filex = Amp\File\read(__DIR__."/"."data/$userid/groupid.txt");  
}else{
$filex = "null"; 	
}

if (file_exists(__DIR__."/"."data/$filex/MsgOpenerMedia.txt")) {
$MEDIA = Amp\File\read(__DIR__."/"."data/$filex/MsgOpenerMedia.txt");  
}else{
$MEDIA = null; 	
}
if (file_exists(__DIR__."/"."data/$filex/MsgOpener.txt")) {
$TXT = Amp\File\read(__DIR__."/"."data/$filex/MsgOpener.txt"); 
}else{
$TXT = null; 	
}
if (file_exists(__DIR__."/"."data/$filex/MsgOpener2.txt")) {
$ENT = json_decode(Amp\File\read(__DIR__."/"."data/$filex/MsgOpener2.txt"),true);  
}else{
$ENT = null; 	
}
if (file_exists(__DIR__."/"."data/$filex/MsgOpenerButtons.txt")) {
$BUTTONS = Amp\File\read(__DIR__."/"."data/$filex/MsgOpenerButtons.txt");
$bot_API_markup_welcome = $this->parseButtons($BUTTONS);
} else {

    $bot_API_markup_welcome = [
        '_' => 'replyInlineMarkup',
        'rows' => [],
    ];
}

$bot_API_markup[] = [['text'=>"מדיה 🖼",'callback_data'=>"הגדרמדיה_1"],['text'=>"👀 צפה",'callback_data'=>"צפהבמדיה_1"]];
$bot_API_markup[] = [['text'=>"טקסט 🔤",'callback_data'=>"הגדרטקסט_1"],['text'=>"👀 צפה",'callback_data'=>"צפהבטקסט_1"]];
$bot_API_markup[] = [['text'=>"כפתורים ⌨️",'callback_data'=>"הגדרכפתורים_1"],['text'=>"👀 צפה",'callback_data'=>"צפהבכפתורים_1"]];
$bot_API_markup[] = [['text'=>"תצוגה מקדימה מלאה 👁",'callback_data'=>"תצוגהמקדימהפתיחה_1"]];
$bot_API_markup[] = [['text'=>"🔙 חזור 🔙",'callback_data'=>"חזרהלהגדרות"]];
$bot_API_markup = [ 'inline_keyboard'=> $bot_API_markup,];

if($MEDIA != null){

if($TXT != null){

			try {
$this->messages->deleteMessages(revoke: true, id: [$msgqutryid]); 
} catch (Throwable $e) {}

$this->messages->sendMessage(peer: $userid, message: "➖➖➖➖➖➖➖➖➖");
$this->messages->sendMessage(peer: $userid, message: "👇🏻 תצוגה מקדימה מלאה");
$sentMessage = $this->messages->sendMedia(peer: $userid, message: "$TXT",  entities: $ENT, media: $MEDIA, reply_markup: $bot_API_markup_welcome);

$this->messages->sendMessage(peer: $userid, message: "כאן תוכל להגדיר הודעת פתיחה מותאמת אישית שתשלח במוצאי שבת כשהקבוצה נפתחת!", reply_markup: $bot_API_markup, parse_mode: 'HTML');

}else{
	
			try {
$this->messages->deleteMessages(revoke: true, id: [$msgqutryid]); 
} catch (Throwable $e) {}

$OPENER = self::OPENER;
$this->messages->sendMessage(peer: $userid, message: "➖➖➖➖➖➖➖➖➖");
$this->messages->sendMessage(peer: $userid, message: "👇🏻 תצוגה מקדימה מלאה");
$sentMessage = $this->messages->sendMedia(peer: $userid, message: $OPENER, media: $MEDIA, reply_markup: $bot_API_markup_welcome);	

$this->messages->sendMessage(peer: $userid, message: "כאן תוכל להגדיר הודעת פתיחה מותאמת אישית שתשלח במוצאי שבת כשהקבוצה נפתחת!", reply_markup: $bot_API_markup, parse_mode: 'HTML');

}

}else{

if($TXT != null){
	
			try {
$this->messages->deleteMessages(revoke: true, id: [$msgqutryid]); 
} catch (Throwable $e) {}

$this->messages->sendMessage(peer: $userid, message: "➖➖➖➖➖➖➖➖➖");
$this->messages->sendMessage(peer: $userid, message: "👇🏻 תצוגה מקדימה מלאה");
$sentMessage = $this->messages->sendMessage(peer: $userid, message: "$TXT", entities: $ENT, reply_markup: $bot_API_markup_welcome);

$this->messages->sendMessage(peer: $userid, message: "כאן תוכל להגדיר הודעת פתיחה מותאמת אישית שתשלח במוצאי שבת כשהקבוצה נפתחת!", reply_markup: $bot_API_markup, parse_mode: 'HTML');

}else{

			try {
$this->messages->deleteMessages(revoke: true, id: [$msgqutryid]); 
} catch (Throwable $e) {}

$OPENER = self::OPENER;
$this->messages->sendMessage(peer: $userid, message: "➖➖➖➖➖➖➖➖➖");
$this->messages->sendMessage(peer: $userid, message: "👇🏻 תצוגה מקדימה מלאה");
$sentMessage = $this->messages->sendMessage(peer: $userid, message: $OPENER, reply_markup: $bot_API_markup_welcome);

$this->messages->sendMessage(peer: $userid, message: "כאן תוכל להגדיר הודעת פתיחה מותאמת אישית שתשלח במוצאי שבת כשהקבוצה נפתחת!", reply_markup: $bot_API_markup, parse_mode: 'HTML');

}


}

} catch (Throwable $e) {}
}

#[FilterButtonQueryData('הודעתסגירה')]
public function CloseMessage(callbackQuery $query) {
try {
$userid = $query->userId;   
$chatid = $query->chatId; 
$User_Full = $this->getInfo($userid);
$first_name = $User_Full['User']['first_name']?? null;
if($first_name == null){
$first_name = "null";
}

$bot_API_markup[] = [['text'=>"מדיה 🖼",'callback_data'=>"הגדרמדיה_2"],['text'=>"👀 צפה",'callback_data'=>"צפהבמדיה_2"]];
$bot_API_markup[] = [['text'=>"טקסט 🔤",'callback_data'=>"הגדרטקסט_2"],['text'=>"👀 צפה",'callback_data'=>"צפהבטקסט_2"]];
$bot_API_markup[] = [['text'=>"כפתורים ⌨️",'callback_data'=>"הגדרכפתורים_2"],['text'=>"👀 צפה",'callback_data'=>"צפהבכפתורים_2"]];
$bot_API_markup[] = [['text'=>"תצוגה מקדימה מלאה 👁",'callback_data'=>"תצוגהמקדימהפתיחה_2"]];
$bot_API_markup[] = [['text'=>"חזרה להגדרות",'callback_data'=>"חזרהלהגדרות"]];
$bot_API_markup = [ 'inline_keyboard'=> $bot_API_markup,];

$query->editText($message = "כאן תוכל להגדיר הודעת סגירה מותאמת אישית שתשלח בערב שבת/חג כשהקבוצה נסגרת!", $replyMarkup = $bot_API_markup, ParseMode::HTML, $noWebpage = false, $scheduleDate = NULL);
    if (file_exists(__DIR__."/"."data/$userid/grs1.txt")) {
unlink(__DIR__."/"."data/$userid/grs1.txt");
}
} catch (Throwable $e) {}
}

#[FilterButtonQueryData('חזרההודעתסגירה')]
public function CloseMessage2(callbackQuery $query) {
try {
$userid = $query->userId;   
$chatid = $query->chatId; 
$msgqutryid = $query->messageId;
$User_Full = $this->getInfo($userid);
$first_name = $User_Full['User']['first_name']?? null;
if($first_name == null){
$first_name = "null";
}

$bot_API_markup[] = [['text'=>"מדיה 🖼",'callback_data'=>"הגדרמדיה_2"],['text'=>"👀 צפה",'callback_data'=>"צפהבמדיה_2"]];
$bot_API_markup[] = [['text'=>"טקסט 🔤",'callback_data'=>"הגדרטקסט_2"],['text'=>"👀 צפה",'callback_data'=>"צפהבטקסט_2"]];
$bot_API_markup[] = [['text'=>"כפתורים ⌨️",'callback_data'=>"הגדרכפתורים_2"],['text'=>"👀 צפה",'callback_data'=>"צפהבכפתורים_2"]];
$bot_API_markup[] = [['text'=>"תצוגה מקדימה מלאה 👁",'callback_data'=>"תצוגהמקדימהפתיחה_2"]];
$bot_API_markup[] = [['text'=>"חזרה להגדרות",'callback_data'=>"חזרהלהגדרות"]];
$bot_API_markup = [ 'inline_keyboard'=> $bot_API_markup,];

$this->messages->sendMessage(peer: $userid, message: "כאן תוכל להגדיר הודעת סגירה מותאמת אישית שתשלח בערב שבת/חג כשהקבוצה נסגרת!", reply_markup: $bot_API_markup, parse_mode: 'HTML');

			try {
$this->messages->deleteMessages(revoke: true, id: [$msgqutryid]); 
} catch (Throwable $e) {}

    if (file_exists(__DIR__."/"."data/$userid/grs1.txt")) {
unlink(__DIR__."/"."data/$userid/grs1.txt");
}
} catch (Throwable $e) {}
}

#[FilterButtonQueryData('הגדרטקסט_2')] 
public function GroupTextSet2(callbackQuery $query) {
	try {
$userid = $query->userId;    
$User_Full = $this->getInfo($userid);
$first_name = $User_Full['User']['first_name']?? null;
if($first_name == null){
$first_name = "null";
}

if (file_exists(__DIR__."/"."data/$userid/groupid.txt")) {
$filex = Amp\File\read(__DIR__."/"."data/$userid/groupid.txt");  
}else{
$filex = "null"; 	
}

if (file_exists(__DIR__."/"."data/$filex/MsgCloser.txt")) {	
$bot_API_markup[] = [['text'=>"חזור 🔙",'callback_data'=>"הודעתסגירה"],['text'=>"🗑 הסר את הטקסט",'callback_data'=>"מחקטקסט_2"]];
$bot_API_markup = [ 'inline_keyboard'=> $bot_API_markup,];
}else{
$bot_API_markup[] = [['text'=>"חזור 🔙",'callback_data'=>"הודעתסגירה"]];
$bot_API_markup = [ 'inline_keyboard'=> $bot_API_markup,];
}

$query->editText($message = "<b>שלח את הודעת הסגירה:</b>
<i>עד 1024 תווים, ניתן להשתמש בכל סיגנונות העיצוב.</i>
", $replyMarkup = $bot_API_markup, ParseMode::HTML, $noWebpage = false, $scheduleDate = NULL);
Amp\File\write(__DIR__."/data/$userid/grs1.txt", 'opener_text_2');
$msgqutryid = $query->messageId;
Amp\File\write(__DIR__."/data/$userid/messagetodelete.txt", "$msgqutryid");
} catch (Throwable $e) {}
}

#[FilterButtonQueryData('מחקטקסט_2')] 
public function RemoveText2(callbackQuery $query) {
	try {
$userid = $query->userId;    
$User_Full = $this->getInfo($userid);
$first_name = $User_Full['User']['first_name']?? null;
if($first_name == null){
$first_name = "null";
}

$bot_API_markup[] = [['text'=>"לא ❌",'callback_data'=>"הגדרטקסט_2"],['text'=>"כן ✅",'callback_data'=>"מחקטקסט_2אישור"]];
$bot_API_markup = [ 'inline_keyboard'=> $bot_API_markup,];

$query->editText($message = "האם אתה באמת בטוח שאתה רוצה למחוק את הטקסט של הודעת הסגירה?", $replyMarkup = $bot_API_markup, ParseMode::HTML, $noWebpage = false, $scheduleDate = NULL);
} catch (Throwable $e) {}
}

#[FilterButtonQueryData('מחקטקסט_2אישור')] 
public function RemoveText2_1(callbackQuery $query) {
	try {
$userid = $query->userId;    
$User_Full = $this->getInfo($userid);
$first_name = $User_Full['User']['first_name']?? null;
if($first_name == null){
$first_name = "null";
}

if (file_exists(__DIR__."/"."data/$userid/groupid.txt")) {
$filex = Amp\File\read(__DIR__."/"."data/$userid/groupid.txt");  
}else{
$filex = "null"; 	
}

if (file_exists(__DIR__."/"."data/$filex/MsgCloser.txt")) {	
unlink(__DIR__."/"."data/$filex/MsgCloser.txt");
}
if (file_exists(__DIR__."/"."data/$filex/MsgCloser2.txt")) {	
unlink(__DIR__."/"."data/$filex/MsgCloser2.txt");
}

$bot_API_markup[] = [['text'=>"🔙 חזור 🔙",'callback_data'=>"הודעתסגירה"]];
$bot_API_markup = [ 'inline_keyboard'=> $bot_API_markup,];

$query->editText($message = "<b>הטקסט הוסר</b> 🗑", $replyMarkup = $bot_API_markup, ParseMode::HTML, $noWebpage = false, $scheduleDate = NULL);
} catch (Throwable $e) {}
}

#[FilterButtonQueryData('צפהבטקסט_2')] 
public function GroupTextView2(callbackQuery $query) {
	try {
$userid = $query->userId;    
$msgqutryid = $query->messageId;
$User_Full = $this->getInfo($userid);
$first_name = $User_Full['User']['first_name']?? null;
if($first_name == null){
$first_name = "null";
}

if (file_exists(__DIR__."/"."data/$userid/groupid.txt")) {
$filex = Amp\File\read(__DIR__."/"."data/$userid/groupid.txt");  
}else{
$filex = "null"; 	
}

if (file_exists(__DIR__."/"."data/$filex/MsgCloser.txt")) {
$TXT = Amp\File\read(__DIR__."/"."data/$filex/MsgCloser.txt"); 
if($TXT != null){
if (file_exists(__DIR__."/"."data/$filex/MsgCloser2.txt")) {
$ENT = json_decode(Amp\File\read(__DIR__."/"."data/$filex/MsgCloser2.txt"),true);  
}else{
$ENT = null; 	
}

$bot_API_markup[] = [['text'=>"🔙 חזור 🔙",'callback_data'=>"הודעתסגירה"]];
$bot_API_markup = [ 'inline_keyboard'=> $bot_API_markup,];
$this->messages->editMessage(no_webpage: true, peer: $userid, id: $msgqutryid, message: $TXT, reply_markup: $bot_API_markup, entities: $ENT);
}else{
$query->answer($message = "ההודעה לא מוגדרת.", $alert = true, $url = null, $cacheTime = 0);
}
}else{
$query->answer($message = "ההודעה לא מוגדרת.", $alert = true, $url = null, $cacheTime = 0);
}
} catch (Throwable $e) {}
}

#[FilterButtonQueryData('הגדרמדיה_2')] 
public function GroupMsgMedia2(callbackQuery $query) {
	try {
$userid = $query->userId;    
$User_Full = $this->getInfo($userid);
$first_name = $User_Full['User']['first_name']?? null;
if($first_name == null){
$first_name = "null";
}

if (file_exists(__DIR__."/"."data/$userid/groupid.txt")) {
$filex = Amp\File\read(__DIR__."/"."data/$userid/groupid.txt");  
}else{
$filex = "null"; 	
}

if (file_exists(__DIR__."/"."data/$filex/MsgCloserMedia.txt")) {	
$bot_API_markup[] = [['text'=>"חזור 🔙",'callback_data'=>"הודעתסגירה"],['text'=>"🗑 הסר את המדיה",'callback_data'=>"מחקמדיה_2"]];
$bot_API_markup = [ 'inline_keyboard'=> $bot_API_markup,];
}else{
$bot_API_markup[] = [['text'=>"חזור 🔙",'callback_data'=>"הודעתסגירה"]];
$bot_API_markup = [ 'inline_keyboard'=> $bot_API_markup,];
}

$query->editText($message = "<b>שלח את המדיה החדשה לסגירה:</b>
<i>מדיה מותרת: תמונות, סרטונים, קבצים, מדבקות, קובצי GIF, אודיו, הודעות קוליות, סרטונים עגולים ועוד..(כל סוגי המדיה הנתמכים)</i>
", $replyMarkup = $bot_API_markup, ParseMode::HTML, $noWebpage = false, $scheduleDate = NULL);
Amp\File\write(__DIR__."/data/$userid/grs1.txt", 'opener_media_2');
$msgqutryid = $query->messageId;
Amp\File\write(__DIR__."/data/$userid/messagetodelete.txt", "$msgqutryid");
} catch (Throwable $e) {}
}

#[FilterButtonQueryData('צפהבמדיה_2')] 
public function GroupMsgMediaView2(callbackQuery $query) {
	try {
$userid = $query->userId;    
$msgqutryid = $query->messageId;
$User_Full = $this->getInfo($userid);
$first_name = $User_Full['User']['first_name']?? null;
if($first_name == null){
$first_name = "null";
}

if (file_exists(__DIR__."/"."data/$userid/groupid.txt")) {
$filex = Amp\File\read(__DIR__."/"."data/$userid/groupid.txt");  
}else{
$filex = "null"; 	
}

if (file_exists(__DIR__."/"."data/$filex/MsgCloserMedia.txt")) {
$MEDIA = Amp\File\read(__DIR__."/"."data/$filex/MsgCloserMedia.txt");  

$bot_API_markup[] = [['text'=>"🔙 חזרה 🔙",'callback_data'=>"חזרההודעתסגירה"]];
$bot_API_markup = [ 'inline_keyboard'=> $bot_API_markup,];

			try {
$sentMessage = $this->messages->sendMedia(peer: $userid, media: $MEDIA, reply_markup: $bot_API_markup);
} catch (Throwable $e) {
$sentMessage = $this->messages->sendMessage(peer: $userid, message: $e->getMessage(), reply_markup: $bot_API_markup);
}

			try {
$this->messages->deleteMessages(revoke: true, id: [$msgqutryid]); 
} catch (Throwable $e) {}

}else{
$query->answer($message = "ההודעה לא מוגדרת.", $alert = true, $url = null, $cacheTime = 0);
}

} catch (Throwable $e) {}
}

#[FilterButtonQueryData('מחקמדיה_2')] 
public function RemoveMedia2(callbackQuery $query) {
	try {
$userid = $query->userId;    
$User_Full = $this->getInfo($userid);
$first_name = $User_Full['User']['first_name']?? null;
if($first_name == null){
$first_name = "null";
}

$bot_API_markup[] = [['text'=>"לא ❌",'callback_data'=>"הגדרמדיה_2"],['text'=>"כן ✅",'callback_data'=>"מחקמדיה_2אישור"]];
$bot_API_markup = [ 'inline_keyboard'=> $bot_API_markup,];

$query->editText($message = "האם אתה באמת בטוח שאתה רוצה למחוק את המדיה של הודעת הסגירה?", $replyMarkup = $bot_API_markup, ParseMode::HTML, $noWebpage = false, $scheduleDate = NULL);
} catch (Throwable $e) {}
}

#[FilterButtonQueryData('מחקמדיה_2אישור')] 
public function RemoveMedia2_1(callbackQuery $query) {
	try {
$userid = $query->userId;    
$User_Full = $this->getInfo($userid);
$first_name = $User_Full['User']['first_name']?? null;
if($first_name == null){
$first_name = "null";
}

if (file_exists(__DIR__."/"."data/$userid/groupid.txt")) {
$filex = Amp\File\read(__DIR__."/"."data/$userid/groupid.txt");  
}else{
$filex = "null"; 	
}

if (file_exists(__DIR__."/"."data/$filex/MsgCloserMedia.txt")) {
unlink(__DIR__."/"."data/$filex/MsgCloserMedia.txt");  
}

$bot_API_markup[] = [['text'=>"🔙 חזור 🔙",'callback_data'=>"הודעתסגירה"]];
$bot_API_markup = [ 'inline_keyboard'=> $bot_API_markup,];

$query->editText($message = "<b>המדיה הוסרה</b> 🗑", $replyMarkup = $bot_API_markup, ParseMode::HTML, $noWebpage = false, $scheduleDate = NULL);
} catch (Throwable $e) {}
}

#[FilterButtonQueryData('צפהבכפתורים_2')] 
public function buttonsmanageviewgroup2(callbackQuery $query) {
	try {
$userid = $query->userId;    
$User_Full = $this->getInfo($userid);
$first_name = $User_Full['User']['first_name']?? null;
if($first_name == null){
$first_name = "null";
}
$username = $User_Full['User']['username']?? null;

if (file_exists(__DIR__."/"."data/$userid/groupid.txt")) {
$filex = Amp\File\read(__DIR__."/"."data/$userid/groupid.txt");  
}else{
$filex = "null"; 	
}

    if (file_exists(__DIR__."/"."data/$filex/MsgCloserButtons.txt")) {
$BUTTONS = Amp\File\read(__DIR__."/"."data/$filex/MsgCloserButtons.txt");
$bot_API_markup = $this->parseButtons($BUTTONS);
} else {

    $bot_API_markup = [
        '_' => 'replyInlineMarkup',
        'rows' => [],
    ];
}

$bot_API_markup['rows'][] = [

    '_' => 'keyboardButtonRow',

    'buttons' => [

        [
            '_' => 'keyboardButtonCallback',

            'text' => '🔙 חזור 🔙',

            'data' => 'הודעתסגירה',
        ]
    ]
];

$query->editText($message = "צפייה בכפתורים:", $replyMarkup = $bot_API_markup, ParseMode::HTML, $noWebpage = false, $scheduleDate = NULL);
} catch (Throwable $e) {
$query->editText($message = $e->getMessage(), $replyMarkup = $bot_API_markup, $noWebpage = false, $scheduleDate = NULL);
}
}

#[FilterButtonQueryData('הגדרכפתורים_2')] 
public function GroupMsgButtonSet2(callbackQuery $query) {
	try {
$userid = $query->userId;    
$User_Full = $this->getInfo($userid);
$first_name = $User_Full['User']['first_name']?? null;
if($first_name == null){
$first_name = "null";
}

if (file_exists(__DIR__."/"."data/$userid/groupid.txt")) {
$filex = Amp\File\read(__DIR__."/"."data/$userid/groupid.txt");  
}else{
$filex = "null"; 	
}

if (file_exists(__DIR__."/"."data/$filex/MsgCloserButtons.txt")) {	
$bot_API_markup[] = [['text'=>"חזור 🔙",'callback_data'=>"הודעתסגירה"],['text'=>"🗑 הסר כפתורים",'callback_data'=>"מחקכפתורים_2"]];
$bot_API_markup = [ 'inline_keyboard'=> $bot_API_markup,];
}else{
$bot_API_markup[] = [['text'=>"חזור 🔙",'callback_data'=>"הודעתסגירה"]];
$bot_API_markup = [ 'inline_keyboard'=> $bot_API_markup,];
}

$query->editText($message = "<b>שלח את הכפתורים שתרצה להוסיף בפורמט הבא:</b>
<pre>Button text 1 - http://www.example.com/ \nButton text 2 - http://www.example2.com/</pre>

<b>🔘 שלח כפתורים בפורמט:</b>

<pre>טקסט - פעולה</pre>

<b>📌 פעולות נתמכות:</b>
<pre>https://example.com
alert:טקסט
popup:טקסט
copy:טקסט</pre>

<b>📌 כמה כפתורים באותה שורה:</b>

<pre>כפתור 1 - https://t.me/test1 &amp;&amp; כפתור 2 - https://t.me/test2</pre>

<b>📌 עיצוב נתמך:</b>
<pre>style:bg_primary
style:bg_success
style:bg_danger</pre>

<b>📌 אייקון:</b>
<pre>icon:123456 </pre>

<b>📌 דוגמאות:</b>
<pre>כניסה - https://t.me/test

התראה - alert:שבת שלום - style: bg_danger

פופאפ - popup:הקבוצה תיפתח במוצ&quot;ש

העתקה - copy:https://t.me/test

אישור - https://t.me/test - style:bg_success

קבוצה - https://t.me/test - style:bg_primary - icon:5424972470023104089

אתר - https://google.com &amp;&amp; תמיכה - https://t.me/support</pre>

<b>⚠️ הערות:</b>
<pre>• כל שורה חדשה = שורת כפתורים חדשה
• &amp;&amp; = כמה כפתורים באותה שורה
• ניתן לשלב style + icon יחד
• ניתן להשתמש בלי עיצוב או אייקון</pre>", $replyMarkup = $bot_API_markup, ParseMode::HTML, $noWebpage = false, $scheduleDate = NULL);
Amp\File\write(__DIR__."/data/$userid/grs1.txt", 'opener_buttons_2');
$msgqutryid = $query->messageId;
Amp\File\write(__DIR__."/data/$userid/messagetodelete.txt", "$msgqutryid");
} catch (Throwable $e) {}
}

#[FilterButtonQueryData('מחקכפתורים_2')] 
public function RemoveButtons2(callbackQuery $query) {
	try {
$userid = $query->userId;    
$User_Full = $this->getInfo($userid);
$first_name = $User_Full['User']['first_name']?? null;
if($first_name == null){
$first_name = "null";
}

$bot_API_markup[] = [['text'=>"לא ❌",'callback_data'=>"הגדרכפתורים_2"],['text'=>"כן ✅",'callback_data'=>"מחקכפתורים_2אישור"]];
$bot_API_markup = [ 'inline_keyboard'=> $bot_API_markup,];

$query->editText($message = "האם אתה באמת בטוח שאתה רוצה למחוק את הכפתורים של הודעת הסגירה?", $replyMarkup = $bot_API_markup, ParseMode::HTML, $noWebpage = false, $scheduleDate = NULL);
} catch (Throwable $e) {}
}

#[FilterButtonQueryData('מחקכפתורים_2אישור')] 
public function RemoveButtons2_1(callbackQuery $query) {
	try {
$userid = $query->userId;    
$User_Full = $this->getInfo($userid);
$first_name = $User_Full['User']['first_name']?? null;
if($first_name == null){
$first_name = "null";
}

if (file_exists(__DIR__."/"."data/$userid/groupid.txt")) {
$filex = Amp\File\read(__DIR__."/"."data/$userid/groupid.txt");  
}else{
$filex = "null"; 	
}

if (file_exists(__DIR__."/"."data/$filex/MsgCloserButtons.txt")) {
unlink(__DIR__."/"."data/$filex/MsgCloserButtons.txt");  
}

$bot_API_markup[] = [['text'=>"🔙 חזור 🔙",'callback_data'=>"הודעתסגירה"]];
$bot_API_markup = [ 'inline_keyboard'=> $bot_API_markup,];

$query->editText($message = "<b>הכפתורים הוסרו</b> 🗑", $replyMarkup = $bot_API_markup, ParseMode::HTML, $noWebpage = false, $scheduleDate = NULL);
} catch (Throwable $e) {}
}

#[Handler]
public function HandleGroupMsgSet2(Incoming & PrivateMessage & IsNotEdited $message): void {
		try {
$messagetext = $message->message;
$entities = $message->entities;
$messagefile = $message->media;
$grouped_id = $message->groupedId;
$messageid = $message->id;
$senderid = $message->senderId;
$User_Full = $this->getInfo($message->senderId);
$first_name = $User_Full['User']['first_name']?? null;
if($first_name == null){
$first_name = "null";
}
$last_name = $User_Full['User']['last_name']?? null;
if($last_name == null){
$last_name = "null";
}
$username = $User_Full['User']['username']?? null;
if($username == null){
$username = "null";
}

if(!preg_match('/^\/([Ss]tart)/',$messagetext)){  

    if (file_exists(__DIR__."/data/$senderid/grs1.txt")) {
$check = Amp\File\read(__DIR__."/data/$senderid/grs1.txt");    

if (file_exists(__DIR__."/"."data/$senderid/groupid.txt")) {
$filex = Amp\File\read(__DIR__."/"."data/$senderid/groupid.txt");  
}else{
$filex = "null"; 	
}

if($check == "opener_text_2"){ 

if($grouped_id != null){
$bot_API_markup[] = [['text'=>"❌ ביטול ❌",'callback_data'=>"הודעתסגירה"]];
$bot_API_markup = [ 'inline_keyboard'=> $bot_API_markup,];
$this->messages->sendMessage(peer: $senderid, message: "<b>נא לשלוח הודעת טקסט בלבד!</b>", reply_markup: $bot_API_markup, parse_mode: 'HTML');

			try {
$this->messages->deleteMessages(revoke: true, id: [$messageid]); 
} catch (Throwable $e) {}

 if (file_exists(__DIR__."/data/$senderid/messagetodelete.txt")) {
$filexmsgid = Amp\File\read(__DIR__."/data/$senderid/messagetodelete.txt");  

			try {
$this->messages->deleteMessages(revoke: true, id: [$filexmsgid]); 
} catch (Throwable $e) {}

}
}else{
	
if($messagefile){

$bot_API_markup[] = [['text'=>"❌ ביטול ❌",'callback_data'=>"הודעתסגירה"]];
$bot_API_markup = [ 'inline_keyboard'=> $bot_API_markup,];
$this->messages->sendMessage(peer: $senderid, message: "<b>נא לשלוח הודעת טקסט בלבד!</b>", reply_markup: $bot_API_markup, parse_mode: 'HTML');

			try {
$this->messages->deleteMessages(revoke: true, id: [$messageid]); 
} catch (Throwable $e) {}

 if (file_exists(__DIR__."/data/$senderid/messagetodelete.txt")) {
$filexmsgid = Amp\File\read(__DIR__."/data/$senderid/messagetodelete.txt");  

			try {
$this->messages->deleteMessages(revoke: true, id: [$filexmsgid]); 
} catch (Throwable $e) {}

}

}

if(!$messagefile){
$messageLength = mb_strlen($messagetext);

if($messageLength > 1024) {

$bot_API_markup[] = [['text'=>"❌ ביטול ❌",'callback_data'=>"הודעתסגירה"]];
$bot_API_markup = [ 'inline_keyboard'=> $bot_API_markup,];
$this->messages->sendMessage(peer: $senderid, message: "<b>נא לשלוח טקסט עד 1024 תווים</b>
כמות התווים ששלחת: $messageLength", reply_markup: $bot_API_markup, parse_mode: 'HTML');

			try {
$this->messages->deleteMessages(revoke: true, id: [$messageid]); 
} catch (Throwable $e) {}

 if (file_exists(__DIR__."/data/$senderid/messagetodelete.txt")) {
$filexmsgid = Amp\File\read(__DIR__."/data/$senderid/messagetodelete.txt");  
			try {
$this->messages->deleteMessages(revoke: true, id: [$filexmsgid]); 
} catch (Throwable $e) {}

}

} 
else 
{
unlink(__DIR__."/data/$senderid/grs1.txt");

$bot_API_markup[] = [['text'=>"חזרה",'callback_data'=>"הודעתסגירה"]];
$bot_API_markup = [ 'inline_keyboard'=> $bot_API_markup,];
$this->messages->sendMessage(peer: $senderid, message: "<b>ההודעה נשמרה בהצלחה! ✔️</b>", reply_markup: $bot_API_markup, parse_mode: 'HTML');

			try {
$this->messages->deleteMessages(revoke: true, id: [$messageid]); 
} catch (Throwable $e) {}

 if (file_exists(__DIR__."/data/$senderid/messagetodelete.txt")) {
$filexmsgid = Amp\File\read(__DIR__."/data/$senderid/messagetodelete.txt");  
			try {
$this->messages->deleteMessages(revoke: true, id: [$filexmsgid]); 
} catch (Throwable $e) {}

}


Amp\File\write(__DIR__."/"."data/$filex/MsgCloser.txt", $messagetext);
Amp\File\write(__DIR__."/"."data/$filex/MsgCloser2.txt", json_encode(array_map(static fn($e) => $e->toMTProto(),$entities,)));

}


	
}


}

}

if($check == "opener_media_2"){
 
if($grouped_id != null){
$bot_API_markup[] = [['text'=>"❌ ביטול ❌",'callback_data'=>"הודעתסגירה"]];
$bot_API_markup = [ 'inline_keyboard'=> $bot_API_markup,];
$this->messages->sendMessage(peer: $senderid, message: "<b>אין תמיכה באלבומים!</b>", reply_markup: $bot_API_markup, parse_mode: 'HTML');

			try {
$this->messages->deleteMessages(revoke: true, id: [$messageid]); 
} catch (Throwable $e) {}

 if (file_exists(__DIR__."/data/$senderid/messagetodelete.txt")) {
$filexmsgid = Amp\File\read(__DIR__."/data/$senderid/messagetodelete.txt");  

			try {
$this->messages->deleteMessages(revoke: true, id: [$filexmsgid]); 
} catch (Throwable $e) {}

}
}else{
	
if(!$messagefile){
			try {
$this->messages->deleteMessages(revoke: true, id: [$messageid]); 
}catch (\danog\MadelineProto\Exception $e) {
$estring = (string) $e;
if(preg_match("/MESSAGE_DELETE_FORBIDDEN/",$estring)){
}

} catch (\danog\MadelineProto\RPCErrorException $e) {
    if ($e->rpc === 'MESSAGE_DELETE_FORBIDDEN') {	
}
}

 if (file_exists(__DIR__."/data/$senderid/messagetodelete.txt")) {
$filexmsgid = Amp\File\read(__DIR__."/data/$senderid/messagetodelete.txt");  
			try {
$this->messages->deleteMessages(revoke: true, id: [$filexmsgid]); 
}catch (\danog\MadelineProto\Exception $e) {
$estring = (string) $e;
if(preg_match("/MESSAGE_DELETE_FORBIDDEN/",$estring)){
}

} catch (\danog\MadelineProto\RPCErrorException $e) {
    if ($e->rpc === 'MESSAGE_DELETE_FORBIDDEN') {	
}
}

}

$bot_API_markup[] = [['text'=>"❌ ביטול ❌",'callback_data'=>"הודעתסגירה"]];
$bot_API_markup = [ 'inline_keyboard'=> $bot_API_markup,];
$this->messages->sendMessage(peer: $senderid, message: "<b>נא לשלוח מדיה בלבד!</b>", reply_markup: $bot_API_markup, parse_mode: 'HTML');

}

if($messagefile){

unlink(__DIR__."/data/$senderid/grs1.txt");

$bot_API_markup[] = [['text'=>"חזרה",'callback_data'=>"הודעתסגירה"]];
$bot_API_markup = [ 'inline_keyboard'=> $bot_API_markup,];
$this->messages->sendMessage(peer: $senderid, message: "<b>המדיה נשמרה בהצלחה! ✔️</b>", reply_markup: $bot_API_markup, parse_mode: 'HTML');

			try {
$this->messages->deleteMessages(revoke: true, id: [$messageid]); 
} catch (Throwable $e) {}

 if (file_exists(__DIR__."/data/$senderid/messagetodelete.txt")) {
$filexmsgid = Amp\File\read(__DIR__."/data/$senderid/messagetodelete.txt");  
			try {
$this->messages->deleteMessages(revoke: true, id: [$filexmsgid]); 
} catch (Throwable $e) {}

}

$botApiFileId = $message->media->botApiFileId;
Amp\File\write(__DIR__."/"."data/$filex/MsgCloserMedia.txt", $botApiFileId);
}


	
}

}

if($check == "opener_buttons_2"){
 
if ($this->validateButtonsInput($messagetext)) {
unlink(__DIR__."/data/$senderid/grs1.txt");

Amp\File\write(__DIR__."/"."data/$filex/MsgCloserButtons.txt", $messagetext);

			try {
$this->messages->deleteMessages(revoke: true, id: [$messageid]); 
} catch (Throwable $e) {}

 if (file_exists(__DIR__."/data/$senderid/messagetodelete.txt")) {
$filexmsgid = Amp\File\read(__DIR__."/data/$senderid/messagetodelete.txt");  
$bot_API_markup[] = [['text'=>"חזרה",'callback_data'=>"הודעתסגירה"]];
$bot_API_markup = [ 'inline_keyboard'=> $bot_API_markup,];
$Updates = $this->messages->editMessage(peer: $senderid, id: $filexmsgid, message: "<b>הכפתורים נשמרו בהצלחה! ✔️</b>", reply_markup: $bot_API_markup, parse_mode: 'HTML');

}


} else {
			try {
$this->messages->deleteMessages(revoke: true, id: [$messageid]); 
} catch (Throwable $e) {}

 if (file_exists(__DIR__."/data/$senderid/messagetodelete.txt")) {
$filexmsgid = Amp\File\read(__DIR__."/data/$senderid/messagetodelete.txt");  
$bot_API_markup[] = [['text'=>"❌ ביטול ❌",'callback_data'=>"הודעתסגירה"]];
$bot_API_markup = [ 'inline_keyboard'=> $bot_API_markup,];

			try {
$Updates = $this->messages->editMessage(peer: $senderid, id: $filexmsgid, message: "<b>נא שלח את הכפתורים שתרצה להוסיף בפורמט הנכון!</b>", reply_markup: $bot_API_markup, parse_mode: 'HTML');
} catch (Throwable $e) {}


}
}


}

}
}
} catch (Throwable $e) {}
}

#[FilterButtonQueryData('תצוגהמקדימהפתיחה_2')] 
public function view_welcomeMessage_ful12(callbackQuery $query) {
	try {
$userid = $query->userId;    
$msgqutryid = $query->messageId;
$User_Full = $this->getInfo($userid);
$first_name = $User_Full['User']['first_name']?? null;
if($first_name == null){
$first_name = "null";
}

if (file_exists(__DIR__."/"."data/$userid/groupid.txt")) {
$filex = Amp\File\read(__DIR__."/"."data/$userid/groupid.txt");  
}else{
$filex = "null"; 	
}

if (file_exists(__DIR__."/"."data/$filex/MsgCloserMedia.txt")) {
$MEDIA = Amp\File\read(__DIR__."/"."data/$filex/MsgCloserMedia.txt");  
}else{
$MEDIA = null; 	
}
if (file_exists(__DIR__."/"."data/$filex/MsgCloser.txt")) {
$TXT = Amp\File\read(__DIR__."/"."data/$filex/MsgCloser.txt"); 
}else{
$TXT = null; 	
}
if (file_exists(__DIR__."/"."data/$filex/MsgCloser2.txt")) {
$ENT = json_decode(Amp\File\read(__DIR__."/"."data/$filex/MsgCloser2.txt"),true);  
}else{
$ENT = null; 	
}
if (file_exists(__DIR__."/"."data/$filex/MsgCloserButtons.txt")) {
$BUTTONS = Amp\File\read(__DIR__."/"."data/$filex/MsgCloserButtons.txt");
$bot_API_markup_welcome = $this->parseButtons($BUTTONS);
} else {

    $bot_API_markup_welcome = [
        '_' => 'replyInlineMarkup',
        'rows' => [],
    ];
}


$bot_API_markup[] = [['text'=>"מדיה 🖼",'callback_data'=>"הגדרמדיה_2"],['text'=>"👀 צפה",'callback_data'=>"צפהבמדיה_2"]];
$bot_API_markup[] = [['text'=>"טקסט 🔤",'callback_data'=>"הגדרטקסט_2"],['text'=>"👀 צפה",'callback_data'=>"צפהבטקסט_2"]];
$bot_API_markup[] = [['text'=>"כפתורים ⌨️",'callback_data'=>"הגדרכפתורים_2"],['text'=>"👀 צפה",'callback_data'=>"צפהבכפתורים_2"]];
$bot_API_markup[] = [['text'=>"תצוגה מקדימה מלאה 👁",'callback_data'=>"תצוגהמקדימהפתיחה_2"]];
$bot_API_markup[] = [['text'=>"🔙 חזור 🔙",'callback_data'=>"חזרהלהגדרות"]];
$bot_API_markup = [ 'inline_keyboard'=> $bot_API_markup,];

if($MEDIA != null){

if($TXT != null){

			try {
$this->messages->deleteMessages(revoke: true, id: [$msgqutryid]); 
} catch (Throwable $e) {}

$this->messages->sendMessage(peer: $userid, message: "➖➖➖➖➖➖➖➖➖");
$this->messages->sendMessage(peer: $userid, message: "👇🏻 תצוגה מקדימה מלאה");
$sentMessage = $this->messages->sendMedia(peer: $userid, message: "$TXT",  entities: $ENT, media: $MEDIA, reply_markup: $bot_API_markup_welcome);

$this->messages->sendMessage(peer: $userid, message: "כאן תוכל להגדיר הודעת סגירה מותאמת אישית שתשלח בערב שבת כשהקבוצה נסגרת!", reply_markup: $bot_API_markup, parse_mode: 'HTML');

}else{
	
			try {
$this->messages->deleteMessages(revoke: true, id: [$msgqutryid]); 
} catch (Throwable $e) {}

$OPENER = self::CLOSER;
$this->messages->sendMessage(peer: $userid, message: "➖➖➖➖➖➖➖➖➖");
$this->messages->sendMessage(peer: $userid, message: "👇🏻 תצוגה מקדימה מלאה");
$sentMessage = $this->messages->sendMedia(peer: $userid, message: $OPENER, media: $MEDIA, reply_markup: $bot_API_markup_welcome);	

$this->messages->sendMessage(peer: $userid, message: "כאן תוכל להגדיר הודעת סגירה מותאמת אישית שתשלח בערב שבת כשהקבוצה נסגרת!", reply_markup: $bot_API_markup, parse_mode: 'HTML');

}

}else{

if($TXT != null){
	
			try {
$this->messages->deleteMessages(revoke: true, id: [$msgqutryid]); 
} catch (Throwable $e) {}

$this->messages->sendMessage(peer: $userid, message: "➖➖➖➖➖➖➖➖➖");
$this->messages->sendMessage(peer: $userid, message: "👇🏻 תצוגה מקדימה מלאה");
$sentMessage = $this->messages->sendMessage(peer: $userid, message: "$TXT", entities: $ENT, reply_markup: $bot_API_markup_welcome);

$this->messages->sendMessage(peer: $userid, message: "כאן תוכל להגדיר הודעת סגירה מותאמת אישית שתשלח בערב שבת כשהקבוצה נסגרת!", reply_markup: $bot_API_markup, parse_mode: 'HTML');

}else{

			try {
$this->messages->deleteMessages(revoke: true, id: [$msgqutryid]); 
} catch (Throwable $e) {}

$OPENER = self::CLOSER;
$this->messages->sendMessage(peer: $userid, message: "➖➖➖➖➖➖➖➖➖");
$this->messages->sendMessage(peer: $userid, message: "👇🏻 תצוגה מקדימה מלאה");
$sentMessage = $this->messages->sendMessage(peer: $userid, message: $OPENER, reply_markup: $bot_API_markup_welcome);

$this->messages->sendMessage(peer: $userid, message: "כאן תוכל להגדיר הודעת סגירה מותאמת אישית שתשלח בערב שבת כשהקבוצה נסגרת!", reply_markup: $bot_API_markup, parse_mode: 'HTML');

}


}

} catch (Throwable $e) {}
}

/* ================ stats ================ */
#[FilterCommandCaseInsensitive('stats')]
public function StatsGroups(
    Incoming & IsNotEdited $message
): void {

    try {

        $sentMessage = $this->messages->sendMessage(
            peer: $message->chatId,
            message: "⌛️ Loading statistics..."
        );

        $messageId = $this->extractMessageId(
            $sentMessage
        );

        $dialogs = $this->getDialogIds();

        $supergroups = 0;
        $normalGroups = 0;
        $lockedGroups = 0;
        $alertsEnabled = 0;

        foreach ($dialogs as $peer) {
            try {

                $info = $this->getInfo($peer);

                if (
                    !isset($info['type'])
                ) {
                    continue;
                }


                if ($info['type'] === 'supergroup') {
                    $supergroups++;
                }


                if ($info['type'] === 'chat') {
                    $normalGroups++;
                }

                if (
                    isset(
                        $info['Chat']['default_banned_rights']['send_messages']
                    ) &&
                    $info['Chat']['default_banned_rights']['send_messages']
                ) {
                    $lockedGroups++;
                }

                if (
                    file_exists(
                        __DIR__ . "/data/$peer/alertshabat.txt"
                    )
                ) {
                    $alertsEnabled++;
                }

            } catch (\Throwable $e) {
                continue;
            }
        }

        $totalGroups = $supergroups + $normalGroups;

        $zmanim = $this->getShabbatLockTimes();

        $closeDateTime =
            $zmanim['close_datetime'] ?? 'Unknown';

        $openDateTime =
            $zmanim['open_datetime'] ?? 'Unknown';

        $closeDateObj = \DateTime::createFromFormat(
            'd/m/Y H:i',
            $closeDateTime
        );

        $openDateObj = \DateTime::createFromFormat(
            'd/m/Y H:i',
            $openDateTime
        );

        $isLockedNow = false;

        if (
            $closeDateObj &&
            $openDateObj
        ) {

            $nowTs = time();

            $closeTs = $closeDateObj->getTimestamp();

            $openTs = $openDateObj->getTimestamp();

            $isLockedNow =
                $closeTs <= $nowTs &&
                $openTs > $nowTs;
        }

        $systemStatus = $isLockedNow
            ? 'סגור'
            : 'פתוח';

        $testModeStatus =
            $this->testMode
                ? 'פועל'
                : 'כבוי';

        $version = 'v2.0.1';

        $statsMessage =
"📊 <b>סטטיסטיקות</b> 📊

🕯 <b>קבוצות שומרות שבת/חג:</b> <code>{$totalGroups}</code>
- <b>קבוצות על:</b> <code>{$supergroups}</code>
- <b>קבוצות רגילות:</b> <code>{$normalGroups}</code>

🔒 <b>קבוצות סגורות כרגע:</b> <code>{$lockedGroups}</code>

🔔 <b>קבוצות שהפעילו זמנים:</b> <code>{$alertsEnabled}</code>

🕯 <b>זמן סגירה הבא:</b> <code>{$closeDateTime}</code>

🍷 <b>זמן פתיחה הבא:</b> <code>{$openDateTime}</code>

⚡ <b>מצב קבוצות נוכחי:</b> <code>{$systemStatus}</code>

🧪 <b>מצב בדיקות:</b> <code>{$testModeStatus}</code>

🤖 <b>גרסת הבוט:</b> <code>{$version}</code>";

        $this->messages->editMessage(
            peer: $message->chatId,
            id: $messageId,
            message: $statsMessage,
            parse_mode: 'HTML'
        );

    } catch (\Throwable $e) {

        $this->messages->sendMessage(
            peer: $message->chatId,
            message:
                "❌ Error:\n\n" .
                $e->getMessage()
        );
    }
}

/* ================ cron ================ */
#[Cron(period: 60.0)] 
public function shabatCron(): void {
try {
	
date_default_timezone_set("Asia/Jerusalem");

$zmanim = $this->testMode ? $this->getTestShabbatLockTimes(): $this->getShabbatLockTimes();

$closeDateTime = $zmanim['close_datetime'];
$closeDateOnly = null;
if ($closeDateTime) {

    $closeDate = \DateTime::createFromFormat(
        'd/m/Y H:i',
        $closeDateTime
    );

    if ($closeDate !== false) {
        $closeDateOnly = $closeDate->format('d/m/Y');
    }
}
$openDateTime  = $zmanim['open_datetime'];
$now = date('d/m/Y H:i');

$closeLockFile = __DIR__ . "/close_lock.txt";
$alreadyClosed = false;
if (file_exists($closeLockFile)) {

    $lockData = trim(
        Amp\File\read($closeLockFile)
    );

    if ($lockData === $closeDateTime) {
        $alreadyClosed = true;
    }
}

$nowTs = time();

$closeDateObj = \DateTime::createFromFormat(
    'd/m/Y H:i',
    $closeDateTime
);

$closeTs = $closeDateObj
    ? $closeDateObj->getTimestamp()
    : 0;

if (
    $closeTs <= $nowTs &&
    ($nowTs - $closeTs) < 120 &&
    !$alreadyClosed
) {

    Amp\File\write($closeLockFile, $closeDateTime);

if (file_exists(__DIR__."/"."data/DBgroups.txt")) {
$userstoasend = Amp\File\read(__DIR__."/"."data/DBgroups.txt");  
$usersArray = explode("\n", $userstoasend);
$usersArray = array_filter($usersArray);
$userstoasend1 = ($usersArray);



foreach ($userstoasend1 as $peer) {
try {
$info = $this->getInfo($peer);
$checkar1 = $info['Chat']['default_banned_rights']['view_messages'];
$checkar2 = $info['Chat']['default_banned_rights']['send_messages'];
$checkar3 = $info['Chat']['default_banned_rights']['send_media'];
$checkar4 = $info['Chat']['default_banned_rights']['send_stickers'];
$checkar5 = $info['Chat']['default_banned_rights']['send_gifs'];
$checkar6 = $info['Chat']['default_banned_rights']['send_games'];
$checkar7 = $info['Chat']['default_banned_rights']['send_inline'];
$checkar8 = $info['Chat']['default_banned_rights']['embed_links'];
$checkar9 = $info['Chat']['default_banned_rights']['send_polls'];
$checkar10 = $info['Chat']['default_banned_rights']['change_info'];
$checkar11 = $info['Chat']['default_banned_rights']['invite_users'];
$checkar12 = $info['Chat']['default_banned_rights']['pin_messages'];
$checkar13 = $info['Chat']['default_banned_rights']['manage_topics'];
$checkar14 = $info['Chat']['default_banned_rights']['send_photos'];
$checkar15 = $info['Chat']['default_banned_rights']['send_videos'];
$checkar16 = $info['Chat']['default_banned_rights']['send_roundvideos'];
$checkar17 = $info['Chat']['default_banned_rights']['send_audios'];
$checkar18 = $info['Chat']['default_banned_rights']['send_voices'];
$checkar19 = $info['Chat']['default_banned_rights']['send_docs'];
$checkar20 = $info['Chat']['default_banned_rights']['send_plain'];
$checkartime = $info['Chat']['default_banned_rights']['until_date'];
if($checkar1 != false){
$checkar1 = "true";
}else{
$checkar1 = "false";
}
if($checkar2 != false){
$checkar2 = "true";
}else{
$checkar2 = "false";
}
if($checkar3 != false){
$checkar3 = "true";
}else{
$checkar3 = "false";
}
if($checkar4 != false){
$checkar4 = "true";
}else{
$checkar4 = "false";
}
if($checkar5 != false){
$checkar5 = "true";
}else{
$checkar5 = "false";
}
if($checkar6 != false){
$checkar6 = "true";
}else{
$checkar6 = "false";
}
if($checkar7 != false){
$checkar7 = "true";
}else{
$checkar7 = "false";
}
if($checkar8 != false){
$checkar8 = "true";
}else{
$checkar8 = "false";
}
if($checkar9 != false){
$checkar9 = "true";
}else{
$checkar9 = "false";
}
if($checkar10 != false){
$checkar10 = "true";
}else{
$checkar10 = "false";
}
if($checkar11 != false){
$checkar11 = "true";
}else{
$checkar11 = "false";
}
if($checkar12 != false){
$checkar12 = "true";
}else{
$checkar12 = "false";
}
if($checkar13 != false){
$checkar13 = "true";
}else{
$checkar13 = "false";
}
if($checkar14 != false){
$checkar14 = "true";
}else{
$checkar14 = "false";
}
if($checkar15 != false){
$checkar15 = "true";
}else{
$checkar15 = "false";
}
if($checkar16 != false){
$checkar16 = "true";
}else{
$checkar16 = "false";
}
if($checkar17 != false){
$checkar17 = "true";
}else{
$checkar17 = "false";
}
if($checkar18 != false){
$checkar18 = "true";
}else{
$checkar18 = "false";
}
if($checkar19 != false){
$checkar19 = "true";
}else{
$checkar19 = "false";
}
if($checkar20 != false){
$checkar20 = "true";
}else{
$checkar20 = "false";
}
$checkartime20 = (string) $checkartime;


try {
Amp\File\write(__DIR__."/"."data/$peer/chatb1.txt",$checkar1."\n".$checkar2."\n".$checkar3."\n".$checkar4."\n".$checkar5."\n".$checkar6."\n".$checkar7."\n".$checkar8."\n".$checkar9."\n".$checkar10."\n".$checkar11."\n".$checkar12."\n".$checkar13."\n".$checkar14."\n".$checkar15."\n".$checkar16."\n".$checkar17."\n".$checkar18."\n".$checkar19."\n".$checkar20."\n".$checkartime20);
} finally {
$this->sleep(0.1);
$chatBannedRights = ['_'                => 'chatBannedRights', 
                    'view_messages'     => false, 
                    'send_messages'     => true, 
                    'send_media'        => true, 
                    'send_stickers'     => true, 
                    'send_gifs'         => true, 
                    'send_games'        => true, 
                    'send_inline'       => true, 
                    'embed_links'       => true, 
                    'send_polls'        => true, 
                    'change_info'       => true, 
                    'invite_users'      => true, 
                    'pin_messages'      => true,
                    'manage_topics'     => true, 
                    'send_photos'       => true, 
                    'send_videos'       => true, 
                    'send_roundvideos'  => true, 
                    'send_audios'       => true, 
                    'send_voices'       => true, 
                    'send_docs'         => true,
                    'send_plain'        => true, 
                    'until_date'        => 0,
                ];
	
$Updates1 = $this->messages->editChatDefaultBannedRights(peer: $peer, banned_rights: $chatBannedRights, );
}

if (file_exists(__DIR__."/"."data/$peer/alertshabat2.txt")) {

if (file_exists(__DIR__."/"."data/$peer/MsgCloserMedia.txt")) {
$MEDIA = Amp\File\read(__DIR__."/"."data/$peer/MsgCloserMedia.txt");  
}else{
$MEDIA = null; 	
}
if (file_exists(__DIR__."/"."data/$peer/MsgCloser.txt")) {
$TXT = Amp\File\read(__DIR__."/"."data/$peer/MsgCloser.txt"); 
}else{
$TXT = null; 	
}
if (file_exists(__DIR__."/"."data/$peer/MsgCloser2.txt")) {
$ENT = json_decode(Amp\File\read(__DIR__."/"."data/$peer/MsgCloser2.txt"),true);  
}else{
$ENT = null; 	
}
if (file_exists(__DIR__."/"."data/$peer/MsgCloserButtons.txt")) {
$BUTTONS = Amp\File\read(__DIR__."/"."data/$peer/MsgCloserButtons.txt");
$bot_API_markup_welcome = $this->parseButtons($BUTTONS);
} else {

    $bot_API_markup_welcome = [
        '_' => 'replyInlineMarkup',
        'rows' => [],
    ];
}


if($MEDIA != null){

if($TXT != null){

$sentMessage = $this->messages->sendMedia(peer: $peer, message: "$TXT",  entities: $ENT, media: $MEDIA, reply_markup: $bot_API_markup_welcome);

}else{
	
$OPENER = self::CLOSER;
$sentMessage = $this->messages->sendMedia(peer: $peer, message: $OPENER, media: $MEDIA, reply_markup: $bot_API_markup_welcome);	

}

}else{

if($TXT != null){
	
$sentMessage = $this->messages->sendMessage(peer: $peer, message: "$TXT", entities: $ENT, reply_markup: $bot_API_markup_welcome);

}else{

$OPENER = self::CLOSER;
$sentMessage = $this->messages->sendMessage(peer: $peer, message: $OPENER, reply_markup: $bot_API_markup_welcome);

}


}





}
$this->sleep(0.1);

} catch (Throwable $e) {
continue;
} 
}



}

}

$alertLockFile = __DIR__ . "/alert_lock.txt";
$alreadyAlerted = false;
if (file_exists($alertLockFile)) {

    $lockData = trim(Amp\File\read($alertLockFile));

    if ($lockData === $closeDateOnly) {
        $alreadyAlerted = true;
    }
}

if (
    $closeDateOnly &&
    date('d/m/Y') === $closeDateOnly &&
    date('H:i') === (
    $this->testMode
        ? $this->getAlertTestTime()
        : '13:30') &&
    !$alreadyAlerted
) {

    Amp\File\write($alertLockFile, $closeDateOnly);

if (file_exists(__DIR__."/"."data/DBgroups.txt")) {
$userstoasend = Amp\File\read(__DIR__."/"."data/DBgroups.txt");  
$usersArray = explode("\n", $userstoasend);
$usersArray = array_filter($usersArray);
$userstoasend1 = ($usersArray);

$ShabatTimes = $this->getZmanimForCities();

$me = $this->getSelf();
$me_username = $me['username'];

foreach ($userstoasend1 as $peer) {
try {
if (file_exists(__DIR__."/"."data/$peer/alertshabat.txt")) {
$inlineQueryPeerTypePM = ['_' => 'inlineQueryPeerTypePM'];
$inlineQueryPeerTypeChat = ['_' => 'inlineQueryPeerTypeChat'];
$inlineQueryPeerTypeBotPM = ['_' => 'inlineQueryPeerTypeBotPM'];
$inlineQueryPeerTypeMegagroup = ['_' => 'inlineQueryPeerTypeMegagroup'];
$inlineQueryPeerTypeBroadcast = ['_' => 'inlineQueryPeerTypeBroadcast'];

$keyboardButtonSwitchInline = ['_' => 'keyboardButtonSwitchInline', 'same_peer' => false, 'text' => 'לשיתוף זמני השבת 🕯', 'query' => 'shabat', 'peer_types' => [$inlineQueryPeerTypePM, $inlineQueryPeerTypeChat, $inlineQueryPeerTypeBotPM, $inlineQueryPeerTypeMegagroup, $inlineQueryPeerTypeBroadcast]];
$keyboardButtonRow1 = ['_' => 'keyboardButtonRow', 'buttons' => [$keyboardButtonSwitchInline]];
$bot_API_markup = ['_' => 'replyInlineMarkup', 'rows' => [$keyboardButtonRow1]];

$sendmoadaa1 = $this->messages->sendMessage(peer: $peer, message: $ShabatTimes, reply_markup: $bot_API_markup, parse_mode: 'html');
$this->sleep(0.1);
}

} catch (Throwable $e) {
continue;
}
}



}

}

$openLockFile = __DIR__ . "/open_lock.txt";
$alreadyOpened = false;
if (file_exists($openLockFile)) {

    $lockData = trim(Amp\File\read($openLockFile));

    if ($lockData === $openDateTime) {
        $alreadyOpened = true;
    }
}

$openDateObj = \DateTime::createFromFormat(
    'd/m/Y H:i',
    $openDateTime
);

$openTs = $openDateObj
    ? $openDateObj->getTimestamp()
    : 0;

if (
    $openTs <= $nowTs &&
    ($nowTs - $openTs) < 120 &&
    !$alreadyOpened
) {

    Amp\File\write($openLockFile, $openDateTime);

if (file_exists(__DIR__."/"."data/DBgroups.txt")) {
$userstoasend = Amp\File\read(__DIR__."/"."data/DBgroups.txt");  
$usersArray = explode("\n", $userstoasend);
$usersArray = array_filter($usersArray);
$userstoasend1 = ($usersArray);

foreach ($userstoasend1 as $peer) {
try {

if (file_exists(__DIR__."/"."data/$peer/chatb1.txt")) {
$lines = file(__DIR__."/"."data/$peer/chatb1.txt");
$lines = array_pad($lines, 21, "false\n");
$dillerr1 = $lines[0];
$dillerr2 = $lines[1];
$dillerr3 = $lines[2];
$dillerr4 = $lines[3];
$dillerr5 = $lines[4];
$dillerr6 = $lines[5];
$dillerr7 = $lines[6];
$dillerr8 = $lines[7];
$dillerr9 = $lines[8];
$dillerr10 = $lines[9];
$dillerr11 = $lines[10];
$dillerr12 = $lines[11];
$dillerr13 = $lines[12];
$dillerr14 = $lines[13];
$dillerr15 = $lines[14];
$dillerr16 = $lines[15];
$dillerr17 = $lines[16];
$dillerr18 = $lines[17];
$dillerr19 = $lines[18];
$dillerr20 = $lines[19];
$dillerr21 = $lines[20];

$checkarnew1 = filter_var($dillerr1, FILTER_VALIDATE_BOOLEAN);
$checkarnew2 = filter_var($dillerr2, FILTER_VALIDATE_BOOLEAN);
$checkarnew3 = filter_var($dillerr3, FILTER_VALIDATE_BOOLEAN);
$checkarnew4 = filter_var($dillerr4, FILTER_VALIDATE_BOOLEAN);
$checkarnew5 = filter_var($dillerr5, FILTER_VALIDATE_BOOLEAN);
$checkarnew6 = filter_var($dillerr6, FILTER_VALIDATE_BOOLEAN);
$checkarnew7 = filter_var($dillerr7, FILTER_VALIDATE_BOOLEAN);
$checkarnew8 = filter_var($dillerr8, FILTER_VALIDATE_BOOLEAN);
$checkarnew9 = filter_var($dillerr9, FILTER_VALIDATE_BOOLEAN);
$checkarnew10 = filter_var($dillerr10, FILTER_VALIDATE_BOOLEAN);
$checkarnew11 = filter_var($dillerr11, FILTER_VALIDATE_BOOLEAN);
$checkarnew12 = filter_var($dillerr12, FILTER_VALIDATE_BOOLEAN);
$checkarnew13 = filter_var($dillerr13, FILTER_VALIDATE_BOOLEAN);
$checkarnew14 = filter_var($dillerr14, FILTER_VALIDATE_BOOLEAN);
$checkarnew15 = filter_var($dillerr15, FILTER_VALIDATE_BOOLEAN);
$checkarnew16 = filter_var($dillerr16, FILTER_VALIDATE_BOOLEAN);
$checkarnew17 = filter_var($dillerr17, FILTER_VALIDATE_BOOLEAN);
$checkarnew18 = filter_var($dillerr18, FILTER_VALIDATE_BOOLEAN);
$checkarnew19 = filter_var($dillerr19, FILTER_VALIDATE_BOOLEAN);
$checkarnew20 = filter_var($dillerr20, FILTER_VALIDATE_BOOLEAN);
$checkarnew21 = intval($dillerr21);

$chatBannedRights2 = ['_'                => 'chatBannedRights', 
                    'view_messages'     => $checkarnew1,
                    'send_messages'     => $checkarnew2, 
                    'send_media'        => $checkarnew3, 
                    'send_stickers'     => $checkarnew4, 
                    'send_gifs'         => $checkarnew5, 
                    'send_games'        => $checkarnew6, 
                    'send_inline'       => $checkarnew7, 
                    'embed_links'       => $checkarnew8, 
                    'send_polls'        => $checkarnew9, 
                    'change_info'       => $checkarnew10, 
                    'invite_users'      => $checkarnew11, 
                    'pin_messages'      => $checkarnew12,
                    'manage_topics'     => $checkarnew13, 
                    'send_photos'       => $checkarnew14, 
                    'send_videos'       => $checkarnew15, 
                    'send_roundvideos'  => $checkarnew16, 
                    'send_audios'       => $checkarnew17, 
                    'send_voices'       => $checkarnew18, 
                    'send_docs'         => $checkarnew19,
                    'send_plain'        => $checkarnew20, 
                    'until_date'        => $checkarnew21,
                ];

$Updates2 = $this->messages->editChatDefaultBannedRights(peer: $peer, banned_rights: $chatBannedRights2, );
if (file_exists(__DIR__."/"."data/$peer/alertshabat2.txt")) {

if (file_exists(__DIR__."/"."data/$peer/MsgOpenerMedia.txt")) {
$MEDIA = Amp\File\read(__DIR__."/"."data/$peer/MsgOpenerMedia.txt");  
}else{
$MEDIA = null; 	
}
if (file_exists(__DIR__."/"."data/$peer/MsgOpener.txt")) {
$TXT = Amp\File\read(__DIR__."/"."data/$peer/MsgOpener.txt"); 
}else{
$TXT = null; 	
}
if (file_exists(__DIR__."/"."data/$peer/MsgOpener2.txt")) {
$ENT = json_decode(Amp\File\read(__DIR__."/"."data/$peer/MsgOpener2.txt"),true);  
}else{
$ENT = null; 	
}
if (file_exists(__DIR__."/"."data/$peer/MsgOpenerButtons.txt")) {
$BUTTONS = Amp\File\read(__DIR__."/"."data/$peer/MsgOpenerButtons.txt");
$bot_API_markup_welcome = $this->parseButtons($BUTTONS);
} else {

    $bot_API_markup_welcome = [
        '_' => 'replyInlineMarkup',
        'rows' => [],
    ];
}

if($MEDIA != null){

if($TXT != null){

$sentMessage = $this->messages->sendMedia(peer: $peer, message: "$TXT",  entities: $ENT, media: $MEDIA, reply_markup: $bot_API_markup_welcome);

}else{

$OPENER = self::OPENER;
$sentMessage = $this->messages->sendMedia(peer: $peer, message: $OPENER, media: $MEDIA, reply_markup: $bot_API_markup_welcome);	

}

}else{

if($TXT != null){

$sentMessage = $this->messages->sendMessage(peer: $peer, message: "$TXT", entities: $ENT, reply_markup: $bot_API_markup_welcome);

}else{

$OPENER = self::OPENER;
$sentMessage = $this->messages->sendMessage(peer: $peer, message: $OPENER, reply_markup: $bot_API_markup_welcome);

}


}




}
$this->sleep(0.1);
}
} catch (Throwable $e) {
continue;
}
}


}

}

} catch (Throwable $e) {
}
}

/* ================ payments / donate ================ */
#[FilterCommandCaseInsensitive('donate')]
public function Payments(Incoming & PrivateMessage & IsNotEdited $message): void {
try {
$messagetext = $message->message;
$messageid = $message->id;
$messagefile = $message->media;
$senderid = $message->senderId;
$grouped_id = $message->groupedId;
$User_Full = $this->getInfo($message->senderId);
$first_name = $User_Full['User']['first_name']?? null;
if($first_name == null){
$first_name = "null";
}
$last_name = $User_Full['User']['last_name']?? null;
if($last_name == null){
$last_name = "null";
}

$username = $User_Full['User']['username'] ?? ($User_Full['User']['usernames'][0]['username'] ?? "(null)");

$originalString = $senderid;
$encodedString = $originalString;

$labeledPrice1 = ['_' => 'labeledPrice', 'label' => 'star', 'amount' => 5];
$invoice1 = ['_' => 'invoice', 'currency' => 'XTR', 'prices' => [$labeledPrice1],];

$labeledPrice2 = ['_' => 'labeledPrice', 'label' => 'star', 'amount' => 25];
$invoice2 = ['_' => 'invoice', 'currency' => 'XTR', 'prices' => [$labeledPrice2],];

$labeledPrice3 = ['_' => 'labeledPrice', 'label' => 'star', 'amount' => 100];
$invoice3 = ['_' => 'invoice', 'currency' => 'XTR', 'prices' => [$labeledPrice3],];

$labeledPrice4 = ['_' => 'labeledPrice', 'label' => 'star', 'amount' => 150];
$invoice4 = ['_' => 'invoice', 'currency' => 'XTR', 'prices' => [$labeledPrice4],];

$labeledPrice5 = ['_' => 'labeledPrice', 'label' => 'star', 'amount' => 250];
$invoice5 = ['_' => 'invoice', 'currency' => 'XTR', 'prices' => [$labeledPrice5],];

$labeledPrice6 = ['_' => 'labeledPrice', 'label' => 'star', 'amount' => 400];
$invoice6 = ['_' => 'invoice', 'currency' => 'XTR', 'prices' => [$labeledPrice6],];

$inputMediaInvoice1 = ['_' => 'inputMediaInvoice', 'title' => 'תמכו בנו!', 'description' => 'תמכו בנו ב - 5 ⭐️', 'invoice' => $invoice1, 'payload' => "donate|$senderid|5", 'provider_data' => 'test']; 
$inputMediaInvoice2 = ['_' => 'inputMediaInvoice', 'title' => 'תמכו בנו!', 'description' => 'תמכו בנו ב - 25 ⭐️', 'invoice' => $invoice2, 'payload' => "donate|$senderid|25", 'provider_data' => 'test']; 
$inputMediaInvoice3 = ['_' => 'inputMediaInvoice', 'title' => 'תמכו בנו!', 'description' => 'תמכו בנו ב - 100 ⭐️', 'invoice' => $invoice3, 'payload' => "donate|$senderid|100", 'provider_data' => 'test']; 
$inputMediaInvoice4 = ['_' => 'inputMediaInvoice', 'title' => 'תמכו בנו!', 'description' => 'תמכו בנו ב - 150 ⭐️', 'invoice' => $invoice4, 'payload' => "donate|$senderid|150", 'provider_data' => 'test']; 
$inputMediaInvoice5 = ['_' => 'inputMediaInvoice', 'title' => 'תמכו בנו!', 'description' => 'תמכו בנו ב - 250 ⭐️', 'invoice' => $invoice5, 'payload' => "donate|$senderid|250", 'provider_data' => 'test']; 
$inputMediaInvoice6 = ['_' => 'inputMediaInvoice', 'title' => 'תמכו בנו!', 'description' => 'תמכו בנו ב - 400 ⭐️', 'invoice' => $invoice6, 'payload' => "donate|$senderid|400", 'provider_data' => 'test']; 


$payments_ExportedInvoice1 = $this->payments->exportInvoice(invoice_media: $inputMediaInvoice1, );
$urlexp1 = $payments_ExportedInvoice1['url']; //5

$payments_ExportedInvoice2 = $this->payments->exportInvoice(invoice_media: $inputMediaInvoice2, );
$urlexp2 = $payments_ExportedInvoice2['url']; //25

$payments_ExportedInvoice3 = $this->payments->exportInvoice(invoice_media: $inputMediaInvoice3, );
$urlexp3 = $payments_ExportedInvoice3['url']; //100

$payments_ExportedInvoice4 = $this->payments->exportInvoice(invoice_media: $inputMediaInvoice4, );
$urlexp4 = $payments_ExportedInvoice4['url']; //150

$payments_ExportedInvoice5 = $this->payments->exportInvoice(invoice_media: $inputMediaInvoice5, );
$urlexp5 = $payments_ExportedInvoice5['url']; //250

$payments_ExportedInvoice6 = $this->payments->exportInvoice(invoice_media: $inputMediaInvoice6, );
$urlexp6 = $payments_ExportedInvoice6['url']; //400


$bot_API_markup = ['inline_keyboard' => 
    [
        [	
['text'=>"⭐️ 5",'url'=>"$urlexp1"],['text'=>"⭐️ 25",'url'=>"$urlexp2"],['text'=>"⭐️ 100",'url'=>"$urlexp3"]
                    ],
                    [	
['text'=>"⭐️ 150",'url'=>"$urlexp4"],['text'=>"⭐️ 250",'url'=>"$urlexp5"],['text'=>"⭐️ 400",'url'=>"$urlexp6"]
        ]
    ]
];

$inputReplyToMessage = ['_' => 'inputReplyToMessage', 'reply_to_msg_id' => $messageid];
$sentMessage = $this->messages->sendMessage(peer: $senderid, reply_to: $inputReplyToMessage, message: "היי, תודה שאתם רוצים לתמוך בנו 🥰
בחרו את סכום התרומה שתרצו לתת 👇", reply_markup: $bot_API_markup, parse_mode: 'HTML', effect: 5159385139981059251);

} catch (Throwable $e) {}
}
public function onupdateBotPrecheckoutQuery($update) {
		try{
if ($this->isSelfBot()) {
$userid = $update['user_id'];
$total_amount = $update['total_amount']; 
$query_id = $update['query_id'];
$sucses = $this->messages->setBotPrecheckoutResults(success: true, query_id: $query_id);
}
} catch (\Throwable $e) {}
}
public function onUpdateNewMessage($update) {
		try{
if ($this->isSelfBot()) {
        $msg = $update['message'];
        $messageId = $msg['id'];
        $userId = $msg['from_id'] ?? null;

$User_Full = $this->getInfo($userId);
$first_name = $User_Full['User']['first_name']?? null;
if($first_name == null){
$first_name = "null";
}

$username = $User_Full['User']['username'] ?? ($User_Full['User']['usernames'][0]['username'] ?? null);
if($username === null){
$username = "(null)";
}else{
$username = "@".$username;
}

        if (isset($msg['action']['_']) && $msg['action']['_'] === 'messageActionPinMessage') {

    $botId = $this->getSelf()['id'];

    $actorId = $msg['from_id'] ?? null;

    if ($actorId == $botId) {
        $serviceMessageId = $msg['id'];
        try {
        $this->messages->deleteMessages(['id' => [$serviceMessageId], 'revoke' => true]);
		} catch (\Throwable $e) {}
    }
    }

        if (isset($msg['action']['_']) && $msg['action']['_'] === 'messageActionPaymentSentMe') {
            $amount   = $msg['action']['total_amount'];
            $currency = $msg['action']['currency'];
            $payload  = (string) $msg['action']['payload'];
            $charge   = $msg['action']['charge']['id'];
echo $charge;
$parts = explode('|', $payload);
if (count($parts) < 3) return;
$type    = $parts[0];
$uid     = $parts[1];
$price   = (int)$parts[2];

    if($amount != $price){
        return;
    }

    if($type == 'payment'){
    $credits = (int)$parts[3];
    $orderid = (int)$parts[4];

	}
    elseif($type == 'donate'){
$inputReplyToMessage = ['_' => 'inputReplyToMessage', 'reply_to_msg_id' => $messageId];
$this->messages->sendMessage(peer: $userId, reply_to: $inputReplyToMessage, message: "<b>amount:</b> $amount ⭐️
🎉 תודה על תרומתך 🎉", parse_mode: 'HTML', effect: 5159385139981059251);	

$this->sendMessageToAdmins("<b>תרומה התקבלה במערכת! 🎉</b>
FIRSTNAME: <a href='mention:$userId'>$first_name </a>
ID: <a href='mention:$userId'>$userId </a>
USERNAME: $username
<b>סכום:</b> $amount ⭐️",parseMode: ParseMode::HTML);
}

        }
    }
} catch (\Throwable $e) {}
}

/* ================ admin handlers ================ */

/*
* debug - זמנים
*/
#[FilterCommandCaseInsensitive('testzmanim')]
public function testzmanim(
    Incoming & PrivateMessage & FromAdmin & IsNotEdited $message
): void {

    try {

        $zmanim = $this->testMode
            ? $this->getTestShabbatLockTimes()
            : $this->getShabbatLockTimes();

        $text =
            "🧪 Debug Zmanim\n\n" .

            "Test mode: " .
            ($this->testMode ? 'ON ✅' : 'OFF ❌') .
            "\n\n" .

            "close_datetime: " .
            ($zmanim['close_datetime'] ?? 'null') .
            "\n" .

            "open_datetime: " .
            ($zmanim['open_datetime'] ?? 'null') .
            "\n\n" .

            "close_date: " .
            ($zmanim['close_date'] ?? 'null') .
            "\n" .

            "close_time: " .
            ($zmanim['close_time'] ?? 'null') .
            "\n\n" .

            "open_date: " .
            ($zmanim['open_date'] ?? 'null') .
            "\n" .

            "open_time: " .
            ($zmanim['open_time'] ?? 'null');

        $this->messages->sendMessage(
            peer: $message->chatId,
            message: $text
        );

    } catch (\Throwable $e) {

        $this->messages->sendMessage(
            peer: $message->chatId,
            message: "❌ Error:\n\n" . $e->getMessage()
        );
    }
}

/*
* הגדרת פקודות
*/
#[FilterCommandCaseInsensitive('setcommands')]
public function setcommands(Incoming & PrivateMessage & FromAdmin & IsNotEdited $message): void
{
    try {

        $this->bots->setBotCommands(

            scope: [
                '_' => 'botCommandScopeUsers'
            ],

            commands: [

                [
                    '_' => 'botCommand',
                    'command' => 'start',
                    'description' => 'התחל שימוש ברובוט ✅'
                ],

                [
                    '_' => 'botCommand',
                    'command' => 'shabat',
                    'description' => 'זמני שבת וחג 🕯'
                ],

                [
                    '_' => 'botCommand',
                    'command' => 'shabbat',
                    'description' => 'זמני שבת וחג 🕯'
                ],

                [
                    '_' => 'botCommand',
                    'command' => 'stats',
                    'description' => 'קבוצות שומרות שבת/חג 📊'
                ],

                [
                    '_' => 'botCommand',
                    'command' => 'donate',
                    'description' => 'תמיכה ברובוט ⭐️'
                ],
            ]
        );

foreach ($this->getAdminIds() as $adminId) {
        $this->bots->setBotCommands(

            scope: [
                '_' => 'botCommandScopePeer',
                'peer' => $adminId
            ],

            commands: [
                [
                    '_' => 'botCommand',
                    'command' => 'start',
                    'description' => 'התחל שימוש ברובוט ✅'
                ],

                [
                    '_' => 'botCommand',
                    'command' => 'shabat',
                    'description' => 'זמני שבת וחג 🕯'
                ],

                [
                    '_' => 'botCommand',
                    'command' => 'shabbat',
                    'description' => 'זמני שבת וחג 🕯'
                ],

                [
                    '_' => 'botCommand',
                    'command' => 'stats',
                    'description' => 'קבוצות שומרות שבת/חג 📊'
                ],

                [
                    '_' => 'botCommand',
                    'command' => 'donate',
                    'description' => 'תמיכה ברובוט ⭐️'
                ],

                [
                    '_' => 'botCommand',
                    'command' => 'admin',
                    'description' => 'פאנל ניהול ⚙️'
                ],

                [
                    '_' => 'botCommand',
                    'command' => 'testzmanim',
                    'description' => 'דיבוג זמנים 🧪'
                ],

                [
                    '_' => 'botCommand',
                    'command' => 'restart',
                    'description' => 'אתחל את הבוט 🔄'
                ],
            ]
        );
}

$this->bots->setBotCommands(

    scope: [
        '_' => 'botCommandScopeChatAdmins'
    ],

    commands: [

        [
            '_' => 'botCommand',
            'command' => 'add',
            'description' => 'הפעלת שמירת שבת/חג 🕯'
        ],

        [
            '_' => 'botCommand',
            'command' => 'remove',
            'description' => 'כיבוי שמירת שבת/חג ❌'
        ],

        [
            '_' => 'botCommand',
            'command' => 'settings',
            'description' => 'הגדרות הקבוצה ⚙️'
        ],
    ]
);

        $this->messages->sendMessage(
            peer: $message->chatId,
            message: "✅ Commands updated successfully"
        );

    } catch (\Throwable $e) {

        $this->messages->sendMessage(
            peer: $message->chatId,
            message: "❌ Error:\n\n" . $e->getMessage()
        );
    }
}

/*
* כפתורים פאנל ניהול
*/
public function getAdminKeyboard() {
    $markup[] = [['text'=>"סטטיסטיקות מנויים 📊",'callback_data'=>"סטטיסטיקות"]];
    $markup[] = [['text'=>"הצג מנויים 👁",'callback_data'=>"רשימתמשתמשים"]];
    $markup[] = [['text'=>"שידור למנויים 📮",'callback_data'=>"Broadcast"]];
    $markup = [ 'inline_keyboard'=> $markup];

    return $markup;
}

#[FilterCommandCaseInsensitive('admin')]
public function admincommand(Incoming & PrivateMessage & FromAdmin & IsNotEdited $message): void {
		try {
$senderid = $message->senderId;
$User_Full = $this->getInfo($message->senderId);
$first_name = $User_Full['User']['first_name']?? null;
if($first_name == null){
$first_name = "null";
}
$last_name = $User_Full['User']['last_name']?? null;
if($last_name == null){
$last_name = "null";
}
$username = $User_Full['User']['username']?? null;
if($username == null){
$username = "null";
}

$markup = $this->getAdminKeyboard();
$this->messages->sendMessage(peer: $message->senderId, message: "<b>ברוך הבא מנהל! 👋</b>
/setcommands - הגדר פקודות
/testzmanim - דיבוג זמנים
/restart - אתחל את המערכת

", reply_markup: $markup, parse_mode: 'HTML');
    if (file_exists("data/$senderid/grs1.txt")) {
unlink("data/$senderid/grs1.txt");
}
} catch (Throwable $e) {}
    }

#[FilterButtonQueryData('חזרהמנהל')] 
public function addsohe1hazor(callbackQuery $query) {
	try {
$userid = $query->userId;    
$User_Full = $this->getInfo($userid);
$first_name = $User_Full['User']['first_name']?? null;
if($first_name == null){
$first_name = "null";
}

$ADMIN = $this->getAdminIds();
if (in_array((string)$userid, array_map('strval', $ADMIN), true)) {
	
$markup = $this->getAdminKeyboard();
$query->editText($message = "<b>ברוך הבא מנהל! 👋</b>
/setcommands - הגדר פקודות
/testzmanim - דיבוג זמנים
/restart - אתחל את המערכת

", $replyMarkup = $markup, ParseMode::HTML, $noWebpage = false, $scheduleDate = NULL);
if (file_exists("data/$userid/grs1.txt")) {
unlink("data/$userid/grs1.txt");
}
}
} catch (Throwable $e) {}
}

#[FilterButtonQueryData('חזרהמנהל2')] 
public function addsohe1hazor2(callbackQuery $query) {
	try {
$userid = $query->userId;  
$msgid = $query->messageId;  

$ADMIN = $this->getAdminIds();
if (in_array((string)$userid, array_map('strval', $ADMIN), true)) {

try {
$this->messages->deleteMessages(revoke: true, id: [$msgid]); 
} catch (Throwable $e) {}

$markup = $this->getAdminKeyboard();
$this->messages->sendMessage(peer: $userid, message: "<b>ברוך הבא מנהל! 👋</b>
/setcommands - הגדר פקודות
/testzmanim - דיבוג זמנים
/restart - אתחל את המערכת

", reply_markup: $markup, parse_mode: 'HTML');

if (file_exists(__DIR__."/data/$userid/grs1.txt")) {
unlink(__DIR__."/data/$userid/grs1.txt");
}

    if (file_exists(__DIR__."/data/BUTTONS.txt")) {
unlink(__DIR__."/data/BUTTONS.txt");  
	}	
 if (file_exists(__DIR__."/data/$userid/txt.txt")) {
unlink(__DIR__."/data/$userid/txt.txt");  
}
  if (file_exists(__DIR__."/data/$userid/ent.txt")) {
unlink(__DIR__."/data/$userid/ent.txt");  
  }	  
  if (file_exists(__DIR__."/data/$userid/media.txt")) {
unlink(__DIR__."/data/$userid/media.txt");  
  }	 

}
} catch (Throwable $e) {}
}

/* ================ restart ================ */
public static function getPlugins(): array {
    return [\danog\MadelineProto\EventHandler\Plugin\RestartPlugin::class];
}
public static function getPluginPaths(): string|array|null {
    return null;
}

/* ================ מנויים ================ */

#[FilterButtonQueryData('רשימתמשתמשים')] 
public function reshimamishtamshim(callbackQuery $query)
{
	try {
$userid = $query->userId;    
$User_Full = $this->getInfo($userid);
$first_name = $User_Full['User']['first_name']?? null;
if($first_name == null){
$first_name = "null";
}

$dialogs = $this->getDialogIds();
$newLangsComma = implode("\n", $dialogs);
Amp\File\write(__DIR__."/ids.txt",$newLangsComma);

    if (file_exists(__DIR__."/ids.txt")) {
$filex = Amp\File\read(__DIR__."/ids.txt");
$numFruits = count($dialogs);

if($filex != null){
$file = __DIR__."/ids.txt";
$outputFile = __DIR__."/idsnew.txt"; 
$startLine = 1; 
$endLine = 5; 
$lines = file($file); 
$selectedLines = array_slice($lines, $startLine - 1, $endLine - $startLine + 1);
Amp\File\write($outputFile, implode("", $selectedLines));
$outputFilex = Amp\File\read(__DIR__."/idsnew.txt"); 

Amp\File\write(__DIR__."/data/startline_cat.txt","$startLine");
Amp\File\write(__DIR__."/data/endline_cat.txt","$endLine");
Amp\File\write(__DIR__."/data/page_var.txt","1");

$category = array_filter(array_map('trim', explode("\n", $outputFilex)));

$resultpages = ceil($numFruits / 5);

$bot_API_markup = [];

foreach($category as $txt) {
try {
	
$User_Full2 = $this->getInfo($txt);
$type = $User_Full2['type']?? null;

if($type == "channel" || "supergroup" || "chat"){
$first_name2 = $User_Full2['Chat']['title']?? null;
if($first_name2 == null){
$first_name2 = "$txt";
}
}

if($type == "user" || "bot"){
$first_name2 = $User_Full2['User']['first_name']?? null;
if($first_name2 == null){
$first_name2 = "$txt";
}
}

}catch (\danog\MadelineProto\Exception $e) {
$estring = (string) $e;
if(preg_match("/This peer is not present in the internal peer database/",$estring)){
$first_name2 = "$txt";
}
}

    $bot_API_markup[] = [['text' => $first_name2, 'callback_data' => "openprofile_$txt"]];
}
if($numFruits > $endLine){
$bot_API_markup[] = [['text'=>"הבא",'callback_data'=>"רשימתמנוייםהמשך"]];
}
if($startLine > 5){
$bot_API_markup[] = [['text'=>"הקודם",'callback_data'=>"רשימתמנוייםהקודם"]];
}
$bot_API_markup[] = [['text'=>"דף 1/$resultpages 📃",'callback_data'=>"var_cat"]];
$bot_API_markup[] = [['text'=>"חזרה",'callback_data'=>"חזרהמנהל"]];
$bot_API_markup = ['inline_keyboard' => $bot_API_markup];

$query->editText($message = "⛓️‍💥 <b>סך הכל מנויים:</b> $numFruits", $replyMarkup = $bot_API_markup, ParseMode::HTML, $noWebpage = false, $scheduleDate = NULL);
}

if($filex == null){
$newLangsComma = "אין עדיין משתמשים..";
$bot_API_markup[] = [['text'=>"חזרה",'callback_data'=>"חזרהמנהל"]];
$bot_API_markup = [ 'inline_keyboard'=> $bot_API_markup,];

$query->editText($message = "<b>$newLangsComma</b>", $replyMarkup = $bot_API_markup, ParseMode::HTML, $noWebpage = false, $scheduleDate = NULL);
}

	}	
    if (!file_exists(__DIR__."/ids.txt")) {
if($filex == null){
$newLangsComma = "אין עדיין משתמשים..";
$bot_API_markup[] = [['text'=>"חזרה",'callback_data'=>"חזרהמנהל"]];
$bot_API_markup = [ 'inline_keyboard'=> $bot_API_markup,];

$query->editText($message = "<b>$newLangsComma</b>", $replyMarkup = $bot_API_markup, ParseMode::HTML, $noWebpage = false, $scheduleDate = NULL);
}

	}		

} catch (Throwable $e) {}
}

#[FilterButtonQueryData('רשימתמנוייםהמשך')] 
public function reshimamishtamshim2(callbackQuery $query) {
	try {
$userid = $query->userId;    
$User_Full = $this->getInfo($userid);
$first_name = $User_Full['User']['first_name']?? null;
if($first_name == null){
$first_name = "null";
}

$dialogs = $this->getDialogIds();
$newLangsComma = implode("\n", $dialogs);
Amp\File\write(__DIR__."/ids.txt",$newLangsComma);

    if (file_exists(__DIR__."/ids.txt")) {
$filex = Amp\File\read(__DIR__."/ids.txt");
$numFruits = count($dialogs);

if($filex != null){
$startx = Amp\File\read(__DIR__."/data/startline_cat.txt"); 
$endx = Amp\File\read(__DIR__."/data/endline_cat.txt"); 



$file = __DIR__."/ids.txt";
$outputFile = __DIR__."/idsnew.txt"; 
$startLine = $startx + 5; 
$endLine = $endx + 5; 
$lines = file($file); 
$selectedLines = array_slice($lines, $startLine - 1, $endLine - $startLine + 1);
Amp\File\write($outputFile, implode("", $selectedLines));
$outputFilex = Amp\File\read(__DIR__."/idsnew.txt"); 

$pages_varx = Amp\File\read(__DIR__."/data/page_var.txt"); 
$pages_var = $pages_varx + 1; 

Amp\File\write(__DIR__."/data/startline_cat.txt","$startLine");
Amp\File\write(__DIR__."/data/endline_cat.txt","$endLine");
Amp\File\write(__DIR__."/data/page_var.txt","$pages_var");

$category = array_filter(array_map('trim', explode("\n", $outputFilex)));

$resultpages = ceil($numFruits / 5);

$bot_API_markup = [];

foreach($category as $txt) {
try {
	
$User_Full2 = $this->getInfo($txt);
$type = $User_Full2['type']?? null;

if($type == "channel" || "supergroup" || "chat"){
$first_name2 = $User_Full2['Chat']['title']?? null;
if($first_name2 == null){
$first_name2 = "$txt";
}
}

if($type == "user" || "bot"){
$first_name2 = $User_Full2['User']['first_name']?? null;
if($first_name2 == null){
$first_name2 = "$txt";
}
}

}catch (\danog\MadelineProto\Exception $e) {
$estring = (string) $e;
if(preg_match("/This peer is not present in the internal peer database/",$estring)){
$first_name2 = "$txt";
}
}

    $bot_API_markup[] = [['text' => $first_name2, 'callback_data' => "openprofile_$txt"]];
}
if($numFruits > $endLine){
$bot_API_markup[] = [['text'=>"הבא",'callback_data'=>"רשימתמנוייםהמשך"]];
}
if($startLine > 5){
$bot_API_markup[] = [['text'=>"הקודם",'callback_data'=>"רשימתמנוייםהקודם"]];
}
$bot_API_markup[] = [['text'=>"דף $pages_var/$resultpages 📃",'callback_data'=>"var_cat"]];
$bot_API_markup[] = [['text'=>"חזרה",'callback_data'=>"חזרהמנהל"]];
$bot_API_markup = ['inline_keyboard' => $bot_API_markup];

$query->editText($message = "⛓️‍💥 <b>סך הכל מנויים:</b> $numFruits", $replyMarkup = $bot_API_markup, ParseMode::HTML, $noWebpage = false, $scheduleDate = NULL);
}

if($filex == null){
$newLangsComma = "אין עדיין משתמשים..";
$bot_API_markup[] = [['text'=>"חזרה",'callback_data'=>"חזרהמנהל"]];
$bot_API_markup = [ 'inline_keyboard'=> $bot_API_markup,];

$query->editText($message = "<b>$newLangsComma</b>", $replyMarkup = $bot_API_markup, ParseMode::HTML, $noWebpage = false, $scheduleDate = NULL);
}

	}	
    if (!file_exists(__DIR__."/ids.txt")) {
if($filex == null){
$newLangsComma = "אין עדיין משתמשים..";
$bot_API_markup[] = [['text'=>"חזרה",'callback_data'=>"חזרהמנהל"]];
$bot_API_markup = [ 'inline_keyboard'=> $bot_API_markup,];

$query->editText($message = "<b>$newLangsComma</b>", $replyMarkup = $bot_API_markup, ParseMode::HTML, $noWebpage = false, $scheduleDate = NULL);
}

	}		
} catch (Throwable $e) {}	
}

#[FilterButtonQueryData('רשימתמנוייםהקודם')] 
public function reshimamishtamshim3(callbackQuery $query) {
	try {
$userid = $query->userId;    
$User_Full = $this->getInfo($userid);
$first_name = $User_Full['User']['first_name']?? null;
if($first_name == null){
$first_name = "null";
}

$dialogs = $this->getDialogIds();
$newLangsComma = implode("\n", $dialogs);
Amp\File\write(__DIR__."/ids.txt",$newLangsComma);

    if (file_exists(__DIR__."/ids.txt")) {
$filex = Amp\File\read(__DIR__."/ids.txt");
$numFruits = count($dialogs);

if($filex != null){
$startx = Amp\File\read(__DIR__."/data/startline_cat.txt"); 
$endx = Amp\File\read(__DIR__."/data/endline_cat.txt"); 

$file = __DIR__."/ids.txt";
$outputFile = __DIR__."/idsnew.txt"; 
$startLine = $startx - 5; 
$endLine = $endx - 5; 
$lines = file($file); 
$selectedLines = array_slice($lines, $startLine - 1, $endLine - $startLine + 1);
Amp\File\write($outputFile, implode("", $selectedLines));
$outputFilex = Amp\File\read(__DIR__."/idsnew.txt"); 

$pages_varx = Amp\File\read(__DIR__."/data/page_var.txt"); 
$pages_var = $pages_varx - 1; 

Amp\File\write(__DIR__."/data/startline_cat.txt","$startLine");
Amp\File\write(__DIR__."/data/endline_cat.txt","$endLine");
Amp\File\write(__DIR__."/data/page_var.txt","$pages_var");

$category = array_filter(array_map('trim', explode("\n", $outputFilex)));

$resultpages = ceil($numFruits / 5);

$bot_API_markup = [];

foreach($category as $txt) {
try {
	
$User_Full2 = $this->getInfo($txt);
$type = $User_Full2['type']?? null;

if($type == "channel" || "supergroup" || "chat"){
$first_name2 = $User_Full2['Chat']['title']?? null;
if($first_name2 == null){
$first_name2 = "$txt";
}
}

if($type == "user" || "bot"){
$first_name2 = $User_Full2['User']['first_name']?? null;
if($first_name2 == null){
$first_name2 = "$txt";
}
}

}catch (\danog\MadelineProto\Exception $e) {
$estring = (string) $e;
if(preg_match("/This peer is not present in the internal peer database/",$estring)){
$first_name2 = "$txt";
}
}


    $bot_API_markup[] = [['text' => $first_name2, 'callback_data' => "openprofile_$txt"]];
}
if($numFruits > $endLine){
$bot_API_markup[] = [['text'=>"הבא",'callback_data'=>"רשימתמנוייםהמשך"]];
}
if($startLine > 5){
$bot_API_markup[] = [['text'=>"הקודם",'callback_data'=>"רשימתמנוייםהקודם"]];
}
$bot_API_markup[] = [['text'=>"דף $pages_var/$resultpages 📃",'callback_data'=>"var_cat"]];
$bot_API_markup[] = [['text'=>"חזרה",'callback_data'=>"חזרהמנהל"]];
$bot_API_markup = ['inline_keyboard' => $bot_API_markup];

$query->editText($message = "⛓️‍💥 <b>סך הכל מנויים:</b> $numFruits", $replyMarkup = $bot_API_markup, ParseMode::HTML, $noWebpage = false, $scheduleDate = NULL);
}

if($filex == null){
$newLangsComma = "אין עדיין משתמשים..";
$bot_API_markup[] = [['text'=>"חזרה",'callback_data'=>"חזרהמנהל"]];
$bot_API_markup = [ 'inline_keyboard'=> $bot_API_markup,];

$query->editText($message = "<b>$newLangsComma</b>", $replyMarkup = $bot_API_markup, ParseMode::HTML, $noWebpage = false, $scheduleDate = NULL);
}

	}	
    if (!file_exists(__DIR__."/ids.txt")) {
if($filex == null){
$newLangsComma = "אין עדיין משתמשים..";
$bot_API_markup[] = [['text'=>"חזרה",'callback_data'=>"חזרהמנהל"]];
$bot_API_markup = [ 'inline_keyboard'=> $bot_API_markup,];

$query->editText($message = "<b>$newLangsComma</b>", $replyMarkup = $bot_API_markup, ParseMode::HTML, $noWebpage = false, $scheduleDate = NULL);
}

	}		
} catch (Throwable $e) {}	
}

#[FilterButtonQueryData('var_cat')]
public function var_cat_command(callbackQuery $query) { 
try { 
    if (file_exists(__DIR__."/ids.txt")) {
$filex = Amp\File\read(__DIR__."/ids.txt"); 
$numFruits = count(explode("\n",$filex));
if (file_exists(__DIR__."/data/page_var.txt")) {
$pages_varx = Amp\File\read(__DIR__."/data/page_var.txt"); 
$pages_var = $pages_varx; 
$resultpages = ceil($numFruits / 5);

$query->answer($message = "מציג דף $pages_var/$resultpages 📃", $alert = true, $url = null, $cacheTime = 0);
}
}
} catch (Throwable $e) {}
}

#[Handler]
public function adnimhandle(callbackQuery $query): void {
		try {
$userid = $query->userId;  
$querydata = $query->data;  
$msgid = $query->messageId;   
$User_Full = $this->getInfo($userid);
$first_name = $User_Full['User']['first_name']?? null;
if($first_name == null){
$first_name = "null";
}
$last_name = $User_Full['User']['last_name']?? null;
if($last_name == null){
$last_name = "null";
}
$username = $User_Full['User']['username']?? null;
if($username == null){
$username = "null";
}

$ADMIN = $this->getAdminIds();
if (in_array((string)$userid, array_map('strval', $ADMIN), true)) {

if(preg_match('/openprofile_/',$querydata)){ 
$str = str_replace('openprofile_','',$querydata);

try {
	
$User_Full2 = $this->getInfo($str);
$chackdb = true;
} catch (Throwable $e) {
$chackdb = false;
}

$type = $User_Full2['type']?? null;
if($type == null){
$type = "(null)";
}

if($type == "channel" || "supergroup" || "chat"){
$first_name2 = $User_Full2['Chat']['title']?? null;
if($first_name2 == null){
$first_name2 = "(null)";
}
$username2 = "(null)";
}

if($type == "user" || "bot"){
$first_name2 = $User_Full2['User']['first_name']?? null;
if($first_name2 == null){
$first_name2 = "(null)";
}

try {
$usernames = $User_Full2['User']['usernames']?? null;
$newLangsCommausername = null;
$peerList2username = [];
foreach ($usernames as $username) {
$usernamexfr = $username['username'];
$usernamexfr = "@".$usernamexfr;
$peerList2username[]=$usernamexfr;
}
$newLangsCommausername = implode(" ", $peerList2username);
}catch (\danog\MadelineProto\Exception $e) {
} catch (\danog\MadelineProto\RPCErrorException $e) {
}
$username2 = $User_Full2['User']['username']?? null;
if($username2 == null){	
if($newLangsCommausername != null){
$username2 = $newLangsCommausername;
}else{
$username2 = "(null)";
}
}else{
$username2 = "@".$username2;
}

}

try {
$this->messages->deleteMessages(revoke: true, id: [$msgid]); 

}catch (\danog\MadelineProto\Exception $e) {
$estring = (string) $e;
if(preg_match("/MESSAGE_DELETE_FORBIDDEN/",$estring)){
}

} catch (\danog\MadelineProto\RPCErrorException $e) {
    if ($e->rpc === 'MESSAGE_DELETE_FORBIDDEN') {	
}
}

$sentMessage = $this->messages->sendMessage(peer: $userid, message: "⏳");
$sentMessage2 = $this->extractMessageId($sentMessage);

try {

$bot_API_markup[] = [['text'=>"חזרה",'callback_data'=>"חזרהמנהל"],];
$bot_API_markup = [ 'inline_keyboard'=> $bot_API_markup,];

if($chackdb != false){
$txtuserag = "
════════════
█│║║▌│║║█║│║║█║
█─ 🆔  <a href='mention:$str'>$str </a>
█─ 🎭  $username2
█─ 👤  $first_name2
█─ 🔌  $type";
}
if($chackdb != true){
$txtuserag = "
════════════
█│║║▌│║║█║│║║█║
█─ 🆔  $str
█─ 🎭  $username2
█─ 👤  $first_name2
█─ 🔌  $type";
}

$this->messages->editMessage(peer: $userid, id: $sentMessage2, message: "$txtuserag", reply_markup: $bot_API_markup, parse_mode: 'HTML');

} catch (Throwable $e) {}

if (file_exists(__DIR__."/data/$userid/grs1.txt")) {
unlink(__DIR__."/data/$userid/grs1.txt");
}

}

}
} catch (Throwable $e) {}
}

/* ================ סטטיסטיקות ================ */
#[FilterButtonQueryData('סטטיסטיקות')]
public function StatsUsers(callbackQuery $query)
{
    try {
        $bot_API_markup = [
            'inline_keyboard' => [
                [['text' => "🔙 חזרה 🔙", 'callback_data' => "חזרהמנהל"]]
            ]
        ];

        $query->editText("⌛️", null, ParseMode::HTML);

$dialogs = $this->getDialogIds();

        $numChannels = $numSupergroups = $numChats = $numBots = 0;
        $numUsers = 0;

        foreach ($dialogs as $id) {
            try {
                $info = $this->getInfo($id);

                switch ($info['type'] ?? 'user') {
                    case 'channel':    $numChannels++; break;
                    case 'supergroup': $numSupergroups++; break;
                    case 'chat':       $numChats++; break;
                    case 'bot':        $numBots++; break;
                    case 'user':        $numUsers++; break;
                  //  default:           $numUsers++; break;
                }
            } catch (Throwable $e) {
				//$numUsers++;
			    continue;
            }
        }

$allIds = $numUsers + $numChannels + $numSupergroups + $numChats + $numBots;


        $fmt = fn($n) => number_format($n, 0, '.', ',');

        $message = "<b>🧮 סטטיסטיקות מנויים 📊</b>
- - - - - - - - - -
📢 כמות ערוצים: {$fmt($numChannels)}
💬 כמות קבוצות: {$fmt($numChats)}
👥 כמות קבוצות-על: {$fmt($numSupergroups)}
🤖 כמות בוטים: {$fmt($numBots)}
👤 כמות משתמשים: {$fmt($numUsers)}
- - - - - - - - - -
<b>🎯 סך הכל מנויים: {$fmt($allIds)}</b>";

        $query->editText($message, $bot_API_markup, ParseMode::HTML);

    } catch (Throwable $e) { }
}

#[FilterButtonQueryData('closeMsg')]
public function closeBroadcastMsg(callbackQuery $query) {
	try {
$this->messages->deleteMessages(revoke: true, id: [$query->messageId]); 
} catch (\Throwable $e) {
$query->answer($message = "I can't close the message, close it yourself.", $alert = false, $url = null, $cacheTime = 0);		
}
}

/* ================ מערכת שידור ================ */
///-----------------------------------------
# https://github.com/WizardLoop/BroadcastManager
///-----------------------------------------

#[FilterButtonQueryData('Broadcast')] 
public function broadcastCommand(callbackQuery $query)
{
	try {
$userid = $query->userId;    
$User_Full = $this->getInfo($userid);
$first_name = $User_Full['User']['first_name']?? null;
if($first_name == null){
$first_name = "null";
}

$bot_API_markup[] = [['text'=>"נתוני הודעה אחרונה 📊",'callback_data'=>"LastBrodDATA"]];
$bot_API_markup[] = [['text'=>"שידור למנויים 📮",'callback_data'=>"setBroadcast"]];
$bot_API_markup[] = [['text'=>"מחק הודעה אחרונה 🗑",'callback_data'=>"deleteLastBroadcast"]];
$bot_API_markup[] = [['text'=>"מחק את כל ההודעות 🗑",'callback_data'=>"deleteAllBroadcast"]];
$bot_API_markup[] = [['text'=>"בטל נעיצת הודעות ⛓️‍💥",'callback_data'=>"cancelPinned"]];
$bot_API_markup[] = [['text'=>"חזרה",'callback_data'=>"חזרהמנהל"]];
$bot_API_markup = [ 'inline_keyboard'=> $bot_API_markup,];

$query->editText($message = "<b>תפריט שידור, אנא בחר:</b>", $replyMarkup = $bot_API_markup, ParseMode::HTML, $noWebpage = false, $scheduleDate = NULL);
} catch (Throwable $e) {}
}

#[FilterButtonQueryData('deleteLastBroadcast')]
public function deleteLastBroadcast(callbackQuery $query)
{
try {
$API = new \danog\MadelineProto\API(__DIR__.'/bot.shabbat');
$manager = new BroadcastManager($API);
BroadcastManager::setDataDir(__DIR__ . '/data');
if (!$manager->hasLastBroadcast()) {
$query->answer($message = "אין הודעת שידור למחיקה!", $alert = true, $url = null, $cacheTime = 0);
	}else{
$query->answer($message = "אנא המתן...", $alert = false, $url = null, $cacheTime = 0);
$allUsers = $this->getDialogIds(); 
$manager->deleteLastBroadcastForAll($allUsers, $query->userId, 20);
    }
} catch (Throwable $e) {}
}

#[FilterButtonQueryData('deleteAllBroadcast')]
public function deleteAllBroadcast(callbackQuery $query)
{
try {
$API = new \danog\MadelineProto\API(__DIR__.'/bot.shabbat');
$manager = new BroadcastManager($API);
BroadcastManager::setDataDir(__DIR__ . '/data');

if (!$manager->hasAllBroadcast()) {
$query->answer($message = "אין הודעות שידור למחיקה!", $alert = true, $url = null, $cacheTime = 0);
	}else{
$query->answer($message = "אנא המתן...", $alert = false, $url = null, $cacheTime = 0);
$allUsers = $this->getDialogIds(); 
$manager->deleteAllBroadcastsForAll($allUsers, $query->userId, 20);
    }
} catch (Throwable $e) {}
}

#[FilterButtonQueryData('cancelPinned')]
public function cancelPinned(callbackQuery $query)
{
try {
$api = new \danog\MadelineProto\API(__DIR__.'/bot.shabbat');
$manager = new BroadcastManager($api);
BroadcastManager::setDataDir(__DIR__ . '/data');
$query->answer($message = "אנא המתן...", $alert = false, $url = null, $cacheTime = 0);
$allUsers = $this->getDialogIds(); 
$subfilter = 'users';
$filter_sub = $manager->filterPeers($allUsers, $subfilter);
$subs = $filter_sub['targets'];
$manager->unpinAllMessagesForAll($subs, $query->userId, 20);
} catch (Throwable $e) {}
}

#[FilterButtonQueryData('LastBrodDATA')]
public function LastBrodDATA(callbackQuery $query)
{  
try{

$API = new \danog\MadelineProto\API(__DIR__.'/bot.shabbat');
$manager = new BroadcastManager($API);
BroadcastManager::setDataDir(__DIR__ . '/data');
if ($manager->lastBroadcastData()) {
$filex = $manager->lastBroadcastData();
$bot_API_markup[] = [['text'=>"חזרה",'callback_data'=>"Broadcast"]];
$bot_API_markup = [ 'inline_keyboard'=> $bot_API_markup,];

$query->editText($message = $filex, $replyMarkup = $bot_API_markup, ParseMode::HTML, $noWebpage = false, $scheduleDate = NULL);

	}else{
$filex = "📊 אין עדיין נתונים."; 
$query->answer($message = $filex, $alert = true, $url = null, $cacheTime = 0);	
	}
} catch (Throwable $e) {}
}

#[FilterButtonQueryData('setBroadcast')] 
public function setBroadcast(callbackQuery $query)
{
	try {
$userid = $query->userId;    
$User_Full = $this->getInfo($userid);
$first_name = $User_Full['User']['first_name']?? null;
if($first_name == null){
$first_name = "null";
}

$api = new \danog\MadelineProto\API(__DIR__.'/bot.shabbat');
$manager = new BroadcastManager($api);
BroadcastManager::setDataDir(__DIR__ . '/data');

if(!$manager->progress()){

$bot_API_markup[] = [['text'=>"❌ ביטול ❌",'callback_data'=>"חזרהלפאנל"]];
$bot_API_markup = [ 'inline_keyboard'=> $bot_API_markup,];

$query->editText($message = "<b>נא שלח את ההודעה שתרצה לשלוח:</b>", $replyMarkup = $bot_API_markup, ParseMode::HTML, $noWebpage = false, $scheduleDate = NULL);

$userDir = __DIR__ . "/data/$userid";
        if (!is_dir($userDir)) {
            mkdir($userDir, 0777, true);
        }
		
Amp\File\write(__DIR__."/data/$userid/grs1.txt", 'broadcast1');
$msgqutryid = $query->messageId;
Amp\File\write(__DIR__."/data/$userid/messagetodelete.txt", "$msgqutryid");

}else{
$message = "יש שידור פעיל כרגע אנא המתן..";  
$query->answer($message = $message, $alert = true, $url = null, $cacheTime = 0);
}

} catch (Throwable $e) {}
}

    #[Handler]
    public function handlebroadcast1(Incoming & PrivateMessage & FromAdmin $message): void
    {
		try {
$messagetext = $message->message;
$messageid = $message->id;
$messagefile = $message->media;
$grouped_id = $message->groupedId;
$entities = $message->entities;
$senderid = $message->senderId;
$User_Full = $this->getInfo($message->senderId);
$first_name = $User_Full['User']['first_name']?? null;
if($first_name == null){
$first_name = "null";
}
$last_name = $User_Full['User']['last_name']?? null;
if($last_name == null){
$last_name = "null";
}
$username = $User_Full['User']['username']?? null;
if($username == null){
$username = "null";
}

$userDir = __DIR__ . "/data/$senderid";
        if (!is_dir($userDir)) {
            mkdir($userDir, 0777, true);
        }
		

    if (file_exists(__DIR__."/data/$senderid/grs1.txt")) {
$check = Amp\File\read(__DIR__."/data/$senderid/grs1.txt");    
if($check == "broadcast1"){
    
if(!preg_match('/^\/([Ss]tart)/',$messagetext)){   


$messageLength = mb_strlen($messagetext);

if($messageLength > 1024) {
	
			try {
$this->messages->deleteMessages(revoke: true, id: [$messageid]); 
}catch (\danog\MadelineProto\Exception $e) {
$estring = (string) $e;
if(preg_match("/MESSAGE_DELETE_FORBIDDEN/",$estring)){
}

} catch (\danog\MadelineProto\RPCErrorException $e) {
    if ($e->rpc === 'MESSAGE_DELETE_FORBIDDEN') {	
}
}
$bot_API_markup = ['inline_keyboard' => 
    [
        [
['text'=>"❌ ביטול ❌",'callback_data'=>"פאנל"]
        ]
    ]
];

$sentMessage = $this->messages->sendMessage(peer: $message->senderId, message: "נא לשלוח כיתוב עד 1024 תווים בלבד.
כמות התווים ששלחת: $messageLength", reply_markup: $bot_API_markup);


 if (file_exists(__DIR__."/data/$senderid/messagetodelete.txt")) {
$filexmsgid = Amp\File\read(__DIR__."/data/$senderid/messagetodelete.txt");  

			try {
$this->messages->deleteMessages(revoke: true, id: [$filexmsgid]); 
}catch (\danog\MadelineProto\Exception $e) {
$estring = (string) $e;
if(preg_match("/MESSAGE_DELETE_FORBIDDEN/",$estring)){
}

} catch (\danog\MadelineProto\RPCErrorException $e) {
    if ($e->rpc === 'MESSAGE_DELETE_FORBIDDEN') {	
}
}

unlink(__DIR__."/data/$senderid/messagetodelete.txt");
}

}else{

  if (file_exists(__DIR__."/data/$senderid/grs1.txt")) {
unlink(__DIR__."/data/$senderid/grs1.txt");  
  }	
    if (file_exists(__DIR__."/data/BUTTONS.txt")) {
unlink(__DIR__."/data/BUTTONS.txt");  
	}	
 if (file_exists(__DIR__."/data/$senderid/txt.txt")) {
unlink(__DIR__."/data/$senderid/txt.txt");  
}
  if (file_exists(__DIR__."/data/$senderid/ent.txt")) {
unlink(__DIR__."/data/$senderid/ent.txt");  
  }	  
  if (file_exists(__DIR__."/data/$senderid/media.txt")) {
unlink(__DIR__."/data/$senderid/media.txt");  
  }	 


if($messagetext != null){
Amp\File\write(__DIR__."/data/$senderid/txt.txt", "$messagetext");
Amp\File\write(__DIR__."/data/$senderid/ent.txt", json_encode(array_map(static fn($e) => $e->toMTProto(),$entities,)));	
}
if(!$messagefile){
}else{
$botApiFileId = $message->media->botApiFileId;
Amp\File\write(__DIR__."/data/$senderid/media.txt", "$botApiFileId");
}

			try {
$this->messages->deleteMessages(revoke: true, id: [$messageid]); 
}catch (\danog\MadelineProto\Exception $e) {
$estring = (string) $e;
if(preg_match("/MESSAGE_DELETE_FORBIDDEN/",$estring)){
}

} catch (\danog\MadelineProto\RPCErrorException $e) {
    if ($e->rpc === 'MESSAGE_DELETE_FORBIDDEN') {	
}
}

 if (file_exists(__DIR__."/data/$senderid/messagetodelete.txt")) {
$filexmsgid = Amp\File\read(__DIR__."/data/$senderid/messagetodelete.txt");  

			try {
$this->messages->deleteMessages(revoke: true, id: [$filexmsgid]); 
}catch (\danog\MadelineProto\Exception $e) {
$estring = (string) $e;
if(preg_match("/MESSAGE_DELETE_FORBIDDEN/",$estring)){
}

} catch (\danog\MadelineProto\RPCErrorException $e) {
    if ($e->rpc === 'MESSAGE_DELETE_FORBIDDEN') {	
}
}

unlink(__DIR__."/data/$senderid/messagetodelete.txt");
}


if (file_exists(__DIR__."/data/broadcastsend.txt")) {
$broadcast_send = Amp\File\read(__DIR__."/data/broadcastsend.txt");
}
if (!file_exists(__DIR__."/data/broadcastsend.txt")) {
$broadcast_send = "משתמשים";
}

if (file_exists(__DIR__."/data/pinmessage.txt")) {
$bot_API_markup[] = [['text'=>"📌 נעץ הודעה בצ'אט: ✔️",'callback_data'=>"נעץהודעהללא"]];
}
if (!file_exists(__DIR__."/data/pinmessage.txt")) {
$bot_API_markup[] = [['text'=>"📌 נעץ הודעה בצ'אט: ✖️",'callback_data'=>"נעץהודעה"]];
}

$bot_API_markup[] = [['text'=>"📮 יעד תפוצה: $broadcast_send",'callback_data'=>"מצבתפוצה"]];

$bot_API_markup[] = [['text'=>"👁 הצג כפתורי קישור",'callback_data'=>"צפהבכפתורים"]];
$bot_API_markup[] = [['text'=>"🔌 הוסף כפתורי קישור ➕",'callback_data'=>"הוספתכפתורים"]];

$bot_API_markup[] = [['text'=>"✅ שדר הודעה ✅",'callback_data'=>"שדרהודעה"]];

$bot_API_markup[] = [['text'=>"❌ ביטול ❌",'callback_data'=>"חזרהלפאנל"]];
$bot_API_markup = [ 'inline_keyboard'=> $bot_API_markup,];

 if (file_exists(__DIR__."/data/$senderid/txt.txt")) {
$filexmsgidtxt = Amp\File\read(__DIR__."/data/$senderid/txt.txt");  
}else{
$filexmsgidtxt = null; 
}
  if (file_exists(__DIR__."/data/$senderid/ent.txt")) {
$filexmsgident = json_decode(Amp\File\read(__DIR__."/data/$senderid/ent.txt"),true);  
  }else{
$filexmsgident = null;  
  }	  
  if (file_exists(__DIR__."/data/$senderid/media.txt")) {
$filexmsgidmedia = Amp\File\read(__DIR__."/data/$senderid/media.txt");  
  }else{
$filexmsgidmedia = null;  
  }	 

if($filexmsgidmedia != null){
	
if($filexmsgidtxt != null){
$sentMessage = $this->messages->sendMedia(peer: $message->senderId, message: "$filexmsgidtxt", entities: $filexmsgident, media: $filexmsgidmedia, reply_markup: $bot_API_markup);
}else{
$sentMessage = $this->messages->sendMedia(peer: $message->senderId, media: $filexmsgidmedia, reply_markup: $bot_API_markup);
}

}else{

if($filexmsgidtxt != null){
$sentMessage = $this->messages->sendMessage(peer: $message->senderId, message: "$filexmsgidtxt", entities: $filexmsgident, reply_markup: $bot_API_markup);
}
}

}


	
}


}

}

} catch (Throwable $e) {}
	}

#[FilterButtonQueryData('חזרהתפריטשידור')] 
public function hazarashidur(callbackQuery $query)
{
	try {
$userid = $query->userId; 
$msgqutryid = $query->messageId;   
$User_Full = $this->getInfo($userid);
$first_name = $User_Full['User']['first_name']?? null;
if($first_name == null){
$first_name = "null";
}

if (file_exists(__DIR__."/data/broadcastsend.txt")) {
$broadcast_send = Amp\File\read(__DIR__."/data/broadcastsend.txt");
}
if (!file_exists(__DIR__."/data/broadcastsend.txt")) {
$broadcast_send = "משתמשים";
}


if (file_exists(__DIR__."/data/pinmessage.txt")) {
$bot_API_markup[] = [['text'=>"📌 נעץ הודעה בצ'אט: ✔️",'callback_data'=>"נעץהודעהללא"]];
}
if (!file_exists(__DIR__."/data/pinmessage.txt")) {
$bot_API_markup[] = [['text'=>"📌 נעץ הודעה בצ'אט: ✖️",'callback_data'=>"נעץהודעה"]];
}
$bot_API_markup[] = [['text'=>"📮 יעד תפוצה: $broadcast_send",'callback_data'=>"מצבתפוצה"]];

$bot_API_markup[] = [['text'=>"👁 הצג כפתורי קישור",'callback_data'=>"צפהבכפתורים"]];
$bot_API_markup[] = [['text'=>"🔌 הוסף כפתורי קישור ➕",'callback_data'=>"הוספתכפתורים"]];

$bot_API_markup[] = [['text'=>"✅ שדר הודעה ✅",'callback_data'=>"שדרהודעה"]];

$bot_API_markup[] = [['text'=>"❌ ביטול ❌",'callback_data'=>"פאנל"]];
$bot_API_markup = [ 'inline_keyboard'=> $bot_API_markup,];

 if (file_exists(__DIR__."/data/$userid/txt.txt")) {
$filexmsgidtxt = Amp\File\read(__DIR__."/data/$userid/txt.txt");  
}else{
$filexmsgidtxt = null; 
}
  if (file_exists(__DIR__."/data/$userid/ent.txt")) {
$filexmsgident = json_decode(Amp\File\read(__DIR__."/data/$userid/ent.txt"),true);  
  }else{
$filexmsgident = null;  
  }	

if($filexmsgidtxt != null){
$this->messages->editMessage(peer: $userid, id: $msgqutryid, message: "$filexmsgidtxt", entities: $filexmsgident, reply_markup: $bot_API_markup);
}else{
$query->editText($message = "תפריט שידור אנא בחר:", $replyMarkup = $bot_API_markup, ParseMode::HTML, $noWebpage = false, $scheduleDate = NULL);
}
} catch (Throwable $e) {}
}

#[FilterButtonQueryData('נעץהודעה')] 
public function addsoheshidur1forneitza1(callbackQuery $query)
{
	try {
$userid = $query->userId;    
$User_Full = $this->getInfo($userid);
$first_name = $User_Full['User']['first_name']?? null;
if($first_name == null){
$first_name = "null";
}

Amp\File\write(__DIR__."/data/pinmessage.txt","on");

$bot_API_markup[] = [['text'=>"חזרה",'callback_data'=>"חזרהתפריטשידור"]];
$bot_API_markup = [ 'inline_keyboard'=> $bot_API_markup,];

$query->editText($message = "כעת ההודעה תנעץ בצ'אט ✔️", $replyMarkup = $bot_API_markup, ParseMode::HTML, $noWebpage = false, $scheduleDate = NULL);
} catch (Throwable $e) {}
}

#[FilterButtonQueryData('נעץהודעהללא')] 
public function addsoheshidur1forneitza2(callbackQuery $query)
{
	try {
$userid = $query->userId;    
$User_Full = $this->getInfo($userid);
$first_name = $User_Full['User']['first_name']?? null;
if($first_name == null){
$first_name = "null";
}

if (file_exists(__DIR__."/data/pinmessage.txt")) {
unlink(__DIR__."/data/pinmessage.txt");
}

$bot_API_markup[] = [['text'=>"חזרה",'callback_data'=>"חזרהתפריטשידור"]];
$bot_API_markup = [ 'inline_keyboard'=> $bot_API_markup,];

$query->editText($message = "כעת ההודעה לא תנעץ בצ'אט ✖️", $replyMarkup = $bot_API_markup, ParseMode::HTML, $noWebpage = false, $scheduleDate = NULL);
} catch (Throwable $e) {}
}

#[FilterButtonQueryData('מצבתפוצה')] 
public function broadsetsenders(callbackQuery $query)
{
	try {
$userid = $query->userId;    
$User_Full = $this->getInfo($userid);
$first_name = $User_Full['User']['first_name']?? null;
if($first_name == null){
$first_name = "null";
}

$bot_API_markup[] = [['text'=>"רק למשתמשים",'callback_data'=>"מצבתפוצה1"]];
$bot_API_markup[] = [['text'=>"רק לערוצים",'callback_data'=>"מצבתפוצה2"]];
$bot_API_markup[] = [['text'=>"רק לקבוצות",'callback_data'=>"מצבתפוצה3"]];
$bot_API_markup[] = [['text'=>"לכל המנויים",'callback_data'=>"מצבתפוצה4"]];
$bot_API_markup[] = [['text'=>"חזרה",'callback_data'=>"חזרהתפריטשידור"]];
$bot_API_markup = [ 'inline_keyboard'=> $bot_API_markup,];

$query->editText($message = "<b>אנא בחר מצב תפוצה 🔘</b>
האם לשלוח את ההודעה לכל המנויים או עם פילטר: רק משתמשים/קבוצות/ערוצים.", $replyMarkup = $bot_API_markup, ParseMode::HTML, $noWebpage = false, $scheduleDate = NULL);
} catch (Throwable $e) {}
}

#[FilterButtonQueryData('מצבתפוצה1')] 
public function broadsetsenders1(callbackQuery $query)
{
	try {
$userid = $query->userId;    
$User_Full = $this->getInfo($userid);
$first_name = $User_Full['User']['first_name']?? null;
if($first_name == null){
$first_name = "null";
}

Amp\File\write(__DIR__."/data/broadcastsend.txt","משתמשים");

$bot_API_markup[] = [['text'=>"חזרה",'callback_data'=>"חזרהתפריטשידור"]];
$bot_API_markup = [ 'inline_keyboard'=> $bot_API_markup,];

$query->editText($message = "<b>התפוצה שנבחרה:</b> רק למשתמשים", $replyMarkup = $bot_API_markup, ParseMode::HTML, $noWebpage = false, $scheduleDate = NULL);
} catch (Throwable $e) {}
}

#[FilterButtonQueryData('מצבתפוצה2')] 
public function broadsetsenders2(callbackQuery $query)
{
	try {
$userid = $query->userId;    
$User_Full = $this->getInfo($userid);
$first_name = $User_Full['User']['first_name']?? null;
if($first_name == null){
$first_name = "null";
}

Amp\File\write(__DIR__."/data/broadcastsend.txt","ערוצים");

$bot_API_markup[] = [['text'=>"חזרה",'callback_data'=>"חזרהתפריטשידור"]];
$bot_API_markup = [ 'inline_keyboard'=> $bot_API_markup,];

$query->editText($message = "<b>התפוצה שנבחרה:</b> רק לערוצים", $replyMarkup = $bot_API_markup, ParseMode::HTML, $noWebpage = false, $scheduleDate = NULL);
} catch (Throwable $e) {}
}

#[FilterButtonQueryData('מצבתפוצה3')] 
public function broadsetsenders3(callbackQuery $query)
{
	try {
$userid = $query->userId;    
$User_Full = $this->getInfo($userid);
$first_name = $User_Full['User']['first_name']?? null;
if($first_name == null){
$first_name = "null";
}

Amp\File\write(__DIR__."/data/broadcastsend.txt","קבוצות");

$bot_API_markup[] = [['text'=>"חזרה",'callback_data'=>"חזרהתפריטשידור"]];
$bot_API_markup = [ 'inline_keyboard'=> $bot_API_markup,];

$query->editText($message = "<b>התפוצה שנבחרה:</b> רק לקבוצות", $replyMarkup = $bot_API_markup, ParseMode::HTML, $noWebpage = false, $scheduleDate = NULL);
} catch (Throwable $e) {}
}

#[FilterButtonQueryData('מצבתפוצה4')] 
public function broadsetsenders4(callbackQuery $query)
{
	try {
$userid = $query->userId;    
$User_Full = $this->getInfo($userid);
$first_name = $User_Full['User']['first_name']?? null;
if($first_name == null){
$first_name = "null";
}

Amp\File\write(__DIR__."/data/broadcastsend.txt","כולם");

$bot_API_markup[] = [['text'=>"חזרה",'callback_data'=>"חזרהתפריטשידור"]];
$bot_API_markup = [ 'inline_keyboard'=> $bot_API_markup,];

$query->editText($message = "<b>התפוצה שנבחרה:</b> כולם", $replyMarkup = $bot_API_markup, ParseMode::HTML, $noWebpage = false, $scheduleDate = NULL);
} catch (Throwable $e) {}
}

#[FilterButtonQueryData('הוספתכפתורים')] 
public function hosafkaf(callbackQuery $query)
{
	try {
$userid = $query->userId;    
$User_Full = $this->getInfo($userid);
$first_name = $User_Full['User']['first_name']?? null;
if($first_name == null){
$first_name = "null";
}

$bot_API_markup[] = [['text'=>"❌ ביטול ❌",'callback_data'=>"חזרהתפריטשידור"]];
$bot_API_markup = [ 'inline_keyboard'=> $bot_API_markup,];

$query->editText($message = "<b>שלח את הכפתורים שתרצה להגדיר בפורמט הבא:</b>

• <u>רשימת כפתורים(כפתור בודד בשורה):</u>
<pre>Button text 1 - http://www.example.com/
Button text 2 - http://www.example2.com/</pre>

• <u>מספר כפתורים בשורה אחת:</u>
<pre>Button text 1 - http://www.example.com/ &amp;&amp; Button text 2 - http://www.example2.com/</pre>

• <u>הוסף כפתור מסוג תפריט:</u>
<pre>שם כפתור - data: שם תפריט</pre>

<u>שמות תפריטים data:</u>
<pre>
closeMsg (סגור הודעה)
</pre>

<u>דוגמה:</u>
<pre>סגור - data:closeMsg</pre>", $replyMarkup = $bot_API_markup, ParseMode::HTML, $noWebpage = false, $scheduleDate = NULL);

Amp\File\write(__DIR__."/data/$userid/grs1.txt", 'addBUTTONS');
$msgqutryid = $query->messageId;
Amp\File\write(__DIR__."/data/$userid/messagetodelete.txt", "$msgqutryid");
} catch (Throwable $e) {}
}

#[Handler]
public function handlebuttons(Incoming & PrivateMessage & FromAdmin $message): void
    {
		try {
$messagetext = $message->message;
$entities = $message->entities;
$messagefile = $message->media;
$messageid = $message->id;
$senderid = $message->senderId;
$User_Full = $this->getInfo($message->senderId);
$first_name = $User_Full['User']['first_name']?? null;
if($first_name == null){
$first_name = "null";
}
$last_name = $User_Full['User']['last_name']?? null;
if($last_name == null){
$last_name = "null";
}
$username = $User_Full['User']['username']?? null;
if($username == null){
$username = "null";
}

    if (file_exists(__DIR__."/data/$senderid/grs1.txt")) {
$edit = Amp\File\read(__DIR__."/data/$senderid/grs1.txt");    
if($edit == "addBUTTONS"){
 
if(!preg_match('/^\/([Ss]tart)/',$messagetext)){   

if (!function_exists("parseButtons")) {
    function parseButtons(string $text): array|false
    {
        $lines = explode("\n", trim($text));
        $keyboard = [];

        foreach ($lines as $lineNumber => $line) {
            $line = trim($line);
            if ($line === '') continue;

            $row = [];
            $buttons = explode('&&', $line);

            foreach ($buttons as $btnNumber => $button) {
                $button = trim($button);

                // פורמט בסיסי: טקסט - ערך
                if (!preg_match('/^(.+?)\s*-\s*(.+)$/u', $button, $m)) {
                    return false;
                }

                $text  = trim($m[1]);
                $value = trim($m[2]);

                // URL
                if (preg_match('#^https?://#i', $value)) {

                    $row[] = [
                        'text' => $text,
                        'url'  => $value
                    ];

                // CALLBACK DATA
                } elseif (preg_match('#^data:\s*(.+)$#u', $value, $dm)) {

                    $callback = trim($dm[1]); // 🔹 מנקה רווחים לפני ואחרי

                    // 🔒 הגבלת 64 bytes (לא תווים!)
                    if (strlen($callback) > 64) {
                        return false;
                    }

                    $row[] = [
                        'text' => $text,
                        'callback_data' => $callback
                    ];

                } else {
                    // לא URL ולא data:
                    return false;
                }
            }

            $keyboard[] = $row;
        }

        return $keyboard;
    }
}

$parsedButtons = parseButtons($messagetext);

if ($parsedButtons !== false) {
unlink(__DIR__."/data/$senderid/grs1.txt");

			try {
$this->messages->deleteMessages(revoke: true, id: [$messageid]); 
}catch (\danog\MadelineProto\Exception $e) {
$estring = (string) $e;
if(preg_match("/MESSAGE_DELETE_FORBIDDEN/",$estring)){
}

} catch (\danog\MadelineProto\RPCErrorException $e) {
    if ($e->rpc === 'MESSAGE_DELETE_FORBIDDEN') {	
}
}

 if (file_exists(__DIR__."/data/$senderid/messagetodelete.txt")) {
$filexmsgid = Amp\File\read(__DIR__."/data/$senderid/messagetodelete.txt");  
$bot_API_markup = [];
$bot_API_markup[] = [['text'=>"חזרה",'callback_data'=>"חזרהתפריטשידור"]];
$bot_API_markup = [ 'inline_keyboard'=> $bot_API_markup,];
$Updates = $this->messages->editMessage(peer: $senderid, id: $filexmsgid, message: "<b>הכפתורים נשמרו בהצלחה! ✔️</b>", reply_markup: $bot_API_markup, parse_mode: 'HTML');

Amp\File\write(__DIR__."/data/BUTTONS.txt", json_encode($parsedButtons, JSON_UNESCAPED_UNICODE));

}


} else {
			try {
$this->messages->deleteMessages(revoke: true, id: [$messageid]); 
}catch (\danog\MadelineProto\Exception $e) {
$estring = (string) $e;
if(preg_match("/MESSAGE_DELETE_FORBIDDEN/",$estring)){
}

} catch (\danog\MadelineProto\RPCErrorException $e) {
    if ($e->rpc === 'MESSAGE_DELETE_FORBIDDEN') {	
}
}

 if (file_exists(__DIR__."/data/$senderid/messagetodelete.txt")) {
$filexmsgid = Amp\File\read(__DIR__."/data/$senderid/messagetodelete.txt");  
$bot_API_markup = [];
$bot_API_markup[] = [['text'=>"❌ ביטול ❌",'callback_data'=>"חזרהתפריטשידור"]];
$bot_API_markup = [ 'inline_keyboard'=> $bot_API_markup,];

			try {
$Updates = $this->messages->editMessage(peer: $senderid, id: $filexmsgid, message: "<b>נא שלח את הכפתורים שתרצה להוסיף בפורמט הנכון!</b>", reply_markup: $bot_API_markup, parse_mode: 'HTML');
}catch (\danog\MadelineProto\Exception $e) {
$estring = (string) $e;
if(preg_match("/MESSAGE_NOT_MODIFIED/",$estring)){
}

} catch (\danog\MadelineProto\RPCErrorException $e) {
    if ($e->rpc === 'MESSAGE_NOT_MODIFIED') {	
}
}


}
}








	
}


}





	
}

} catch (Throwable $e) {}
}

#[FilterButtonQueryData('צפהבכפתורים')] 
public function buttonsmanageview(callbackQuery $query)
{
	try {
$userid = $query->userId;    
$User_Full = $this->getInfo($userid);
$first_name = $User_Full['User']['first_name']?? null;
if($first_name == null){
$first_name = "null";
}

$buttons = __DIR__."/data/BUTTONS.txt";

    if (file_exists($buttons)) {

if (!function_exists('loadButtons')) {
    function loadButtons(string $file): array|null
    {
        if (!file_exists($file)) {
            return null;
        }

        $json = Amp\File\read($file);
        $buttons = json_decode($json, true);

        if (!is_array($buttons)) {
            return null;
        }

        return $buttons;
    }
}

$buttonsData = loadButtons($buttons);

if ($buttonsData === null || empty($buttonsData)) {
$BUTTONS = "לא הוגדרו עדיין כפתורים..";  
$query->answer($message = "$BUTTONS", $alert = true, $url = null, $cacheTime = 0);
	}else{

$buttonsData[] = [['text' => 'חזרה', 'callback_data' => 'חזרהתפריטשידור']];
$bot_API_markup = ['inline_keyboard' => $buttonsData];

$query->editText($message = "הכפתורים שלך:", $replyMarkup = $bot_API_markup, ParseMode::HTML, $noWebpage = false, $scheduleDate = NULL);
}


	}
	
    if (!file_exists($buttons)) {
$BUTTONS = "לא הוגדרו עדיין כפתורים..";  
$query->answer($message = "$BUTTONS", $alert = true, $url = null, $cacheTime = 10);
	}
	
} catch (Throwable $e) {}
}

#[FilterButtonQueryData('שדרהודעה')] 
public function buttonsmanageview2(callbackQuery $query)
{
	try{
$userid = $query->userId;  
$msgqutryid = $query->messageId;   
$User_Full = $this->getInfo($userid);
$first_name = $User_Full['User']['first_name']?? null;
if($first_name == null){
$first_name = "null";
}

$buttons = __DIR__."/data/BUTTONS.txt";

    if (file_exists($buttons)) {

if (!function_exists('loadButtons')) {
    function loadButtons(string $file): array|null
    {
        if (!file_exists($file)) {
            return null;
        }

        $json = Amp\File\read($file);
        $buttons = json_decode($json, true);

        if (!is_array($buttons)) {
            return null;
        }

        return $buttons;
    }
}

$buttonsData = loadButtons($buttons);

if ($buttonsData === null || empty($buttonsData)) {
$bot_API_markup = null;  
}else{
$bot_API_markup = ['inline_keyboard' => $buttonsData];
}


	}else{
$bot_API_markup = null;  
	}
	

			try {
$this->messages->deleteMessages(revoke: true, id: [$msgqutryid]); 
} catch (Throwable $e) {}

 if (file_exists(__DIR__."/data/$userid/txt.txt")) {
$filexmsgidtxt = Amp\File\read(__DIR__."/data/$userid/txt.txt");  
}else{
$filexmsgidtxt = null; 
}
  if (file_exists(__DIR__."/data/$userid/ent.txt")) {
$filexmsgident = json_decode(Amp\File\read(__DIR__."/data/$userid/ent.txt"),true);  
  }else{
$filexmsgident = null;  
  }	  
  if (file_exists(__DIR__."/data/$userid/media.txt")) {
$filexmsgidmedia = Amp\File\read(__DIR__."/data/$userid/media.txt");  
  }else{
$filexmsgidmedia = null;  
  }	 

        try {
$dialogs = $this->getDialogIds();
        } catch (Throwable $e) { $dialogs = []; }

    if (!file_exists(__DIR__."/data/pinmessage.txt")) {
$pinmessage = false;
	}else{
$pinmessage = true;
	}

    if (!file_exists(__DIR__."/data/broadcastsend.txt")) {
$subfilter = 'users';
	}else{
$check2 = Amp\File\read(__DIR__."/data/broadcastsend.txt");  

if($check2 == "משתמשים"){
$subfilter = 'users';
}elseif($check2 == "ערוצים"){
$subfilter = 'channels';
}elseif($check2 == "קבוצות"){	
$subfilter = 'groups';
}elseif($check2 == "כולם"){
$subfilter = 'all';
}else{
$subfilter = 'users';
}
}


if($filexmsgidmedia != null){

if($filexmsgidtxt != null){
$messages = [['message' => "$filexmsgidtxt", 'entities' => $filexmsgident, 'media' => $filexmsgidmedia, 'reply_markup' => $bot_API_markup]];
}else{
$messages = [['media' => $filexmsgidmedia, 'reply_markup' => $bot_API_markup]];
}

}else{

if($filexmsgidtxt != null){
$messages = [['message' => "$filexmsgidtxt", 'entities' => $filexmsgident, 'reply_markup' => $bot_API_markup]];
}

}

$api = new \danog\MadelineProto\API(__DIR__.'/bot.shabbat');
$manager = new BroadcastManager($api);
BroadcastManager::setDataDir(__DIR__ . '/data');

if(!$manager->progress()){
$filter_sub = $manager->filterPeers($dialogs, $subfilter);
$subs = $filter_sub['targets'];

$manager->broadcastWithProgress($subs, $messages, $userid, $pinmessage, 20);

}else{
$message = "יש שידור פעיל כרגע אנא המתן..";  
$query->answer($message = $message, $alert = true, $url = null, $cacheTime = 0);
}

} catch (Throwable $e) {}
}

#[FilterButtonQueryData('בקרוב')]
public function comingsoon(callbackQuery $query) {  
try{
$query->answer($message = "בקרוב מאוד זה יפעל 💡", $alert = true, $url = null, $cacheTime = 0);
} catch (Throwable $e) {}
}

}

function RunBot(): void {
	try {
$API_ID = parse_ini_file(__DIR__."/".'.env')['API_ID'];
$API_HASH = parse_ini_file(__DIR__."/".'.env')['API_HASH'];
$BOT_TOKEN = parse_ini_file(__DIR__."/".'.env')['BOT_TOKEN'];
$settings = new \danog\MadelineProto\Settings;
$settings->setAppInfo((new \danog\MadelineProto\Settings\AppInfo)->setApiId((int)$API_ID)->setApiHash($API_HASH));

$logger = (new \danog\MadelineProto\Settings\Logger)->setLevel(\danog\MadelineProto\Logger::ERROR);
$settings->setLogger($logger);

Shabbat::startAndLoopBot(__DIR__.'/bot.shabbat', $BOT_TOKEN, $settings);

} catch (\Throwable $e) {
if (strpos($e->getMessage(), 'bad_msg_notification') !== false) exit(1);
if ($e instanceof \Amp\TimeoutException || $e instanceof \Amp\CancelledException) exit(1);
}
}
RunBot();
