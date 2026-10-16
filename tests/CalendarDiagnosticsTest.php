<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace Shabbat\Tests;

use Amp\CancelledException;
use Amp\Http\Client\TimeoutException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shabbat\Calendar\CalendarDiagnostics;
use Shabbat\Calendar\CalendarEngine;
use Shabbat\Http\ProviderException;
use Shabbat\Storage\Database;
use Shabbat\Storage\SettingsRepository;
use Shabbat\Time\FrozenClock;

require_once __DIR__ . '/Fixtures/CalendarFixtureClient.php';

final class CalendarDiagnosticsTest extends TestCase
{
    private function clock(string $instant = '2026-10-15T09:00:00Z'): FrozenClock
    {
        return new FrozenClock(new \DateTimeImmutable($instant));
    }

    public function testDefaultsMatchEveryRelevantMigratedGroupFieldAndJerusalemCityCustom(): void
    {
        $database = Database::open(':memory:');
        $database->migrate();
        $actual = (new SettingsRepository($database, $this->clock()))->ensureGroup('-1');
        $settings = CalendarDiagnostics::settings();
        foreach ($settings as $field => $value) {
            self::assertSame($actual[$field], $value, $field);
        }
        self::assertSame(40, CalendarEngine::candleMinutes($settings));
        self::assertSame(31.76904, $settings['latitude']);
        self::assertSame(35.21633, $settings['longitude']);
    }

    public function testFullUntouchedPublicResponsesExerciseAllServiceViewsWithOnlyThreeRequests(): void
    {
        $solar = json_decode((string) file_get_contents(__DIR__ . '/Fixtures/hebcal-jerusalem-default-full-solar-2026-09-23_2026-12-10.json'), true, 64, JSON_THROW_ON_ERROR);
        $calendar = json_decode((string) file_get_contents(__DIR__ . '/Fixtures/hebcal-jerusalem-default-full-calendar-2026-09-23_2026-12-10.json'), true, 64, JSON_THROW_ON_ERROR);
        $http = new CalendarFixtureClient();
        $http->override = static function (string $url, array $query) use ($solar, $calendar): array {
            if (str_ends_with($url, '/zmanim')) {
                self::assertSame(31.76904, $query['latitude']);
                self::assertSame(35.21633, $query['longitude']);
                return $solar;
            }
            if ($query['start'] === $query['end']) {
                $calendar['range'] = ['start' => $query['start'], 'end' => $query['end']];
                $calendar['items'] = array_values(array_filter($calendar['items'], static fn(array $item): bool => $item['date'] === $query['start']));
            }
            return $calendar;
        };
        $report = (new CalendarDiagnostics($this->clock(), $http))->run(['date' => '2026-10-15']);
        self::assertTrue($report['ok']);
        self::assertSame(['start' => '2026-09-23', 'end' => '2026-12-10'], $report['window']);
        self::assertSame(79, $report['stages']['solar']['day_count']);
        self::assertSame('valid_inactive', $report['stages']['omer']['state']);
        self::assertNull($report['stages']['omer']['day']);
        self::assertNotNull($report['stages']['shabbat_holidays']['next_close_at']);
        self::assertSame($solar['times']['sunset']['2026-10-15'], $report['stages']['zmanim']['sunset']);
        foreach ($report['stages'] as $stage) {
            self::assertSame('ok', $stage['status']);
        }
        self::assertCount(3, $http->calls);
        self::assertTrue($report['read_only']);
        self::assertFalse($report['credentials_required']);
        self::assertFalse($report['persisted_data_used']);
    }

    public function testOmerInSeasonIsSuccessfulAndDistinctFromIneligibleDaytime(): void
    {
        $http = new CalendarFixtureClient();
        $report = (new CalendarDiagnostics($this->clock('2026-04-12T17:00:00Z'), $http))->run();
        self::assertTrue($report['ok']);
        self::assertSame('active_season', $report['stages']['omer']['state']);
        self::assertSame(11, $report['stages']['omer']['day']);
        self::assertSame('night', $report['stages']['omer']['phase']);
        self::assertTrue($report['stages']['omer']['eligible']);
        $noon = (new CalendarDiagnostics($this->clock(), new CalendarFixtureClient()))->run(['date' => '2026-04-12']);
        self::assertSame('active_season', $noon['stages']['omer']['state']);
        self::assertFalse($noon['stages']['omer']['eligible']);
        self::assertSame('2026-04-12T12:00:00+03:00', $noon['input']['instant']);
    }

