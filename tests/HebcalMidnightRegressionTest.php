<?php

// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);

namespace Shabbat\Tests;

use Amp\Cancellation;
use PHPUnit\Framework\TestCase;
use Shabbat\Calendar\CalendarService;
use Shabbat\Calendar\HebcalProvider;
use Shabbat\Http\JsonClient;
use Shabbat\Http\ProviderException;
use Shabbat\Storage\Database;
use Shabbat\Storage\SettingsRepository;
use Shabbat\Time\FrozenClock;

final class HebcalMidnightRegressionTest extends TestCase
{
    private function settings(): array
    {
        $clock = new FrozenClock(new \DateTimeImmutable('2026-10-14T12:00:00Z'));
        $db = Database::open(':memory:');
        $db->migrate();
        return (new SettingsRepository($db, $clock))->ensureGroup('-1');
    }

    private function fixture(string $name): array
    {
        return json_decode((string) file_get_contents(__DIR__ . '/Fixtures/' . $name), true, 64, JSON_THROW_ON_ERROR);
    }

    private function client(array $solar, ?array $calendar = null): JsonClient
    {
        return new class ($solar, $calendar) implements JsonClient {
            public function __construct(private array $solar, private ?array $calendar)
            {
            }

            public function get(string $url, array $query = [], ?Cancellation $cancellation = null): array
            {
                $cancellation?->throwIfRequested();
                if (str_ends_with($url, '/hebcal')) {
                    return $this->calendar ?? throw new \LogicException('Unexpected calendar request.');
                }
                return $this->solar;
            }
        };
    }

    private function solarRange(string $start, string $end): array
    {
        $data = $this->fixture('hebcal-jerusalem-solar-2026-10.json');
        $data['date'] = ['start' => $start, 'end' => $end];
        foreach ($data['times'] as &$values) {
            $values = array_filter($values, static fn(string $key): bool => $key >= $start && $key <= $end, ARRAY_FILTER_USE_KEY);
        }
        unset($values);
        return $data;
    }

    public function testPublicOctoberRangeKeepsWinterMidnightOnItsOriginalInstant(): void
    {
        $raw = $this->fixture('hebcal-jerusalem-solar-2026-10.json');
        self::assertSame('2026-10-25T23:23:00+02:00', $raw['times']['chatzotNight']['2026-10-26']);
        $solar = (new HebcalProvider($this->client($raw)))->solar($this->settings(), '2026-09-23', '2026-12-10');
        self::assertCount(79, $solar);
        self::assertSame((new \DateTimeImmutable('2026-10-25T23:23:00+02:00'))->getTimestamp(), $solar['2026-10-26']['chatzotNight']);
        self::assertSame((new \DateTimeImmutable($raw['times']['sunset']['2026-10-26']))->getTimestamp(), $solar['2026-10-26']['sunset']);
    }

    public function testSummerMidnightRetainsTheNominalCivilDate(): void
    {
        $raw = $this->fixture('hebcal-jerusalem-solar-2026-07.json');
        $solar = (new HebcalProvider($this->client($raw)))->solar($this->settings(), '2026-07-01', '2026-07-02');
        foreach ($solar as $day => $values) {
            $instant = (new \DateTimeImmutable('@' . $values['chatzotNight']))->setTimezone(new \DateTimeZone('Asia/Jerusalem'));
            self::assertSame($day, $instant->format('Y-m-d'));
            self::assertSame((new \DateTimeImmutable($raw['times']['chatzotNight'][$day]))->getTimestamp(), $values['chatzotNight']);
            self::assertLessThan($values['sunrise'], $values['chatzotNight']);
        }
    }

    public function testSpringTransitionPreservesMidnightBeforeTheClockChange(): void
    {
        $raw = $this->fixture('hebcal-jerusalem-solar-2026-03.json');
        $solar = (new HebcalProvider($this->client($raw)))->solar($this->settings(), '2026-03-26', '2026-03-28');
        self::assertSame((new \DateTimeImmutable('2026-03-26T23:44:00+02:00'))->getTimestamp(), $solar['2026-03-27']['chatzotNight']);
        self::assertSame((new \DateTimeImmutable('2026-03-27T06:34:00+03:00'))->getTimestamp(), $solar['2026-03-27']['sunrise']);
        self::assertSame((new \DateTimeImmutable('2026-03-28T00:44:00+03:00'))->getTimestamp(), $solar['2026-03-28']['chatzotNight']);
    }

    public function testWinterSingleDayHasNoDependencyOnPreviousRequestedDay(): void
    {
        $raw = $this->solarRange('2026-11-04', '2026-11-04');
        $solar = (new HebcalProvider($this->client($raw)))->solar($this->settings(), '2026-11-04', '2026-11-04');
        self::assertSame((new \DateTimeImmutable('2026-11-03T23:23:00+02:00'))->getTimestamp(), $solar['2026-11-04']['chatzotNight']);
    }

