<?php

// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);

namespace Shabbat\Tests;

use PHPUnit\Framework\TestCase;
use Shabbat\Calendar\CalendarEngine;
use Shabbat\Time\LocalTime;
use Shabbat\Content\OmerText;
use Shabbat\Notifications\ReminderPlanner;

final class CalendarTest extends TestCase
{
    private function settings(): array
    {
        return ['timezone_id' => 'Asia/Jerusalem','geoname_id' => 281184,'candle_minutes' => 18,'havdalah_method' => 'degrees','havdalah_value' => 8.5,'mode' => 'lock_messages','shabbat_enabled' => true,'holidays_enabled' => true,'close_before_candles_minutes' => 10,'open_after_havdalah_minutes' => 5,'language' => 'he'];
    }
    private function fixture(string $start = '2026-10-08', string $end = '2026-10-29'): array
    {
        $solar = [];
        for ($day = new \DateTimeImmutable($start); $day->format('Y-m-d') <= $end; $day = $day->modify('+1 day')) {
            $key = $day->format('Y-m-d');
            $solar[$key] = ['sunset' => LocalTime::resolve($key, '18:00', 'Asia/Jerusalem'),'tzeit85deg' => LocalTime::resolve($key, '18:40', 'Asia/Jerusalem'),'misheyakir' => LocalTime::resolve($key, '05:45', 'Asia/Jerusalem'),'minchaGedola' => LocalTime::resolve($key, '12:30', 'Asia/Jerusalem')];
        }
        return [['range' => ['start' => $start,'end' => $end],'items' => []],$solar];
    }
    public function testBeforeDuringAfterAndSundayDoNotReusePreviousWeek(): void
    {
        [$cal,$sun] = $this->fixture();
        $engine = new CalendarEngine();
        $settings = $this->settings();
        $before = $engine->evaluate($settings, $cal, $sun, new \DateTimeImmutable('2026-10-16 17:00', new \DateTimeZone('Asia/Jerusalem')));
        self::assertFalse($before['should_group_be_locked']);
        self::assertSame('2026-10-17', $before['next_event']['event_key']);
        $during = $engine->evaluate($settings, $cal, $sun, new \DateTimeImmutable('2026-10-17 10:00', new \DateTimeZone('Asia/Jerusalem')));
        self::assertTrue($during['is_work_prohibited_now']);
        self::assertTrue($during['should_group_be_locked']);
        $after = $engine->evaluate($settings, $cal, $sun, new \DateTimeImmutable('2026-10-18 10:00', new \DateTimeZone('Asia/Jerusalem')));
        self::assertNull($after['current_event']);
        self::assertSame('2026-10-24', $after['next_event']['event_key'] ?? null);
    }
    public function testYomTovAdjacentToShabbatNeverOpensBetween(): void
    {
        [$cal,$sun] = $this->fixture();
        $cal['items'] = [['date' => '2026-10-16','category' => 'holiday','yomtov' => true,'hebrew' => 'חג','title' => 'Festival']];
        $state = (new CalendarEngine())->evaluate($this->settings(), $cal, $sun, new \DateTimeImmutable('2026-10-16 19:00', new \DateTimeZone('Asia/Jerusalem')));
        self::assertTrue($state['should_group_be_locked']);
        self::assertSame(['2026-10-16','2026-10-17'], $state['current_event']['days']);
        self::assertSame($sun['2026-10-17']['tzeit85deg'] + 300, $state['next_open_at']);
    }
    public function testFestivalAfterShabbatLightsAtNightfallAndDoesNotClaimEarlyFestivalDay(): void
    {
        [$cal,$sun] = $this->fixture();
        $cal['items'] = [['date' => '2026-10-18','category' => 'holiday','yomtov' => true,'hebrew' => 'חג','title' => 'Festival']];
        $engine = new CalendarEngine();
        $settings = $this->settings();
        $before = $engine->evaluate($settings, $cal, $sun, new \DateTimeImmutable('2026-10-17T17:30:00+03:00'));
        self::assertTrue($before['is_shabbat']);
        self::assertFalse($before['is_yom_tov']);
        self::assertSame($sun['2026-10-17']['tzeit85deg'], $before['current_event']['intermediate'][0]['candles_at']);
        $twilight = $engine->evaluate($settings, $cal, $sun, new \DateTimeImmutable('2026-10-17T18:10:00+03:00'));
        self::assertTrue($twilight['is_shabbat']);
        self::assertTrue($twilight['is_yom_tov']);
        self::assertTrue($twilight['should_group_be_locked']);
    }
    public function testHolidayCategoryAloneDoesNotProhibitWork(): void
    {
        [$cal,$sun] = $this->fixture();
        $cal['items'] = [['date' => '2026-10-15','category' => 'holiday','title_orig' => 'Minor holiday']];
        $state = (new CalendarEngine())->evaluate($this->settings(), $cal, $sun, new \DateTimeImmutable('2026-10-15 12:00', new \DateTimeZone('Asia/Jerusalem')));
        self::assertFalse($state['is_yom_tov']);
        self::assertFalse($state['is_work_prohibited_now']);
    }
    public function testAllCandleOptionsAndDegreesRemainExact(): void
    {
        foreach ([10,15,18,20,22,30,40] as $minutes) {
            self::assertSame($minutes, CalendarEngine::candleMinutes(['candle_minutes' => $minutes]));
        }
        self::assertSame(40, CalendarEngine::candleMinutes(['geoname_id' => 281184]));
        self::assertSame(18, CalendarEngine::candleMinutes(['geoname_id' => 293397]));
        self::assertSame(200, CalendarEngine::nightfall(['sunset' => 100,'tzeit85deg' => 200], ['havdalah_method' => 'degrees','havdalah_value' => 8.5]));
        self::assertSame(4420, CalendarEngine::nightfall(['sunset' => 100], ['havdalah_method' => 'minutes','havdalah_value' => 72]));
    }
    public function testMissingSunDoesNotGuess(): void
    {
        [$cal,$sun] = $this->fixture();
        $sun['2026-10-17']['tzeit85deg'] = null;
        $this->expectException(\Shabbat\Http\ProviderException::class);
        (new CalendarEngine())->evaluate($this->settings(), $cal, $sun, new \DateTimeImmutable('2026-10-17T10:00:00+03:00'));
    }
    public function testDstMissingTimeSkippedRepeatedTimeOnce(): void
    {
        self::assertNull(LocalTime::resolve('2026-03-08', '02:30', 'America/New_York'));
        self::assertSame(strtotime('2026-11-01T01:30:00-04:00'), LocalTime::resolve('2026-11-01', '01:30', 'America/New_York'));
        self::assertNotSame(LocalTime::resolve('2026-10-16', '12:00', 'Asia/Jerusalem'), LocalTime::resolve('2026-10-16', '12:00', 'America/New_York'));
    }
    public function testAll49CountsAndWeekTransitionsAreBundled(): void
    {
        for ($day = 1; $day <= 49; $day++) {
            self::assertNotSame('', OmerText::count($day));
            self::assertStringContainsString((string)$day, OmerText::count($day, 'sephardi', 'en'));
        }
        self::assertStringContainsString('1 week', OmerText::count(7, 'sephardi', 'en'));
        self::assertStringContainsString('1 day', OmerText::count(8, 'sephardi', 'en'));
        self::assertStringContainsString('2 weeks', OmerText::count(14, 'sephardi', 'en'));
        self::assertStringContainsString('7 weeks', OmerText::count(49, 'sephardi', 'en'));
        self::assertStringNotContainsString('ברוך', OmerText::render(7, 'sephardi', true, true));
    }
    public function testTefillinCombinesModesDeduplicatesAndCutsOffOnFriday(): void
    {
        [$cal,$sun] = $this->fixture();
        $state = (new CalendarEngine())->evaluate($this->settings(), $cal, $sun, new \DateTimeImmutable('2026-10-16T10:00:00+03:00'));
        $jobs = (new ReminderPlanner())->tefillin($this->settings(), ['enabled' => true,'times' => ['17:00','17:45'],'before_sunset' => [60,30,15],'max_per_day' => 10,'min_gap_minutes' => 15], '2026-10-16', $sun['2026-10-16'], $state);
        self::assertCount(2, $jobs);
        self::assertSame(array_unique(array_column($jobs, 'run_at')), array_column($jobs, 'run_at'));
        foreach ($jobs as $job) {
            self::assertLessThan($state['next_close_at'], $job['run_at']);
        }
    }
    public function testTefillinDisabledOnYomTovCholHamoedAndTishaBav(): void
    {
        $p = new ReminderPlanner();
        $sun = ['misheyakir' => 100,'sunset' => 1000];
        $rule = ['enabled' => true,'times' => ['12:00']];
        foreach ([['is_work_prohibited_now' => true],['is_chol_hamoed' => true],['is_tisha_bav' => true]] as $state) {
            self::assertSame([], $p->tefillin($this->settings(), $rule, '2026-10-15', $sun, $state));
        }
    }
}
