<?php

// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);

namespace Shabbat\Tests;

use Amp\Cancellation;
use Amp\DeferredCancellation;
use PHPUnit\Framework\TestCase;
use Shabbat\Calendar\HebcalProvider;
use Shabbat\Calendar\CalendarService;
use Shabbat\Http\JsonClient;
use Shabbat\Http\ProviderException;
use Shabbat\Http\SharedJsonClient;
use Shabbat\Http\SqliteJsonClient;
use Shabbat\Storage\Database;
use Shabbat\Storage\SettingsRepository;
use Shabbat\Time\FrozenClock;

use function Amp\async;
use function Amp\delay;

require_once __DIR__ . '/Fixtures/CalendarFixtureClient.php';
final class CalendarProviderTest extends TestCase
{
    private FrozenClock $clock;
    private array $settings;
    protected function setUp(): void
    {
        $this->clock = new FrozenClock(new \DateTimeImmutable('2026-04-12T12:00:00Z'));
        $db = Database::open(':memory:');
        $db->migrate();
        $this->settings = (new SettingsRepository($db, $this->clock))->ensureGroup('-1');
    }
    public function testCoordinatesAndIsraelSchemeRemainIndependentAcrossGroups(): void
    {
        $http = new CalendarFixtureClient();
        $service = new CalendarService(new HebcalProvider($http), $this->clock);
        $zone = date_default_timezone_get();
        $jerusalem = $service->zmanim($this->settings, '2026-04-12');
        $other = $this->settings;
        $other['latitude'] = 32.8;
        $other['longitude'] = 34.98;
        $other['location_name'] = 'Haifa';
        $haifa = $service->zmanim($other, '2026-04-12');
        self::assertSame(600, $haifa['times']['sunset'] - $jerusalem['times']['sunset']);
        $other['timezone_id'] = 'America/New_York';
        $other['israel'] = false;
        $service->snapshot($other, new \DateTimeImmutable('2026-05-23T12:00:00-04:00'));
        self::assertSame($zone, date_default_timezone_get());
        $calendarCalls = array_filter($http->calls, static fn(array $call): bool=>str_ends_with($call[0], '/hebcal'));
        self::assertContains('off', array_column(array_column($calendarCalls, 1), 'i'));
        self::assertContains('on', array_column(array_column($calendarCalls, 1), 'i'));
    }
    public function testMergedShavuotSequenceKeepsAccurateCurrentDayFlagsInIsraelAndDiaspora(): void
    {
        $service = new CalendarService(new HebcalProvider(new CalendarFixtureClient()), $this->clock);
        $friday = $service->snapshot($this->settings, new \DateTimeImmutable('2026-05-22T10:00:00+03:00'));
        self::assertTrue($friday['is_yom_tov']);
        self::assertFalse($friday['is_shabbat']);
        self::assertTrue($friday['should_group_be_locked']);
        $saturday = $service->snapshot($this->settings, new \DateTimeImmutable('2026-05-23T10:00:00+03:00'));
        self::assertTrue($saturday['is_shabbat']);
        self::assertFalse($saturday['is_yom_tov']);
        self::assertSame(['2026-05-22','2026-05-23'], $saturday['current_event']['days']);
        $diaspora = $this->settings;
        $diaspora['israel'] = false;
        $secondDay = $service->snapshot($diaspora, new \DateTimeImmutable('2026-05-23T10:00:00+03:00'));
        self::assertTrue($secondDay['is_shabbat']);
        self::assertTrue($secondDay['is_yom_tov']);
        self::assertSame($saturday['next_open_at'], $secondDay['next_open_at']);
    }
    public function testDafYomiUsesExplicitCivilDateAndReturnsPageAndTrustedLink(): void
    {
        $service = new CalendarService(new HebcalProvider(new CalendarFixtureClient()), $this->clock);
        $today = $service->daf($this->settings, '2026-04-12');
        $tomorrow = $service->daf($this->settings, '2026-04-13');
        self::assertSame(14, $today['page']);
        self::assertSame(15, $tomorrow['page']);
        self::assertSame('Berakhot', $today['tractate']);
        self::assertSame('2026-04-12', $today['date']);
    }
    public function testInvalidProviderDateRangeIsRejected(): void
    {
        $http = new CalendarFixtureClient();
        $http->override = static fn(): array=>['range' => ['start' => '2026-02-30','end' => '2026-04-30'],'items' => []];
        $this->expectException(ProviderException::class);
        (new HebcalProvider($http))->calendar('2026-04-12', '2026-04-13', true);
    }
    public function testStaleCoverageIsRejectedEvenIfProviderTimestampIsCurrent(): void
    {
        $http = new CalendarFixtureClient();
        $http->override = static fn(): array=>['date' => '2026-04-12T12:00:00Z','range' => ['start' => '2026-04-01','end' => '2026-04-05'],'items' => []];
        $this->expectException(ProviderException::class);
        (new HebcalProvider($http))->calendar('2026-04-12', '2026-04-13', true);
    }
    public function testMalformedSolarTimestampAndWrongTimezoneAreRejected(): void
    {
        foreach (['2026-04-12T18:00:00+01:00','2026-04-31T18:00:00+03:00','yesterday'] as $bad) {
            $http = new CalendarFixtureClient();
            $http->override = static fn(): array=>['date' => ['start' => '2026-04-12','end' => '2026-04-12'],'times' => ['sunset' => ['2026-04-12' => $bad]]];
            try {
                (new HebcalProvider($http))->solar($this->settings, '2026-04-12', '2026-04-12');
                self::fail('Invalid solar value accepted.');
            } catch (ProviderException) {
                self::assertTrue(true);
            }
        }
    }
    public function testNullPolarSunsetIsPreservedButIncompleteDateMapFails(): void
    {
        $http = new CalendarFixtureClient();
        $http->override = static fn(): array=>['date' => ['start' => '2026-04-12','end' => '2026-04-12'],'times' => ['sunset' => ['2026-04-12' => null]]];
        self::assertNull((new HebcalProvider($http))->solar($this->settings, '2026-04-12', '2026-04-12')['2026-04-12']['sunset']);
        $http->override = static fn(): array=>['date' => ['start' => '2026-04-12','end' => '2026-04-12'],'times' => ['sunset' => []]];
        $this->expectException(ProviderException::class);
        (new HebcalProvider($http))->solar($this->settings, '2026-04-12', '2026-04-12');
    }
    public function testPersistentRawCacheShowsOriginalFetchTimeAndVisibleStaleFallback(): void
    {
        $db = Database::open(':memory:');
        $db->migrate();
        $http = new CalendarFixtureClient();
        $query = ['start' => '2026-04-12','end' => '2026-04-13','i' => 'on'];
        $one = new SqliteJsonClient($http, $db, $this->clock, 30);
        $first = $one->get('https://www.hebcal.com/hebcal', $query);
        $two = new SqliteJsonClient($http, $db, $this->clock, 30);
        $cached = $two->get('https://www.hebcal.com/hebcal', $query);
        self::assertCount(1, $http->calls);
        self::assertSame($first, $cached);
        $this->clock->set(new \DateTimeImmutable('2026-04-12T12:01:00Z'));
        $http->override = static function (): array {
            throw new ProviderException('offline');
        };
        $stale = $two->get('https://www.hebcal.com/hebcal', $query);
        self::assertTrue($stale['__shabbat_cache']['stale']);
        self::assertSame($first['__shabbat_cache']['fetched_at'], $stale['__shabbat_cache']['fetched_at']);
        self::assertArrayHasKey('__shabbat_refresh_error', $stale);
        self::assertSame('2026-04-12', $db->pdo()->query('SELECT coverage_start FROM calendar_cache')->fetchColumn());
    }
    public function testCancellationIsNotConvertedToCachedSuccess(): void
    {
        $db = Database::open(':memory:');
        $db->migrate();
        $http = new CalendarFixtureClient();
        $client = new SqliteJsonClient($http, $db, $this->clock, 30);
        $client->get('https://www.hebcal.com/hebcal', ['start' => '2026-04-12','end' => '2026-04-13','i' => 'on']);
        $cancel = new DeferredCancellation();
        $cancel->cancel();
        $this->expectException(\Amp\CancelledException::class);
        $client->get('https://www.hebcal.com/hebcal', ['start' => '2026-04-12','end' => '2026-04-13','i' => 'on'], $cancel->getCancellation());
    }
    public function testConcurrentIdenticalRequestsCoalesceAndOneCancelledWaiterDoesNotCancelOthers(): void
    {
        $inner = new class implements JsonClient {
            public int $calls = 0;
            public function get(string $url, array $query = [], ?Cancellation $cancellation = null): array
            {
                $this->calls++;
                delay(0.02);
                return ['value' => 'fixture'];
            }
        };
        $shared = new SharedJsonClient($inner, $this->clock);
        $cancel = new DeferredCancellation();
        $one = async(fn(): array=>$shared->get('https://fixture.invalid', ['q' => 'city'], $cancel->getCancellation()));
        $two = async(fn(): array=>$shared->get('https://fixture.invalid', ['q' => 'city']));
        delay(0.005);
        $cancel->cancel();
        try {
            $one->await();
            self::fail('Cancellation did not propagate.');
        } catch (\Amp\CancelledException) {
        }
        self::assertSame(['value' => 'fixture'], $two->await());
        self::assertSame(1, $inner->calls);
        self::assertSame(['value' => 'fixture'], $shared->get('https://fixture.invalid', ['q' => 'city']));
        self::assertSame(1, $inner->calls);
    }
    public function testEveryDisplayLanguageMapsExplicitlyAndCannotChangeRestrictions(): void
    {
        foreach (['he' => 'he','en' => 's','ru' => 'ru','fr' => 'fr','es' => 'es','pt-br' => 'pt'] as $internal => $providerCode) {
            $http = new CalendarFixtureClient();
            $provider = new HebcalProvider($http);
            $response = $provider->calendar('2026-05-22', '2026-05-23', false, $internal);
            self::assertSame($providerCode, $http->calls[0][1]['lg']);
            self::assertSame($internal, $response['requested_language']);
            self::assertSame($providerCode, $response['provider_language']);
            $group = $this->settings;
            $group['language'] = $internal;
            $group['israel'] = false;
            $state = (new CalendarService($provider, $this->clock))->snapshot($group, new \DateTimeImmutable('2026-05-23T10:00:00+03:00'));
            self::assertTrue($state['is_shabbat']);
            self::assertTrue($state['is_yom_tov']);
            self::assertTrue($state['should_group_be_locked']);
        }
    }
    public function testOriginalEnglishTitleWorksWhenProviderOmitsRedundantTitleOriginal(): void
    {
        $fixture = new CalendarFixtureClient();
        $fixture->override = static function (string $url, array $query): array {
            $original = (new CalendarFixtureClient())->get($url, $query);
            if (str_ends_with($url, '/hebcal')) {
                foreach ($original['items'] as &$item) {
                    if ($item['category'] === 'holiday') {
                        unset($item['title_orig']);
                    }
                } unset($item);
            }
            return $original;
        };
        $group = $this->settings;
        $group['language'] = 'en';
        $state = (new CalendarService(new HebcalProvider($fixture), $this->clock))->snapshot($group, new \DateTimeImmutable('2019-08-11T10:00:00+03:00'));
        self::assertTrue($state['is_tisha_bav']);
        self::assertFalse($state['is_yom_tov']);
    }
}
