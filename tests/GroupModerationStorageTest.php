<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-only
namespace Shabbat\Tests;

use DateTimeImmutable;
use InvalidArgumentException;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Shabbat\Scheduling\LockCoordinator;
use Shabbat\Scheduling\Permissions;
use Shabbat\Storage\Database;
use Shabbat\Storage\SettingsRepository;
use Shabbat\Storage\StaleSettings;
use Shabbat\Storage\TemplateRepository;
use Shabbat\Time\FrozenClock;

final class GroupModerationStorageTest extends TestCase
{
    private Database $db;
    private FrozenClock $clock;
    private SettingsRepository $settings;
    private SchedulerFakeGateway $gateway;
    protected function setUp(): void
    {
        $this->db = Database::open(':memory:');
        $this->db->migrate();
        $this->clock = new FrozenClock(new DateTimeImmutable('2026-10-14T09:00:00Z'));
        $this->settings = new SettingsRepository($this->db, $this->clock);
        $this->settings->ensureGroup('-1');
        $this->gateway = new SchedulerFakeGateway();
    }
    public function testRestrictionCatalogExactlyMatchesLockedNativeSchema(): void
    {
        $tl = file_get_contents((new \danog\MadelineProto\Settings\TLSchema())->getAPISchema());
        self::assertSame(1, preg_match('/^chatBannedRights#[^\n]+/m', $tl, $line));
        preg_match_all('/([a-z_]+):flags\.\d+\?true/', $line[0], $matches);
        self::assertSame($matches[1], Permissions::FLAGS);
        self::assertCount(23, Permissions::FLAGS);
        self::assertCount(21, Permissions::DEFAULT_BLOCK_FLAGS);
        self::assertNotContains('view_messages', Permissions::DEFAULT_BLOCK_FLAGS);
        self::assertSame(Permissions::DEFAULT_BLOCK_FLAGS, $this->settings->getGroup('-1')['lock_block_flags']);
    }
    public function testGroupOnlyConsentDefaultsValidationAndRevisionCas(): void
    {
        $group = $this->settings->getGroup('-1');
        foreach (['delete_holy_user_messages','delete_holy_bot_messages','tefillin_delete_previous'] as $field) {
            self::assertFalse($group[$field]);
            self::assertArrayNotHasKey($field, $this->settings->getUser('42'));
            try {
                $this->settings->updateUser('42', 1, [$field => true]);
                self::fail('Personal settings accepted group cleanup consent.');
            } catch (InvalidArgumentException $error) {
                self::assertStringContainsString('Unsupported setting', $error->getMessage());
            }
        }
        $saved = $this->settings->updateGroup('-1', 1, ['lock_block_flags' => ['send_reactions','send_plain'],'delete_holy_user_messages' => true,'tefillin_delete_previous' => true], '42');
        self::assertSame(['send_plain','send_reactions'], $saved['lock_block_flags']);
        self::assertTrue($saved['delete_holy_user_messages']);
        foreach ([['view_messages'], ['unknown'], ['send_photos','send_photos'], ['send_photos' => true]] as $invalid) {
            try {
                $this->settings->updateGroup('-1', 2, ['lock_block_flags' => $invalid], '42');
                self::fail('Invalid restriction mask accepted.');
            } catch (InvalidArgumentException $error) {
                self::assertNotSame('', $error->getMessage());
            }
        }
        $this->expectException(StaleSettings::class);
        $this->settings->updateGroup('-1', 1, ['lock_block_flags' => []], '42');
    }
    public function testSelectedFlagsPreserveUntoggledRestrictionsAndRestoreExpiry(): void
    {
        $original = ['_' => 'chatBannedRights','send_polls' => true,'send_voices' => false,'until_date' => 2000000000];
        $this->gateway->permissions['-1'] = $original;
        $this->settings->updateGroup('-1', 1, ['lock_block_flags' => ['send_photos','edit_rank']], '42');
        $locks = new LockCoordinator($this->db, $this->clock, $this->gateway);
        self::assertSame('locked', $locks->reconcile('-1', 'event', true)['status']);
        $locked = $this->gateway->getPermissions('-1');
        self::assertTrue($locked['send_polls']);
        self::assertFalse($locked['send_voices']);
        self::assertTrue($locked['send_photos']);
        self::assertTrue($locked['edit_rank']);
        self::assertArrayNotHasKey('send_messages', $locked);
        self::assertSame(0, $locked['until_date']);
        self::assertSame('restored', $locks->reconcile('-1', 'event', false)['status']);
        self::assertSame($original, $this->gateway->getPermissions('-1'));
    }
    public function testMaskChangesDuringOwnedLockAddAndDropOnlyFromOriginalSnapshot(): void
    {
        $original = $this->gateway->getPermissions('-1');
        $this->settings->updateGroup('-1', 1, ['lock_block_flags' => ['send_photos']], '42');
        $locks = new LockCoordinator($this->db, $this->clock, $this->gateway);
        $locks->reconcile('-1', 'event', true);
        $snapshot = $locks->state('-1')['snapshot_json'];
        $this->settings->updateGroup('-1', 2, ['lock_block_flags' => ['send_voices','send_reactions']], '42');
        self::assertSame(['status' => 'locked','changed' => false,'permissions_updated' => true], $locks->reconcile('-1', 'event', true));
        self::assertSame($snapshot, $locks->state('-1')['snapshot_json']);
        self::assertSame(hash('sha256', $snapshot), $locks->state('-1')['snapshot_sha256']);
        $actual = $this->gateway->getPermissions('-1');
        self::assertArrayNotHasKey('send_photos', $actual);
        self::assertTrue($actual['send_voices']);
        self::assertTrue($actual['send_reactions']);
        self::assertTrue($actual['send_polls']);
        self::assertNull($locks->state('-1')['previous_applied_json']);
        $locks->reconcile('-1', 'event', false);
        self::assertSame($original, $this->gateway->getPermissions('-1'));
    }
    public function testFailedMaskMutationRetainsBothKnownMasksAndRestoresOriginal(): void
    {
        $original = $this->gateway->getPermissions('-1');
        $this->settings->updateGroup('-1', 1, ['lock_block_flags' => ['send_photos']], '42');
        $locks = new LockCoordinator($this->db, $this->clock, $this->gateway);
        $locks->reconcile('-1', 'event', true);
        $old = $this->gateway->getPermissions('-1');
        $this->settings->updateGroup('-1', 2, ['lock_block_flags' => ['send_voices']], '42');
        $this->gateway->onSet = static function (): never {
            throw new RuntimeException('RPC unavailable');
        };
        self::assertSame('error', $locks->reconcile('-1', 'event', true)['status']);
        self::assertTrue(Permissions::equal($old, json_decode($locks->state('-1')['previous_applied_json'], true)));
        $this->gateway->onSet = null;
        self::assertSame('restored', $locks->reconcile('-1', 'event', false)['status']);
        self::assertSame($original, $this->gateway->getPermissions('-1'));
    }
    public function testLostMaskResponseRecognizesNewOwnedMaskWithoutTakingNewSnapshot(): void
    {
        $this->settings->updateGroup('-1', 1, ['lock_block_flags' => ['send_photos']], '42');
        $locks = new LockCoordinator($this->db, $this->clock, $this->gateway);
        $locks->reconcile('-1', 'event', true);
        $snapshot = $locks->state('-1')['snapshot_json'];
        $this->settings->updateGroup('-1', 2, ['lock_block_flags' => Permissions::BLOCK_FLAGS], '42');
        $this->gateway->onSet = function (string $id, array $rights): never {
            $this->gateway->permissions[$id] = $rights;
            throw new RuntimeException('lost response');
        };
        self::assertSame('error', $locks->reconcile('-1', 'event', true)['status']);
        $this->gateway->onSet = null;
        self::assertSame(['status' => 'locked','changed' => false], $locks->reconcile('-1', 'event', true));
        self::assertSame($snapshot, $locks->state('-1')['snapshot_json']);
        self::assertArrayNotHasKey('view_messages', $this->gateway->permissions['-1']);
        self::assertSame('restored', $locks->reconcile('-1', 'event', false)['status']);
    }
    public function testFreshMaskChangeDefersMutationAndFailedSaveDoesNotChangeDebt(): void
    {
        $this->settings->updateGroup('-1', 1, ['lock_block_flags' => ['send_photos']], '42');
        $changed = false;
        $locks = new LockCoordinator($this->db, $this->clock, $this->gateway, function () use (&$changed): bool {
            if (!$changed) {
                $changed = true;
                $this->settings->updateGroup('-1', 2, ['lock_block_flags' => ['send_voices']], '42');
            }
            return true;
        });
        self::assertSame('deferred', $locks->reconcile('-1', 'event', true)['status']);
        self::assertCount(0, $this->gateway->changes);
        self::assertSame('locked', $locks->reconcile('-1', 'event', true)['status']);
        $before = $locks->state('-1')['snapshot_json'];
        $this->db->pdo()->exec("CREATE TRIGGER reject_mask BEFORE UPDATE OF lock_block_flags ON group_settings BEGIN SELECT RAISE(FAIL,'disk failure'); END");
        try {
            $this->settings->updateGroup('-1', 3, ['lock_block_flags' => []], '42');
            self::fail('Failed settings persistence was accepted.');
        } catch (PDOException $error) {
            self::assertStringContainsString('disk failure', $error->getMessage());
        }
        self::assertSame(['send_voices'], $this->settings->getGroup('-1')['lock_block_flags']);
        self::assertSame($before, $locks->state('-1')['snapshot_json']);
    }
    public function testManualConflictsAndPreexistingLocksAreNeverOverwritten(): void
    {
        $locks = new LockCoordinator($this->db, $this->clock, $this->gateway);
        $locks->reconcile('-1', 'event', true);
        $this->gateway->permissions['-1']['send_photos'] = false;
        $this->settings->updateGroup('-1', 1, ['lock_block_flags' => ['send_voices']], '42');
        self::assertSame('conflict', $locks->reconcile('-1', 'event', true)['status']);
        self::assertCount(1, $this->gateway->changes);
        $this->settings->ensureGroup('-2');
        $this->gateway->permissions['-2'] = ['_' => 'chatBannedRights','view_messages' => true];
        self::assertSame('prelocked', $locks->reconcile('-2', 'event', true)['status']);
        self::assertSame('prelocked', $locks->reconcile('-2', 'event', false)['status']);
        self::assertCount(1, $this->gateway->changes);
    }
    public function testMigrationPreservesSevenMigrationDataAndSeparatesAnnouncementTemplates(): void
    {
        $directory = sys_get_temp_dir() . '/shabbat-moderation-schema-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        try {
            foreach (glob(dirname(__DIR__) . '/migrations/*.sql') as $file) {
                if ((int)basename($file) <= 7) {
                    copy($file, $directory . '/' . basename($file));
                }
            }
            $old = Database::open(':memory:');
            $old->migrate($directory);
            $settings = new SettingsRepository($old, $this->clock);
            $settings->ensureGroup('-9', 'Retained', ['language' => 'fr','candle_minutes' => 22]);
            $settings->getUser('42');
            $templates = new TemplateRepository($old, $this->clock);
            // Seed the historic schema using its own columns, before newer repository fields exist.
            $old->pdo()->prepare('INSERT INTO message_templates(group_id,kind,text,entities_json,media_json,buttons_json,updated_at) VALUES(?,?,?,?,?,?,?)')
                ->execute(['-9', 'zmanim', '😀 Retained', Database::encode([['_' => 'messageEntityBold', 'offset' => 3, 'length' => 8]]), Database::encode(['bot_api_file_id' => 'retained-media']), Database::encode([[['text' => 'Link', 'url' => 'https://example.com']]]), $this->clock->now()->getTimestamp()]);
            $old->pdo()->exec("INSERT INTO group_lock_state(group_id,event_key,status,snapshot_json,applied_json,snapshot_sha256,prepared_at) VALUES('-9','legacy','locked','{\"send_polls\":true}','{\"send_photos\":true}','retained-checksum',1)");
            $old->pdo()->exec("INSERT INTO scheduled_jobs(group_id,module,event_key,run_at,expires_at,settings_revision,payload_json,random_id,created_at) VALUES('-9','tefillin','retained',1,2,1,'{}','19',1)");
            $old->pdo()->exec("INSERT INTO delivery_log(job_id,attempt,random_id,started_at,outcome,response_json) VALUES(1,1,'19',1,'sent','{\"message_id\":5}')");
            $snapshots = [];
            foreach (['groups','group_settings','user_preferences','message_templates','group_lock_state','scheduled_jobs','delivery_log'] as $table) {
                $snapshots[$table] = $old->pdo()->query('SELECT * FROM ' . $table)->fetchAll();
            }
            self::assertSame(['008_group_moderation.sql', '009_admin_roles_links.sql', '010_subscriber_directory.sql', '011_custom_star_amounts.sql', '012_tefillin_acknowledgements.sql', '013_permission_recovery.sql', '014_diagnostic_panels.sql', '015_permission_snapshot_provenance.sql', '016_delivery_safety.sql'], $old->migrate());
            foreach ($snapshots as $table => $rows) {
                $current = $old->pdo()->query('SELECT * FROM ' . $table . ' ORDER BY 1')->fetchAll();
                foreach ($rows as $index => $row) {
                    self::assertSame($row, array_intersect_key($current[$index], $row), $table);
                }
            }
            $announcement = $templates->get('-9', 'times_alert');
            $daily = $templates->get('-9', 'zmanim');
            foreach (['text','entities','media','buttons','revision','imported_at'] as $field) {
                self::assertSame($daily[$field], $announcement[$field]);
            }
            $templates->save('-9', 'times_alert', ['text' => 'Announcement only'], '42', $announcement['revision']);
            self::assertSame('😀 Retained', $templates->get('-9', 'zmanim')['text']);
            self::assertSame([], $old->integrity()['foreign_key_check']);
            $old->assertCurrentSchema();
        } finally {
            foreach (glob($directory . '/*') as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }
    public function testPreviousUpgradeUnverifiedMaskRequiresReviewWithoutReplacingLegacySnapshot(): void
    {
        $original = $this->gateway->getPermissions('-1');
        $snapshot = Database::encode($original);
        $oldMask = Permissions::locked($original, array_slice(Permissions::DEFAULT_BLOCK_FLAGS, 0, 19));
        $this->gateway->permissions['-1'] = $oldMask;
        $this->db->pdo()->prepare("INSERT INTO group_lock_state(group_id,event_key,status,snapshot_json,applied_json,snapshot_sha256,prepared_at,locked_at) VALUES('-1','legacy','locked',?,?,?,1,1)")
            ->execute([$snapshot,Database::encode($oldMask),hash('sha256', $snapshot)]);
        $locks = new LockCoordinator($this->db, $this->clock, $this->gateway);
        $result = $locks->reconcile('-1', 'legacy', true);
        self::assertSame('recovery_required', $result['status']);
        self::assertSame('unverified_snapshot', $result['recovery_reason']);
        self::assertSame($snapshot, $locks->state('-1')['snapshot_json']);
        self::assertSame('legacy_unverified', $locks->state('-1')['snapshot_provenance']);
        self::assertSame($oldMask, $this->gateway->getPermissions('-1'));
        self::assertSame('recovery_required', $locks->reconcile('-1', 'legacy', false)['status']);
        self::assertSame($oldMask, $this->gateway->getPermissions('-1'));
    }
}
