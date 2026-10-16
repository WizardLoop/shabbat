<?php

// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);

namespace Shabbat\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shabbat\Calendar\{CalendarService, HebcalProvider};
use Shabbat\Content\ShabbatTimesFormatter;
use Shabbat\Location\GeoNames;
use Shabbat\Runtime\{Application, Runner};
use Shabbat\Scheduling\JobRepository;
use Shabbat\Storage\{Database, FlowTokenRepository, SettingsRepository};
use Shabbat\Time\FrozenClock;
use Shabbat\UI\{BotController, Lang};

final class ShabbatHolidayOverviewTest extends TestCase
{
    public static function languagesAndFestivalPhases(): iterable
    {
        foreach (Lang::CODES as $language) {
            yield $language . '-upcoming' => [$language, '2026-04-01T12:00:00+03:00'];
            yield $language . '-active' => [$language, '2026-04-02T12:00:00+03:00'];
        }
    }

    private function context(string $date, string $language = 'he'): array
    {
        $db = Database::open(':memory:');
        $db->migrate();
        $clock = new FrozenClock(new \DateTimeImmutable($date));
        $settings = new SettingsRepository($db, $clock);
        $user = $settings->getUser('1');
        $user = $settings->updateUser('1', $user['revision'], ['language' => $language]);
        $http = new FullHebcalFixtureClient();
        $calendar = new CalendarService(new HebcalProvider($http), $clock);
        return [$db, $clock, $settings, $user, $http, $calendar];
    }

    #[DataProvider('languagesAndFestivalPhases')]
    public function testIsolatedYomTovBeforeShabbatAppearsWithBoundariesInEveryDefaultPath(string $language, string $date): void
    {
        [$db, $clock, $settings, $user, $http, $calendar] = $this->context($date, $language);
        $group = $settings->ensureGroup('-1001', 'Festival fixture', ['language' => $language, 'mode' => 'messages', 'times_alert_enabled' => true]);
        $geo = new GeoNames($http, $clock, '');
        $flows = new FlowTokenRepository($db, $clock);
        $gateway = new UiNavigationFakeGateway();
        $ui = new BotController($settings, $flows, $calendar, $gateway, $geo, $clock);
        $sendGateway = new GroupMessageButtonsGateway();
        $app = new Application($sendGateway, $clock, $db, $calendar, $geo);
        $snapshot = $calendar->snapshot($user);
        $reference = $snapshot['current_event'] ?? $snapshot['next_event'];
        self::assertSame(['2026-04-02'], $reference['days'], 'Actual Israel Pesach fixture has Thursday Yom Tov, ordinary Friday and separate Shabbat.');
        self::assertTrue($reference['is_yom_tov']);
        self::assertFalse($reference['is_shabbat']);
        $expected = (new ShabbatTimesFormatter())->overview($user, $snapshot)['text'];
        $lang = new Lang();
        [$main, $holidays] = explode('<b>' . $lang->text($language, 'times_upcoming_calendar') . '</b>', $expected);
        foreach (['2026-04-03 18:20', '2026-04-04 19:37'] as $time) {
            self::assertStringContainsString($time, $main);
            self::assertSame(1, substr_count($expected, $time));
        }
        foreach (['2026-04-01 18:19', '2026-04-02 19:36'] as $time) {
            self::assertStringContainsString($time, $holidays);
            self::assertStringNotContainsString($time, $main);
            self::assertSame(1, substr_count($expected, $time));
        }
        self::assertStringContainsString('<b>' . $lang->text($language, 'times_shabbat_title') . '</b>', $main);
        self::assertSame(1, substr_count($expected, $lang->text($language, 'times_heading')));
        self::assertSame(1, substr_count($expected, 'Asia/Jerusalem'));
        foreach (['2026-04-10 ', '2026-04-11 ', 'Hebcal', '(+03:00)', '(+02:00)'] as $hidden) {
            self::assertStringNotContainsString($hidden, $expected);
        }

        self::assertSame($expected, $ui->renderView('1', $group, 'shabbat'));
        self::assertTrue($ui->handleMessage(['user_id' => '1', 'private' => true, 'text' => '/shabbat']));
        self::assertSame($expected, $gateway->sent[0]['text']);
        $query = ['user_id' => '1', 'chat_id' => '1', 'private' => true, 'message_id' => 101, 'query_id' => '123'];
        self::assertTrue($ui->handleCallback($query + ['data' => 'sb:show:shabbat']));
        self::assertSame($expected, $gateway->edited[0]['text']);
        $token = $flows->create('1', 'group', '-1001', $group['revision'], 'panel');
        self::assertTrue($ui->handleCallback($query + ['data' => 'sb:ap:' . $token]));
        self::assertSame($expected, $gateway->edited[1]['text']);
        self::assertTrue($ui->handleInline(['user_id' => '1', 'query_id' => '456', 'query' => 'shabat']));
        self::assertSame($expected, $gateway->inline[0]['results'][0]['send_message']['message']);
        $share = $flows->create('1', 'share', '-1001', $group['revision'], 'share_snapshot', payload: ['settings' => $group, 'module' => 'shabbat', 'date' => null]);
        self::assertTrue($ui->handleInline(['user_id' => '1', 'query_id' => '457', 'query' => 'share ' . $share]));
        self::assertSame("Festival fixture\n" . $expected, $gateway->inline[1]['results'][0]['send_message']['message']);
        self::assertSame($expected, $app->payload($group, 'times_alert', $clock->now())['text']);
        $template = $app->templates->save('-1001', 'times_alert', ['text' => '', 'buttons_mode' => 'none'], '1');
        self::assertSame($expected, $app->payload($group, 'times_alert', $clock->now(), templateOverride: $template)['text']);

        (new \ReflectionProperty(Runner::class, 'stopping'))->setValue(null, false);
        $now = $clock->now()->getTimestamp();
        (new JobRepository($db, $clock))->enqueue('-1001', 'times_alert', 'festival-layout', $now, $now + 300, $group['revision'], []);
        $worker = (new \ReflectionProperty(Application::class, 'worker'))->getValue($app);
        $delivery = $worker->runOnce('festival-layout');
        if ($snapshot['current_event'] === null) {
            self::assertSame('sent', $delivery['status']);
            self::assertSame($expected, $sendGateway->sent[0]['payload']['text']);
        } else {
            self::assertSame('cancelled', $delivery['status'], 'Existing quiet-period policy still prevents scheduled notices during Yom Tov.');
            self::assertSame([], $sendGateway->sent);
        }
        self::assertLessThanOrEqual(3900, strlen(mb_convert_encoding(html_entity_decode(strip_tags($expected), ENT_QUOTES | ENT_HTML5, 'UTF-8'), 'UTF-16LE', 'UTF-8')) / 2);
    }

