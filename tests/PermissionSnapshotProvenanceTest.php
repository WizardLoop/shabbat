<?php

// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);

namespace Shabbat\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shabbat\Scheduling\{LockCoordinator,PermissionRecovery,PermissionRecoveryProvenance,Permissions};
use Shabbat\Storage\{Database,SettingsRepository};
use Shabbat\Time\FrozenClock;

final class PermissionSnapshotProvenanceTest extends TestCase
{
    private Database $db;
    private FrozenClock $clock;
    private PermissionRecoveryGateway $gateway;
    private LockCoordinator $locks;
    protected function setUp(): void
    {
        $this->db = Database::open(':memory:');
        $this->db->migrate();
        $this->clock = new FrozenClock(new \DateTimeImmutable('2026-10-15T09:00:00Z'));
        (new SettingsRepository($this->db, $this->clock))->ensureGroup('-1');
        $this->gateway = new PermissionRecoveryGateway();
        $this->locks = new LockCoordinator($this->db, $this->clock, $this->gateway);
    }
    private function restrictiveOriginal(): array
    {
        return ['_' => 'chatBannedRights','until_date' => 2147483647,'send_plain' => false,'send_photos' => true,
            'send_videos' => true,'send_docs' => true,'send_polls' => true,'invite_users' => true,'pin_messages' => true,
            'change_info' => true,'manage_topics' => false,'edit_rank' => true,'send_reactions' => false];
    }
    private function legacy(string $status = 'conflict'): array
    {
        $original = ['_' => 'chatBannedRights','until_date' => 2147483647];
        foreach (Permissions::FLAGS as $flag) {
            $original[$flag] = $flag === 'edit_rank';
        }
        $snapshot = Database::encode($original);
        $applied = Permissions::locked($original);
        $this->db->pdo()->prepare('INSERT INTO group_lock_state(group_id,event_key,status,snapshot_json,snapshot_sha256,applied_json,prepared_at,last_error) VALUES(?,?,?,?,?,?,?,?)')
            ->execute(['-1','old-event',$status,$snapshot,hash('sha256', $snapshot),Database::encode($applied),1,'Observed manage_topics differs']);
        $this->gateway->current = $applied;
        return $this->locks->state('-1');
    }
    public function testNewRestrictiveSnapshotRoundTripsExactlyAndRecordsVerifiedOrigin(): void
    {
        $original = $this->restrictiveOriginal();
        $this->gateway->current = $original;
        self::assertSame('locked', $this->locks->reconcile('-1', 'week', true)['status']);
        $state = $this->locks->state('-1');
        self::assertTrue(PermissionRecoveryProvenance::verified($state));
        self::assertSame(PermissionRecoveryProvenance::VERIFIED, $state['snapshot_provenance']);
        self::assertSame(Database::encode($original), $state['snapshot_json']);
        self::assertSame('restored', $this->locks->reconcile('-1', 'week', false)['status']);
        self::assertSame($original, $this->gateway->writes[1]);
        self::assertSame($original, $this->gateway->current);
        self::assertSame($state['snapshot_evidence_json'], $this->locks->state('-1')['snapshot_evidence_json']);
    }
    public static function unresolved(): array
    {
        return array_map(static fn(string $status): array => [$status], ['prepared','locked','restoring','conflict','error','recovery_required']);
    }
    #[DataProvider('unresolved')]
    public function testUnverifiedOriginalCannotBeAutomaticallyRestoredOrReplacedNextWeek(string $status): void
    {
        $this->legacy($status);
        $baseline = Database::encode($this->restrictiveOriginal());
        $this->db->pdo()->prepare('UPDATE group_lock_state SET previous_applied_json=applied_json,recovery_baseline_json=?,recovery_baseline_sha256=?')->execute([$baseline,hash('sha256', $baseline)]);
        $before = $this->locks->state('-1');
        $this->gateway->onRead = static fn() => throw new \LogicException('No read is needed to reject unverified stored origin.');
        foreach ([false,true,false] as $close) {
            $result = $this->locks->reconcile('-1', 'later-week', $close);
            self::assertSame('recovery_required', $result['status']);
            self::assertSame('unverified_snapshot', $result['recovery_reason']);
            $this->clock->set($this->clock->now()->modify('+7 days'));
        }
        $after = $this->locks->state('-1');
        foreach (['snapshot_json','snapshot_sha256','applied_json','previous_applied_json','recovery_baseline_json','recovery_baseline_sha256','prepared_at','event_key','revision'] as $field) {
            self::assertSame($before[$field], $after[$field]);
        }
        self::assertSame([], $this->gateway->writes);
        $audit = $this->db->pdo()->query("SELECT details_json FROM audit_log WHERE action='permissions.unverified_original'")->fetchAll();
        self::assertCount(1, $audit);
        self::assertSame($before['last_error'], json_decode($audit[0]['details_json'], true)['last_error']);
    }
    public function testLegacyRestoreIsUnavailableButExplicitVerifiedAdoptEnablesFutureExactCycles(): void
    {
        $before = $this->legacy();
        $recovery = new PermissionRecovery($this->db, $this->clock, $this->gateway, $this->locks, static fn(): bool => true, static fn(): bool => true);
        $inspect = $recovery->inspect('-1', '42');
        self::assertFalse($inspect['original_verified']);
        self::assertFalse($inspect['can_restore']);
        self::assertSame('unverified_snapshot', $inspect['restore_block_reason']);
        self::assertNull($recovery->prepare('-1', '42', 'restore')['token']);
        self::assertFalse($inspect['can_adopt']);
        // The administrator first configures the intended normal permissions in Telegram.
        $this->gateway->current = $this->restrictiveOriginal();
        $preview = $recovery->prepare('-1', '42', 'adopt');
        self::assertTrue($preview['can_adopt']);
        self::assertSame('adopted', $recovery->confirm($preview['token'], '42')['status']);
        self::assertSame([], $this->gateway->writes);
        self::assertSame($before['snapshot_json'], $this->locks->state('-1')['snapshot_json']);
        self::assertSame('locked', $this->locks->reconcile('-1', 'verified-new-cycle', true)['status']);
        self::assertTrue(PermissionRecoveryProvenance::verified($this->locks->state('-1')));
        self::assertSame('restored', $this->locks->reconcile('-1', 'verified-new-cycle', false)['status']);
        self::assertSame($this->restrictiveOriginal(), $this->gateway->current);
        $audit = json_decode($this->db->pdo()->query("SELECT details_json FROM audit_log WHERE action='permissions.recovery.adopt'")->fetchColumn(), true);
        self::assertSame('legacy_unverified', $audit['original_provenance']);
        self::assertSame($before['snapshot_json'], $audit['original_json']);
    }
    public static function completed(): array
    {
        return [['restored'],['prelocked']];
    }
    #[DataProvider('completed')]
    public function testCompletedLegacyCycleCapturesFreshNormalWithoutUsingOldSnapshot(string $status): void
    {
        $this->legacy($status);
        $this->gateway->current = $this->restrictiveOriginal();
        self::assertSame('locked', $this->locks->reconcile('-1', 'new-cycle', true)['status']);
        self::assertSame(Database::encode($this->restrictiveOriginal()), $this->locks->state('-1')['snapshot_json']);
        self::assertSame('restored', $this->locks->reconcile('-1', 'new-cycle', false)['status']);
        self::assertSame($this->restrictiveOriginal(), $this->gateway->current);
    }
    public function testRejectedFreshReadOrSnapshotPersistenceFailureNeverWritesPermissions(): void
    {
        $this->gateway->onRead = static fn() => throw new \RuntimeException('Incomplete current default permissions');
        self::assertSame('error', $this->locks->reconcile('-1', 'week', true)['status']);
        self::assertNull($this->locks->state('-1')['snapshot_json']);
        self::assertFalse(PermissionRecoveryProvenance::verified($this->locks->state('-1')));
        self::assertSame([], $this->gateway->writes);
        $this->gateway->onRead = null;
        $this->gateway->current = $this->restrictiveOriginal();
        $this->db->pdo()->exec("CREATE TRIGGER reject_provenance BEFORE UPDATE OF snapshot_evidence_json ON group_lock_state BEGIN SELECT RAISE(FAIL,'disk fault'); END");
        self::assertSame('error', $this->locks->reconcile('-1', 'week', true)['status']);
        self::assertNull($this->locks->state('-1')['snapshot_json']);
        self::assertSame([], $this->gateway->writes);
    }
    public function testManageTopicsMismatchDoesNotRemoveAnyOriginalRestriction(): void
    {
        $original = $this->restrictiveOriginal();
        $this->gateway->current = $original;
        $this->locks->reconcile('-1', 'week', true);
        $this->gateway->onWrite = function (): void {
            $this->gateway->current['manage_topics'] = true;
        };
        $result = $this->locks->reconcile('-1', 'week', false);
        self::assertSame('conflict', $result['status']);
        self::assertSame(['manage_topics'], $result['different_fields']);
        self::assertTrue($result['mutation_attempted']);
        self::assertTrue($result['verification_failed']);
        self::assertSame($original, $this->gateway->writes[1]);
        foreach (['send_photos','send_videos','send_docs','send_polls','invite_users','pin_messages','change_info','edit_rank'] as $field) {
            self::assertTrue($this->gateway->current[$field], $field);
        }
    }
    public static function tamperedEvidence(): array
    {
        return [['group_id','-2'],['snapshot_sha256','copied-checksum'],['captured_at',1],
            ['reader_contract','cached-read'],['schema',2]];
    }
    #[DataProvider('tamperedEvidence')]
    public function testEvidenceMustBindExactGroupSnapshotCaptureAndReader(string $field, mixed $value): void
    {
        $this->gateway->current = $this->restrictiveOriginal();
        $this->locks->reconcile('-1', 'week', true);
        $before = $this->locks->state('-1');
        $evidence = json_decode($before['snapshot_evidence_json'], true);
        $evidence[$field] = $value;
        $this->db->pdo()->prepare('UPDATE group_lock_state SET snapshot_evidence_json=?')
            ->execute([Database::encode($evidence)]);
        self::assertFalse(PermissionRecoveryProvenance::verified($this->locks->state('-1')));
        self::assertSame('recovery_required', $this->locks->reconcile('-1', 'week', false)['status']);
        self::assertSame($before['snapshot_json'], $this->locks->state('-1')['snapshot_json']);
        self::assertCount(1, $this->gateway->writes);
    }
    public static function upgradeVersions(): array
    {
        return [[12],[14]];
    }
    #[DataProvider('upgradeVersions')]
    public function testUpgradePreservesLegacyEvidenceAndMigrationRepeatsSafely(int $from): void
    {
        $directory = sys_get_temp_dir() . '/shabbat-dev8-provenance-' . bin2hex(random_bytes(10));
        mkdir($directory, 0700);
        $copies = [];
        try {
            foreach (glob(dirname(__DIR__) . '/migrations/*.sql') as $file) {
                if ((int)basename($file) <= $from) {
                    $copy = $directory . '/' . basename($file);
                    self::assertTrue(copy($file, $copy));
                    $copies[] = $copy;
                }
            }
            $db = Database::open(':memory:');
            self::assertCount($from, $db->migrate($directory));
            (new SettingsRepository($db, $this->clock))->ensureGroup('-1');
            $oldSnapshot = Database::encode(['_' => 'chatBannedRights','edit_rank' => true,'until_date' => 2147483647]);
            $db->pdo()->prepare("INSERT INTO group_lock_state(group_id,event_key,status,snapshot_json,snapshot_sha256,applied_json,prepared_at,last_error) VALUES('-1','old','conflict',?,?,?,1,'old topic mismatch')")
                ->execute([$oldSnapshot,hash('sha256', $oldSnapshot),Database::encode(Permissions::locked(json_decode($oldSnapshot, true)))]);
            $before = $db->pdo()->query('SELECT * FROM group_lock_state')->fetch();
            self::assertCount(16 - $from, $db->migrate());
            self::assertSame([], $db->migrate());
            $after = $db->pdo()->query('SELECT * FROM group_lock_state')->fetch();
            self::assertSame($before, array_intersect_key($after, $before));
            self::assertSame('legacy_unverified', $after['snapshot_provenance']);
            self::assertNull($after['snapshot_evidence_json']);
            $locks = new LockCoordinator($db, $this->clock, $this->gateway);
            self::assertSame('recovery_required', $locks->reconcile('-1', 'later', false)['status']);
            self::assertSame([], $this->gateway->writes);
            self::assertSame(['ok'], $db->integrity()['integrity_check']);
            self::assertSame([], $db->integrity()['foreign_key_check']);
        } finally {
            foreach ($copies as $copy) {
                if (is_file($copy)) {
                    unlink($copy);
                }
            }
            rmdir($directory);
        }
    }
}
