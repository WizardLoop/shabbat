<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace Shabbat\Tests;

use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Shabbat\Scheduling\JobRepository;
use Shabbat\Storage\Database;
use Shabbat\Storage\FlowTokenRepository;
use Shabbat\Storage\SettingsRepository;
use Shabbat\Time\FrozenClock;

final class GroupTitleTest extends TestCase
{
    public function testFreshNamePreservesSettingsRevisionLiveTokensAndDeliveryJobs(): void
    {
        $db = Database::open(':memory:');
        $db->migrate();
        $clock = new FrozenClock(new DateTimeImmutable('2026-10-13T09:00:00Z'));
        $settings = new SettingsRepository($db, $clock);
        $settings->ensureGroup('-1001', '-1001');
        $settings->updateGroup('-1001', 1, ['language' => 'en', 'location_name' => 'Edited city', 'mode' => 'messages', 'daf_enabled' => true], '17');
        $flows = new FlowTokenRepository($db, $clock);
        $token = $flows->create('17', 'group', '-1001', 2, 'edit');
        $jobs = new JobRepository($db, $clock);
        $job = $jobs->enqueue('-1001', 'daf', 'civil-date-fixture', 1791882000, 1791889200, 2, ['text' => 'Preserved delivery']);
        $tables = ['group_settings', 'conversation_states', 'scheduled_jobs', 'delivery_log', 'audit_log'];
        $before = [];
        foreach ($tables as $table) {
            $before[$table] = $db->pdo()->query('SELECT * FROM ' . $table)->fetchAll();
        }
        $clock->set(new DateTimeImmutable('2026-10-13T09:01:00Z'));
        $title = 'קהילת שבת 😀 Paris';
        $settings->refreshGroupTitle('-1001', $title);
        foreach ($tables as $table) {
            self::assertSame($before[$table], $db->pdo()->query('SELECT * FROM ' . $table)->fetchAll(), $table . ' changed on a title refresh');
        }
        self::assertSame($title, $settings->getGroup('-1001')['title']);
        self::assertSame(2, $settings->getGroup('-1001')['revision']);
        self::assertSame(1791882060, (int) $db->pdo()->query("SELECT updated_at FROM groups WHERE group_id='-1001'")->fetchColumn());
        self::assertCount(1, $settings->listGroups(0, 20, 'קהילת'));
        self::assertNotNull($flows->peek($token, '17', 'edit'));
        self::assertSame('pending', $jobs->get($job)['status']);
        $saved = $flows->confirm($token, '17', 'edit', static fn (): bool => true, ['language' => 'fr']);
        self::assertSame(3, $saved['revision']);
        self::assertSame($title, $saved['title']);
    }

    public function testRefreshNeverRegistersAnUnknownPeer(): void
    {
        $db = Database::open(':memory:');
        $db->migrate();
        $settings = new SettingsRepository($db, new FrozenClock(new DateTimeImmutable('2026-10-13T09:00:00Z')));
        try {
            $settings->refreshGroupTitle('-1009876', 'Unknown channel');
            self::fail('An unknown peer was implicitly registered.');
        } catch (InvalidArgumentException) {
            self::assertNull($settings->getGroup('-1009876'));
            self::assertSame(0, (int) $db->pdo()->query('SELECT COUNT(*) FROM groups')->fetchColumn());
            self::assertSame(0, (int) $db->pdo()->query('SELECT COUNT(*) FROM group_settings')->fetchColumn());
        }
    }

    public function testInvalidNamesAndIdentifiersCannotChangeStoredMetadata(): void
    {
        $db = Database::open(':memory:');
        $db->migrate();
        $settings = new SettingsRepository($db, new FrozenClock(new DateTimeImmutable('2026-10-13T09:00:00Z')));
        $original = $settings->ensureGroup('-1001', 'Original name');
        foreach (['', '   ', "bad\0name", "bad\nname", "bad\xFFname", str_repeat('א', 257)] as $invalid) {
            try {
                $settings->refreshGroupTitle('-1001', $invalid);
                self::fail('Invalid group name was accepted.');
            } catch (InvalidArgumentException) {
                self::assertSame($original, $settings->getGroup('-1001'));
            }
        }
        $this->expectException(InvalidArgumentException::class);
        $settings->refreshGroupTitle('invalid-id', 'Otherwise valid name');
    }
}
