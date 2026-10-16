<?php

// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);

namespace Shabbat\Tests;

use Amp\DeferredFuture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shabbat\Scheduling\LockCoordinator;
use Shabbat\Scheduling\Permissions;
use Shabbat\Storage\Database;
use Shabbat\Storage\SettingsRepository;
use Shabbat\Telegram\RetryableTelegramException;
use Shabbat\Time\FrozenClock;

final class PermissionRetryTest extends TestCase
{
    private Database $db;
    private FrozenClock $clock;
    private PermissionRetryFakeGateway $gateway;
    private string $path;
    protected function setUp(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'shabbat-permission-');
        if ($path === false) {
            throw new \RuntimeException('Cannot create isolated test database.');
        }$this->path = $path;
        $this->db = Database::open($path);
        $this->db->migrate();
        $this->clock = new FrozenClock(new \DateTimeImmutable('2026-10-13T09:00:00Z'));
        (new SettingsRepository($this->db, $this->clock))->ensureGroup('-1');
        $this->gateway = new PermissionRetryFakeGateway();
    }
    protected function tearDown(): void
    {
        unset($this->db);
        foreach ([$this->path,$this->path . '-wal',$this->path . '-shm'] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }
    public static function quotaPhases(): array
    {
        $cases = [];
        foreach (['read','preflight','mutation','readback'] as $phase) {
            foreach ([false,true] as $restore) {
                $cases[$phase . ($restore ? ' restore' : ' close')] = [$phase,$restore];
            }
        }return $cases;
    }
    #[DataProvider('quotaPhases')]
    public function testQuotaDeadlineSurvivesRestartAndNoPermissionRpcRunsBeforeIt(string $phase, bool $restore): void
    {
        $locks = new LockCoordinator($this->db, $this->clock, $this->gateway);
        $original = $this->gateway->getPermissions('-1');
        if ($restore) {
            $locks->reconcile('-1', 'old-event', true);
        }$this->gateway->resetActivity();
        $this->gateway->onGet = static function (string $group, int $reads) use ($phase): void {
            if (($phase === 'read' && $reads === 1) || ($phase === 'preflight' && $reads === 2) || ($phase === 'readback' && $reads === 3)) {
                throw new RetryableTelegramException('FLOOD_WAIT_30', 30);
            }
        };
        $this->gateway->onSet = static function (string $group, array $rights, int $writes) use ($phase): void {
            if ($phase === 'mutation' && $writes === 1) {
                throw new RetryableTelegramException('FLOOD_WAIT_30', 30);
            }
        };
        $first = $locks->reconcile('-1', 'event', !$restore);
        self::assertSame('retry_wait', $first['status']);
        self::assertFalse($first['changed']);
        self::assertSame($this->clock->now()->getTimestamp() + 30, $first['retry_at']);
        $snapshot = $locks->state('-1')['snapshot_json'];
        $signature = $locks->state('-1')['snapshot_sha256'];
        $calls = $this->gateway->reads + $this->gateway->writes;
        if ($restore || $phase !== 'read') {
            self::assertNotNull($snapshot);
            self::assertSame(hash('sha256', $snapshot), $signature);
        }
        // A new PDO connection and coordinator simulate a process restart against committed state.
        $reopened = Database::open($this->path);
        $reopened->migrate();
        $afterRestart = new LockCoordinator($reopened, $this->clock, $this->gateway);
        self::assertSame('retry_wait', $afterRestart->reconcile('-1', 'different-event', !$restore)['status']);
        $this->clock->set(new \DateTimeImmutable('2026-10-13T09:00:29Z'));
        self::assertSame('retry_wait', $afterRestart->reconcile('-1', 'event', !$restore)['status']);
        self::assertSame($calls, $this->gateway->reads + $this->gateway->writes);
        $this->gateway->onGet = null;
        $this->gateway->onSet = null;
        $this->clock->set(new \DateTimeImmutable('2026-10-13T09:00:30Z'));
        $result = $afterRestart->reconcile('-1', 'event', !$restore);
        self::assertSame($restore ? 'restored' : 'locked', $result['status']);
        self::assertNull($afterRestart->state('-1')['retry_at']);
        if ($snapshot !== null) {
            self::assertSame($snapshot, $afterRestart->state('-1')['snapshot_json']);
            self::assertSame($signature, $afterRestart->state('-1')['snapshot_sha256']);
        }
        if ($phase === 'readback') {
            self::assertFalse($result['changed']);
        }
        if ($restore) {
            self::assertSame($original, $this->gateway->permissions['-1']);
        } else {
            $afterRestart->reconcile('-1', 'event', false);
            self::assertSame($original, $this->gateway->permissions['-1']);
        }
        unset($afterRestart, $reopened);
    }
    public function testMissingSnapshotAndPartialFailuresNeverGuessOrDiscardRecovery(): void
    {
        $this->db->pdo()->exec("INSERT INTO group_lock_state(group_id,event_key,status,prepared_at) VALUES('-1','unknown','recovery_required',0)");
        $locks = new LockCoordinator($this->db, $this->clock, $this->gateway);
        $this->gateway->onGet = static function (): void {
            throw new RetryableTelegramException('FLOOD_WAIT_15', 15);
        };
        self::assertSame('retry_wait', $locks->reconcile('-1', 'new', false)['status']);
        $this->gateway->onGet = null;
        $this->clock->set(new \DateTimeImmutable('2026-10-13T09:00:15Z'));
        self::assertSame('recovery_required', $locks->reconcile('-1', 'new', false)['status']);
        self::assertSame(0, $this->gateway->writes);
        self::assertNull($locks->state('-1')['snapshot_json']);
    }
    public function testPartialMutationFailurePreservesSnapshotAndRecoversFromObservedState(): void
    {
        $locks = new LockCoordinator($this->db, $this->clock, $this->gateway);
        $this->gateway->onSet = function (string $group, array $rights): void {
            $this->gateway->permissions[$group] = $rights;
            throw new \RuntimeException('Lost RPC response after application');
        };
        self::assertSame('error', $locks->reconcile('-1', 'event', true)['status']);
        $snapshot = $locks->state('-1')['snapshot_json'];
        self::assertNotNull($snapshot);
        $this->gateway->onSet = null;
        $restarted = new LockCoordinator($this->db, $this->clock, $this->gateway);
        self::assertSame(['status' => 'locked','changed' => false], $restarted->reconcile('-1', 'event', true));
        self::assertSame($snapshot, $restarted->state('-1')['snapshot_json']);
        self::assertSame('restored', $restarted->reconcile('-1', 'event', false)['status']);
    }
    public function testNewHolySequenceWhilePermissionReadAwaitsPreventsPrematureRestore(): void
    {
        $desired = true;
        $locks = new LockCoordinator($this->db, $this->clock, $this->gateway, function (string $group) use (&$desired): bool {
            self::assertFalse($this->db->pdo()->inTransaction());
            return $desired;
        });
        $locks->reconcile('-1', 'first-event', true);
        $snapshot = $locks->state('-1')['snapshot_json'];
        $this->gateway->resetActivity();
        $desired = false;
        $entered = new DeferredFuture();
        $release = new DeferredFuture();
        $this->gateway->onGet = static function (string $group, int $reads) use ($entered, $release): void {
            if ($reads === 1) {
                $entered->complete();
                $release->getFuture()->await();
            }
        };
        $restore = \Amp\async(fn(): array=>$locks->reconcile('-1', 'previous-event', false));
        $entered->getFuture()->await();
        $desired = true;
        $release->complete();
        $result = $restore->await();
        self::assertSame(['status' => 'deferred','changed' => false], $result);
        self::assertSame(0, $this->gateway->writes);
        self::assertSame($snapshot, $locks->state('-1')['snapshot_json']);
        self::assertTrue(Permissions::alreadyLocked($this->gateway->permissions['-1']));
        $this->gateway->onGet = null;
        self::assertSame(['status' => 'locked','changed' => false], $locks->reconcile('-1', 'next-festival', true));
        self::assertSame($snapshot, $locks->state('-1')['snapshot_json']);
    }
    public function testDesiredCloseChangedDuringReadDoesNotApplyOutdatedLock(): void
    {
        $desired = true;
        $locks = new LockCoordinator($this->db, $this->clock, $this->gateway, static function (string $group) use (&$desired): bool {
            return $desired;
        });
        $this->gateway->onGet = static function () use (&$desired): void {
            $desired = false;
        };
        self::assertSame(['status' => 'deferred','changed' => false], $locks->reconcile('-1', 'ending-event', true));
        self::assertSame(0, $this->gateway->writes);
        $snapshot = $locks->state('-1')['snapshot_json'];
        self::assertNotNull($snapshot);
        $this->gateway->onGet = null;
        self::assertSame(['status' => 'restored','changed' => false], $locks->reconcile('-1', 'ending-event', false));
        self::assertSame($snapshot, $locks->state('-1')['snapshot_json']);
    }
    public function testLeaseExpiryDuringMutationNeverClaimsSuccessfulChange(): void
    {
        $locks = new LockCoordinator($this->db, $this->clock, $this->gateway);
        $this->gateway->onSet = function (string $group, array $rights): void {
            $this->gateway->permissions[$group] = $rights;
            $this->clock->set(new \DateTimeImmutable('2026-10-13T09:02:01Z'));
        };
        $result = $locks->reconcile('-1', 'event', true);
        self::assertSame('busy', $result['status']);
        self::assertFalse($result['changed']);
        self::assertSame(2, $this->gateway->reads);
        self::assertSame('prepared', $locks->state('-1')['status']);
        $snapshot = $locks->state('-1')['snapshot_json'];
        self::assertNotNull($snapshot);
        $this->gateway->onSet = null;
        $restarted = new LockCoordinator($this->db, $this->clock, $this->gateway);
        self::assertSame(['status' => 'locked','changed' => false], $restarted->reconcile('-1', 'event', true));
        self::assertSame($snapshot, $restarted->state('-1')['snapshot_json']);
        self::assertSame(1, $this->gateway->writes);
    }
    public function testLeaseExpiryDuringReadbackCannotCommitStaleSuccess(): void
    {
        $locks = new LockCoordinator($this->db, $this->clock, $this->gateway);
        $this->gateway->onGet = function (string $group, int $reads): void {
            if ($reads === 3) {
                $this->clock->set(new \DateTimeImmutable('2026-10-13T09:02:01Z'));
            }
        };
        $result = $locks->reconcile('-1', 'event', true);
        self::assertSame('busy', $result['status']);
        self::assertFalse($result['changed']);
        self::assertSame('prepared', $locks->state('-1')['status']);
        self::assertNotNull($locks->state('-1')['snapshot_json']);
    }
    public function testExpiredOwnerCannotOverwriteANewCoordinatorsConfirmedState(): void
    {
        $first = new LockCoordinator($this->db, $this->clock, $this->gateway);
        $second = new LockCoordinator($this->db, $this->clock, $this->gateway);
        $this->gateway->onSet = function (string $group, array $rights) use ($second): void {
            $this->gateway->permissions[$group] = $rights;
            $this->clock->set(new \DateTimeImmutable('2026-10-13T09:02:01Z'));
            $this->gateway->onSet = null;
            self::assertSame(['status' => 'locked','changed' => false], $second->reconcile($group, 'new-event', true));
        };
        $result = $first->reconcile('-1', 'old-event', true);
        self::assertSame('busy', $result['status']);
        self::assertFalse($result['changed']);
        self::assertSame('locked', $first->state('-1')['status']);
        self::assertSame('new-event', $first->state('-1')['event_key']);
        self::assertNull($first->state('-1')['last_error']);
    }
    public function testFreshDesiredEvaluationCancellationCannotMutatePermissions(): void
    {
        $locks = new LockCoordinator($this->db, $this->clock, $this->gateway, static function (): bool {
            throw new \Amp\CancelledException();
        });
        try {
            $locks->reconcile('-1', 'event', true);
            self::fail('Cancellation swallowed');
        } catch (\Amp\CancelledException) {
            self::assertSame(0, $this->gateway->writes);
            self::assertNotNull($locks->state('-1')['snapshot_json']);
            self::assertNull($locks->state('-1')['lease_owner']);
        }
    }
    public function testTypedRetryColumnRejectsInvalidDeadlines(): void
    {
        $locks = new LockCoordinator($this->db, $this->clock, $this->gateway);
        $locks->reconcile('-1', 'event', true);
        foreach (["'invalid'","-1","1.5"] as $value) {
            try {
                $this->db->pdo()->exec("UPDATE group_lock_state SET retry_at=" . $value . " WHERE group_id='-1'");
                self::fail('Invalid deadline accepted');
            } catch (\PDOException) {
                self::assertNull($locks->state('-1')['retry_at']);
            }
        }
    }

    public function testQuotaDeadlinePublishedDuringAnAwaitedReadBlocksTheNextRpc(): void
    {
        $locks = new LockCoordinator($this->db, $this->clock, $this->gateway);
        $this->gateway->onGet = function (): void {
            $this->db->pdo()->prepare("UPDATE group_lock_state SET retry_at=? WHERE group_id='-1'")->execute([$this->clock->now()->getTimestamp() + 60]);
        };
        $result = $locks->reconcile('-1', 'event', true);
        self::assertSame('retry_wait', $result['status']);
        self::assertSame(1, $this->gateway->reads);
        self::assertSame(0, $this->gateway->writes);
        $this->gateway->onGet = null;
        $locks->reconcile('-1', 'event', true);
        self::assertSame(1, $this->gateway->reads);
        self::assertSame(0, $this->gateway->writes);
    }
    public function testPrelockedStateRemainsPrelockedAcrossAQuotaDelay(): void
    {
        $this->gateway->permissions['-1'] = ['_' => 'chatBannedRights','until_date' => 0,'send_messages' => true];
        $locks = new LockCoordinator($this->db, $this->clock, $this->gateway);
        self::assertSame('prelocked', $locks->reconcile('-1', 'event', true)['status']);
        $this->gateway->onGet = static function (): void {
            throw new RetryableTelegramException('FLOOD_WAIT_15', 15);
        };
        $reads = $this->gateway->reads;
        self::assertSame('prelocked', $locks->reconcile('-1', 'event', false)['status']);
        self::assertSame($reads, $this->gateway->reads);
        self::assertNull($locks->state('-1')['retry_at']);

        // A new closing event still needs fresh evidence and must honor a real RPC quota.
        self::assertSame('retry_wait', $locks->reconcile('-1', 'next-event', true)['status']);
        self::assertSame($reads + 1, $this->gateway->reads);
        self::assertSame('prelocked', $locks->state('-1')['status']);
        self::assertNull($locks->state('-1')['snapshot_json']);
        self::assertSame($this->clock->now()->getTimestamp() + 15, $locks->state('-1')['retry_at']);
        self::assertSame('retry_wait', $locks->reconcile('-1', 'next-event', false)['status']);
        self::assertSame($reads + 1, $this->gateway->reads);
        self::assertSame(0, $this->gateway->writes);

        $this->clock->set(new \DateTimeImmutable('2026-10-13T09:00:15Z'));
        $this->gateway->onGet = null;
        self::assertSame('prelocked', $locks->reconcile('-1', 'next-event', false)['status']);
        self::assertSame($reads + 1, $this->gateway->reads);
        self::assertSame(0, $this->gateway->writes);
        self::assertNull($locks->state('-1')['snapshot_json']);
        self::assertNull($locks->state('-1')['retry_at']);
    }
    public function testExpiredRestoreLeasePreservesSnapshotAndCannotReportConfirmedOpening(): void
    {
        $locks = new LockCoordinator($this->db, $this->clock, $this->gateway);
        $original = $this->gateway->getPermissions('-1');
        $locks->reconcile('-1', 'event', true);
        $snapshot = $locks->state('-1')['snapshot_json'];
        $this->gateway->resetActivity();
        $this->gateway->onSet = function (string $group, array $rights): void {
            $this->gateway->permissions[$group] = $rights;
            $this->clock->set(new \DateTimeImmutable('2026-10-13T09:02:01Z'));
        };
        $result = $locks->reconcile('-1', 'event', false);
        self::assertSame('busy', $result['status']);
        self::assertFalse($result['changed']);
        self::assertSame('restoring', $locks->state('-1')['status']);
        self::assertSame($snapshot, $locks->state('-1')['snapshot_json']);
        $this->gateway->onSet = null;
        $restarted = new LockCoordinator($this->db, $this->clock, $this->gateway);
        self::assertSame(['status' => 'restored','changed' => false], $restarted->reconcile('-1', 'event', false));
        self::assertSame($original, $this->gateway->permissions['-1']);
        self::assertSame(1, $this->gateway->writes);
    }
}
