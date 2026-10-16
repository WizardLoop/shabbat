<?php

// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);

namespace Shabbat\Tests;

use PHPUnit\Framework\TestCase;
use Shabbat\Calendar\CalendarService;
use Shabbat\Calendar\HebcalProvider;
use Shabbat\Content\ShabbatTimesFormatter;
use Shabbat\Location\GeoNames;
use Shabbat\Storage\Database;
use Shabbat\Storage\FlowTokenRepository;
use Shabbat\Storage\SettingsRepository;
use Shabbat\Time\FrozenClock;
use Shabbat\UI\BotController;
use Shabbat\UI\Lang;

final class ShabbatOverviewTest extends TestCase
{
    public function testSingleOverviewPreservesEveryEntryAndOnlyFinalExitOfCurrentContinuousPeriod(): void
    {
        $db = Database::open(':memory:');
        $db->migrate();
        $clock = new FrozenClock(new \DateTimeImmutable('2026-05-20T12:00:00+03:00'));
        $settings = (new SettingsRepository($db, $clock))->getUser('1');
        $calendar = new CalendarService(new HebcalProvider(new FullHebcalFixtureClient()), $clock);
        $snapshot = $calendar->snapshot($settings);
        $formatter = new ShabbatTimesFormatter();
        $anchor = $snapshot['next_event'];
        self::assertCount(2, $anchor['days'], 'Fixture exercises Shavuot followed immediately by Shabbat.');
        $page = $formatter->overview($settings, $snapshot);
        foreach ([$anchor, ...$anchor['intermediate']] as $segment) {
            $time = (new \DateTimeImmutable('@' . $segment['candles_at']))->setTimezone(new \DateTimeZone('Asia/Jerusalem'))->format('Y-m-d H:i');
            self::assertStringContainsString($time, $page['text']);
        }
        $exit = (new \DateTimeImmutable('@' . $anchor['havdalah_at']))->setTimezone(new \DateTimeZone('Asia/Jerusalem'))->format('Y-m-d H:i');
        self::assertStringContainsString($exit, $page['text']);
        self::assertSame($page, $formatter->overview($settings, $snapshot, 100), 'Old page buttons must refresh the current view.');
        self::assertSame($page, $formatter->overview($settings, $snapshot, -10));
        self::assertStringContainsString('שבועות', $page['text']);
        self::assertStringNotContainsString('הקבוצה נשארת סגורה', $page['text']);
        self::assertStringNotContainsString('עמוד', $page['text']);
        self::assertLessThanOrEqual(4000, strlen(mb_convert_encoding(html_entity_decode(strip_tags($page['text']), ENT_QUOTES | ENT_HTML5, 'UTF-8'), 'UTF-16LE', 'UTF-8')) / 2);
    }

    public function testCalendarRefreshEditsSamePanelWithoutNextOrPreviousButtons(): void
    {
        $db = Database::open(':memory:');
        $db->migrate();
        $clock = new FrozenClock(new \DateTimeImmutable('2026-05-20T12:00:00+03:00'));
        $settings = new SettingsRepository($db, $clock);
        $http = new FullHebcalFixtureClient();
        $gateway = new UiNavigationFakeGateway();
        $ui = new BotController($settings, new FlowTokenRepository($db, $clock), new CalendarService(new HebcalProvider($http), $clock), $gateway, new GeoNames($http, $clock, ''), $clock);
        $query = ['user_id' => '1','chat_id' => '1','message_id' => 101,'private' => true,'query_id' => '123'];
        $ui->handleCallback($query + ['data' => 'sb:show:shabbat']);
        $first = $gateway->edited[0];
        foreach ($first['markup']['rows'] as $row) {
            foreach ($row['buttons'] as $button) {
                self::assertStringNotContainsString('sb:calendar:', $button['data'] ?? '');
            }
        }
        $ui->handleCallback($query + ['data' => 'sb:show:shabbat']);
        self::assertCount(2, $gateway->edited);
        self::assertSame(101, $gateway->edited[1]['messageId']);
        self::assertSame($first['text'], $gateway->edited[1]['text']);
        self::assertSame([], $gateway->sent);
        self::assertSame(1, $settings->getUser('1')['revision']);
    }

    public function testOctoberOverviewListsHanukkahWithoutInventingFestivalLockingTimes(): void
    {
        $db = Database::open(':memory:');
        $db->migrate();
        $clock = new FrozenClock(new \DateTimeImmutable('2026-10-15T12:00:00+03:00'));
        $settings = (new SettingsRepository($db, $clock))->getUser('1');
        $calendar = new CalendarService(new HebcalProvider(new FullHebcalFixtureClient()), $clock);
        $text = (new ShabbatTimesFormatter())->overview($settings, $calendar->snapshot($settings))['text'];
        foreach (['Jerusalem / ירושלים','2026-10-16 17:26','2026-10-17 18:41','יום שישי','פרשת נח','חנוכה'] as $value) {
            self::assertStringContainsString($value, $text);
        }
        $holidays = explode((new Lang())->text('he', 'times_upcoming_calendar'), $text)[1];
        foreach (['2026-10-23 17:18','2026-10-30 16:12','2026-11-06 16:06','Hebcal','Genesis','Isaiah',(new Lang())->text('he', 'times_upcoming_shabbat')] as $hidden) {
            self::assertStringNotContainsString($hidden, $text);
        }
        self::assertStringNotContainsString('כניסה / הדלקת נרות', $holidays);
        self::assertStringNotContainsString('יציאה', $holidays);
        self::assertStringNotContainsString('עמוד', $text);
    }

