<?php

// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);

namespace Shabbat\Tests;

use Amp\Cancellation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shabbat\Calendar\HebcalProvider;
use Shabbat\Http\JsonClient;
use Shabbat\Http\ProviderException;

final class HebcalFullSolarResponseTest extends TestCase
{
    private const START = '2026-09-23';
    private const END = '2026-12-10';
    private const FIXTURE = 'hebcal-jerusalem-default-full-solar-2026-09-23_2026-12-10.json';

    public function testUnprojectedLiveResponseIncludesEmptyAndSparseNonDailyFields(): void
    {
        $raw = self::fixture();
        self::assertCount(42, $raw['times']);
        self::assertSame(31.76904, $raw['location']['latitude']);
        self::assertSame(35.21633, $raw['location']['longitude']);
        self::assertSame([], $raw['times']['seaLevelSunrise']);
        self::assertSame([], $raw['times']['seaLevelSunset']);
        self::assertCount(3, $raw['times']['molad']);
        self::assertCount(2, $raw['times']['tchilasZmanKidushLevana3Days']);
        self::assertArrayNotHasKey(self::START, $raw['times']['molad']);
        $days = self::provider($raw)->solar(self::settings(), self::START, self::END);
        self::assertCount(79, $days);
        foreach ($days as $day => $times) {
            self::assertCount(35, $times);
            self::assertSame((new \DateTimeImmutable($raw['times']['sunset'][$day]))->getTimestamp(), $times['sunset']);
            self::assertSame((new \DateTimeImmutable($raw['times']['tzeit85deg'][$day]))->getTimestamp(), $times['tzeit85deg']);
            self::assertArrayNotHasKey('seaLevelSunrise', $times);
            self::assertArrayNotHasKey('seaLevelSunset', $times);
            self::assertArrayNotHasKey('molad', $times);
            self::assertArrayNotHasKey('tchilasZmanKidushLevana3Days', $times);
            self::assertArrayNotHasKey('tchilasZmanKidushLevana7Days', $times);
            self::assertArrayNotHasKey('sofZmanKidushLevanaBetweenMoldos', $times);
            self::assertArrayNotHasKey('sofZmanKidushLevana15Days', $times);
        }
        self::assertSame(
            (new \DateTimeImmutable('2026-10-25T23:23:00+02:00'))->getTimestamp(),
            $days['2026-10-26']['chatzotNight']
        );
    }