    public function testMalformedSolarDataFailsSolarStageBlocksDependentViewsAndLeavesDafWorking(): void
    {
        $http = new CalendarFixtureClient();
        $http->override = static function (string $url, array $query): array {
            $data = (new CalendarFixtureClient())->get($url, $query);
            if (str_ends_with($url, '/zmanim')) {
                unset($data['times']['sunset'][$query['start']]);
            }
            return $data;
        };
        $report = (new CalendarDiagnostics($this->clock(), $http))->run();
        self::assertFalse($report['ok']);
        self::assertSame('ok', $report['stages']['calendar']['status']);
        self::assertSame(['status' => 'failed', 'kind' => 'provider', 'reason' => 'solar_daily_coverage_incomplete'], $report['stages']['solar']);
        foreach (['shabbat_holidays', 'zmanim', 'omer'] as $stage) {
            self::assertSame(['status' => 'blocked', 'reason' => 'provider_stage_failed'], $report['stages'][$stage]);
        }
        self::assertSame('ok', $report['stages']['daf']['status']);
        self::assertCount(3, $http->calls);
    }

    public function testMalformedCalendarDataHasIndependentSolarResultAndNoRetries(): void
    {
        $http = new CalendarFixtureClient();
        $http->override = static function (string $url, array $query): array {
            $data = (new CalendarFixtureClient())->get($url, $query);
            if (str_ends_with($url, '/hebcal') && $query['start'] !== $query['end']) {
                $data['items'] = 'malformed';
            }
            return $data;
        };
        $report = (new CalendarDiagnostics($this->clock(), $http))->run();
        self::assertSame('calendar_schema_invalid', $report['stages']['calendar']['reason']);
        self::assertSame('ok', $report['stages']['solar']['status']);
        self::assertSame('ok', $report['stages']['daf']['status']);
        self::assertCount(3, $http->calls);
    }

    public function testUnavailableRequiredTimesAreCalculationFailuresRatherThanTransportFailures(): void
    {
        $http = new CalendarFixtureClient();
        $http->override = static function (string $url, array $query): array {
            $data = (new CalendarFixtureClient())->get($url, $query);
            if (str_ends_with($url, '/zmanim')) {
                foreach ($data['times']['sunset'] as &$value) {
                    $value = null;
                }
                unset($value);
            }
            return $data;
        };
        $report = (new CalendarDiagnostics($this->clock(), $http))->run();
        self::assertSame('ok', $report['stages']['solar']['status']);
        self::assertSame(['status' => 'failed', 'kind' => 'calculation', 'reason' => 'calculation_required_times_unavailable'], $report['stages']['shabbat_holidays']);
        self::assertSame('calculation', $report['stages']['omer']['kind']);
        self::assertSame('ok', $report['stages']['daf']['status']);
        self::assertCount(3, $http->calls);
    }

    public function testUnknownErrorTextQueryAndTraceNeverEscapeReport(): void
    {
        $http = new CalendarFixtureClient();
        $http->override = static function (): array {
            throw new ProviderException('BOT_TOKEN=DO_NOT_PRINT_SECRET https://provider.invalid/?secret=DO_NOT_PRINT_SECRET');
        };
        $report = (new CalendarDiagnostics($this->clock(), $http))->run();
        self::assertFalse($report['ok']);
        $json = json_encode($report, JSON_THROW_ON_ERROR);
        foreach (['DO_NOT_PRINT_SECRET', 'BOT_TOKEN', 'provider.invalid', 'trace', 'https://'] as $private) {
            self::assertStringNotContainsString($private, $json);
        }
        self::assertSame('provider_validation_failed', $report['stages']['calendar']['reason']);
        self::assertSame('provider_validation_failed', $report['stages']['solar']['reason']);
        self::assertCount(3, $http->calls);
    }

    public function testTransportTimeoutHasSafeDistinctReasonAndNoTightRetry(): void
    {
        $http = new CalendarFixtureClient();
        $http->override = static function (): array {
            throw new TimeoutException('DO_NOT_PRINT_SECRET');
        };
        $report = (new CalendarDiagnostics($this->clock(), $http))->run();
        self::assertSame('provider_transport_timeout', $report['stages']['solar']['reason']);
        self::assertStringNotContainsString('DO_NOT_PRINT_SECRET', json_encode($report, JSON_THROW_ON_ERROR));
        self::assertCount(3, $http->calls);
    }

    public function testIntentionalCancellationPropagatesWithoutContinuingOtherQueries(): void
    {
        $http = new CalendarFixtureClient();
        $http->override = static function (): array {
            throw new CancelledException();
        };
        try {
            (new CalendarDiagnostics($this->clock(), $http))->run();
            self::fail('Cancellation was converted into a diagnostic result.');
        } catch (CancelledException) {
            self::assertCount(1, $http->calls);
        }
    }

