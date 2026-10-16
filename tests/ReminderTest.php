<?php

// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);

namespace Shabbat\Tests;

use PHPUnit\Framework\TestCase;
use Shabbat\Calendar\CalendarService;
use Shabbat\Calendar\HebcalProvider;
use Shabbat\Notifications\ReminderPlanner;
use Shabbat\Content\TemplateRenderer;
use Shabbat\Storage\Database;
use Shabbat\Storage\SettingsRepository;
use Shabbat\Time\FrozenClock;
use Shabbat\Time\LocalTime;

require_once __DIR__ . '/Fixtures/CalendarFixtureClient.php';
final class ReminderTest extends TestCase
{
    private FrozenClock $clock;
    private array $settings;
    private CalendarService $calendar;
    protected function setUp(): void
    {
        $this->clock = new FrozenClock(new \DateTimeImmutable('2026-04-12T12:00:00Z'));
        $db = Database::open(':memory:');
        $db->migrate();
        $this->settings = (new SettingsRepository($db, $this->clock))->ensureGroup('-1');
        $this->calendar = new CalendarService(new HebcalProvider(new CalendarFixtureClient()), $this->clock);
    }
    public function testOmerTwilightMidnightDawnDayCompletionAndOffSeason(): void
    {
        $this->settings['language'] = 'en';
        $this->settings['omer_day_completion'] = true;
        $twilight = $this->calendar->omer($this->settings, new \DateTimeImmutable('2026-04-12T18:10:00+03:00'));
        self::assertSame(11, $twilight['day']);
        self::assertSame('twilight', $twilight['phase']);
        self::assertFalse($twilight['eligible']);
        self::assertFalse($twilight['day_completion']);
        $night = $this->calendar->omer($this->settings, new \DateTimeImmutable('2026-04-12T19:00:00+03:00'));
        $midnight = $this->calendar->omer($this->settings, new \DateTimeImmutable('2026-04-13T01:00:00+03:00'));
        self::assertSame(11, $night['day']);
        self::assertSame(11, $midnight['day']);
        self::assertTrue($midnight['eligible']);
        self::assertStringContainsString('Today is 11 days', $midnight['count_text']);
        $day = $this->calendar->omer($this->settings, new \DateTimeImmutable('2026-04-13T05:00:00+03:00'));
        self::assertFalse($day['eligible']);
        self::assertTrue($day['day_completion']);
        self::assertSame(11, $day['day']);
        $off = $this->calendar->omer($this->settings, new \DateTimeImmutable('2026-06-12T20:00:00+03:00'));
        self::assertFalse($off['season']);
        self::assertNull($off['day']);
        self::assertNull($off['count_text']);
        self::assertFalse($off['eligible']);
    }
    public function testOmerCountAdvancesThroughShabbatWithoutSending(): void
    {
        $this->settings['omer_day_completion'] = true;
        $saturday = $this->calendar->omer($this->settings, new \DateTimeImmutable('2026-04-11T10:00:00+03:00'));
        $sunday = $this->calendar->omer($this->settings, new \DateTimeImmutable('2026-04-12T10:00:00+03:00'));
        self::assertSame(9, $saturday['day']);
        self::assertSame(10, $sunday['day']);
        self::assertFalse($saturday['eligible']);
        self::assertFalse($saturday['day_completion']);
    }
    public function testOmerAll49DaysComeFromCalendarRatherThanDeliveryCounter(): void
    {
        $start = new \DateTimeImmutable('2026-04-03T12:00:00+03:00');
        for ($day = 1; $day <= 49; $day++) {
            $value = $this->calendar->omer($this->settings, $start->modify('+' . ($day - 1) . ' days'));
            self::assertSame($day, $value['day']);
        }
    }
    public function testOmerAfterMidnightFixedTimeUsesSameNightAndCutoffAtDawn(): void
    {
        $date = '2026-04-12';
        $sun = $this->calendar->zmanim($this->settings, $date)['times'];
        $state = $this->calendar->snapshot($this->settings, new \DateTimeImmutable($date . 'T12:00:00+03:00'));
        $jobs = (new ReminderPlanner())->omer($this->settings, ['enabled' => true,'schedule' => 'fixed','time' => '00:30'], $date, $sun, $state);
        self::assertCount(1, $jobs);
        self::assertSame(LocalTime::resolve('2026-04-13', '00:30', 'Asia/Jerusalem'), $jobs[0]['run_at']);
        self::assertLessThanOrEqual($state['next_dawn_at'], $jobs[0]['expires_at']);
        self::assertSame([], (new ReminderPlanner())->omer($this->settings, ['enabled' => true,'schedule' => 'fixed','time' => '06:00'], $date, $sun, $state));
    }
    public function testEarlyOmerDefersOrSkipsAndSaturdayWaitsForVerifiedGroupOpening(): void
    {
        $date = '2026-04-12';
        $sun = $this->calendar->zmanim($this->settings, $date)['times'];
        $state = $this->calendar->snapshot($this->settings, new \DateTimeImmutable($date . 'T12:00:00+03:00'));
        $planner = new ReminderPlanner();
        $early = ['enabled' => true,'schedule' => 'fixed','time' => '17:00','early_policy' => 'defer'];
        self::assertSame('delayed_until_nightfall', $planner->omer($this->settings, $early, $date, $sun, $state)[0]['reason']);
        $early['early_policy'] = 'skip';
        self::assertSame([], $planner->omer($this->settings, $early, $date, $sun, $state));
        $date = '2026-04-11';
        $this->settings['open_after_havdalah_minutes'] = 10;
        $sun = $this->calendar->zmanim($this->settings, $date)['times'];
        $state = $this->calendar->snapshot($this->settings, new \DateTimeImmutable($date . 'T12:00:00+03:00'));
        $jobs = $planner->omer($this->settings, ['enabled' => true,'schedule' => 'fixed','time' => '18:40','after_open_minutes' => 5], $date, $sun, $state);
        self::assertSame($state['next_open_at'] + 300, $jobs[0]['run_at']);
        self::assertSame('delayed_until_group_open', $jobs[0]['reason']);
    }
    public function testStorageSundayDayZeroAndTishaObservedAfternoonPolicy(): void
    {
        $planner = new ReminderPlanner();
        $date = '2019-08-11';
        $sun = $this->calendar->zmanim($this->settings, $date)['times'];
        $state = $this->calendar->snapshot($this->settings, new \DateTimeImmutable($date . 'T12:00:00+03:00'));
        self::assertTrue($state['is_tisha_bav']);
        $rule = ['enabled' => true,'days' => [0],'times' => ['09:00','13:00'],'tisha_bav' => 'mincha','window_start' => '08:00','window_end' => '17:00'];
        $jobs = $planner->tefillin($this->settings, $rule, $date, $sun, $state);
        self::assertCount(1, $jobs);
        self::assertSame(LocalTime::resolve($date, '13:00', 'Asia/Jerusalem'), $jobs[0]['run_at']);
        $rule['tisha_bav'] = 'off';
        self::assertSame([], $planner->tefillin($this->settings, $rule, $date, $sun, $state));
    }
    public function testTefillinIntervalsSpacingLimitsQuietAndExclusiveEnd(): void
    {
        $date = '2026-04-12';
        $sun = $this->calendar->zmanim($this->settings, $date)['times'];
        $state = $this->calendar->snapshot($this->settings, new \DateTimeImmutable($date . 'T12:00:00+03:00'));
        $rule = ['enabled' => true,'days' => [0],'times' => ['09:00','10:00'],'interval_minutes' => 5,'window_start' => '09:00','window_end' => '10:00',
            'min_gap_minutes' => 5,'max_per_day' => 24,'quiet_start' => '09:15','quiet_end' => '09:30'];
        $jobs = (new ReminderPlanner())->tefillin($this->settings, $rule, $date, $sun, $state);
        self::assertCount(9, $jobs);
        self::assertSame(array_unique(array_column($jobs, 'run_at')), array_column($jobs, 'run_at'));
        foreach ($jobs as $job) {
            self::assertLessThan(LocalTime::resolve($date, '10:00', 'Asia/Jerusalem'), $job['run_at']);
            self::assertGreaterThan($job['run_at'], $job['expires_at']);
        }
    }
    public function testDynamicTemplatePreservesUtf16EntityOffsetsAndPlainSpecialCharacters(): void
    {
        $payload = ['text' => '😀 {{location}} tail','media' => ['bot_api_file_id' => 'fixture'],'buttons' => [],
            'entities' => [['_' => 'messageEntityCustomEmoji','offset' => 0,'length' => 2,'document_id' => '123'],
                ['_' => 'messageEntityBold','offset' => 3,'length' => 12],['_' => 'messageEntityItalic','offset' => 16,'length' => 4],
                ['_' => 'messageEntityUnderline','offset' => 0,'length' => 20]]];
        $rendered = (new TemplateRenderer())->renderPayload($payload, ['location' => 'חיפה']);
        self::assertSame('😀 חיפה tail', $rendered['text']);
        self::assertSame([2,4,4,12], array_column($rendered['entities'], 'length'));
        self::assertSame([0,3,8,0], array_column($rendered['entities'], 'offset'));
        self::assertSame($payload['media'], $rendered['media']);
        self::assertSame('123', $rendered['entities'][0]['document_id']);
        $literal = (new TemplateRenderer())->renderPayload(['text' => '{{location}}','entities' => [['_' => 'messageEntityBold','offset' => 0,'length' => 12]]], ['location' => '<חיפה>']);
        self::assertSame('<חיפה>', $literal['text']);
        self::assertSame(6, $literal['entities'][0]['length']);
        $html = (new TemplateRenderer())->renderPayload(['text' => '<b>{{location}}</b>'], ['location' => '<חיפה>']);
        self::assertSame('<b>&lt;חיפה&gt;</b>', $html['text']);
    }
    public function testEntityCannotSplitAnEmojiSurrogatePair(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new TemplateRenderer())->renderPayload(['text' => '😀','entities' => [['_' => 'messageEntityBold','offset' => 1,'length' => 1]]], []);
    }
    public function testCustomTemplateButtonColorsAreNativeValidatedAndUnknownStylesRejected(): void
    {
        foreach (['primary' => 'bg_primary','success' => 'bg_success','danger' => 'bg_danger'] as $name => $flag) {
            $style = TemplateRenderer::nativeStyle($name);
            self::assertTrue($style[$flag]);
            TemplateRenderer::validateButtons([[['text' => 'Fixture','callback_data' => 'fixture','style' => $style]]]);
        }
        self::assertSame('1234567890123456789', TemplateRenderer::nativeStyle(['icon' => '1234567890123456789'])['icon']);
        foreach ([['bg_primary' => true,'bg_danger' => true],['bg_success' => 'yes'],['unknown' => true],['icon' => '9223372036854775808']] as $invalid) {
            try {
                TemplateRenderer::nativeStyle($invalid);
                self::fail('Invalid button style accepted.');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        self::assertSame('&lt;city&gt;', (new TemplateRenderer())->render('{{location}}', ['location' => '<city>']));
    }
}
