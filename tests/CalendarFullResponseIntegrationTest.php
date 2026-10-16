<?php

// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);

namespace Shabbat\Tests;

use PHPUnit\Framework\TestCase;
use Shabbat\Calendar\CalendarService;
use Shabbat\Calendar\HebcalProvider;
use Shabbat\Http\SharedJsonClient;
use Shabbat\Http\SqliteJsonClient;
use Shabbat\Location\GeoNames;
use Shabbat\Runtime\Application;
use Shabbat\Runtime\Runner;
use Shabbat\Storage\Database;
use Shabbat\Storage\FlowTokenRepository;
use Shabbat\Storage\SettingsRepository;
use Shabbat\Time\FrozenClock;
use Shabbat\UI\BotController;
use Shabbat\UI\Lang;

/** Unmodified complete public responses exercise the real provider, consumers and UI offline. */
final class CalendarFullResponseIntegrationTest extends TestCase
{
    private Database $db;
    private FrozenClock $clock;
    private SettingsRepository $settings;
    private FullCalendarGateway $gateway;
    private FullHebcalFixtureClient $http;
    private CalendarService $calendar;
    private BotController $ui;
    private string $heartbeat;
    private string|false $previousHeartbeat;

    protected function setUp(): void
    {
        (new \ReflectionProperty(Runner::class, 'stopping'))->setValue(null, false);
        $this->db = Database::open(':memory:');
        $this->db->migrate();
        $this->clock = new FrozenClock(new \DateTimeImmutable('2026-10-15T12:00:00+03:00'));
        $this->settings = new SettingsRepository($this->db, $this->clock);
        $this->gateway = new FullCalendarGateway();
        $this->http = new FullHebcalFixtureClient();
        $this->calendar = new CalendarService(new HebcalProvider($this->http), $this->clock);
        $this->ui = new BotController(
            $this->settings,
            new FlowTokenRepository($this->db, $this->clock),
            $this->calendar,
            $this->gateway,
            new GeoNames($this->http, $this->clock, ''),
            $this->clock
        );
        $this->previousHeartbeat = getenv('HEARTBEAT_PATH');
        $this->heartbeat = sys_get_temp_dir() . '/shabbat-full-calendar-' . bin2hex(random_bytes(8)) . '.json';
        putenv('HEARTBEAT_PATH=' . $this->heartbeat);
    }

    protected function tearDown(): void
    {
        if (is_file($this->heartbeat)) {
            unlink($this->heartbeat);
        }
        putenv($this->previousHeartbeat === false ? 'HEARTBEAT_PATH' : 'HEARTBEAT_PATH=' . $this->previousHeartbeat);
        (new \ReflectionProperty(Runner::class, 'stopping'))->setValue(null, false);
    }

    public function testCapturedFixtureProvenanceMatchesUnmodifiedCompleteResponseBytes(): void
    {
        $provenance = FullHebcalFixtureClient::read('hebcal-full-runtime-provenance.json');
        self::assertSame('CC-BY-4.0', $provenance['license']);
        self::assertCount(15, $provenance['fixtures']);
        foreach ($provenance['fixtures'] as $fixture) {
            self::assertSame($fixture['sha256'], hash_file('sha256', __DIR__ . '/Fixtures/' . $fixture['file']));
            self::assertSame('www.hebcal.com', parse_url($fixture['url'], PHP_URL_HOST));
            $raw = FullHebcalFixtureClient::read($fixture['file']);
            self::assertArrayHasKey('date', $raw);
            self::assertArrayHasKey('location', $raw);
            self::assertArrayHasKey('version', $raw);
        }
    }

