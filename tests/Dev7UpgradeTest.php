<?php

// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);

namespace Shabbat\Tests;

use PHPUnit\Framework\TestCase;
use Shabbat\Storage\Database;
use Shabbat\Storage\SettingsRepository;
use Shabbat\Time\FrozenClock;

final class Dev7UpgradeTest extends TestCase
{
    public function testDev6ConflictAndSettingsSurviveAdditiveMigrationsAndRepeat(): void
    {
        $directory = sys_get_temp_dir() . '/shabbat-dev7-schema-' . bin2hex(random_bytes(10));
        mkdir($directory, 0700);
        $copies = [];
        try {
            foreach (glob(dirname(__DIR__) . '/migrations/*.sql') as $file) {
                if ((int)basename($file) <= 12) {
                    $copy = $directory . '/' . basename($file);
                    self::assertTrue(copy($file, $copy));
                    $copies[] = $copy;
                }
            }
            $db = Database::open(':memory:');
            self::assertCount(12, $db->migrate($directory));
            $clock = new FrozenClock(new \DateTimeImmutable('2026-10-15T09:00:00Z'));
            $settings = new SettingsRepository($db, $clock);
            $settings->ensureGroup('-1001', 'Preserved group', ['language' => 'pt-br', 'candle_minutes' => 40]);
            $settings->getUser('42');
            $original = '{"_":"chatBannedRights","send_plain":false,"send_photos":true,"until_date":0}';
            $requested = '{"_":"chatBannedRights","send_plain":true,"send_photos":true,"until_date":0}';
            $db->pdo()->prepare('INSERT INTO group_lock_state(group_id,event_key,status,snapshot_json,applied_json,previous_applied_json,snapshot_sha256,prepared_at,last_error) VALUES(?,?,?,?,?,?,?,?,?)')
                ->execute(['-1001', 'retained-event', 'conflict', $original, $requested, $requested, hash('sha256', $original), 1792054800, 'Retained conflict evidence']);
            $before = [];
            foreach (['groups', 'group_settings', 'users', 'user_preferences', 'group_lock_state', 'schema_migrations'] as $table) {
                $before[$table] = $db->pdo()->query('SELECT * FROM ' . $table . ' ORDER BY 1')->fetchAll();
            }
            self::assertSame(['013_permission_recovery.sql', '014_diagnostic_panels.sql', '015_permission_snapshot_provenance.sql', '016_delivery_safety.sql'], $db->migrate());
            self::assertSame([], $db->migrate());
            $db->assertCurrentSchema();
            foreach ($before as $table => $rows) {
                $after = $db->pdo()->query('SELECT * FROM ' . $table . ' ORDER BY 1')->fetchAll();
                foreach ($rows as $index => $row) {
                    self::assertSame($row, array_intersect_key($after[$index], $row), $table);
                }
            }
            $state = $db->pdo()->query('SELECT * FROM group_lock_state')->fetch();
            self::assertSame('legacy_unverified', $state['snapshot_provenance']);
            self::assertNull($state['snapshot_evidence_json']);
            self::assertNull($state['recovery_baseline_json']);
            self::assertNull($state['recovery_baseline_sha256']);
            foreach (['permission_operations', 'permission_recovery_confirmations', 'diagnostic_panels'] as $table) {
                self::assertSame(0, (int)$db->pdo()->query('SELECT count(*) FROM ' . $table)->fetchColumn());
            }
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