    public function testCholHamoedIsNotYomTovAndHasNoInventedFestivalBoundaries(): void
    {
        [, , , $user, , $calendar] = $this->context('2026-04-03T12:00:00+03:00');
        $snapshot = $calendar->snapshot($user);
        self::assertTrue($snapshot['is_chol_hamoed']);
        self::assertFalse($snapshot['is_yom_tov']);
        self::assertFalse($snapshot['is_work_prohibited_now']);
        self::assertNull($snapshot['lock_event']);
        $text = (new ShabbatTimesFormatter())->overview($user, $snapshot)['text'];
        $holidays = explode('<b>' . (new Lang())->text('he', 'times_upcoming_calendar') . '</b>', $text)[1];
        self::assertStringContainsString('פסח ב׳ (חוה״מ) · 2026-04-03', $holidays);
        self::assertStringNotContainsString('2026-04-01 18:19', $holidays);
        self::assertStringNotContainsString('2026-04-02 19:36', $holidays);
        self::assertSame(1, substr_count($holidays, 'כניסה / הדלקת נרות:'), 'Only actual Pesach VII has festival boundaries.');
        self::assertStringContainsString('2026-04-07 18:23', $holidays);
        self::assertStringContainsString('2026-04-08 19:40', $holidays);
    }

    public function testOldCacheUsesEvaluationDateAndAnEndedFestivalIsNotKeptUntilNextFetch(): void
    {
        [, $clock, , $user, $http, $calendar] = $this->context('2026-04-01T23:00:00+03:00');
        $first = $calendar->snapshot($user);
        $clock->set(new \DateTimeImmutable('2026-04-02T01:00:00+03:00'));
        $second = $calendar->snapshot($user);
        self::assertSame($first['fetched_at'], $second['fetched_at']);
        self::assertSame('2026-04-02', $second['local_date']);
        self::assertSame($clock->now()->getTimestamp(), $second['evaluated_at']);
        self::assertCount(2, $http->calls);
        $text = (new ShabbatTimesFormatter())->overview($user, $second)['text'];
        self::assertStringNotContainsString('ערב פסח', $text);
        self::assertStringContainsString('2026-04-02 19:36', $text);

        $clock->set(new \DateTimeImmutable('2026-04-02T18:00:00+03:00'));
        $beforeExit = $calendar->snapshot($user);
        $clock->set(new \DateTimeImmutable('2026-04-02T20:00:00+03:00'));
        $afterExit = $calendar->snapshot($user);
        self::assertSame($beforeExit['fetched_at'], $afterExit['fetched_at']);
        self::assertStringNotContainsString('2026-04-02 19:36', (new ShabbatTimesFormatter())->overview($user, $afterExit)['text']);
        self::assertStringContainsString('2026-04-04 19:37', (new ShabbatTimesFormatter())->overview($user, $afterExit)['text']);

        $legacy = $second;
        unset($legacy['evaluated_at'], $legacy['local_date']);
        $legacy['fetched_at'] = 1;
        $legacyText = (new ShabbatTimesFormatter())->overview($user, $legacy)['text'];
        $legacy['fetched_at'] = 4102444800;
        self::assertSame($legacyText, (new ShabbatTimesFormatter())->overview($user, $legacy)['text'], 'Legacy rendering is deterministic and ignores fetch time.');
    }

