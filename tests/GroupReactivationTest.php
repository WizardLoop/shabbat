<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace Shabbat\Tests;

use DateTimeImmutable;
use InvalidArgumentException;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use Shabbat\Scheduling\JobRepository;
use Shabbat\Storage\Database;
use Shabbat\Storage\FlowTokenRepository;
use Shabbat\Storage\LegacyImporter;
use Shabbat\Storage\SettingsRepository;
use Shabbat\Time\FrozenClock;

final class GroupReactivationTest extends TestCase
{
    private Database $db;
    private FrozenClock $clock;
    private SettingsRepository $settings;

    protected function setUp(): void
    {
        $this->db = Database::open(':memory:');
        $this->db->migrate();
        $this->clock = new FrozenClock(new DateTimeImmutable('2026-10-13T09:00:00Z'));
        $this->settings = new SettingsRepository($this->db, $this->clock);
        $this->settings->ensureGroup('-1001', 'Retained group');
        $this->settings->updateGroup('-1001', 1, [
            'candle_minutes' => 22, 'havdalah_method' => 'minutes', 'havdalah_value' => 72.0,
            'location_name' => 'Paris', 'latitude' => 48.85, 'longitude' => 2.35, 'timezone_id' => 'Europe/Paris',
            'language' => 'fr', 'israel' => false, 'mode' => 'lock_messages',
            'tefillin_enabled' => true, 'omer_enabled' => false, 'daf_enabled' => true, 'zmanim_enabled' => false,
        ], '17');
    }

    public function testExplicitRemoveRejoinPreservesSettingsAndConsentButRevokesStaleFlowsAndJobs(): void
    {
        $flows = new FlowTokenRepository($this->db, $this->clock);
        $token = $flows->create('17', 'group', '-1001', 2, 'edit');
        $jobs = new JobRepository($this->db, $this->clock);
        $old = $jobs->enqueue('-1001', 'daf', 'today', 1791882000, 1791885600, 2, ['text' => 'Old pending content']);
        $restore = $jobs->enqueue('-1001', 'restore', 'permission-debt', 1791882000, 1791885600, 2, []);
        $this->settings->deactivateGroup('-1001', '17');
        self::assertSame(0, $this->settings->getGroup('-1001')['active']);
        self::assertSame([], $this->settings->listGroups());
        $before = $this->db->pdo()->query("SELECT * FROM group_settings WHERE group_id='-1001'")->fetch();
        $this->clock->set(new DateTimeImmutable('2026-10-13T09:01:00Z'));
        $saved = $this->settings->reactivateGroup('-1001', '17', 'Fresh Telegram name');
        self::assertSame(1, $saved['active']);
        self::assertSame(3, $saved['revision']);
        self::assertSame('Fresh Telegram name', $saved['title']);
        self::assertCount(1, $this->settings->listGroups());
        $after = $this->db->pdo()->query("SELECT * FROM group_settings WHERE group_id='-1001'")->fetch();
        foreach (['revision', 'updated_at', 'updated_by'] as $field) {
            unset($before[$field], $after[$field]);
        }
        self::assertSame($before, $after);
        self::assertTrue($saved['tefillin_enabled']);
        self::assertFalse($saved['omer_enabled']);
        self::assertTrue($saved['daf_enabled']);
        self::assertFalse($saved['zmanim_enabled']);
        self::assertNull($flows->peek($token, '17', 'edit'));
        self::assertSame('cancelled', $jobs->get($old)['status']);
        self::assertSame('pending', $jobs->get($restore)['status']);
        $jobs->enqueue('-1001', 'daf', 'today', 1791882060, 1791885600, 3, ['text' => 'Replanned current content']);
        self::assertSame('pending', $jobs->get($old)['status']);
        self::assertSame(3, $jobs->get($old)['settings_revision']);
        self::assertSame(1, (int) $this->db->pdo()->query("SELECT COUNT(*) FROM audit_log WHERE action='group.reactivate'")->fetchColumn());
    }

    public function testAutomaticListImportCannotResurrectRemovedGroup(): void
    {
        $this->settings->deactivateGroup('-1001', '17');
        $before = $this->settings->getGroup('-1001');
        $root = sys_get_temp_dir() . '/shabbat-removed-import-' . bin2hex(random_bytes(8));
        mkdir($root . '/data', 0700, true);
        file_put_contents($root . '/data/DBgroups.txt', "-1001\n");
        try {
            $report = (new LegacyImporter($this->db, $this->clock))->importGroupListAutomatically($root);
            self::assertSame(['-1001'], $report['existing']);
            self::assertSame([], $report['added']);
            self::assertSame($before, $this->settings->getGroup('-1001'));
            self::assertSame([], $this->settings->listGroups());
        } finally {
            unlink($root . '/data/DBgroups.txt');
            rmdir($root . '/data');
            rmdir($root);
        }
    }

    public function testAlreadyActiveRegistrationIsIdempotentAndUnknownRejoinCannotCreateGroup(): void
    {
        $before = $this->settings->getGroup('-1001');
        self::assertSame($before, $this->settings->reactivateGroup('-1001', '17', 'Ignored active rename'));
        self::assertSame($before, $this->settings->getGroup('-1001'));
        $this->expectException(InvalidArgumentException::class);
        $this->settings->reactivateGroup('-999', '17');
    }

    public function testFailedActivationRollsBackRevisionTokenInvalidationAndMetadata(): void
    {
        $this->settings->deactivateGroup('-1001', '17');
        $flows = new FlowTokenRepository($this->db, $this->clock);
        $token = $flows->create('17', 'group', '-1001', 2, 'edit');
        $before = $this->settings->getGroup('-1001');
        $this->db->pdo()->exec("CREATE TRIGGER reject_rejoin BEFORE UPDATE OF active ON groups WHEN NEW.active=1 BEGIN SELECT RAISE(FAIL,'simulated disk error'); END");
        try {
            $this->settings->reactivateGroup('-1001', '17', 'Fresh title');
            self::fail('Activation database error was swallowed.');
        } catch (PDOException) {
            self::assertSame($before, $this->settings->getGroup('-1001'));
            self::assertNotNull($flows->peek($token, '17', 'edit'));
            self::assertSame(0, (int) $this->db->pdo()->query("SELECT COUNT(*) FROM audit_log WHERE action='group.reactivate'")->fetchColumn());
        }
    }
}
