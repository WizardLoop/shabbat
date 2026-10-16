<?php

// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);

namespace Shabbat\Tests;

use PHPUnit\Framework\TestCase;
use Shabbat\Runtime\{Application,Runner};
use Shabbat\Storage\Database;
use Shabbat\Time\FrozenClock;
use Shabbat\Calendar\{CalendarService,HebcalProvider};
use Shabbat\Http\JsonClient;
use Shabbat\Telegram\TelegramGateway;
use Shabbat\UI\UiGateway;

final class RuntimeApplicationTest extends TestCase
{
    private Database $db;
    private FrozenClock $clock;
    private RuntimeFakeGateway $gateway;
    private CalendarService $calendar;
    private string $heartbeat;
    protected function setUp(): void
    {
        (new \ReflectionProperty(Runner::class, 'stopping'))->setValue(null, false);
        $this->db = Database::open(':memory:');
        $this->db->migrate();
        $this->clock = new FrozenClock(new \DateTimeImmutable('2026-10-16T17:45:00+03:00'));
        $this->gateway = new RuntimeFakeGateway();
        $this->calendar = new CalendarService(new HebcalProvider(new RuntimeFakeJson()), $this->clock);
        $this->heartbeat = sys_get_temp_dir() . '/shabbat-heartbeat-' . bin2hex(random_bytes(8)) . '.json';
        putenv('HEARTBEAT_PATH=' . $this->heartbeat);
    }
    protected function tearDown(): void
    {
        if (is_file($this->heartbeat)) {
            unlink($this->heartbeat);
        }putenv('HEARTBEAT_PATH');
        (new \ReflectionProperty(Runner::class, 'stopping'))->setValue(null, false);
    }
    private function app(): Application
    {
        return new Application($this->gateway, $this->clock, $this->db, $this->calendar);
    }
    public function testRuntimeClosesAndRestoresAcrossRestartLongAfterExit(): void
    {
        $app = $this->app();
        $app->settings->ensureGroup('-1001', 'Example');
        $app->tick();
        self::assertTrue($this->gateway->rights['-1001']['send_messages']);
        self::assertSame('locked', $app->locks->state('-1001')['status']);
        $original = $app->locks->state('-1001')['snapshot_json'];
        $app->tick();
        self::assertSame(1, $this->gateway->mutations);
        $this->clock->set(new \DateTimeImmutable('2026-10-18T10:00:00+03:00'));
        $restarted = $this->app();
        $restarted->tick();
        self::assertFalse($this->gateway->rights['-1001']['send_messages']);
        self::assertSame('restored', $restarted->locks->state('-1001')['status']);
        self::assertSame($original, $restarted->locks->state('-1001')['snapshot_json']);
        self::assertSame(2, $this->gateway->mutations);
        self::assertNotFalse(file_get_contents($this->heartbeat));
    }
    public function testOneGroupFailureDoesNotPreventOtherGroupsLocking(): void
    {
        $app = $this->app();
        $app->settings->ensureGroup('-1001', 'Failure');
        $app->settings->ensureGroup('-1002', 'Success');
        $this->gateway->fail = '-1001';
        $app->tick();
        self::assertSame('error', $app->locks->state('-1001')['status']);
        self::assertSame('locked', $app->locks->state('-1002')['status']);
        self::assertTrue($this->gateway->rights['-1002']['send_messages']);
        self::assertSame([], $this->gateway->sent);
    }
    public function testNoNewModulesAreSentWithoutConsent(): void
    {
        $app = $this->app();
        $app->settings->ensureGroup('-1001', 'Example');
        $this->clock->set(new \DateTimeImmutable('2026-10-15T09:00:00+03:00'));
        $app->tick();
        self::assertSame([], $this->gateway->sent);
        self::assertSame(0, $this->db->pdo()->query("SELECT count(*) FROM scheduled_jobs WHERE module IN ('zmanim','daf','tefillin','omer')")->fetchColumn());
    }
    public function testGroupLanguageIndependentFromUserForAutomaticPayload(): void
    {
        $app = $this->app();
        $g = $app->settings->ensureGroup('-1001', 'Example');
        $u = $app->settings->getUser('42');
        $app->settings->updateUser('42', $u['revision'], ['language' => 'en']);
        $payload = $app->payload($g, 'zmanim', new \DateTimeImmutable('2026-10-15T09:00:00+03:00'));
        self::assertStringContainsString('שקיעה', $payload['text']);
        self::assertStringNotContainsString('Sunset:', $payload['text']);
    }
    public function testTemporaryManualClosureSuppressesRoutineMessages(): void
    {
        $this->clock->set(new \DateTimeImmutable('2026-10-15T09:00:00+03:00'));
        $app = new Application($this->gateway, $this->clock, $this->db, $this->calendar, authorizeOperator:static fn(string $user): bool=>$user === '42');
        $g = $app->settings->ensureGroup('-1001', 'Example');
        $app->settings->updateGroup('-1001', $g['revision'], ['tefillin_enabled' => true,'tefillin_fixed_times' => ['09:00']], '42');
        $app->manualGroups->apply(true, '42');
        $app->tick();
        self::assertSame('locked', $app->locks->state('-1001')['status']);
        self::assertSame([], $this->gateway->sent);
    }
    public function testManualOpeningDoesNotAuthorizeReligiousQuietPeriodReminders(): void
    {
        $app = new Application($this->gateway, $this->clock, $this->db, $this->calendar, authorizeOperator:static fn(string $user): bool=>$user === '42');
        $g = $app->settings->ensureGroup('-1001', 'Example');
        $app->settings->updateGroup('-1001', $g['revision'], ['zmanim_enabled' => true,'zmanim_time' => '17:45'], '42');
        $app->tick();
        $app->manualGroups->apply(false, '42');
        $app->tick();
        self::assertFalse($this->gateway->rights['-1001']['send_messages']);
        self::assertSame([], $this->gateway->sent);
    }
    public function testAllSixGroupLanguagesRenderAutomaticNoticesWithoutPersonalFallback(): void
    {
        $app = $this->app();
        $g = $app->settings->ensureGroup('-1001', 'Example');
        $lang = new \Shabbat\UI\Lang();
        foreach (\Shabbat\UI\Lang::CODES as $code) {
            $g = $app->settings->updateGroup('-1001', $g['revision'], ['language' => $code], '42');
            self::assertSame($lang->text($code, 'group_close_notice'), $app->payload($g, 'close', $this->clock->now())['text']);
            self::assertSame($lang->text($code, 'group_open_notice'), $app->payload($g, 'open', $this->clock->now())['text']);
            self::assertStringContainsString($lang->text($code, 'sunset'), $app->payload($g, 'zmanim', $this->clock->now())['text']);
        }
    }
    public function testPermissionDesireIsReevaluatedWhenReadCrossesNextClosingBoundary(): void
    {
        $app = $this->app();
        $group = $app->settings->ensureGroup('-1001', 'Example');
        $this->clock->set(new \DateTimeImmutable('2026-10-09T17:45:00+03:00'));
        $app->tick();
        self::assertSame(1, $this->gateway->mutations);
        $state = $this->calendar->snapshot($group, new \DateTimeImmutable('2026-10-16T12:00:00+03:00'));
        $closing = $state['next_event']['close_at'];
        $this->clock->set(new \DateTimeImmutable('@' . ($closing - 10)));
        $this->gateway->beforeGetPermissions = function () use ($closing): void {
            $this->clock->set(new \DateTimeImmutable('@' . ($closing + 10)));
        };
        $app->tick();
        self::assertSame(1, $this->gateway->mutations);
        self::assertTrue($this->gateway->rights['-1001']['send_messages']);
        self::assertNotSame('restored', $app->locks->state('-1001')['status']);
    }
    public function testRoutineMessagesWaitForUnresolvedBotOwnedPermissionSnapshot(): void
    {
        $app = $this->app();
        $group = $app->settings->ensureGroup('-1001', 'Example');
        $app->settings->updateGroup('-1001', $group['revision'], ['zmanim_enabled' => true, 'zmanim_time' => '09:00'], '42');
        $app->tick();
        $this->clock->set(new \DateTimeImmutable('2026-10-18T09:00:00+03:00'));
        $this->gateway->fail = '-1001';
        $app->tick();
        self::assertSame([], $this->gateway->sent);
        $job = $this->db->pdo()->query("SELECT * FROM scheduled_jobs WHERE module='zmanim' AND event_key='zmanim:2026-10-18'")->fetch();
        self::assertIsArray($job);
        self::assertSame('pending', $job['status']);
        self::assertGreaterThanOrEqual(1, $job['deferrals']);
        $this->gateway->fail = null;
        $this->clock->set($this->clock->now()->modify('+60 seconds'));
        $app->tick();
        self::assertSame('restored', $app->locks->state('-1001')['status']);
        self::assertCount(1, $this->gateway->sent);
    }