    public function testReligiousFinalExitControlsPrimaryNotDelayedGroupReopening(): void
    {
        [, , , $user, , $calendar] = $this->context('2026-10-17T18:42:00+03:00');
        $user['mode'] = 'lock_messages';
        $user['open_after_havdalah_minutes'] = 60;
        $snapshot = $calendar->snapshot($user);
        self::assertNull($snapshot['current_event']);
        self::assertSame('2026-10-17', $snapshot['lock_event']['event_key']);
        $text = (new ShabbatTimesFormatter())->overview($user, $snapshot)['text'];
        self::assertStringContainsString('2026-10-23 17:18', $text);
        self::assertStringContainsString('2026-10-24 18:34', $text);
        self::assertStringNotContainsString('2026-10-16 17:26', $text);
    }

    public function testCurrentContinuousFestivalAndShabbatHasOnePrimaryAndNoIntermediateExit(): void
    {
        [, , , $user, , $calendar] = $this->context('2026-05-22T12:00:00+03:00');
        $snapshot = $calendar->snapshot($user);
        self::assertSame(['2026-05-22', '2026-05-23'], $snapshot['current_event']['days']);
        $text = (new ShabbatTimesFormatter())->overview($user, $snapshot)['text'];
        foreach (['2026-05-21 18:53', '2026-05-22 18:54', '2026-05-23 20:15'] as $time) {
            self::assertSame(1, substr_count($text, $time));
        }
        self::assertSame(1, substr_count($text, 'זמני שבת וחג'));
        self::assertSame(1, substr_count($text, 'יציאה/הבדלה:'));
        self::assertStringNotContainsString('2026-05-29 ', $text);
    }

