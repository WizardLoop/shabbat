<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-only
namespace Shabbat\Tests;

use Closure;
use DateTimeImmutable;
use InvalidArgumentException;
use PDOException;
use RuntimeException;
use Amp;
use PHPUnit\Framework\TestCase;
use Shabbat\Storage\Database;
use Shabbat\Storage\SettingsRepository;
use Shabbat\Scheduling\JobRepository;
use Shabbat\Scheduling\LockCoordinator;
use Shabbat\Scheduling\OutboxWorker;
use Shabbat\Scheduling\Permissions;
use Shabbat\Telegram\TelegramGateway;
use Shabbat\Telegram\RetryableTelegramException;
use Shabbat\Time\FrozenClock;

final class SchedulerTest extends TestCase
{
    private Database $db;
    private FrozenClock $clock;
    private SettingsRepository $settings;
    private SchedulerFakeGateway $gateway;
    protected function setUp(): void
    {
        $this->db = Database::open(':memory:');
        $this->db->migrate();
        $this->clock = new FrozenClock(new DateTimeImmutable('2026-10-13T09:00:00Z'));
        $this->settings = new SettingsRepository($this->db, $this->clock);
        $this->gateway = new SchedulerFakeGateway();
        $this->settings->ensureGroup('-1');
        $this->settings->ensureGroup('-2');
    }
    public function testDurableSnapshotExistsBeforeFirstLockAndRestoreKeepsOriginalRestrictions(): void
    {
        $locks = new LockCoordinator($this->db, $this->clock, $this->gateway);
        $original = $this->gateway->getPermissions('-1');
        $this->gateway->onSet = function (string $id, array $rights): void {
            $state = $this->db->pdo()->query("SELECT * FROM group_lock_state WHERE group_id='-1'")->fetch();
            self::assertNotNull($state['snapshot_json']);
            self::assertNotNull($state['snapshot_sha256']);
        };
        self::assertSame(['status' => 'locked','changed' => true], $locks->reconcile('-1', 'event-current', true));
        $this->clock->set(new DateTimeImmutable('2026-10-20T09:00:00Z'));
        self::assertSame(['status' => 'restored','changed' => true], $locks->reconcile('-1', 'event-old', false));
        self::assertSame($original, $this->gateway->getPermissions('-1'));
    }
    public function testNewEventDoesNotOpenOldLockInMiddleOfHolidaySequence(): void
    {
        $locks = new LockCoordinator($this->db, $this->clock, $this->gateway);
        $locks->reconcile('-1', 'shabbat', true);
        $snapshot = $locks->state('-1')['snapshot_json'];
        $this->clock->set(new DateTimeImmutable('2026-10-14T09:00:00Z'));
        self::assertFalse($locks->reconcile('-1', 'yom-tov', true)['changed']);
        self::assertSame($snapshot, $locks->state('-1')['snapshot_json']);
        self::assertCount(1, $this->gateway->changes);
        self::assertSame('yom-tov', $locks->state('-1')['event_key']);
    }
    public function testSameCoordinatorCannotOverlapPermissionChanges(): void
    {
        $locks = new LockCoordinator($this->db, $this->clock, $this->gateway);
        $this->gateway->onSet = function () use ($locks): void {
            self::assertSame('busy', $locks->reconcile('-1', 'event', true)['status']);
        };
        self::assertSame('locked', $locks->reconcile('-1', 'event', true)['status']);
        self::assertCount(1, $this->gateway->changes);
    }
    public function testPrelockedGroupIsNeverOpenedByBot(): void
    {
        $this->gateway->permissions['-1'] = ['_' => 'chatBannedRights','send_messages' => true];
        $locks = new LockCoordinator($this->db, $this->clock, $this->gateway);
        self::assertSame('prelocked', $locks->reconcile('-1', 'event', true)['status']);
        self::assertSame('prelocked', $locks->reconcile('-1', 'old-event', false)['status']);
        self::assertCount(0, $this->gateway->changes);
    }
    public function testManualPermissionConflictRemainsVisibleAndIsNotOverwritten(): void
    {
        $locks = new LockCoordinator($this->db, $this->clock, $this->gateway);
        $locks->reconcile('-1', 'event', true);
        $this->gateway->permissions['-1']['send_photos'] = false;
        self::assertSame('conflict', $locks->reconcile('-1', 'event', false)['status']);
        self::assertCount(1, $this->gateway->changes);
        self::assertNotNull($locks->state('-1')['snapshot_json']);
    }
    public function testCorruptSnapshotAndDatabaseWriteFailureNeverGuessPermissions(): void
    {
        $locks = new LockCoordinator($this->db, $this->clock, $this->gateway);
        $locks->reconcile('-1', 'event', true);
        $this->db->pdo()->exec("UPDATE group_lock_state SET snapshot_json='{}' WHERE group_id='-1'");
        self::assertSame('error', $locks->reconcile('-1', 'event', false)['status']);
        self::assertCount(1, $this->gateway->changes);
        $this->db->pdo()->exec("CREATE TRIGGER reject_snapshot BEFORE UPDATE OF snapshot_json ON group_lock_state WHEN NEW.group_id='-2' BEGIN SELECT RAISE(FAIL,'simulated disk failure'); END");
        self::assertSame('error', $locks->reconcile('-2', 'event', true)['status']);
        self::assertCount(1, $this->gateway->changes);
    }
    public function testPartialGroupFailureDoesNotBlockOtherGroupAndCrashAfterRpcIsRecoverable(): void
    {
        $locks = new LockCoordinator($this->db, $this->clock, $this->gateway);
        $this->gateway->onSet = function (string $id, array $rights): void {
            if ($id === '-1') {
                $this->gateway->permissions[$id] = $rights;
                throw new RuntimeException('lost Telegram response');
            }
        };
        self::assertSame('error', $locks->reconcile('-1', 'event', true)['status']);
        self::assertSame('locked', $locks->reconcile('-2', 'event', true)['status']);
        $this->gateway->onSet = null;
        self::assertSame(['status' => 'locked','changed' => false], $locks->reconcile('-1', 'event', true));
        self::assertSame('restored', $locks->reconcile('-1', 'event', false)['status']);
    }
    public function testLateDueDeliveryAndExpiredDeliveryHaveDifferentOutcomes(): void
    {
        $settings = $this->settings->updateGroup('-1', 1, ['daf_enabled' => true], '17');
        $jobs = new JobRepository($this->db, $this->clock);
        $now = $this->clock->now()->getTimestamp();
        $expired = $jobs->enqueue('-1', 'daf', 'old-date', $now - 3600, $now - 1, $settings['revision'], ['text' => 'Expired']);
        $due = $jobs->enqueue('-1', 'daf', 'today', $now - 600, $now + 60, $settings['revision'], ['text' => 'Late but valid']);
        $worker = new OutboxWorker($jobs, $this->settings, $this->gateway, $this->clock, static fn(): bool=>true);
        self::assertSame('sent', $worker->runOnce('worker')['status']);
        self::assertSame('expired', $jobs->get($expired)['status']);
        self::assertSame('sent', $jobs->get($due)['status']);
        self::assertCount(1, $this->gateway->sends);
    }
    public function testLostResponseRetriesReuseRandomIdAndDoNotBlockOtherJobs(): void
    {
        $settings = $this->settings->updateGroup('-1', 1, ['daf_enabled' => true], '17');
        $jobs = new JobRepository($this->db, $this->clock);
        $now = $this->clock->now()->getTimestamp();
        $id = $jobs->enqueue('-1', 'daf', 'today', $now, $now + 1000, $settings['revision'], ['text' => 'Daf']);
        $this->gateway->onSend = function (): array {
            throw new RetryableTelegramException('FLOOD_WAIT_30', 30);
        };
        $worker = new OutboxWorker($jobs, $this->settings, $this->gateway, $this->clock, static fn(): bool=>true);
        self::assertSame('retry', $worker->runOnce('worker')['status']);
        self::assertNull($worker->runOnce('worker'));
        $this->clock->set(new DateTimeImmutable('2026-10-13T09:00:31Z'));
        $this->gateway->onSend = null;
        self::assertSame('sent', $worker->runOnce('worker')['status']);
        self::assertSame($this->gateway->sends[0][2], $this->gateway->sends[1][2]);
        self::assertSame(2, $jobs->get($id)['attempts']);
    }
    public function testRevisionChangeCancelsPendingContentAndKeepsLockRecovery(): void
    {
        $settings = $this->settings->updateGroup('-1', 1, ['daf_enabled' => true], '17');
        $jobs = new JobRepository($this->db, $this->clock);
        $now = $this->clock->now()->getTimestamp();
        $id = $jobs->enqueue('-1', 'daf', 'today', $now, $now + 1000, $settings['revision'], ['text' => 'Daf']);
        $locks = new LockCoordinator($this->db, $this->clock, $this->gateway);
        $locks->reconcile('-1', 'event', true);
        $this->settings->updateGroup('-1', 2, ['location_name' => 'New city'], '18');
        self::assertSame('cancelled', $jobs->get($id)['status']);
        self::assertSame('locked', $locks->state('-1')['status']);
        self::assertNull((new OutboxWorker($jobs, $this->settings, $this->gateway, $this->clock, static fn(): bool=>true))->runOnce('worker'));
    }
    public function testCutoffIsRecheckedAfterEligibilityImmediatelyBeforeTelegram(): void
    {
        $settings = $this->settings->updateGroup('-1', 1, ['tefillin_enabled' => true], '17');
        $jobs = new JobRepository($this->db, $this->clock);
        $now = $this->clock->now()->getTimestamp();
        $id = $jobs->enqueue('-1', 'tefillin', 'today', $now, $now + 30, $settings['revision'], ['text' => 'Tefillin']);
        $worker = new OutboxWorker($jobs, $this->settings, $this->gateway, $this->clock, function (): bool {
            $this->clock->set(new DateTimeImmutable('2026-10-13T09:00:31Z'));
            return true;
        });
        self::assertSame('cancelled', $worker->runOnce('worker')['status']);
        self::assertSame('cancelled', $jobs->get($id)['status']);
        self::assertCount(0, $this->gateway->sends);
    }
    public function testLeasesRecoverAfterCrashAndLogicalEventIsUnique(): void
    {
        $jobs = new JobRepository($this->db, $this->clock);
        $now = $this->clock->now()->getTimestamp();
        $id = $jobs->enqueue('-1', 'daf', 'today', $now, $now + 1000, 1, ['text' => 'One']);
        self::assertSame($id, $jobs->enqueue('-1', 'daf', 'today', $now, $now + 1000, 1, ['text' => 'Duplicate']));
        $first = $jobs->lease('one', 20);
        self::assertNull($jobs->lease('two'));
        $this->clock->set(new DateTimeImmutable('2026-10-13T09:00:21Z'));
        $second = $jobs->lease('two');
        self::assertSame($first['random_id'], $second['random_id']);
        self::assertSame(2, $second['attempts']);
        self::assertSame('uncertain', $this->db->pdo()->query('SELECT outcome FROM delivery_log ORDER BY delivery_id LIMIT 1')->fetchColumn());
    }
    public function testSendTimePayloadPersistsOnceAndRetriesRetainSameTextAndRandomId(): void
    {
        $g = $this->settings->updateGroup('-1', 1, ['daf_enabled' => true], '17');
        $jobs = new JobRepository($this->db, $this->clock);
        $now = $this->clock->now()->getTimestamp();
        $id = $jobs->enqueue('-1', 'daf', 'today', $now, $now + 1000, $g['revision'], ['text' => 'enqueue-time']);
        $factoryCalls = 0;
        $factory = function (array $job, array $settings, DateTimeImmutable $now) use (&$factoryCalls): array {
            $this->db->transaction(static fn(): null=>null);
            $factoryCalls++;
            return ['text' => 'send-time ' . $now->format('H:i:s')];
        };
        $worker = new OutboxWorker($jobs, $this->settings, $this->gateway, $this->clock, static fn(): bool=>true, $factory);
        $this->gateway->onSend = static function (): array {
            throw new RetryableTelegramException('lost response', 30);
        };
        self::assertSame('retry', $worker->runOnce('worker')['status']);
        $prepared = $jobs->get($id);
        self::assertNotNull($prepared['payload_prepared_at']);
        $this->clock->set(new DateTimeImmutable('2026-10-13T09:00:31Z'));
        $this->gateway->onSend = null;
        self::assertSame('sent', $worker->runOnce('worker')['status']);
        self::assertSame(1, $factoryCalls);
        self::assertSame($this->gateway->sends[0][1], $this->gateway->sends[1][1]);
        self::assertSame($this->gateway->sends[0][2], $this->gateway->sends[1][2]);
    }
    public function testPayloadFactoryPassingCutoffDoesNotStartTelegramRpc(): void
    {
        $g = $this->settings->updateGroup('-1', 1, ['daf_enabled' => true], '17');
        $jobs = new JobRepository($this->db, $this->clock);
        $now = $this->clock->now()->getTimestamp();
        $jobs->enqueue('-1', 'daf', 'today', $now, $now + 30, $g['revision'], ['text' => 'enqueue-time']);
        $worker = new OutboxWorker(
            $jobs,
            $this->settings,
            $this->gateway,
            $this->clock,
            static fn(): bool=>true,
            function (): array {
                $this->clock->set(new DateTimeImmutable('2026-10-13T09:00:31Z'));
                return ['text' => 'too late'];
            }
        );
        $worker->runOnce('worker');
        self::assertCount(0, $this->gateway->sends);
    }
    public function testFailedFinalPayloadPersistenceCannotSendAndCanRecoverBeforeCutoff(): void
    {
        $g = $this->settings->updateGroup('-1', 1, ['daf_enabled' => true], '17');
        $jobs = new JobRepository($this->db, $this->clock);
        $now = $this->clock->now()->getTimestamp();
        $id = $jobs->enqueue('-1', 'daf', 'today', $now, $now + 1000, $g['revision'], ['text' => 'enqueue-time']);
        $this->db->pdo()->exec("CREATE TRIGGER reject_payload BEFORE UPDATE OF payload_json ON scheduled_jobs BEGIN SELECT RAISE(FAIL,'simulated disk failure'); END");
        $worker = new OutboxWorker($jobs, $this->settings, $this->gateway, $this->clock, static fn(): bool=>true, static fn(): array=>['text' => 'send-time']);
        self::assertSame('retry', $worker->runOnce('worker')['status']);
        self::assertCount(0, $this->gateway->sends);
        self::assertNull($jobs->get($id)['payload_prepared_at']);
        $this->db->pdo()->exec('DROP TRIGGER reject_payload');
        $this->clock->set(new DateTimeImmutable('2026-10-13T09:00:31Z'));
        self::assertSame('sent', $worker->runOnce('worker')['status']);
        self::assertSame('send-time', $this->gateway->sends[0][1]['text']);
    }
    public function testShutdownStopsNewWorkAndCancellationPropagates(): void
    {
        $settings = $this->settings->updateGroup('-1', 1, ['daf_enabled' => true], '17');
        $jobs = new JobRepository($this->db, $this->clock);
        $now = $this->clock->now()->getTimestamp();
        $id = $jobs->enqueue('-1', 'daf', 'today', $now, $now + 1000, $settings['revision'], ['text' => 'One']);
        $worker = new OutboxWorker($jobs, $this->settings, $this->gateway, $this->clock, static fn(): bool=>true);
        $worker->stop();
        self::assertNull($worker->runOnce('worker'));
        self::assertSame('pending', $jobs->get($id)['status']);
        $this->gateway->onSend = static function (): array {
            throw new Amp\CancelledException();
        };
        $active = new OutboxWorker($jobs, $this->settings, $this->gateway, $this->clock, static fn(): bool=>true);
        $this->expectException(Amp\CancelledException::class);
        $active->runOnce('worker');
    }
}
