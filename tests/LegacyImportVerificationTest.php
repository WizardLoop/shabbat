<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-only
namespace Shabbat\Tests;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Shabbat\Storage\Database;
use Shabbat\Storage\LegacyImporter;
use Shabbat\Storage\SettingsRepository;
use Shabbat\Time\FrozenClock;

final class LegacyImportVerificationTest extends TestCase
{
    private string $root;
    private Database $db;
    private FrozenClock $clock;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/shabbat-import-review-' . bin2hex(random_bytes(8));
        mkdir($this->root . '/data/-1001', 0700, true);
        mkdir($this->root . '/data/17', 0700);
        file_put_contents($this->root . '/data/DBgroups.txt', "-1001\n");
        file_put_contents($this->root . '/data/-1001/MsgCloser.txt', "Group text\r\n");
        file_put_contents($this->root . '/data/-1001/alertshabat.txt', '1');
        file_put_contents($this->root . '/data/-1001/alertshabat2.txt', '1');
        file_put_contents($this->root . '/data/17/txt.txt', "Private draft\r\n");
        file_put_contents($this->root . '/data/17/ent.txt', '{malformed');
        file_put_contents($this->root . '/data/17/groupid.txt', '-1001');
        file_put_contents($this->root . '/ids.txt', "17\n17\n-1001\n");
        $this->db = Database::open(':memory:');
        $this->db->migrate();
        $this->clock = new FrozenClock(new DateTimeImmutable('2026-10-21T09:00:00Z'));
    }

    protected function tearDown(): void
    {
        $resolved = realpath($this->root);
        $prefix = realpath(sys_get_temp_dir()) . DIRECTORY_SEPARATOR . 'shabbat-import-review-';
        if ($resolved === false || !str_starts_with($resolved, $prefix)) {
            throw new \RuntimeException('Fixture cleanup escaped its temporary directory.');
        }
        $this->removeTree($resolved);
    }

    private function removeTree(string $path): void
    {
        foreach (scandir($path) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $child = $path . '/' . $name;
            if (is_dir($child) && !is_link($child)) {
                $this->removeTree($child);
            } else {
                unlink($child);
            }
        }
        rmdir($path);
    }

    public function testPreviewAndApplyIncludeIdenticalPrivateTemplateWarningsAndVerifyValues(): void
    {
        $importer = new LegacyImporter($this->db, $this->clock);
        $preview = $importer->run($this->root);
        self::assertSame(2, $preview['counts']['templates']);
        self::assertSame('not_applied', $preview['verification']['status']);
        self::assertStringContainsString('malformed JSON', implode("\n", $preview['warnings']));
        self::assertSame(0, (int)$this->db->pdo()->query('SELECT COUNT(*) FROM groups')->fetchColumn());
        $applied = $importer->run($this->root, false, $preview);
        self::assertSame($preview['templates'], $applied['templates']);
        self::assertSame($preview['warnings'], $applied['warnings']);
        self::assertSame($preview['plan_sha256'], $applied['plan_sha256']);
        self::assertSame('passed', $applied['verification']['status']);
        self::assertGreaterThan(0, $applied['verification']['inserted_records']);
        self::assertSame("Private draft\r\n", $this->db->pdo()->query("SELECT text FROM message_templates WHERE user_id='17'")->fetchColumn());
        self::assertSame('{malformed', $this->db->pdo()->query("SELECT value_text FROM admin_state WHERE user_id='17' AND state_key='ent.txt'")->fetchColumn());
        self::assertSame(0, (int)$this->db->pdo()->query('SELECT COUNT(*) FROM conversation_states')->fetchColumn());
    }

    public function testChangedLegacySourceAfterPreviewIsRejectedBeforeApplicationWrites(): void
    {
        $importer = new LegacyImporter($this->db, $this->clock);
        $preview = $importer->run($this->root);
        file_put_contents($this->root . '/data/17/txt.txt', 'Changed after operator review');
        try {
            $importer->run($this->root, false, $preview);
            self::fail('Changed source was accepted.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('no longer matches', $error->getMessage());
        }
        foreach (['groups','users','message_templates','admin_state','legacy_imports'] as $table) {
            self::assertSame(0, (int)$this->db->pdo()->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn());
        }
    }

    public function testChangedDestinationAfterPreviewIsRejectedAndEditedPreferencesSurvive(): void
    {
        $settings = new SettingsRepository($this->db, $this->clock);
        $settings->ensureGroup('-1001');
        $importer = new LegacyImporter($this->db, $this->clock);
        $preview = $importer->run($this->root);
        $settings->updateGroup('-1001', 1, ['language' => 'en','mode' => 'messages'], '17');
        try {
            $importer->run($this->root, false, $preview);
            self::fail('Changed destination was accepted.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('no longer matches', $error->getMessage());
        }
        self::assertSame('en', $settings->getGroup('-1001')['language']);
        self::assertSame(0, (int)$this->db->pdo()->query('SELECT COUNT(*) FROM message_templates')->fetchColumn());
        $reviewed = $importer->run($this->root);
        self::assertContains('language', $reviewed['groups'][0]['different_settings']);
        $applied = $importer->run($this->root, false, $reviewed);
        self::assertSame('passed', $applied['verification']['status']);
        self::assertSame('messages', $settings->getGroup('-1001')['mode']);
    }

    public function testDuplicatesInvalidIdentifiersAndOrphanDirectoriesAreExplicit(): void
    {
        file_put_contents($this->root . '/data/DBgroups.txt', "-1001\n-1001\n0\n-0\n001\n-01001\n9223372036854775808\n-9223372036854775809\n");
        mkdir($this->root . '/data/-1002', 0700);
        file_put_contents($this->root . '/data/-1002/MsgCloser.txt', 'Old removed subscription');
        $report = (new LegacyImporter($this->db, $this->clock))->run($this->root, false);
        self::assertSame(['-1001'], array_column($report['groups'], 'group_id'));
        self::assertSame(['-1002'], $report['orphan_group_directories']);
        self::assertSame([
            ['source' => 'DBgroups.txt','identifier' => '-1001','action' => 'deduplicated'],
            ['source' => 'ids.txt','identifier' => '17','action' => 'deduplicated'],
        ], $report['duplicates']);
        self::assertSame(['-1001'], $this->db->pdo()->query('SELECT group_id FROM groups')->fetchAll(\PDO::FETCH_COLUMN));
        self::assertFileExists($this->root . '/data/-1002/MsgCloser.txt');
        self::assertSame(6, count(array_filter($report['warnings'], static fn(string $warning): bool => str_starts_with($warning, 'Skipped invalid group identifier'))));
    }

    public function testRepeatImportPreservesEditedTemplatesDraftsAndPreferences(): void
    {
        $importer = new LegacyImporter($this->db, $this->clock);
        $importer->run($this->root, false);
        $this->db->pdo()->exec("UPDATE message_templates SET text='Edited live template' WHERE group_id='-1001'");
        $this->db->pdo()->exec("UPDATE admin_state SET value_text='Edited live draft' WHERE user_id='17' AND state_key='txt.txt'");
        $before = $this->db->pdo()->query('SELECT * FROM message_templates ORDER BY template_id')->fetchAll();
        $preview = $importer->run($this->root);
        self::assertFalse($preview['templates'][0]['matches_source']);
        $report = $importer->run($this->root, false, $preview);
        self::assertSame(0, $report['verification']['inserted_records']);
        self::assertGreaterThan(0, $report['verification']['preserved_records']);
        self::assertSame($before, $this->db->pdo()->query('SELECT * FROM message_templates ORDER BY template_id')->fetchAll());
        self::assertSame('Edited live draft', $this->db->pdo()->query("SELECT value_text FROM admin_state WHERE user_id='17' AND state_key='txt.txt'")->fetchColumn());
    }

    public function testLateUnreadableInputFailsPreflightBeforeAnyApplicationRecord(): void
    {
        file_put_contents($this->root . '/data/17/txt.txt', str_repeat('x', 2 * 1024 * 1024 + 1));
        try {
            (new LegacyImporter($this->db, $this->clock))->run($this->root, false);
            self::fail('Oversized input was accepted.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('too large', $error->getMessage());
        }
        self::assertSame(0, (int)$this->db->pdo()->query('SELECT COUNT(*) FROM groups')->fetchColumn());
        self::assertSame(0, (int)$this->db->pdo()->query('SELECT COUNT(*) FROM legacy_imports')->fetchColumn());
    }

    public function testImportedPermissionSnapshotRemainsUntrustedAndRecoveryRequired(): void
    {
        file_put_contents($this->root . '/data/-1001/chatb1.json', '{"_":"chatBannedRights","until_date":0,"send_messages":false}');
        $report = (new LegacyImporter($this->db, $this->clock))->run($this->root, false);
        $row = $this->db->pdo()->query("SELECT * FROM group_lock_state WHERE group_id='-1001'")->fetch();
        self::assertSame('recovery_required', $row['status']);
        self::assertSame('legacy_unverified', $row['snapshot_provenance']);
        self::assertNull($row['snapshot_evidence_json']);
        self::assertSame('requires_current_permission_and_calendar_check', $report['recovery'][0]['action']);
        self::assertSame('none', $report['users'][0]['authority']);
        self::assertSame(0, (int)$this->db->pdo()->query('SELECT COUNT(*) FROM conversation_states')->fetchColumn());
    }

    public function testPostImportVerificationRejectsAnUnexpectedWriteToPreservedRecords(): void
    {
        (new SettingsRepository($this->db, $this->clock))->ensureGroup('-1001', 'Original');
        $this->db->pdo()->exec("CREATE TRIGGER import_write_fault AFTER INSERT ON message_templates BEGIN UPDATE groups SET title='Unexpected write' WHERE group_id='-1001'; END");
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('existing record changed');
        (new LegacyImporter($this->db, $this->clock))->run($this->root, false);
    }
    public function testCliBindsReviewedPreviewAndReportsVerifiedBackup(): void
    {
        $database = $this->root . '/destination.sqlite';
        $destination = Database::open($database);
        $destination->migrate();
        unset($destination);
        mkdir($this->root . '/backups', 0700);
        [$exit, $output, $errors] = $this->command(['--db=' . $database,'--legacy=' . $this->root,'--dry-run']);
        self::assertSame(0, $exit, $errors);
        $preview = $this->jsonOutput($output);
        file_put_contents($this->root . '/preview.json', Database::encode($preview));
        $arguments = ['--db=' . $database,'--legacy=' . $this->root,'--apply','--writers-stopped',
            '--backup-dir=' . $this->root . '/backups','--expect-report=' . $this->root . '/preview.json'];
        [$exit, $output, $errors] = $this->command($arguments);
        self::assertSame(0, $exit, $errors);
        $applied = $this->jsonOutput($output);
        self::assertSame('passed', $applied['verification']['status']);
        self::assertSame('ok', $applied['pre_import_backup']['integrity']);
        self::assertSame($applied['pre_import_backup']['sha256'], hash_file('sha256', $applied['pre_import_backup']['path']));
        self::assertFileExists($applied['pre_import_backup']['path'] . '.manifest.json');
        [$exit, , $errors] = $this->command($arguments);
        self::assertSame(1, $exit);
        self::assertStringContainsString('no longer matches', $errors);
        $destination = Database::open($database);
        self::assertSame(1, (int)$destination->pdo()->query('SELECT COUNT(*) FROM groups')->fetchColumn());
        unset($destination);
    }

    private function command(array $arguments): array
    {
        $process = proc_open(
            [PHP_BINARY, '-d', 'opcache.enable_cli=0', dirname(__DIR__) . '/bin/import-legacy.php', ...$arguments],
            [0 => ['pipe','r'],1 => ['pipe','w'],2 => ['pipe','w']],
            $pipes,
            dirname(__DIR__)
        );
        if (!is_resource($process)) {
            throw new \RuntimeException('Cannot launch isolated import CLI.');
        }
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($process), $output, $errors];
    }

    private function jsonOutput(string $output): array
    {
        foreach (explode("\n", $output) as $line) {
            if (str_starts_with($line, '{')) {
                return json_decode($line, true, 64, JSON_THROW_ON_ERROR);
            }
        }
        throw new \RuntimeException('Import CLI did not return its JSON report.');
    }
}