    public function testActiveYomTovCrossingCivilMidnightRemainsUntilItsExactFinalExit(): void
    {
        [, , , $settings] = $this->context('2026-04-01T12:00:00+03:00');
        $settings = array_replace($settings, ['language' => 'en', 'timezone_id' => 'Europe/Stockholm', 'havdalah_method' => 'minutes', 'havdalah_value' => 180]);
        $zone = new \DateTimeZone('Europe/Stockholm');
        $stamp = static fn(string $date): int => (new \DateTimeImmutable($date, $zone))->getTimestamp();
        // Deliberately synthetic late-sunset fixture exercises the permitted fixed-minute method.
        $solar = [];
        foreach (range(29, 31) as $day) {
            $solar['2028-05-' . $day] = ['sunset' => $stamp('2028-05-' . $day . ' 21:50')];
        }
        foreach (range(1, 5) as $day) {
            $date = '2028-06-0' . $day;
            $solar[$date] = ['sunset' => $stamp($date . ' 21:50')];
        }
        $calendar = ['range' => ['start' => '2028-05-29','end' => '2028-06-05'], 'items' => [
            ['date' => '2028-05-31','category' => 'holiday','title' => 'Active Shavuot fixture','hebrew' => 'שבועות','yomtov' => true],
        ]];
        foreach (['00:30' => true, '00:50' => false] as $hour => $active) {
            $now = new \DateTimeImmutable('2028-06-01 ' . $hour, $zone);
            $snapshot = (new \Shabbat\Calendar\CalendarEngine())->evaluate($settings, $calendar, $solar, $now)
                + ['evaluated_at' => $now->getTimestamp(),'local_date' => '2028-06-01','calendar_items' => $calendar['items']];
            self::assertSame($active, $snapshot['is_yom_tov']);
            $text = (new ShabbatTimesFormatter())->overview($settings, $snapshot)['text'];
            self::assertStringContainsString('2028-06-02 21:10', $text);
            self::assertStringContainsString('2028-06-04 00:50', $text);
            if ($active) {
                self::assertStringContainsString('Active Shavuot fixture', $text);
                self::assertStringContainsString('2028-06-01 00:50', $text);
            } else {
                self::assertStringNotContainsString('Active Shavuot fixture', $text);
                self::assertStringNotContainsString('2028-06-01 00:50', $text);
            }
        }
    }

    public function testLongOptionalMetadataCannotHideNearestShabbatOrCurrentFestivalTimes(): void
    {
        [, , , $settings, , $calendar] = $this->context('2026-04-02T12:00:00+03:00');
        $snapshot = $calendar->snapshot($settings);
        $snapshot['calendar_items'] = array_values(array_filter($snapshot['calendar_items'], static fn(array $item): bool => !in_array($item['category'], ['holiday','roshchodesh'], true)));
        for ($i = 0; $i < 20; $i++) {
            $snapshot['calendar_items'][] = ['date' => '2026-04-04','category' => 'molad','title' => str_repeat('🔔', 400) . $i,'hebrew' => str_repeat('🔔', 400) . $i];
        }
        $snapshot['calendar_items'][] = ['date' => '2026-04-02','category' => 'holiday','title' => 'Current Yom Tov','hebrew' => 'יום טוב פעיל','yomtov' => true];
        $stamp = static fn(string $date): int => (new \DateTimeImmutable($date, new \DateTimeZone('Asia/Jerusalem')))->getTimestamp();
        // Synthetic unusually long future metadata; real primary/current times are untouched.
        foreach (['2026-04-08','2026-04-15','2026-04-22','2026-04-29'] as $date) {
            $day = new \DateTimeImmutable($date, new \DateTimeZone('Asia/Jerusalem'));
            $days = [$date,$day->modify('+1 day')->format('Y-m-d'),$day->modify('+2 days')->format('Y-m-d')];
            $period = ['event_key' => implode('+', $days),'days' => $days,'is_shabbat' => false,'is_yom_tov' => true,
                'candles_at' => $stamp($day->modify('-1 day')->format('Y-m-d') . ' 18:00'),'havdalah_at' => $stamp($days[2] . ' 20:00'),'intermediate' => []];
            foreach (array_slice($days, 1) as $next) {
                $period['intermediate'][] = ['candles_at' => $stamp($next . ' 20:00'),'is_yom_tov' => true,'is_shabbat' => false];
            }
            $snapshot['events'][] = $period;
            $snapshot['calendar_items'][] = ['date' => $date,'category' => 'holiday','title' => str_repeat('🔔', 120),'hebrew' => str_repeat('🔔', 120),'yomtov' => true];
        }
        foreach (Lang::CODES as $language) {
            $settings['language'] = $language;
            $text = (new ShabbatTimesFormatter())->overview($settings, $snapshot)['text'];
            foreach (['2026-04-03 18:20','2026-04-04 19:37','2026-04-01 18:19','2026-04-02 19:36'] as $boundary) {
                self::assertStringContainsString($boundary, $text);
            }
            self::assertLessThanOrEqual(3900, strlen(mb_convert_encoding(html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8'), 'UTF-16LE', 'UTF-8')) / 2);
        }
    }
}