    public function testValidatedOverridesPreserveIndependentGeographyLanguageAndIsraelScheme(): void
    {
        $arguments = ['--date=2026-05-23', '--latitude', '40.7', '--longitude=-74', '--timezone=America/New_York', '--israel=off', '--language=pt-br'];
        $options = CalendarDiagnostics::parseArguments($arguments);
        $settings = CalendarDiagnostics::settings($options);
        self::assertNull($settings['geoname_id']);
        self::assertSame(18, CalendarEngine::candleMinutes($settings));
        $http = new CalendarFixtureClient();
        $report = (new CalendarDiagnostics($this->clock(), $http))->run($options);
        self::assertTrue($report['ok']);
        self::assertSame('America/New_York', $report['input']['timezone_id']);
        self::assertFalse($report['input']['israel']);
        self::assertSame('pt-br', $report['input']['language']);
        self::assertSame('off', $http->calls[0][1]['i']);
        self::assertSame('pt', $http->calls[0][1]['lg']);
        self::assertTrue($report['stages']['shabbat_holidays']['is_yom_tov']);
    }

    #[DataProvider('invalidArguments')]
    public function testInvalidOptionsCannotReachProvider(array $arguments): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CalendarDiagnostics::parseArguments($arguments);
    }

    public static function invalidArguments(): array
    {
        return [
            'invalid day' => [['--date=2026-02-30']],
            'missing value' => [['--date']],
            'empty value' => [['--date=']],
            'duplicate value' => [['--date=2026-10-15', '--date=2026-10-16']],
            'unknown option' => [['--env=private.env']],
            'bare argument' => [['2026-10-15']],
            'partial coordinates' => [['--latitude=31']],
            'out of range' => [['--latitude=91', '--longitude=35']],
            'non-finite coordinate' => [['--latitude=INF', '--longitude=35']],
            'wrong zone' => [['--timezone=+03:00']],
            'wrong Israel scheme' => [['--israel=sometimes']],
            'wrong language' => [['--language=zz']],
            'option used as value' => [['--date', '--language=he']],
        ];
    }

    public function testCliWithoutArgumentArrayStopsBeforeAnyNetworkOrBootstrap(): void
    {
        $process = proc_open(
            [PHP_BINARY, '-r', 'unset($argv); require $_SERVER["argv"][1];', dirname(__DIR__) . '/bin/check-calendar.php'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            dirname(__DIR__),
            null,
            ['bypass_shell' => true],
        );
        self::assertIsResource($process);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(2, proc_close($process));
        self::assertSame('', $stderr);
        self::assertSame('cli_arguments_unavailable', json_decode($stdout, true, 64, JSON_THROW_ON_ERROR)['reason']);
    }

    public function testCliInvalidOptionsDoNotReadEnvCreateStorageOrPrintSecrets(): void
    {
        $fixture = sys_get_temp_dir() . '/shabbat-calendar-diagnostic-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($fixture, 0700));
        $env = $fixture . '/.env';
        file_put_contents($env, 'BOT_TOKEN=DO_NOT_PRINT_SECRET');
        try {
            $environment = getenv();
            self::assertIsArray($environment);
            $environment = array_replace($environment, [
                'BOT_TOKEN' => 'DO_NOT_PRINT_SECRET', 'API_HASH' => 'DO_NOT_PRINT_SECRET',
                'APP_DB_PATH' => $fixture . '/never-created.sqlite',
                'DATA_DIR' => $fixture . '/never-created-data',
                'SESSION_PATH' => $fixture . '/never-created-session',
            ]);
            $process = proc_open(
                [PHP_BINARY, dirname(__DIR__) . '/bin/check-calendar.php', '--date=DO_NOT_PRINT_SECRET'],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                $fixture,
                $environment,
                ['bypass_shell' => true],
            );
            self::assertIsResource($process);
            fclose($pipes[0]);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(2, proc_close($process));
            self::assertSame('', $stderr);
            self::assertStringNotContainsString('DO_NOT_PRINT_SECRET', $stdout);
            self::assertSame('invalid_options', json_decode($stdout, true, 64, JSON_THROW_ON_ERROR)['reason']);
            self::assertSame(['.env'], array_values(array_diff(scandir($fixture), ['.', '..'])));
            self::assertSame('BOT_TOKEN=DO_NOT_PRINT_SECRET', file_get_contents($env));
        } finally {
            unlink($env);
            rmdir($fixture);
        }
    }
}