    public function testPublicFixtureHashesAndRetentionAreRecorded(): void
    {
        $provenance = json_decode(
            file_get_contents(__DIR__ . '/Fixtures/hebcal-jerusalem-default-full-provenance.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        self::assertSame('CC-BY-4.0', $provenance['license']);
        self::assertSame('2026-10-15', $provenance['retrieved_date']);
        self::assertCount(2, $provenance['fixtures']);
        foreach ($provenance['fixtures'] as $fixture) {
            self::assertSame('complete response; no fields projected or values changed', $fixture['retention']);
            self::assertSame($fixture['sha256'], hash_file('sha256', __DIR__ . '/Fixtures/' . $fixture['file']));
            self::assertStringStartsWith('https://www.hebcal.com/', $fixture['url']);
        }
    }

    public function testUnknownProviderExtensionsCannotBecomeOperationalValues(): void
    {
        $raw = self::fixture();
        $expected = self::provider($raw)->solar(self::settings(), self::START, self::END);
        $raw['times']['futureEmptyExtension'] = [];
        $raw['times']['futureScalarExtension'] = 'not a daily series';
        $raw['times']['futureSparseExtension'] = ['2026-10-15' => 'untrusted extension text'];
        $raw['times'][0] = null;
        self::assertSame($expected, self::provider($raw)->solar(self::settings(), self::START, self::END));
    }

    public function testSunsetSeriesIsAlwaysRequired(): void
    {
        $raw = self::fixture();
        unset($raw['times']['sunset']);
        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('Missing sunset time series.');
        self::provider($raw)->solar(self::settings(), self::START, self::END);
    }

    #[DataProvider('incompleteDailySeries')]
    public function testEveryPresentSupportedDailySeriesRequiresFullCoverage(string $name): void
    {
        $raw = self::fixture();
        unset($raw['times'][$name]['2026-10-15']);
        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('Incomplete solar date coverage.');
        self::provider($raw)->solar(self::settings(), self::START, self::END);
    }

    public static function incompleteDailySeries(): array
    {
        return [
            'permission sunset' => ['sunset'],
            'configured nightfall' => ['tzeit85deg'],
            'alternate configured nightfall' => ['tzeit7083deg'],
            'omer dawn' => ['alotHaShachar'],
            'tefillin earliest time' => ['misheyakir'],
            'displayed sunrise' => ['sunrise'],
            'displayed latest prayer' => ['sofZmanTfillaMGA'],
            'preceding solar midnight' => ['chatzotNight'],
        ];
    }

    #[DataProvider('invalidDailyValues')]
    public function testSupportedDailyValuesRetainStrictTimestampAndDateValidation(string $name, string $value): void
    {
        $raw = self::fixture();
        $raw['times'][$name]['2026-10-15'] = $value;
        $this->expectException(ProviderException::class);
        self::provider($raw)->solar(self::settings(), self::START, self::END);
    }

    public static function invalidDailyValues(): array
    {
        return [
            'sunset wrong IANA offset' => ['sunset', '2026-10-15T18:00:00+02:00'],
            'sunrise wrong civil day' => ['sunrise', '2026-10-16T06:00:00+03:00'],
            'dawn invalid civil date' => ['alotHaShachar', '2026-10-32T05:00:00+03:00'],
            'nightfall missing offset' => ['tzeit85deg', '2026-10-15T19:00:00'],
            'midnight following night' => ['chatzotNight', '2026-10-16T00:23:00+03:00'],
            'midnight after sunrise' => ['chatzotNight', '2026-10-15T10:23:00+03:00'],
        ];
    }

    public function testRecognizedDailySeriesCannotBeScalar(): void
    {
        $raw = self::fixture();
        $raw['times']['misheyakir'] = 'malformed';
        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('Malformed solar time series.');
        self::provider($raw)->solar(self::settings(), self::START, self::END);
    }

    public function testEmptyRecognizedDailySeriesRemainsAnError(): void
    {
        $raw = self::fixture();
        $raw['times']['sunset'] = [];
        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('Incomplete solar date coverage.');
        self::provider($raw)->solar(self::settings(), self::START, self::END);
    }

    public function testExplicitNullPolarTimesArePreservedWithoutInventingTimes(): void
    {
        $raw = self::fixture();
        foreach (['sunset', 'tzeit85deg', 'tzeit7083deg', 'alotHaShachar', 'sunrise', 'chatzotNight'] as $name) {
            $raw['times'][$name] = array_fill_keys(array_keys($raw['times'][$name]), null);
        }
        $days = self::provider($raw)->solar(self::settings(), self::START, self::END);
        foreach ($days as $times) {
            self::assertArrayHasKey('sunset', $times);
            self::assertNull($times['sunset']);
            self::assertNull($times['tzeit85deg']);
            self::assertNull($times['alotHaShachar']);
        }
    }

    private static function fixture(): array
    {
        return json_decode(file_get_contents(__DIR__ . '/Fixtures/' . self::FIXTURE), true, 512, JSON_THROW_ON_ERROR);
    }

    private static function settings(): array
    {
        return ['latitude' => 31.76904, 'longitude' => 35.21633, 'timezone_id' => 'Asia/Jerusalem'];
    }

    private static function provider(array $raw): HebcalProvider
    {
        $client = new class ($raw) implements JsonClient {
            public function __construct(private array $response)
            {
            }
            public function get(string $url, array $query = [], ?Cancellation $cancellation = null): array
            {
                $cancellation?->throwIfRequested();
                return $this->response;
            }
        };
        return new HebcalProvider($client);
    }
}