    public function testDefaultPrivateCommandsAndGroupTefillinUseFullResponsesInAllSixLanguages(): void
    {
        $user = $this->settings->getUser('42');
        self::assertSame(31.76904, $user['latitude']);
        self::assertSame(35.21633, $user['longitude']);
        self::assertSame('Asia/Jerusalem', $user['timezone_id']);
        self::assertTrue($user['israel']);
        $group = $this->settings->ensureGroup('-10042', 'Offline calendar integration');
        $lang = new Lang();
        $raw = FullHebcalFixtureClient::read('hebcal-jerusalem-default-full-solar-2026-09-23_2026-12-10.json');
        $sunset = (new \DateTimeImmutable($raw['times']['sunset']['2026-10-15']))->format('H:i');
        foreach (Lang::CODES as $code) {
            $user = $this->settings->updateUser('42', $user['revision'], ['language' => $code]);
            $group = $this->settings->updateGroup('-10042', $group['revision'], ['language' => $code], '42');
            foreach (['zmanim','shabbat','omer'] as $module) {
                self::assertTrue($this->ui->handleMessage(['user_id' => '42','private' => true,'text' => '/' . $module]));
                $text = $this->gateway->private[array_key_last($this->gateway->private)]['text'];
                self::assertStringNotContainsString($lang->text($code, 'provider_unavailable'), $text, $code . '/' . $module);
                if ($module === 'zmanim') {
                    self::assertStringContainsString($lang->text($code, 'sunset'), $text);
                    self::assertStringContainsString($sunset, $text);
                } elseif ($module === 'shabbat') {
                    self::assertStringContainsString($lang->text($code, 'times_heading'), $text);
                    self::assertStringContainsString('2026-10-17', $text);
                    self::assertStringNotContainsString('Hebcal.com', $text);
                    self::assertStringContainsString('<a href="https://www.hebcal.com/">Hebcal.com</a>', $lang->text($code, 'info_calendar_text'));
                } else {
                    self::assertSame($lang->text($code, 'omer_inactive'), $text);
                }
            }
            $preview = $this->ui->renderView('42', $group, 'tefillin', '2026-10-15');
            self::assertStringNotContainsString($lang->text($code, 'provider_unavailable'), $preview);
            self::assertStringContainsString($lang->text($code, 'preview_only'), $preview);
            self::assertStringContainsString('09:00', $preview);
        }
        self::assertSame(0, $this->gateway->permissionChanges);
        self::assertSame([], $this->gateway->sent);
        self::assertCount(6, array_filter($this->http->calls, static fn(array $call): bool => str_ends_with($call[0], '/hebcal')));
    }

    public function testFullRawResponsesRemainUsableThroughPersistentAndSharedCacheAcrossRestart(): void
    {
        $group = $this->settings->ensureGroup('-10042');
        $makeCalendar = fn(): CalendarService => new CalendarService(
            new HebcalProvider(new SharedJsonClient(new SqliteJsonClient($this->http, $this->db, $this->clock), $this->clock)),
            $this->clock
        );
        $first = $makeCalendar()->zmanim($group, '2026-10-15');
        self::assertCount(2, $this->http->calls);
        $cached = $makeCalendar()->zmanim($group, '2026-10-15');
        self::assertSame($first, $cached);
        self::assertCount(2, $this->http->calls);
        $solarPayload = $this->db->pdo()->query("SELECT payload_json FROM calendar_cache WHERE payload_json LIKE '%seaLevelSunrise%'")->fetchColumn();
        self::assertIsString($solarPayload);
        $raw = json_decode($solarPayload, true, 64, JSON_THROW_ON_ERROR);
        self::assertCount(42, $raw['times']);
        self::assertSame([], $raw['times']['seaLevelSunrise']);
        self::assertArrayHasKey('molad', $raw['times']);
        self::assertArrayNotHasKey('molad', $cached['times']);
        self::assertArrayNotHasKey('seaLevelSunrise', $cached['times']);
        self::assertIsInt($cached['times']['sunset']);
    }

    public function testActiveOmerUsesGenuineLocaleCountsAndChangesAfterSunsetInAllSixLanguages(): void
    {
        $this->clock->set(new \DateTimeImmutable('2026-04-14T12:00:00+03:00'));
        $group = $this->settings->ensureGroup('-10042');
        $lang = new Lang();
        foreach (Lang::CODES as $code) {
            $group = $this->settings->updateGroup('-10042', $group['revision'], ['language' => $code], '42');
            $day = $this->calendar->omer($group);
            self::assertTrue($day['season'], $code);
            self::assertSame(12, $day['day'], $code);
            self::assertSame('2026-04-14', $day['date']);
            self::assertSame('day', $day['phase']);
            self::assertFalse($day['eligible']);
            $night = $this->calendar->omer($group, new \DateTimeImmutable('2026-04-14T21:00:00+03:00'));
            self::assertTrue($night['season']);
            self::assertSame(13, $night['day'], $code);
            self::assertSame('2026-04-15', $night['date']);
            self::assertSame('night', $night['phase']);
            self::assertTrue($night['eligible']);
            self::assertFalse($night['quiet_now']);
            self::assertNotEmpty($night['count_text']);
            $view = $this->ui->renderView('42', $group, 'omer', '2026-04-14');
            self::assertStringNotContainsString($lang->text($code, 'provider_unavailable'), $view);
            self::assertStringNotContainsString($lang->text($code, 'omer_inactive'), $view);
            self::assertStringContainsString($lang->text($code, 'omer'), $view);
        }
        foreach ($this->http->calls as [$url, $query]) {
            if (str_ends_with($url, '/hebcal')) {
                self::assertContains($query['lg'], ['he','s','ru','fr','es','pt']);
            }
        }
    }

