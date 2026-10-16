<?php

// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);

namespace Shabbat\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shabbat\Calendar\{CalendarEngine, HebcalProvider};
use Shabbat\Notifications\ReminderPlanner;
use Shabbat\Storage\{Database, SettingsRepository};
use Shabbat\Time\{FrozenClock, LocalTime};

/** Real provider spelling plus explicit legacy-format compatibility; no network. */
final class CholHamoedClassificationTest extends TestCase
{
    public static function capturedWeekdays(): iterable
    {
        yield 'Pesach Israel' => ['2026-04-03', "Pesach II (CH''M)"];
        yield 'Sukkot Israel' => ['2026-09-27', "Sukkot II (CH''M)"];
    }

    #[DataProvider('capturedWeekdays')]
    public function testCapturedProviderClassificationControlsReminderOptIn(string $date, string $original): void
    {
        [$settings, $state, $sun] = $this->calendar($date);
        $matches = array_values(array_filter($state['calendar_items'], static fn(array $item): bool =>
            $item['date'] === $date && ($item['title_orig'] ?? null) === $original));
        self::assertCount(1, $matches, 'Unmodified captured provider response contains the exact double-apostrophe spelling.');
        self::assertTrue($state['is_chol_hamoed']);
        self::assertFalse($state['is_yom_tov']);
        self::assertFalse($state['is_work_prohibited_now']);
        self::assertFalse($state['should_group_be_locked']);
        self::assertNull($state['lock_event']);
        self::assertFalse((bool)$settings['tefillin_chol_hamoed']);
        $planner = new ReminderPlanner();
        $rule = ['enabled' => true, 'times' => ['11:00']];
        self::assertSame([], $planner->tefillin($settings, $rule, $date, $sun, $state));
        self::assertSame([], $planner->tefillin($settings, $rule + ['chol_hamoed' => false], $date, $sun, $state));
        $enabled = $planner->tefillin($settings, $rule + ['chol_hamoed' => true], $date, $sun, $state);
        self::assertCount(1, $enabled);
        self::assertSame(LocalTime::resolve($date, '11:00', 'Asia/Jerusalem'), $enabled[0]['run_at']);
    }

    public function testCholHamoedOnShabbatStillLocksAndExplicitReminderOptInCannotOverrideIt(): void
    {
        [$settings, $state, $sun] = $this->calendar('2026-04-04');
        self::assertTrue($state['is_chol_hamoed']);
        self::assertTrue($state['is_shabbat']);
        self::assertFalse($state['is_yom_tov']);
        self::assertTrue($state['should_group_be_locked']);
        self::assertSame([], (new ReminderPlanner())->tefillin($settings, [
            'enabled' => true, 'times' => ['11:00'], 'chol_hamoed' => true,
        ], '2026-04-04', $sun, $state));
    }

    public function testActualYomTovRemainsSeparateAndCannotBeEnabledThroughCholHamoedPolicy(): void
    {
        foreach (['2026-04-02', '2026-09-26'] as $date) {
            [$settings, $state, $sun] = $this->calendar($date);
            self::assertFalse($state['is_chol_hamoed']);
            self::assertTrue($state['is_yom_tov']);
            self::assertTrue($state['should_group_be_locked']);
            self::assertSame([], (new ReminderPlanner())->tefillin($settings, [
                'enabled' => true, 'times' => ['11:00'], 'chol_hamoed' => true,
            ], $date, $sun, $state));
        }
    }

    public static function explicitMarkerVariants(): iterable
    {
        foreach ([true, false] as $israel) {
            foreach (["(CH'M)", "(CH''M)"] as $marker) {
                yield ($israel ? 'Israel' : 'diaspora') . ' Pesach ' . $marker => ['2026-04-05', 'Pesach IV ' . $marker, $israel];
                yield ($israel ? 'Israel' : 'diaspora') . ' Sukkot ' . $marker => ['2026-09-28', 'Sukkot III ' . $marker, $israel];
            }
        }
    }

    #[DataProvider('explicitMarkerVariants')]
    public function testBothExplicitSpellingsWorkWithoutCreatingHolyPeriods(string $date, string $original, bool $israel): void
    {
        // Diaspora cases are modeled contract fixtures, not claimed captured responses.
        [$settings] = $this->calendar($date);
        $settings['israel'] = $israel;
        $zone = new \DateTimeZone($settings['timezone_id']);
        $now = new \DateTimeImmutable($date . ' 12:00', $zone);
        $start = $now->modify('-2 days')->format('Y-m-d');
        $end = $now->modify('+2 days')->format('Y-m-d');
        $solar = [];
        for ($day = new \DateTimeImmutable($start, $zone); $day->format('Y-m-d') <= $end; $day = $day->modify('+1 day')) {
            $solar[$day->format('Y-m-d')] = [
                'sunset' => $day->setTime(18, 0)->getTimestamp(),
                'tzeit85deg' => $day->setTime(18, 40)->getTimestamp(),
            ];
        }
        $calendar = ['range' => ['start' => $start, 'end' => $end], 'items' => [[
            'date' => $date, 'category' => 'holiday', 'title' => 'Localized label', 'title_orig' => $original,
        ]]];
        $engine = new CalendarEngine();
        $state = $engine->evaluate($settings, $calendar, $solar, $now);
        self::assertTrue($state['is_chol_hamoed']);
        self::assertFalse($state['is_yom_tov']);
        self::assertFalse($state['is_work_prohibited_now']);
        self::assertFalse($state['should_group_be_locked']);
        $calendar['items'][0]['title_orig'] = str_replace(["(CH'M)", "(CH''M)"], '(CHM)', $original);
        $unmarked = $engine->evaluate($settings, $calendar, $solar, $now);
        self::assertFalse($unmarked['is_chol_hamoed'], 'Only the two reviewed explicit markers are recognized.');
        self::assertSame($unmarked['events'], $state['events'], 'Chol Hamoed classification never changes religious periods.');
        self::assertSame($unmarked['operational_events'], $state['operational_events'], 'Classification never changes lock intervals.');
    }

    private function calendar(string $date): array
    {
        $clock = new FrozenClock(new \DateTimeImmutable($date . 'T10:00:00+03:00'));
        $db = Database::open(':memory:');
        $db->migrate();
        $settings = (new SettingsRepository($db, $clock))->ensureGroup('-1001', 'Holiday fixture', ['mode' => 'lock_messages']);
        // The October capture begins on September 23 and includes the actual Sukkot dates.
        [$start, $end] = str_starts_with($date, '2026-04')
            ? ['2026-03-24', '2026-06-09'] : ['2026-09-23', '2026-12-10'];
        $provider = new HebcalProvider(new FullHebcalFixtureClient());
        $calendar = $provider->calendar($start, $end, true, 'he');
        $solar = $provider->solar($settings, $start, $end);
        $state = (new CalendarEngine())->evaluate($settings, $calendar, $solar, $clock->now())
            + ['calendar_items' => $calendar['items']];
        return [$settings, $state, $solar[$date]];
    }
}