    public function testUpcomingYomTovUsesVerifiedContinuousPeriodAndNeverIntermediateExit(): void
    {
        $db = Database::open(':memory:');
        $db->migrate();
        $clock = new FrozenClock(new \DateTimeImmutable('2026-05-01T12:00:00+03:00'));
        $settings = (new SettingsRepository($db, $clock))->getUser('1');
        $settings['language'] = 'en';
        $stamp = static fn(string $date): int => (new \DateTimeImmutable($date, new \DateTimeZone('Asia/Jerusalem')))->getTimestamp();
        $saturday = ['event_key' => '2026-05-02','days' => ['2026-05-02'],'title' => 'Shabbat','is_shabbat' => true,'is_yom_tov' => false,'candles_at' => $stamp('2026-05-01 18:00'),'havdalah_at' => $stamp('2026-05-02 20:00')];
        $festival = ['event_key' => '2026-05-22+2026-05-23','days' => ['2026-05-22','2026-05-23'],'title' => 'Shavuot I / Shavuot II','is_shabbat' => true,'is_yom_tov' => true,'candles_at' => $stamp('2026-05-21 18:10'),'havdalah_at' => $stamp('2026-05-23 20:20'),'intermediate' => [
            ['days' => ['2026-05-23'],'is_shabbat' => true,'is_yom_tov' => true,'candles_at' => $stamp('2026-05-22 18:11'),'havdalah_at' => $stamp('2026-05-22 20:12')],
        ]];
        $snapshot = ['next_event' => $saturday,'events' => [$saturday,$festival],'calendar_items' => [
            ['date' => '2026-05-22','category' => 'holiday','title' => 'Shavuot I','yomtov' => true],
            ['date' => '2026-05-23','category' => 'holiday','title' => 'Shavuot II','yomtov' => true],
        ]];
        $text = (new ShabbatTimesFormatter())->overview($settings, $snapshot)['text'];
        foreach (['Shavuot I','2026-05-21 18:10','2026-05-22 18:11','2026-05-23 20:20'] as $value) {
            self::assertStringContainsString($value, $text);
        }
        self::assertStringNotContainsString('2026-05-22 20:12', $text);
        $snapshot['coverage'] = ['start' => '2026-04-23', 'end' => '2026-05-23'];
        $boundary = (new ShabbatTimesFormatter())->overview($settings, $snapshot)['text'];
        self::assertStringContainsString((new Lang())->text('en', 'times_yomtov_unavailable'), $boundary);
        self::assertStringNotContainsString('2026-05-23 20:20', $boundary, 'A coverage-boundary period may continue on an unverified following day.');
        unset($snapshot['coverage']);
        $snapshot['events'] = [$saturday];
        self::assertStringContainsString((new Lang())->text('en', 'times_yomtov_unavailable'), (new ShabbatTimesFormatter())->overview($settings, $snapshot)['text']);
    }

    public function testAllSixOverviewsRemainValidHtmlAndPreserveTimesWithLongMetadata(): void
    {
        $db = Database::open(':memory:');
        $db->migrate();
        $clock = new FrozenClock(new \DateTimeImmutable('2026-05-20T12:00:00+03:00'));
        $settings = (new SettingsRepository($db, $clock))->getUser('1');
        $snapshot = (new CalendarService(new HebcalProvider(new FullHebcalFixtureClient()), $clock))->snapshot($settings);
        $event = $snapshot['next_event'];
        for ($i = 0; $i < 30; $i++) {
            $snapshot['calendar_items'][] = ['date' => $event['days'][0], 'category' => 'molad', 'title' => str_repeat('𐐀<&>', 400) . $i, 'hebrew' => str_repeat('𐐀<&>', 400) . $i];
        }
        $exit = (new \DateTimeImmutable('@' . $event['havdalah_at']))->setTimezone(new \DateTimeZone('Asia/Jerusalem'))->format('Y-m-d H:i');
        foreach (Lang::CODES as $language) {
            $settings['language'] = $language;
            $html = (new ShabbatTimesFormatter())->overview($settings, $snapshot)['text'];
            $parsed = \danog\TelegramEntities\Entities::fromHtml(\Shabbat\UI\Presentation::html($html));
            self::assertStringContainsString($exit, $parsed->message);
            self::assertStringNotContainsString('<b>', $parsed->message);
            self::assertLessThanOrEqual(4000, strlen(mb_convert_encoding($parsed->message, 'UTF-16LE', 'UTF-8')) / 2);
            self::assertNotEmpty(array_filter($parsed->entities, static fn(array $entity): bool => $entity['type'] === 'bold'));
        }
    }

    public function testDailyViewUsesRealBoldEntitiesInsteadOfVisibleHtmlTags(): void
    {
        $db = Database::open(':memory:');
        $db->migrate();
        $clock = new FrozenClock(new \DateTimeImmutable('2026-10-15T12:00:00+03:00'));
        $settings = new SettingsRepository($db, $clock);
        $http = new FullHebcalFixtureClient();
        $ui = new BotController($settings, new FlowTokenRepository($db, $clock), new CalendarService(new HebcalProvider($http), $clock), new UiNavigationFakeGateway(), new GeoNames($http, $clock, ''), $clock);
        $html = $ui->renderView('1', $settings->getUser('1'), 'zmanim');
        $parsed = \danog\TelegramEntities\Entities::fromHtml($html);
        self::assertStringNotContainsString('<b>', $parsed->message);
        self::assertStringNotContainsString('&lt;b&gt;', $html);
        self::assertGreaterThan(10, count(array_filter($parsed->entities, static fn(array $entity): bool=>$entity['type'] === 'bold')));
        self::assertStringContainsString('06:42', $parsed->message);
    }
}