    public function testOmerTwilightPreviousNightDawnAndQuietPeriodUseActualSolarBoundaries(): void
    {
        $this->clock->set(new \DateTimeImmutable('2026-04-14T12:00:00+03:00'));
        $group = $this->settings->ensureGroup('-10042');
        $sun = $this->calendar->zmanim($group, '2026-04-14')['times'];
        $at = static fn(int $stamp): \DateTimeImmutable => new \DateTimeImmutable('@' . $stamp);
        $twilight = $this->calendar->omer($group, $at($sun['sunset']));
        self::assertSame(13, $twilight['day']);
        self::assertSame('twilight', $twilight['phase']);
        self::assertFalse($twilight['eligible']);
        $night = $this->calendar->omer($group, $at($sun['tzeit85deg']));
        self::assertSame('night', $night['phase']);
        self::assertTrue($night['eligible']);
        $tomorrow = $this->calendar->zmanim($group, '2026-04-15')['times'];
        $beforeDawn = $this->calendar->omer($group, $at($tomorrow['alotHaShachar'] - 1));
        self::assertSame(13, $beforeDawn['day']);
        self::assertSame('2026-04-14', $beforeDawn['night_date']);
        self::assertTrue($beforeDawn['eligible']);
        $afterDawn = $this->calendar->omer($group, $at($tomorrow['alotHaShachar']));
        self::assertSame('day', $afterDawn['phase']);
        self::assertFalse($afterDawn['eligible']);
        $group = $this->settings->updateGroup('-10042', $group['revision'], ['omer_day_completion' => true], '42');
        self::assertTrue($this->calendar->omer($group, new \DateTimeImmutable('2026-04-15T12:00:00+03:00'))['day_completion']);
        $shabbat = $this->calendar->omer($group, new \DateTimeImmutable('2026-04-17T21:00:00+03:00'));
        self::assertSame(16, $shabbat['day']);
        self::assertTrue($shabbat['quiet_now']);
        self::assertFalse($shabbat['eligible']);
        $last = $this->calendar->omer($group, new \DateTimeImmutable('2026-04-02T21:00:00+03:00'));
        self::assertSame(1, $last['day']);
        $this->clock->set(new \DateTimeImmutable('2026-05-20T21:00:00+03:00'));
        $last = $this->calendar->omer($group);
        self::assertSame(49, $last['day']);
        $ended = $this->calendar->omer($group, new \DateTimeImmutable('2026-05-21T21:00:00+03:00'));
        self::assertFalse($ended['season']);
        self::assertNull($ended['day']);
        self::assertFalse($ended['eligible']);
    }

    public function testChosenDegreeAndFixedMinuteMethodsUseVerifiedFullSolarValues(): void
    {
        $this->clock->set(new \DateTimeImmutable('2026-04-14T21:00:00+03:00'));
        $group = $this->settings->ensureGroup('-10042');
        $sun = $this->calendar->zmanim($group, '2026-04-14')['times'];
        foreach ([['degrees',8.5,'tzeit85deg'],['degrees',7.083,'tzeit7083deg'],['minutes',42,null],['minutes',50,null],['minutes',72,null]] as [$method,$value,$field]) {
            $group = $this->settings->updateGroup('-10042', $group['revision'], [
                'havdalah_method' => $method,'havdalah_value' => $value,
                'omer_nightfall_method' => $method,'omer_nightfall_value' => $value,
            ], '42');
            $expected = $field === null ? $sun['sunset'] + $value * 60 : $sun[$field];
            $omer = $this->calendar->omer($group);
            self::assertSame($expected, $omer['nightfall']);
            self::assertSame(13, $omer['day']);
            self::assertTrue($omer['eligible']);
            $state = $this->calendar->snapshot($group);
            $lastDate = $state['next_event']['days'][array_key_last($state['next_event']['days'])];
            $exitSun = $this->calendar->zmanim($group, $lastDate)['times'];
            $exit = $field === null ? $exitSun['sunset'] + $value * 60 : $exitSun[$field];
            self::assertSame($exit, $state['next_event']['havdalah_at']);
            self::assertSame($exit, $state['next_event']['open_at']);
        }
    }