    public function testLateActualRestorationDefersOmerUntilConfiguredGap(): void
    {
        require_once __DIR__ . '/Fixtures/CalendarFixtureClient.php';
        $calendar = new CalendarService(new HebcalProvider(new CalendarFixtureClient()), $this->clock);
        $app = new Application($this->gateway, $this->clock, $this->db, $calendar);
        $group = $app->settings->ensureGroup('-1001', 'Example');
        $app->settings->updateGroup('-1001', $group['revision'], ['omer_enabled' => true, 'omer_mode' => 'nightfall', 'omer_after_nightfall_minutes' => 0, 'omer_after_open_minutes' => 10], '42');
        $this->clock->set(new \DateTimeImmutable('2026-04-03T17:45:00+03:00'));
        $app->tick();
        $this->clock->set(new \DateTimeImmutable('2026-04-04T20:00:00+03:00'));
        $app->tick();
        $restored = $app->locks->state('-1001');
        self::assertSame('restored', $restored['status']);
        self::assertSame([], $this->gateway->sent);
        $job = $this->db->pdo()->query("SELECT * FROM scheduled_jobs WHERE module='omer' AND event_key='omer-night:2026-04-04'")->fetch();
        self::assertIsArray($job);
        self::assertSame('pending', $job['status']);
        self::assertSame($restored['restored_at'] + 600, $job['retry_at']);
        $this->clock->set($this->clock->now()->modify('+9 minutes'));
        $app->tick();
        self::assertSame([], $this->gateway->sent);
        $this->clock->set($this->clock->now()->modify('+1 minute'));
        $app->tick();
        self::assertCount(1, $this->gateway->sent);
        self::assertStringContainsString('היום', str_replace(['ַ', 'ָ', 'ֹ', 'ֵ', 'ֶ', 'ְ', 'ִ', 'ּ', 'ֻ', 'ֽ'], '', $this->gateway->sent[0][1]['text']));
    }

