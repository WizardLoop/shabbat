<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-only
namespace Shabbat\Tests;

use Closure;
use DateTimeImmutable;
use InvalidArgumentException;
use PDO;
use PDOException;
use RuntimeException;
use Amp;
use PHPUnit\Framework\TestCase;
use Shabbat\Storage\Database;
use Shabbat\Storage\SettingsRepository;
use Shabbat\Storage\FlowTokenRepository;
use Shabbat\Storage\LegacyImporter;
use Shabbat\Storage\BackupService;
use Shabbat\Storage\TemplateRepository;
use Shabbat\Storage\StaleSettings;
use Shabbat\Time\FrozenClock;

final class StorageTest extends TestCase
{
    private Database $db;
    private FrozenClock $clock;
    private SettingsRepository $settings;
    private array $temporaryDirectories = [];
    protected function setUp(): void
    {
        $this->db = Database::open(':memory:');
        $this->db->migrate();
        $this->clock = new FrozenClock(new DateTimeImmutable('2026-10-13T09:00:00Z'));
        $this->settings = new SettingsRepository($this->db, $this->clock);
    }
    protected function tearDown(): void
    {
        foreach ($this->temporaryDirectories as $directory) {
            $this->removeTemporaryDirectory($directory);
        }
    }
    private function temporary(): string
    {
        $path = sys_get_temp_dir() . '/shabbat-test-' . bin2hex(random_bytes(8));
        mkdir($path, 0700);
        $this->temporaryDirectories[] = $path;
        return $path;
    }
    private function removeTemporaryDirectory(string $directory): void
    {
        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $directory . '/' . $entry;
            if (is_dir($path) && !is_link($path)) {
                $this->removeTemporaryDirectory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($directory);
    }
    public function testMigrationsAreIdempotentAndIntegrityIsChecked(): void
    {
        self::assertSame([], $this->db->migrate());
        self::assertSame(['ok'], $this->db->integrity()['integrity_check']);
        self::assertSame([], $this->db->integrity()['foreign_key_check']);
        self::assertSame(1, (int)$this->db->pdo()->query('PRAGMA foreign_keys')->fetchColumn());
    }
    public function testChangedAppliedMigrationFails(): void
    {
        $dir = $this->temporary();
        $file = $dir . '/001_initial.sql';
        copy(dirname(__DIR__) . '/migrations/001_initial.sql', $file);
        file_put_contents($file, "\n-- unexpected mutation\n", FILE_APPEND);
        $this->expectException(RuntimeException::class);
        $this->db->migrate($dir);
    }
    public function testPersonalPreferencesDoNotModifyAnyGroup(): void
    {
        $group = $this->settings->ensureGroup('-100123', 'Test');
        $user = $this->settings->getUser('321');
        $this->settings->updateUser('321', $user['revision'], ['location_name' => 'Haifa','latitude' => 32.8,'longitude' => 34.98]);
        self::assertSame('Haifa', $this->settings->getUser('321')['location_name']);
        self::assertSame($group, $this->settings->getGroup('-100123'));
    }
    public function testSettingsCompareAndSwapAndTimezoneKeepsCoordinates(): void
    {
        $original = $this->settings->ensureGroup('-1', 'Known');
        $updated = $this->settings->updateGroup('-1', 1, ['timezone_id' => 'America/New_York','havdalah_value' => 8.5], '17');
        self::assertSame($original['latitude'], $updated['latitude']);
        self::assertSame($original['longitude'], $updated['longitude']);
        self::assertSame(8.5, $updated['havdalah_value']);
        self::assertSame(2, $updated['revision']);
        self::assertCount(1, $this->settings->listGroups(0, 20, 'Known'));
        $this->expectException(StaleSettings::class);
        $this->settings->updateGroup('-1', 1, ['language' => 'en'], '18');
    }
    public function testUnsupportedAngleAndFractionalFixedMinutesDoNotPersist(): void
    {
        $this->settings->ensureGroup('-1');
        foreach ([['havdalah_value' => 8.0],['havdalah_method' => 'minutes','havdalah_value' => 42.5],['omer_nightfall_value' => 9.0]] as $invalid) {
            try {
                $this->settings->updateGroup('-1', 1, $invalid, '17');
                self::fail('Unsupported method was saved.');
            } catch (InvalidArgumentException) {
                self::assertSame(1, $this->settings->getGroup('-1')['revision']);
            }
        }
        self::assertSame(7.083, $this->settings->updateGroup('-1', 1, ['havdalah_value' => 7.083], '17')['havdalah_value']);
    }
    public function testAllCandleOptionsPreserveIntegerValues(): void
    {
        $this->settings->ensureGroup('-1');
        $revision = 1;
        foreach ([10,15,18,20,22,30,40] as $minutes) {
            $row = $this->settings->updateGroup('-1', $revision++, ['candle_minutes' => $minutes], '17');
            self::assertSame($minutes, (int)$row['candle_minutes']);
        }
    }
    public function testTwoGroupFlowsAreIndependentAndCanFinishInReverseOrder(): void
    {
        $this->settings->ensureGroup('-1');
        $this->settings->ensureGroup('-2');
        $flows = new FlowTokenRepository($this->db, $this->clock);
        $one = $flows->create('17', 'group', '-1', 1, 'location');
        $two = $flows->create('17', 'group', '-2', 1, 'location');
        $flows->updatePayload($two, '17', 'location', ['geoname_id' => 294801]);
        self::assertSame(294801, $flows->peek($two, '17', 'location')['payload']['geoname_id']);
        $flows->confirm($two, '17', 'location', static fn(): bool=>true, ['location_name' => 'Haifa']);
        $flows->confirm($one, '17', 'location', static fn(): bool=>true, ['location_name' => 'Tel Aviv']);
        self::assertSame('Tel Aviv', $this->settings->getGroup('-1')['location_name']);
        self::assertSame('Haifa', $this->settings->getGroup('-2')['location_name']);
        self::assertNull($flows->peek($one, '17', 'location'));
    }
    public function testExpiredOtherUserAndReplayFlowsAreRejected(): void
    {
        $this->settings->ensureGroup('-1');
        $flows = new FlowTokenRepository($this->db, $this->clock);
        $token = $flows->create('17', 'group', '-1', 1, 'location', 5);
        self::assertNull($flows->peek($token, '18', 'location'));
        $this->clock->set(new DateTimeImmutable('2026-10-13T09:00:06Z'));
        self::assertNull($flows->peek($token, '17', 'location'));
        $this->expectException(StaleSettings::class);
        $flows->confirm($token, '17', 'location', static fn(): bool=>true, []);
    }
    public function testLossOfAdminRightsDoesNotConsumeOrModifyFlow(): void
    {
        $this->settings->ensureGroup('-1');
        $flows = new FlowTokenRepository($this->db, $this->clock);
        $token = $flows->create('17', 'group', '-1', 1, 'location');
        try {
            $flows->confirm($token, '17', 'location', static fn(): bool=>false, ['language' => 'en']);
            self::fail('Unauthorized confirmation succeeded.');
        } catch (RuntimeException) {
        }
        self::assertNotNull($flows->peek($token, '17', 'location'));
        self::assertSame('he', $this->settings->getGroup('-1')['language']);
    }
    public function testRevisionChangeRevokesOldFlowAndSharingCannotWrite(): void
    {
        $this->settings->ensureGroup('-1');
        $flows = new FlowTokenRepository($this->db, $this->clock);
        $old = $flows->create('17', 'group', '-1', 1, 'location');
        $this->settings->updateGroup('-1', 1, ['language' => 'en'], '18');
        self::assertNull($flows->peek($old, '17', 'location'));
        $sharing = $flows->create('17', 'share', '-1', 2, 'share');
        $this->expectException(RuntimeException::class);
        $flows->confirm($sharing, '17', 'share', static fn(): bool=>true, ['location_name' => 'Bad']);
    }
    public function testAuthorizationRunsOutsideDatabaseTransaction(): void
    {
        $this->settings->ensureGroup('-1');
        $flows = new FlowTokenRepository($this->db, $this->clock);
        $token = $flows->create('17', 'group', '-1', 1, 'location');
        $confirmed = $flows->confirm($token, '17', 'location', function (): bool {
            $this->db->transaction(static fn(): bool=>true);
            return true;
        }, ['language' => 'en']);
        self::assertSame('en', $confirmed['language']);
    }
    public function testLegacyDryRunThenIdempotentImportPreservesEditsMediaAndSnapshots(): void
    {
        $fixture = __DIR__ . '/Fixtures/legacy';
        $importer = new LegacyImporter($this->db, $this->clock);
        $dry = $importer->run($fixture, true);
        self::assertSame(2, $dry['counts']['groups']);
        self::assertNull($this->settings->getGroup('-100101'));
        $importer->run($fixture, false);
        $templates = new TemplateRepository($this->db, $this->clock);
        $template = $templates->get('-100101', 'close');
        self::assertSame('fixture-media-id', $template['media']['bot_api_file_id']);
        self::assertSame('bold', $template['entities'][0]['_']);
        self::assertSame('custom', $template['buttons_mode']);
        self::assertTrue($template['buttons'][0][0]['style']['bg_success']);
        $this->settings->updateGroup('-100101', 1, ['location_name' => 'Edited','tefillin_enabled' => true], '17');
        $templates->save('-100101', 'close', ['text' => 'Changed','entities' => [],'buttons' => []], '17', 1);
        $importer->run($fixture, false);
        self::assertSame('Edited', $this->settings->getGroup('-100101')['location_name']);
        self::assertTrue($this->settings->getGroup('-100101')['tefillin_enabled']);
        self::assertSame('Changed', $templates->get('-100101', 'close')['text']);
        self::assertSame(2, (int)$this->db->pdo()->query('SELECT COUNT(*) FROM groups')->fetchColumn());
        self::assertSame('recovery_required', $this->db->pdo()->query("SELECT status FROM group_lock_state WHERE group_id='-100101'")->fetchColumn());
        self::assertFalse($this->settings->getGroup('-100102')['omer_enabled']);
        self::assertSame(10, (int)$this->settings->getGroup('-100102')['close_before_candles_minutes']);
    }
    public function testAutomaticGroupListRecognitionNeedsNoAddAndPreservesEditedGroups(): void
    {
        $fixture = __DIR__ . '/Fixtures/legacy';
        $importer = new LegacyImporter($this->db, $this->clock);
        self::assertCount(2, $importer->importGroupListAutomatically($fixture)['added']);
        $this->settings->updateGroup('-100101', 1, ['mode' => 'messages','language' => 'en'], '17');
        self::assertSame([], $importer->importGroupListAutomatically($fixture)['added']);
        self::assertSame('messages', $this->settings->getGroup('-100101')['mode']);
        self::assertSame('en', $this->settings->getGroup('-100101')['language']);
        self::assertFalse($this->settings->getGroup('-100102')['daf_enabled']);
        self::assertTrue($this->settings->getGroup('-100101')['times_alert_enabled']);
    }
    public function testConsistentWalBackupAndStoppedRestore(): void
    {
        $dir = $this->temporary();
        $path = $dir . '/app.sqlite';
        $backup = $dir . '/snapshot.sqlite';
        $database = Database::open($path);
        $database->migrate();
        $settings = new SettingsRepository($database, $this->clock);
        $settings->ensureGroup('-9007199254740993', 'WAL group');
        self::assertSame('wal', $database->pdo()->query('PRAGMA journal_mode')->fetchColumn());
        $manifest = (new BackupService())->backup($database, $backup);
        self::assertSame(hash_file('sha256', $backup), $manifest['sha256']);
        unset($settings, $database);
        $restored = BackupService::restore($backup, $dir . '/restored.sqlite', true);
        self::assertSame(realpath($dir) . DIRECTORY_SEPARATOR . 'restored.sqlite', $restored['restored']);
        $copy = Database::open($restored['restored']);
        self::assertSame('-9007199254740993', (new SettingsRepository($copy, $this->clock))->getGroup('-9007199254740993')['group_id']);
        unset($copy);
    }
    public function testRestoreRequiresStoppedWriters(): void
    {
        $this->expectException(RuntimeException::class);
        BackupService::restore('does-not-matter', 'does-not-matter', false);
    }
    public function testTemplateConfirmationRollbackKeepsTokenRevisionAndContent(): void
    {
        $this->settings->ensureGroup('-1');
        $templates = new TemplateRepository($this->db, $this->clock);
        $templates->save('-1', 'open', ['text' => 'Original'], '17', 0);
        $flows = new FlowTokenRepository($this->db, $this->clock);
        $token = $flows->create('17', 'group', '-1', 1, 'template');
        try {
            $flows->confirmAndApply(
                $token,
                '17',
                'template',
                static fn(): bool=>true,
                [],
                static function (PDO $pdo) use ($templates): void {
                    $templates->saveInTransaction($pdo, '-1', 'open', ['text' => 'Wrong stale edit'], '17', 99);
                }
            );
            self::fail('Stale template mutation committed.');
        } catch (StaleSettings) {
        }
        self::assertNotNull($flows->peek($token, '17', 'template'));
        self::assertSame(1, $this->settings->getGroup('-1')['revision']);
        self::assertSame('Original', $templates->get('-1', 'open')['text']);
        $flows->confirmAndApply(
            $token,
            '17',
            'template',
            static fn(): bool=>true,
            [],
            static function (PDO $pdo) use ($templates): void {
                $templates->saveInTransaction($pdo, '-1', 'open', ['text' => 'Committed'], '17', 1);
            }
        );
        self::assertNull($flows->peek($token, '17', 'template'));
        self::assertSame(2, $this->settings->getGroup('-1')['revision']);
        self::assertSame('Committed', $templates->get('-1', 'open')['text']);
    }
    public function testTemplateRevisionProtectsConcurrentEditors(): void
    {
        $this->settings->ensureGroup('-1');
        $templates = new TemplateRepository($this->db, $this->clock);
        $templates->save('-1', 'open', ['text' => 'First','entities' => [],'media' => ['bot_api_file_id' => 'id'],'buttons' => []], '17', 0);
        $templates->save('-1', 'open', ['text' => 'Second','entities' => [],'buttons' => []], '18', 1);
        $this->expectException(StaleSettings::class);
        $templates->save('-1', 'open', ['text' => 'Stale'], '17', 1);
    }
}