    public function testShavuotFollowedByShabbatStaysClosedUntilFinalExitInIsraelAndDiaspora(): void
    {
        $this->clock->set(new \DateTimeImmutable('2026-05-21T12:00:00+03:00'));
        $app = new Application($this->gateway, $this->clock, $this->db, $this->calendar);
        $israel = $app->settings->ensureGroup('-10042', 'Israel');
        $israel = $app->settings->updateGroup('-10042', $israel['revision'], [
            'close_before_candles_minutes' => 10,'open_after_havdalah_minutes' => 15,
        ], '42');
        $diaspora = $app->settings->ensureGroup('-10043', 'Diaspora');
        $diaspora = $app->settings->updateGroup('-10043', $diaspora['revision'], [
            'israel' => false,'close_before_candles_minutes' => 10,'open_after_havdalah_minutes' => 15,
        ], '42');
        $state = $this->calendar->snapshot($israel);
        self::assertSame(['2026-05-22','2026-05-23'], $state['next_event']['days']);
        $sun = $this->calendar->zmanim($israel, '2026-05-21')['times'];
        self::assertSame($sun['sunset'] - 40 * 60, $state['next_event']['candles_at']);
        self::assertSame($sun['sunset'] - 50 * 60, $state['next_event']['close_at']);
        $closing = $state['next_event']['close_at'];
        $opening = $state['next_event']['open_at'];
        $this->clock->set(new \DateTimeImmutable('@' . ($closing - 1)));
        $app->tick();
        self::assertSame(0, $this->gateway->permissionChanges);
        $this->gateway->getPermissions('-10042');
        $this->gateway->getPermissions('-10043');
        $original = $this->gateway->rights;
        $this->clock->set(new \DateTimeImmutable('@' . $closing));
        $app->tick();
        self::assertSame(2, $this->gateway->permissionChanges);
        self::assertSame('locked', $app->locks->state('-10042')['status']);
        self::assertSame('locked', $app->locks->state('-10043')['status']);
        $friday = $this->calendar->snapshot($israel, new \DateTimeImmutable('2026-05-22T12:00:00+03:00'));
        self::assertTrue($friday['is_yom_tov']);
        self::assertFalse($friday['is_shabbat']);
        $between = $this->calendar->zmanim($israel, '2026-05-22')['times']['tzeit85deg'] + 60;
        $this->clock->set(new \DateTimeImmutable('@' . $between));
        $app->tick();
        self::assertSame(2, $this->gateway->permissionChanges);
        $saturday = $this->calendar->snapshot($israel, new \DateTimeImmutable('2026-05-23T12:00:00+03:00'));
        self::assertTrue($saturday['is_shabbat']);
        self::assertFalse($saturday['is_yom_tov']);
        $second = $this->calendar->snapshot($diaspora, new \DateTimeImmutable('2026-05-23T12:00:00+03:00'));
        self::assertTrue($second['is_shabbat']);
        self::assertTrue($second['is_yom_tov']);
        self::assertSame($opening, $second['next_open_at']);
        $this->clock->set(new \DateTimeImmutable('@' . ($opening - 1)));
        $app->tick();
        self::assertSame(2, $this->gateway->permissionChanges);
        $this->clock->set(new \DateTimeImmutable('@' . $opening));
        $app->tick();
        self::assertSame(4, $this->gateway->permissionChanges);
        self::assertSame($original, $this->gateway->rights);
        self::assertSame('restored', $app->locks->state('-10042')['status']);
        self::assertSame('restored', $app->locks->state('-10043')['status']);
        self::assertSame([], $this->gateway->sent);
    }
}
