<?php

// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);

namespace Shabbat\Tests;

use PHPUnit\Framework\TestCase;
use Shabbat\Calendar\{CalendarService,HebcalProvider};
use Shabbat\Http\JsonClient;
use Shabbat\Runtime\{Application,Runner};
use Shabbat\Scheduling\{JobRepository,OutboxWorker};
use Shabbat\Storage\Database;
use Shabbat\Time\FrozenClock;

final class RuntimeDispatchSafetyTest extends TestCase
{
    private string $heartbeat;
    protected function setUp(): void
    {
        (new \ReflectionProperty(Runner::class, 'stopping'))->setValue(null, false);
        $this->heartbeat = sys_get_temp_dir() . '/shabbat-dev9-' . bin2hex(random_bytes(8)) . '.json';
        putenv('HEARTBEAT_PATH=' . $this->heartbeat);
    }
    protected function tearDown(): void
    {
        if (is_file($this->heartbeat)) {
            unlink($this->heartbeat);
        }
        putenv('HEARTBEAT_PATH');
        (new \ReflectionProperty(Runner::class, 'stopping'))->setValue(null, false);
    }
    private function app(string $date, array $settings = [], ?JsonClient $http = null): array
    {
        $clock = new FrozenClock(new \DateTimeImmutable($date, new \DateTimeZone('Asia/Jerusalem')));
        $db = Database::open(':memory:');
        $db->migrate();
        $gateway = new RuntimeFakeGateway();
        $calendar = new CalendarService(new HebcalProvider($http ?? new RuntimeFakeJson()), $clock);
        $app = new Application($gateway, $clock, $db, $calendar);
        $group = $app->settings->ensureGroup('-1001', 'Safety fixture', $settings);
        return [$app, $clock, $db, $gateway, $group];
    }
    private function worker(Application $app): OutboxWorker
    {
        return (new \ReflectionProperty($app, 'worker'))->getValue($app);
    }
    private function job(Application $app, string $kind, string $event = 'regression', ?int $runAt = null, ?int $expires = null): int
    {
        $group = $app->settings->getGroup('-1001');
        $now = $app->clock->now()->getTimestamp();
        return (new JobRepository($app->db, $app->clock))->enqueue('-1001', $kind, $event, $runAt ?? $now, $expires ?? $now + 7200, $group['revision'], ['text' => 'fixture']);
    }
    public function testCalendarEvaluatesAfterSlowProviderCrossesShabbatEntry(): void
    {
        $clock = new FrozenClock(new \DateTimeImmutable('2026-10-16T17:19:59+03:00'));
        $http = new class ($clock) implements JsonClient {
            public function __construct(private FrozenClock $clock)
            {
            }
            public function get(string $url, array $query = [], ?\Amp\Cancellation $cancellation = null): array
            {
                if (str_ends_with($url, '/zmanim')) {
                    $this->clock->set($this->clock->now()->modify('+2 seconds'));
                }
                return (new RuntimeFakeJson())->get($url, $query);
            }
        };
        $db = Database::open(':memory:');
        $db->migrate();
        $settings = new \Shabbat\Storage\SettingsRepository($db, $clock);
        $group = $settings->ensureGroup('-1');
        $calendar = new CalendarService(new HebcalProvider($http), $clock);
        $historical = $clock->now();
        self::assertTrue($calendar->snapshotNow($group)['quiet_now']);
        self::assertFalse($calendar->snapshot($group, $historical)['quiet_now']);
    }
    public function testSecondEligibilityCacheMissCannotSendUsingOldTimestamp(): void
    {
        [$app, $clock, $db, $gateway, $group] = $this->app('2026-10-16 17:19:59', ['zmanim_enabled' => true, 'zmanim_time' => '17:00']);
        $boundary = (new \DateTimeImmutable('2026-10-16T17:20:00+03:00'))->getTimestamp();
        $client = new class ($clock) implements JsonClient {
            public bool $advance = false;
            public function __construct(private FrozenClock $clock)
            {
            }
            public function get(string $url, array $query = [], ?\Amp\Cancellation $cancellation = null): array
            {
                if ($this->advance) {
                    $this->advance = false;
                    $this->clock->set($this->clock->now()->modify('+2 seconds'));
                }
                return (new RuntimeFakeJson())->get($url, $query);
            }
        };
        $calendar = new CalendarService(new HebcalProvider($client), $clock);
        $app = new Application($gateway, $clock, $db, $calendar);
        $id = $this->job($app, 'zmanim');
        $eligible = (new \ReflectionMethod($app, 'eligible'))->getClosure($app);
        $dispatch = (new \ReflectionMethod($app, 'dispatchEligible'))->getClosure($app);
        $jobs = new JobRepository($db, $clock);
        $worker = new OutboxWorker($jobs, $app->settings, $gateway, $clock, $eligible, static function () use ($client, $calendar): array {
            (new \ReflectionProperty($calendar, 'cache'))->setValue($calendar, []);
            $client->advance = true;
            return ['text' => 'ready'];
        }, null, $dispatch);
        self::assertSame('cancelled', $worker->runOnce('owner')['status']);
        self::assertSame($boundary + 1, $clock->now()->getTimestamp());
        self::assertSame([], $gateway->sent);
        self::assertSame('cancelled', $jobs->get($id)['status']);
    }
    public function testActualQuietHoursDeferPreviouslyPlannedReminderAndResume(): void
    {
        [$app, $clock, $db, $gateway] = $this->app('2026-10-15 09:20:00', ['tefillin_enabled' => true, 'quiet_start' => '09:15', 'quiet_end' => '10:00']);
        $id = $this->job($app, 'tefillin', runAt: $clock->now()->modify('-20 minutes')->getTimestamp());
        self::assertSame('deferred', $this->worker($app)->runOnce('owner')['status']);
        self::assertSame([], $gateway->sent);
        $jobs = new JobRepository($db, $clock);
        self::assertSame($clock->now()->modify('+40 minutes')->getTimestamp(), $jobs->get($id)['retry_at']);
        $clock->set($clock->now()->modify('+40 minutes'));
        self::assertSame('sent', $this->worker($app)->runOnce('owner')['status']);
        self::assertCount(1, $gateway->sent);
    }
    public function testFinalEvidenceRejectsBoundaryRevisionRollbackAndShutdown(): void
    {
        [$app, $clock, $db, , $group] = $this->app('2026-10-16 17:19:59', ['zmanim_enabled' => true]);
        $id = $this->job($app, 'zmanim');
        $job = (new JobRepository($db, $clock))->get($id);
        $eligible = new \ReflectionMethod($app, 'eligible');
        $dispatch = new \ReflectionMethod($app, 'dispatchEligible');
        self::assertTrue($eligible->invoke($app, $job, $group, $clock->now()));
        self::assertTrue($dispatch->invoke($app, $job, $group, $clock->now()));
        self::assertFalse($dispatch->invoke($app, $job, $group, $clock->now()->modify('+1 second')));
        self::assertFalse($dispatch->invoke($app, $job, $group, $clock->now()->modify('-1 second')));
        $changed = $group;
        $changed['revision']++;
        self::assertFalse($dispatch->invoke($app, $job, $changed, $clock->now()));
        $app->stop();
        self::assertFalse($dispatch->invoke($app, $job, $group, $clock->now()));
    }
    public function testPersistedTefillinBacklogCoalescesThenActualGapApplies(): void
    {
        [$app, $clock, $db, $gateway] = $this->app('2026-10-15 11:30:00', ['tefillin_enabled' => true, 'tefillin_min_spacing_minutes' => 30]);
        foreach (['09:00', '10:00', '11:00'] as $time) {
            $this->job($app, 'tefillin', $time, (new \DateTimeImmutable('2026-10-15 ' . $time, new \DateTimeZone('Asia/Jerusalem')))->getTimestamp());
        }
        self::assertSame('sent', $this->worker($app)->runOnce('owner')['status']);
        self::assertNull($this->worker($app)->runOnce('owner'));
        self::assertCount(1, $gateway->sent);
        $clock->set($clock->now()->modify('+10 minutes'));
        $this->job($app, 'tefillin', 'new-reminder');
        self::assertSame('deferred', $this->worker($app)->runOnce('owner')['status']);
        self::assertCount(1, $gateway->sent);
        $clock->set($clock->now()->modify('+20 minutes'));
        self::assertSame('sent', $this->worker($app)->runOnce('owner')['status']);
    }
    public function testNotificationsTraverseAllGroupsFairlyAndCannotRefreshPermissionHeartbeat(): void
    {
        [$app, $clock, $db, $gateway] = $this->app('2026-10-15 09:00:00', ['zmanim_enabled' => true, 'zmanim_time' => '09:00']);
        for ($i = 2; $i <= 61; $i++) {
            $app->settings->ensureGroup('-' . (1000 + $i), 'Group ' . $i, ['zmanim_enabled' => true, 'zmanim_time' => '09:00']);
        }
        $app->permissionTick();
        $firstHeartbeat = json_decode(file_get_contents($this->heartbeat), true);
        for ($i = 0; $i < 20 && count($gateway->sent) < 61; $i++) {
            $clock->set($clock->now()->modify('+1 second'));
            $app->notificationTick();
        }
        self::assertCount(61, $gateway->sent);
        self::assertCount(61, array_unique(array_column($gateway->sent, 0)));
        $lastHeartbeat = json_decode(file_get_contents($this->heartbeat), true);
        self::assertSame($firstHeartbeat['permission_timestamp'], $lastHeartbeat['permission_timestamp']);
        self::assertGreaterThan($firstHeartbeat['permission_timestamp'], $lastHeartbeat['notification_timestamp']);
        self::assertLessThanOrEqual(20, $lastHeartbeat['cycles']['notifications']['groups_planned']);
    }
    public function testPermissionPassBoundsConcurrentNetworkWorkAndJoinsIt(): void
    {
        [$app, , , $gateway] = $this->app('2026-10-16 17:25:00');
        for ($i = 2; $i <= 12; $i++) {
            $app->settings->ensureGroup('-' . (1000 + $i));
        }
        $active = 0;
        $max = 0;
        $gateway->beforeSetPermissions = static function () use (&$active, &$max): void {
            $active++;
            $max = max($max, $active);
            \Amp\delay(0.001);
            $active--;
        };
        $app->permissionTick();
        self::assertSame(4, $max);
        self::assertSame(0, $active);
        self::assertSame(12, $gateway->mutations);
        self::assertSame([], $gateway->sent);
    }
    public function testOmerPlannerReceivesQuietHoursAndShutdownStopsEveryLane(): void
    {
        require_once __DIR__ . '/Fixtures/CalendarFixtureClient.php';
        [$app, , $db] = $this->app('2026-04-13 17:00:00', ['omer_enabled' => true, 'omer_mode' => 'nightfall', 'omer_after_nightfall_minutes' => 0, 'quiet_start' => '18:30', 'quiet_end' => '19:30'], new CalendarFixtureClient());
        $app->notificationTick();
        self::assertSame(0, $db->pdo()->query("SELECT COUNT(*) FROM scheduled_jobs WHERE module='omer'")->fetchColumn());
        $app->stop();
        $app->permissionTick();
        $app->notificationTick();
        self::assertSame(0, $db->pdo()->query('SELECT COUNT(*) FROM group_lock_state')->fetchColumn());
    }
}