    public function testAutumnTransitionUsesEachActualInstantOffset(): void
    {
        $raw = $this->solarRange('2026-10-25', '2026-10-26');
        $solar = (new HebcalProvider($this->client($raw)))->solar($this->settings(), '2026-10-25', '2026-10-26');
        self::assertSame((new \DateTimeImmutable('2026-10-25T00:23:00+03:00'))->getTimestamp(), $solar['2026-10-25']['chatzotNight']);
        self::assertSame((new \DateTimeImmutable('2026-10-25T23:23:00+02:00'))->getTimestamp(), $solar['2026-10-26']['chatzotNight']);
        self::assertSame((new \DateTimeImmutable($raw['times']['sunset']['2026-10-25']))->getTimestamp(), $solar['2026-10-25']['sunset']);
    }

    public function testSnapshotAndZmanimUseLiveCapturedRangeWithoutChangingOperationalTimes(): void
    {
        $clock = new FrozenClock(new \DateTimeImmutable('2026-10-14T12:00:00Z'));
        $raw = $this->fixture('hebcal-jerusalem-solar-2026-10.json');
        $calendar = $this->fixture('hebcal-jerusalem-calendar-2026-10.json');
        $settings = $this->settings();
        $settings['close_before_candles_minutes'] = 10;
        $settings['open_after_havdalah_minutes'] = 15;
        $service = new CalendarService(new HebcalProvider($this->client($raw, $calendar)), $clock);
        $state = $service->snapshot($settings);
        self::assertFalse($state['should_group_be_locked']);
        self::assertSame(['2026-10-17'], $state['next_event']['days']);
        $sunset = (new \DateTimeImmutable($raw['times']['sunset']['2026-10-16']))->getTimestamp();
        self::assertSame($sunset - 40 * 60, $state['next_event']['candles_at']);
        self::assertSame($sunset - 50 * 60, $state['next_event']['close_at']);
        self::assertSame((new \DateTimeImmutable($raw['times']['tzeit85deg']['2026-10-17']))->getTimestamp() + 15 * 60, $state['next_event']['open_at']);
        $zmanim = $service->zmanim($settings, '2026-10-26');
        self::assertSame((new \DateTimeImmutable($raw['times']['chatzotNight']['2026-10-26']))->getTimestamp(), $zmanim['times']['chatzotNight']);
        $duringShabbat = $service->snapshot($settings, new \DateTimeImmutable('2026-10-17T12:00:00+03:00'));
        self::assertTrue($duringShabbat['should_group_be_locked']);
        $afterShabbat = $service->snapshot($settings, new \DateTimeImmutable('@' . $state['next_event']['open_at']));
        self::assertFalse($afterShabbat['should_group_be_locked']);
    }

    public function testOnlyMidnightCanUseThePrecedingCivilDate(): void
    {
        foreach (['sunrise', 'sunset', 'alotHaShachar', 'misheyakir', 'tzeit7083deg', 'tzeit85deg'] as $name) {
            $raw = $this->solarRange('2026-11-04', '2026-11-04');
            $raw['times'][$name]['2026-11-04'] = '2026-11-03T23:23:00+02:00';
            try {
                (new HebcalProvider($this->client($raw)))->solar($this->settings(), '2026-11-04', '2026-11-04');
                self::fail('Accepted previous-day ordinary field: ' . $name);
            } catch (ProviderException $error) {
                self::assertSame('Solar date/timezone mismatch.', $error->getMessage());
            }
        }
    }

    public function testMidnightRejectsOtherNightsNoonAndInvalidTimezoneOffsets(): void
    {
        foreach (
            [
            '2026-11-02T23:23:00+02:00',
            '2026-11-04T23:23:00+02:00',
            '2026-11-05T00:23:00+02:00',
            '2026-11-03T11:59:00+02:00',
            '2026-11-04T12:00:00+02:00',
            '2026-11-03T23:23:00+03:00',
            ] as $invalid
        ) {
            $raw = $this->solarRange('2026-11-04', '2026-11-04');
            $raw['times']['chatzotNight']['2026-11-04'] = $invalid;
            try {
                (new HebcalProvider($this->client($raw)))->solar($this->settings(), '2026-11-04', '2026-11-04');
                self::fail('Accepted invalid midnight: ' . $invalid);
            } catch (ProviderException) {
                self::assertTrue(true);
            }
        }
    }

    public function testMidnightMustFollowPreviousSunsetAndPrecedeCurrentSunriseWhenAvailable(): void
    {
        foreach (['2026-11-03T15:00:00+02:00', '2026-11-04T08:00:00+02:00'] as $invalid) {
            $raw = $this->solarRange('2026-11-03', '2026-11-04');
            $raw['times']['chatzotNight']['2026-11-04'] = $invalid;
            try {
                (new HebcalProvider($this->client($raw)))->solar($this->settings(), '2026-11-03', '2026-11-04');
                self::fail('Accepted midnight outside its solar night: ' . $invalid);
            } catch (ProviderException) {
                self::assertTrue(true);
            }
        }
    }
}
