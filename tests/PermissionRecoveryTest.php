<?php

// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);

namespace Shabbat\Tests;

use PHPUnit\Framework\TestCase;
use Shabbat\Scheduling\{LockCoordinator,PermissionRecovery,PermissionRecoveryJournal,Permissions};
use Shabbat\Storage\{Database,SettingsRepository};
use Shabbat\Time\FrozenClock;

final class PermissionRecoveryTest extends TestCase
{
    private Database $db;
    private FrozenClock $clock;
    private SettingsRepository $settings;
    private PermissionRecoveryGateway $gateway;
    private LockCoordinator $locks;
    private PermissionRecovery $recovery;
    private bool $safe = true;
    private bool $authorized = true;
    protected function setUp(): void
    {
        $this->db = Database::open(':memory:');
        $this->db->migrate();
        $this->clock = new FrozenClock(new \DateTimeImmutable('2026-10-13T09:00:00Z'));
        $this->settings = new SettingsRepository($this->db, $this->clock);
        $this->settings->ensureGroup('-1');
        $this->settings->updateGroup('-1', 1, ['lock_block_flags' => ['send_plain']], '42');
        $this->gateway = new PermissionRecoveryGateway();
        $this->locks = new LockCoordinator($this->db, $this->clock, $this->gateway);
        $this->recovery = new PermissionRecovery($this->db, $this->clock, $this->gateway, $this->locks, fn(string $actor): bool => $this->authorized && $actor === '42', fn(): bool => $this->safe);
    }
    private function conflict(): void
    {
        $this->locks->reconcile('-1', 'first-week', true);
        $this->gateway->current['send_plain'] = false;
        $this->gateway->current['send_photos'] = true;
        self::assertSame('conflict', $this->locks->reconcile('-1', 'first-week', false)['status']);
    }
    public function testAutomaticThreeWayRecoveryPreservesExternalUntouchedRestrictionsThroughRestartAndNextWeek(): void
    {
        $this->locks->reconcile('-1', 'first-week', true);
        $original = $this->locks->state('-1')['snapshot_json'];
        $this->gateway->current['send_photos'] = true;
        self::assertSame('locked', $this->locks->reconcile('-1', 'first-week', true)['status']);
        self::assertSame($original, $this->locks->state('-1')['snapshot_json']);
        $this->gateway->current['send_videos'] = true;
        $restart = new LockCoordinator($this->db, $this->clock, $this->gateway);
        self::assertSame('restored', $restart->reconcile('-1', 'first-week', false)['status']);
        self::assertFalse($this->gateway->current['send_plain'] ?? false);
        self::assertTrue($this->gateway->current['send_photos']);
        self::assertTrue($this->gateway->current['send_videos']);
        $this->clock->set(new \DateTimeImmutable('2026-10-23T16:00:00Z'));
        self::assertSame('locked', $restart->reconcile('-1', 'next-week', true)['status']);
        self::assertSame('restored', $restart->reconcile('-1', 'next-week', false)['status']);
        self::assertTrue($this->gateway->current['send_photos']);
        self::assertTrue($this->gateway->current['send_videos']);
    }
    public function testBotTouchedAndUnknownAndFiniteExpiryChangesNeverAutomaticallyAdopt(): void
    {
        $this->conflict();
        $original = $this->locks->state('-1')['snapshot_json'];
        $this->clock->set(new \DateTimeImmutable('2026-10-23T16:00:00Z'));
        self::assertSame('conflict', $this->locks->reconcile('-1', 'next-week', true)['status']);
        self::assertSame($original, $this->locks->state('-1')['snapshot_json']);
        self::assertCount(1, $this->gateway->writes);
        $base = ['until_date' => 0];
        $applied = Permissions::locked($base, ['send_plain']);
        self::assertNull(Permissions::externalBaseline($base, $applied, $applied + ['future_flag' => true]));
        self::assertNull(Permissions::externalBaseline($base, $applied, array_replace($applied, ['until_date' => 123456])));
    }
    public function testAdministratorAdoptsCurrentThenNextWeekClosesAndRestoresNewNormal(): void
    {
        $this->conflict();
        $preview = $this->recovery->prepare('-1', '42', 'adopt');
        self::assertTrue($preview['can_adopt']);
        $original = $this->locks->state('-1')['snapshot_json'];
        self::assertSame('adopted', $this->recovery->confirm($preview['token'], '42')['status']);
        self::assertCount(1, $this->gateway->writes);
        self::assertSame($original, $this->locks->state('-1')['snapshot_json']);
        self::assertSame('expired', $this->recovery->confirm($preview['token'], '42')['status']);
        $audit = $this->db->pdo()->query("SELECT details_json FROM audit_log WHERE action='permissions.recovery.adopt'")->fetchColumn();
        self::assertSame($original, json_decode($audit, true)['original_json']);
        $this->clock->set(new \DateTimeImmutable('2026-10-23T16:00:00Z'));
        self::assertSame('locked', $this->locks->reconcile('-1', 'next-week', true)['status']);
        self::assertSame('restored', $this->locks->reconcile('-1', 'next-week', false)['status']);
        self::assertTrue($this->gateway->current['send_photos']);
        self::assertFalse($this->gateway->current['send_plain']);
    }
    public function testAdministratorRestoreUsesExactOriginalAndPersistsDecision(): void
    {
        $this->conflict();
        $original = json_decode($this->locks->state('-1')['snapshot_json'], true);
        $preview = $this->recovery->prepare('-1', '42', 'restore');
        self::assertSame(['status' => 'restored','changed' => true], $this->recovery->confirm($preview['token'], '42'));
        self::assertSame($original, $this->gateway->current);
        self::assertSame('confirmed', (new PermissionRecoveryJournal($this->db, $this->clock))->latest('-1')['outcome']);
    }
    public function testActiveReligiousOrManualLockBlocksBothChoices(): void
    {
        $this->conflict();
        $preview = $this->recovery->prepare('-1', '42', 'restore');
        $this->safe = false;
        self::assertSame('active_lock', $this->recovery->confirm($preview['token'], '42')['status']);
        foreach (['adopt','restore'] as $action) {
            $denied = $this->recovery->prepare('-1', '42', $action);
            self::assertNull($denied['token']);
            self::assertSame('active_lock', $denied['block_reason']);
        }
        self::assertCount(1, $this->gateway->writes);
    }
    public function testLockedCurrentCannotBecomeNormalByAdoption(): void
    {
        $this->locks->reconcile('-1', 'week', true);
        $preview = $this->recovery->prepare('-1', '42', 'adopt');
        self::assertFalse($preview['can_adopt']);
        self::assertSame('locked_current', $preview['adopt_block_reason']);
        self::assertNull($preview['token']);
    }
    public function testExpiryAndWrongActorAndReplacementPreviewAreRejected(): void
    {
        $this->conflict();
        $first = $this->recovery->prepare('-1', '42', 'restore');
        self::assertSame('expired', $this->recovery->confirm($first['token'], '43')['status']);
        $second = $this->recovery->prepare('-1', '42', 'restore');
        self::assertSame('expired', $this->recovery->confirm($first['token'], '42')['status']);
        $this->clock->set($this->clock->now()->modify('+301 seconds'));
        self::assertSame('expired', $this->recovery->confirm($second['token'], '42')['status']);
        self::assertCount(1, $this->gateway->writes);
    }
    public function testRemoteAndSettingsAndLockStateChangesInvalidateConfirmation(): void
    {
        $this->conflict();
        $preview = $this->recovery->prepare('-1', '42', 'restore');
        $this->gateway->current['send_videos'] = true;
        self::assertSame('stale', $this->recovery->confirm($preview['token'], '42')['status']);
        $preview = $this->recovery->prepare('-1', '42', 'restore');
        $this->settings->updateGroup('-1', 2, ['candle_minutes' => 30], '42');
        self::assertSame('stale', $this->recovery->confirm($preview['token'], '42')['status']);
        $preview = $this->recovery->prepare('-1', '42', 'restore');
        $this->db->pdo()->exec("UPDATE group_lock_state SET revision=revision+1 WHERE group_id='-1'");
        self::assertSame('stale', $this->recovery->confirm($preview['token'], '42')['status']);
        self::assertCount(1, $this->gateway->writes);
    }
    public function testAuthorityLossDuringRemoteReadStopsMutationAndTokenCannotReplay(): void
    {
        $this->conflict();
        $preview = $this->recovery->prepare('-1', '42', 'restore');
        $this->gateway->onRead = function (): void {
            $this->authorized = false;
        };
        self::assertSame('forbidden', $this->recovery->confirm($preview['token'], '42')['status']);
        $this->authorized = true;
        self::assertSame('expired', $this->recovery->confirm($preview['token'], '42')['status']);
        self::assertCount(1, $this->gateway->writes);
    }
    public function testConcurrentCoordinatorAndExpiredLeaseCannotOverwriteRecovery(): void
    {
        $this->conflict();
        $preview = $this->recovery->prepare('-1', '42', 'restore');
        $this->gateway->onRead = function (): void {
            $other = new LockCoordinator($this->db, $this->clock, $this->gateway);
            self::assertSame('busy', $other->reconcile('-1', 'other', true)['status']);
        };
        self::assertSame('restored', $this->recovery->confirm($preview['token'], '42')['status']);
        $this->gateway->onRead = null;
        $this->conflict();
        $preview = $this->recovery->prepare('-1', '42', 'restore');
        $this->gateway->onRead = function (): void {
            $this->clock->set($this->clock->now()->modify('+121 seconds'));
        };
        self::assertSame('busy', $this->recovery->confirm($preview['token'], '42')['status']);
        self::assertCount(3, $this->gateway->writes);
    }
    public function testCancellationPropagatesAndLeavesDurableUnknownOutcomeRecoverable(): void
    {
        $this->conflict();
        $preview = $this->recovery->prepare('-1', '42', 'restore');
        $this->gateway->onWrite = static fn() => throw new \Amp\CancelledException();
        try {
            $this->recovery->confirm($preview['token'], '42');
            self::fail('Cancellation must propagate.');
        } catch (\Amp\CancelledException) {
            self::assertNull($this->locks->state('-1')['lease_owner']);
        }
        self::assertSame('unknown', (new PermissionRecoveryJournal($this->db, $this->clock))->latest('-1')['outcome']);
        $this->gateway->onWrite = null;
        self::assertSame('restored', $this->locks->reconcile('-1', 'recovery', false)['status']);
        self::assertCount(2, $this->gateway->writes);
    }
    public function testNoRemoteCallRunsInsideDatabaseTransactionAndJournalIsBounded(): void
    {
        $this->gateway->onRead = function (): void {
            $this->db->transaction(static fn() => true);
        };
        $this->gateway->onWrite = $this->gateway->onRead;
        for ($i = 0; $i < 15; $i++) {
            self::assertSame('locked', $this->locks->reconcile('-1', 'week-' . $i, true)['status']);
            self::assertSame('restored', $this->locks->reconcile('-1', 'week-' . $i, false)['status']);
        }
        self::assertSame(20, (int)$this->db->pdo()->query('SELECT count(*) FROM permission_operations')->fetchColumn());
    }
    public function testShutdownOrHolyBoundaryReachedDuringReadPreventsRecoveryWrite(): void
    {
        $this->conflict();
        $preview = $this->recovery->prepare('-1', '42', 'restore');
        $this->gateway->onRead = function (): void {
            $this->safe = false;
        };
        self::assertSame('active_lock', $this->recovery->confirm($preview['token'], '42')['status']);
        self::assertCount(1, $this->gateway->writes);
    }
    public function testAuthorityRefreshCrossingHolyBoundaryIsRecheckedBeforeMutation(): void
    {
        $this->conflict();
        $calls = 0;
        $flipAt = PHP_INT_MAX;
        $recovery = new PermissionRecovery($this->db, $this->clock, $this->gateway, $this->locks, function () use (&$calls, &$flipAt): bool {
            $calls++;
            if ($calls === $flipAt) {
                $this->safe = false;
            }
            return true;
        }, fn(): bool => $this->safe);
        $preview = $recovery->prepare('-1', '42', 'restore');
        $flipAt = $calls + 2;
        self::assertSame('active_lock', $recovery->confirm($preview['token'], '42')['status']);
        self::assertCount(1, $this->gateway->writes);
    }
    public function testFinalAsyncAuthorizationCannotHideNewRemoteLockDuringAdoption(): void
    {
        $this->conflict();
        $calls = 0;
        $flipAt = PHP_INT_MAX;
        $recovery = new PermissionRecovery($this->db, $this->clock, $this->gateway, $this->locks, function () use (&$calls, &$flipAt): bool {
            $calls++;
            if ($calls === $flipAt) {
                $this->gateway->current['send_plain'] = true;
            }
            return true;
        }, static fn(): bool => true);
        $preview = $recovery->prepare('-1', '42', 'adopt');
        $flipAt = $calls + 8;
        self::assertSame('conflict', $recovery->confirm($preview['token'], '42')['status']);
        self::assertSame('conflict', $this->locks->state('-1')['status']);
        self::assertTrue($this->gateway->current['send_plain']);
        self::assertTrue($recovery->inspect('-1', '42')['can_restore']);
        self::assertCount(1, $this->gateway->writes);
    }
    public function testFinalAuthorizationCannotHideRemoteChangeAfterExplicitRestore(): void
    {
        $this->conflict();
        $calls = 0;
        $flipAt = PHP_INT_MAX;
        $recovery = new PermissionRecovery($this->db, $this->clock, $this->gateway, $this->locks, function () use (&$calls, &$flipAt): bool {
            $calls++;
            if ($calls === $flipAt) {
                $this->gateway->current['send_plain'] = true;
            }
            return true;
        }, static fn(): bool => true);
        $preview = $recovery->prepare('-1', '42', 'restore');
        $flipAt = $calls + 8;
        self::assertSame('conflict', $recovery->confirm($preview['token'], '42')['status']);
        self::assertSame('conflict', $this->locks->state('-1')['status']);
        self::assertTrue($this->gateway->current['send_plain']);
        self::assertTrue($recovery->inspect('-1', '42')['can_restore']);
        self::assertCount(2, $this->gateway->writes);
    }
    public function testFuturePersistedQuotaReturnsRetryWaitWithoutMutation(): void
    {
        $this->conflict();
        $retry = $this->clock->now()->getTimestamp() + 60;
        $this->db->pdo()->prepare("UPDATE group_lock_state SET retry_at=? WHERE group_id='-1'")->execute([$retry]);
        $preview = $this->recovery->prepare('-1', '42', 'restore');
        self::assertSame(['status' => 'retry_wait','changed' => false,'retry_at' => $retry], $this->recovery->confirm($preview['token'], '42'));
        self::assertCount(1, $this->gateway->writes);
    }
    public function testFinalRemoteReadCrossingTimeCannotCommitWithoutLocalSafetyContext(): void
    {
        $this->conflict();
        $preview = $this->recovery->prepare('-1', '42', 'adopt');
        $reads = 0;
        $this->gateway->onRead = function () use (&$reads): void {
            $reads++;
            if ($reads === 3) {
                $this->clock->set($this->clock->now()->modify('+1 second'));
            }
        };
        self::assertSame('stale', $this->recovery->confirm($preview['token'], '42')['status']);
        self::assertSame('conflict', $this->locks->state('-1')['status']);
        self::assertCount(1, $this->gateway->writes);
    }
    public function testProvenDeferredFirstAttemptRetiresWithoutResettingAmbiguousDispatchedDebt(): void
    {
        $locks = new LockCoordinator($this->db, $this->clock, $this->gateway, static fn(): bool => false);
        self::assertSame('deferred', $locks->reconcile('-1', 'week', true)['status']);
        $oldOriginal = $locks->state('-1')['snapshot_json'];
        $this->gateway->current['send_photos'] = true;
        $this->clock->set($this->clock->now()->modify('+7 days'));
        self::assertSame('locked', $this->locks->reconcile('-1', 'next-week', true)['status']);
        self::assertSame($oldOriginal, $this->db->pdo()->query("SELECT original_json FROM permission_operations WHERE stage='retired_unwritten'")->fetchColumn());
        self::assertSame('restored', $this->locks->reconcile('-1', 'next-week', false)['status']);
        self::assertTrue($this->gateway->current['send_photos']);
        self::assertFalse($this->gateway->current['send_plain'] ?? false);
    }
    public function testDeferredBeforeWriteIsDistinguishedFromUnknownDispatch(): void
    {
        $locks = new LockCoordinator($this->db, $this->clock, $this->gateway, static fn(): bool => false);
        self::assertSame('deferred', $locks->reconcile('-1', 'week', true)['status']);
        $journal = new PermissionRecoveryJournal($this->db, $this->clock);
        self::assertSame('not_dispatched', $journal->latest('-1')['outcome']);
        self::assertSame('deferred', $journal->latest('-1')['stage']);
        self::assertCount(0, $this->gateway->writes);
        self::assertSame('restored', $locks->reconcile('-1', 'week', false)['status']);
        $this->gateway->current['send_photos'] = true;
        self::assertSame('locked', $this->locks->reconcile('-1', 'next-week', true)['status']);
        self::assertSame('restored', $this->locks->reconcile('-1', 'next-week', false)['status']);
        self::assertTrue($this->gateway->current['send_photos']);
    }
    public function testPartialRestoreNeverClaimsSuccessOrClearsOriginal(): void
    {
        $this->conflict();
        $original = $this->locks->state('-1')['snapshot_json'];
        $preview = $this->recovery->prepare('-1', '42', 'restore');
        $this->gateway->onWrite = function (): void {
            $this->gateway->current['send_plain'] = true;
            $this->gateway->current['send_photos'] = true;
        };
        self::assertSame('conflict', $this->recovery->confirm($preview['token'], '42')['status']);
        self::assertSame($original, $this->locks->state('-1')['snapshot_json']);
        self::assertSame('conflict', (new PermissionRecoveryJournal($this->db, $this->clock))->latest('-1')['outcome']);
    }
    public function testMissingOrCorruptOriginalAllowsExplicitAdoptButNeverRestore(): void
    {
        $this->conflict();
        $this->db->pdo()->exec("UPDATE group_lock_state SET snapshot_sha256='invalid' WHERE group_id='-1'");
        $inspect = $this->recovery->inspect('-1', '42');
        self::assertFalse($inspect['can_restore']);
        self::assertSame('missing_snapshot', $inspect['restore_block_reason']);
        self::assertTrue($inspect['can_adopt']);
        self::assertSame('adopted', $this->recovery->confirm($this->recovery->prepare('-1', '42', 'adopt')['token'], '42')['status']);
        self::assertSame('locked', $this->locks->reconcile('-1', 'next-week', true)['status']);
    }
}