    public function testBroadcastPriorityWaitsForRunningTickAndFreshPermissionRead(): void
    {
        $app = $this->app();
        $app->settings->ensureGroup('-1001', 'Example');
        $entered = new \Amp\DeferredFuture();
        $gate = new \Amp\DeferredFuture();
        $events = [];
        $this->gateway->beforeGetPermissions = static function () use (&$events): void {
            $events[] = 'permission-read';
        };
        $this->gateway->beforeSetPermissions = function () use ($entered, $gate, &$events): void {
            self::assertFalse($this->db->pdo()->inTransaction());
            $events[] = 'lock-rpc-start';
            $entered->complete();
            $gate->getFuture()->await();
            $events[] = 'lock-rpc-complete';
        };
        $tick = \Amp\async($app->tick(...));
        $entered->getFuture()->await();
        $priority = \Amp\async(static function () use ($app, &$events): bool {
            $allowed = $app->prioritizePermissions();
            $events[] = 'broadcast-ready';
            return $allowed;
        });
        \Amp\delay(0.001);
        self::assertFalse($priority->isComplete());
        self::assertSame(0, $this->gateway->mutations);
        $gate->complete();
        $tick->await();
        self::assertTrue($priority->await());
        self::assertSame(1, $this->gateway->mutations);
        self::assertSame(4, count(array_filter($events, static fn(string $event): bool => $event === 'permission-read')));
        self::assertSame('permission-read', $events[count($events) - 2]);
        self::assertSame('broadcast-ready', end($events));
    }

    public function testBroadcastPriorityDoesNotStartFreshWorkAfterShutdown(): void
    {
        $app = $this->app();
        $app->settings->ensureGroup('-1001', 'Example');
        $app->tick();
        $reads = 0;
        $this->gateway->beforeGetPermissions = static function () use (&$reads): void {
            $reads++;
        };
        Runner::requestShutdown();
        self::assertFalse($app->prioritizePermissions());
        self::assertSame(0, $reads);
        self::assertSame(1, $this->gateway->mutations);
    }

    public function testRunningTickCancellationPropagatesToWaitingBroadcast(): void
    {
        $app = $this->app();
        $app->settings->ensureGroup('-1001', 'Example');
        $entered = new \Amp\DeferredFuture();
        $gate = new \Amp\DeferredFuture();
        $attempts = 0;
        $this->gateway->beforeSetPermissions = static function () use ($entered, $gate, &$attempts): void {
            $attempts++;
            $entered->complete();
            $gate->getFuture()->await();
            throw new \Amp\CancelledException(new \RuntimeException('deliberate fixture cancellation'));
        };
        $tick = \Amp\async($app->tick(...));
        $entered->getFuture()->await();
        $priority = \Amp\async($app->prioritizePermissions(...));
        \Amp\delay(0.001);
        $gate->complete();
        foreach ([$tick, $priority] as $future) {
            try {
                $future->await();
                self::fail('Cancellation was swallowed.');
            } catch (\Amp\CancelledException) {
                self::assertTrue(true);
            }
        }
        self::assertSame(1, $attempts);
        self::assertSame(0, $this->gateway->mutations);
    }

    public function testShutdownStopsNewWorkAndKeepsStoredSnapshot(): void
    {
        $app = $this->app();
        $app->settings->ensureGroup('-1001', 'Example');
        $app->tick();
        $state = $app->locks->state('-1001');
        Runner::requestShutdown();
        $this->clock->set(new \DateTimeImmutable('2026-10-18T10:00:00+03:00'));
        $app->tick();
        self::assertSame(1, $this->gateway->mutations);
        self::assertSame($state['snapshot_json'], $app->locks->state('-1001')['snapshot_json']);
    }
}
