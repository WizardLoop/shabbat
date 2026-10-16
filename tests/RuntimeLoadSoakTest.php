<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace Shabbat\Tests;

use Amp\DeferredFuture;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Shabbat\Calendar\CalendarService;
use Shabbat\Calendar\HebcalProvider;
use Shabbat\Runtime\Application;
use Shabbat\Runtime\Runner;
use Shabbat\Scheduling\JobRepository;
use Shabbat\Storage\Database;
use Shabbat\Time\FrozenClock;

final class RuntimeLoadSoakTest extends TestCase
{
    private Database $db;
    private FrozenClock $clock;
    private RuntimeLoadGateway $gateway;
    private Application $app;
    private string $heartbeat;
    private string|false $previousHeartbeat;
    private CalendarService $calendar;
    protected function setUp(): void
    {
        (new \ReflectionProperty(Runner::class, 'stopping'))->setValue(null, false);
        $this->db = Database::open(':memory:');
        $this->db->migrate();
        $this->clock = new FrozenClock(new DateTimeImmutable('2026-10-15T09:00:00+03:00'));
        $this->gateway = new RuntimeLoadGateway();
        require_once __DIR__ . '/Fixtures/CalendarFixtureClient.php';
        $this->calendar = new CalendarService(new HebcalProvider(new CalendarFixtureClient()), $this->clock);
        $this->heartbeat = sys_get_temp_dir() . '/shabbat-load-' . bin2hex(random_bytes(8)) . '.json';
        $this->previousHeartbeat = getenv('HEARTBEAT_PATH');
        putenv('HEARTBEAT_PATH=' . $this->heartbeat);
        $this->app = new Application($this->gateway, $this->clock, $this->db, $this->calendar);
    }
    protected function tearDown(): void
    {
        $this->app->stop();
        if (is_file($this->heartbeat)) {
            unlink($this->heartbeat);
        }
        putenv($this->previousHeartbeat === false ? 'HEARTBEAT_PATH' : 'HEARTBEAT_PATH=' . $this->previousHeartbeat);
        (new \ReflectionProperty(Runner::class, 'stopping'))->setValue(null, false);
    }
    private function seedGroups(int $count, array $changes = []): void
    {
        for ($i = 1; $i <= $count; $i++) {
            $group = $this->app->settings->ensureGroup('-' . $i, 'Synthetic group ' . $i);
            if ($changes !== []) {
                $this->app->settings->updateGroup($group['group_id'], $group['revision'], $changes, '42');
            }
        }
    }
    public function testFiveHundredGroupsUseFourConcurrentPermissionSlotsAndRestoreExactly(): void
    {
        $this->seedGroups(500);
        $this->clock->set(new DateTimeImmutable('2026-10-16T17:45:00+03:00'));
        $this->gateway->permissionDelay = 0.001;
        $this->gateway->failGroup = '-125';
        $this->gateway->onPermissionIo = function (): void {
            // Opening a local transaction here would throw if a scheduler transaction spanned I/O.
            $this->db->transaction(static fn() => null);
        };
        $this->app->permissionTick();
        self::assertSame(4, $this->gateway->maxPermissionIo);
        self::assertSame(0, $this->gateway->activePermissionIo);
        self::assertCount(499, $this->gateway->mutations);
        self::assertSame('error', $this->app->locks->state('-125')['status']);
        self::assertSame(499, (int)$this->db->pdo()->query("SELECT COUNT(*) FROM group_lock_state WHERE status='locked'")->fetchColumn());
        self::assertSame([], $this->gateway->sent);
        $this->clock->set(new DateTimeImmutable('2026-10-18T10:00:00+03:00'));
        $this->gateway->failGroup = null;
        $this->app->permissionTick();
        self::assertCount(998, $this->gateway->mutations);
        foreach ($this->gateway->rights as $rights) {
            self::assertSame(RuntimeLoadGateway::original(), $rights);
        }
        self::assertSame(4, $this->gateway->maxPermissionIo);
        self::assertSame([], $this->db->integrity()['foreign_key_check']);
    }
    public function testFiveHundredReminderGroupsArePlannedFairlyAndRepeatedCyclesDoNotDuplicate(): void
    {
        $this->seedGroups(500, ['zmanim_enabled' => true,'zmanim_time' => '09:00']);
        $cycles = 0;
        do {
            $this->app->notificationTick();
            $heartbeat = json_decode(file_get_contents($this->heartbeat), true, 512, JSON_THROW_ON_ERROR);
            self::assertLessThanOrEqual(20, $heartbeat['cycles']['notifications']['groups_planned']);
            self::assertLessThanOrEqual(20, $heartbeat['cycles']['notifications']['jobs_processed']);
            $cycles++;
        } while (count($this->gateway->sent) < 500 && $cycles < 100);
        self::assertCount(500, $this->gateway->sent);
        self::assertCount(500, array_unique(array_column($this->gateway->sent, 0)));
        self::assertCount(500, array_unique(array_column($this->gateway->sent, 2)));
        for ($i = 0; $i < 30; $i++) {
            $this->app->notificationTick();
        }
        self::assertCount(500, $this->gateway->sent);
        self::assertSame(500, (int)$this->db->pdo()->query("SELECT COUNT(*) FROM scheduled_jobs WHERE status='sent'")->fetchColumn());
    }
    public function testBlockedNotificationPreflightDoesNotBlockPermissionCycle(): void
    {
        $this->seedGroups(2);
        $g = $this->app->settings->getGroup('-1');
        $this->app->settings->updateGroup('-1', $g['revision'], ['zmanim_enabled' => true,'zmanim_time' => '09:00'], '42');
        $g = $this->app->settings->getGroup('-2');
        $this->app->settings->updateGroup('-2', $g['revision'], ['manual_override_mode' => 'lock','manual_override_until' => $this->clock->now()->getTimestamp() + 3600], '42');
        $entered = new DeferredFuture();
        $release = new DeferredFuture();
        $this->gateway->beforeSend = static function () use ($entered, $release): void {
            if (!$entered->isComplete()) {
                $entered->complete();
            }
            $release->getFuture()->await();
        };
        $notification = \Amp\async($this->app->notificationTick(...));
        try {
            $entered->getFuture()->await(new \Amp\TimeoutCancellation(10));
            $permission = \Amp\async($this->app->permissionTick(...));
            $permission->await(new \Amp\TimeoutCancellation(10));
            self::assertFalse($notification->isComplete());
            self::assertSame('locked', $this->app->locks->state('-2')['status']);
            self::assertSame([], $this->gateway->sent);
        } finally {
            $release->complete();
            $notification->await();
        }
        self::assertCount(1, $this->gateway->sent);
    }
    public function testPermissionBatchWaitDoesNotBlockIndependentNotificationCycle(): void
    {
        $this->seedGroups(2);
        $g = $this->app->settings->getGroup('-1');
        $this->app->settings->updateGroup('-1', $g['revision'], ['zmanim_enabled' => true,'zmanim_time' => '09:00'], '42');
        $g = $this->app->settings->getGroup('-2');
        $this->app->settings->updateGroup('-2', $g['revision'], ['manual_override_mode' => 'lock','manual_override_until' => $this->clock->now()->getTimestamp() + 3600], '42');
        $entered = new DeferredFuture();
        $release = new DeferredFuture();
        $this->gateway->onPermissionIo = static function (string $group, string $kind) use ($entered, $release): void {
            if ($group === '-2' && $kind === 'write') {
                $entered->complete();
                $release->getFuture()->await();
            }
        };
        $permission = \Amp\async($this->app->permissionTick(...));
        try {
            $entered->getFuture()->await(new \Amp\TimeoutCancellation(10));
            $notification = \Amp\async($this->app->notificationTick(...));
            $notification->await(new \Amp\TimeoutCancellation(10));
            self::assertFalse($permission->isComplete());
            self::assertCount(1, $this->gateway->sent);
        } finally {
            $release->complete();
            $permission->await();
        }
        self::assertSame('locked', $this->app->locks->state('-2')['status']);
    }
    public function testFortyFiveDayApplicationSoakNeverSendsRoutineMessagesDuringSyntheticHolyWindows(): void
    {
        $this->seedGroups(4, ['zmanim_enabled' => true,'zmanim_time' => '09:00']);
        $jobs = new JobRepository($this->db, $this->clock);
        $expected = 0;
        $maxRetained = 0;
        $date = new DateTimeImmutable('2026-05-10T09:00:00+03:00');
        for ($day = 0; $day < 45; $day++) {
            $this->clock->set($date->modify('+' . $day . ' days'));
            $state = $this->calendar->snapshotNow($this->app->settings->getGroup('-1'));
            if (!$state['quiet_now'] && !$state['is_work_prohibited_now']) {
                $expected += 4;
            }
            $this->app->tick();
            $this->app->tick();
            self::assertCount($expected, $this->gateway->sent);
            $this->clock->set($this->clock->now()->setTimezone(new \DateTimeZone('Asia/Jerusalem'))->setTime(17, 45));
            $this->app->tick();
            $this->clock->set($this->clock->now()->setTimezone(new \DateTimeZone('Asia/Jerusalem'))->setTime(20, 0));
            $this->app->tick();
            self::assertCount($expected, $this->gateway->sent);
            $jobs->maintain(30, 200);
            $retained = (int)$this->db->pdo()->query('SELECT COUNT(*) FROM scheduled_jobs')->fetchColumn();
            $maxRetained = max($maxRetained, $retained);
            self::assertLessThan(220, $retained);
        }
        self::assertGreaterThan(100, $expected);
        self::assertLessThan(220, $maxRetained);
        self::assertSame([], $this->db->integrity()['foreign_key_check']);
        self::assertSame(['ok'], $this->db->integrity()['integrity_check']);
    }
}
