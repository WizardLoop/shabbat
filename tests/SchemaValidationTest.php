<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace Shabbat\Tests;

use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Shabbat\Storage\Database;

final class SchemaValidationTest extends TestCase
{
    private array $temporary = [];

    protected function tearDown(): void
    {
        foreach ($this->temporary as $directory) {
            foreach (glob($directory . '/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }

    private function definitions(bool $all = true): string
    {
        $path = sys_get_temp_dir() . '/shabbat-schema-' . bin2hex(random_bytes(8));
        mkdir($path, 0700);
        $this->temporary[] = $path;
        $files = $all ? glob(dirname(__DIR__) . '/migrations/*.sql') : [dirname(__DIR__) . '/migrations/001_initial.sql'];
        foreach ($files as $file) {
            copy($file, $path . '/' . basename($file));
        }
        return $path;
    }

    private function rejectReadOnly(Database $database, ?string $directory = null): string
    {
        $before = $database->pdo()->query('SELECT total_changes()')->fetchColumn();
        $schema = $database->pdo()->query('SELECT name,sql FROM sqlite_master ORDER BY name')->fetchAll();
        $database->pdo()->exec('PRAGMA query_only=ON');
        try {
            $database->assertCurrentSchema($directory);
            self::fail('Partial or mismatched schema was accepted.');
        } catch (RuntimeException $error) {
            self::assertSame($before, $database->pdo()->query('SELECT total_changes()')->fetchColumn());
            self::assertSame($schema, $database->pdo()->query('SELECT name,sql FROM sqlite_master ORDER BY name')->fetchAll());
            return $error->getMessage();
        }
    }

    public function testFullyMigratedCurrentDefinitionsPassWithQueryOnlyAndNoWrites(): void
    {
        $db = Database::open(':memory:');
        $db->migrate();
        $expected = array_map('basename', glob(dirname(__DIR__) . '/migrations/*.sql'));
        sort($expected, SORT_STRING);
        self::assertSame($expected, $db->pdo()->query('SELECT version FROM schema_migrations ORDER BY version')->fetchAll(PDO::FETCH_COLUMN));
        $before = $db->pdo()->query('SELECT total_changes()')->fetchColumn();
        $db->pdo()->exec('PRAGMA query_only=ON');
        $db->assertCurrentSchema();
        self::assertSame($before, $db->pdo()->query('SELECT total_changes()')->fetchColumn());
    }

    public function testOnlyInitialMigrationFailsBeforeAnyAutoMigrationOrWrite(): void
    {
        $db = Database::open(':memory:');
        $db->migrate($this->definitions(false));
        self::assertStringContainsString('Missing required schema migrations', $this->rejectReadOnly($db));
        self::assertSame(['001_initial.sql'], $db->pdo()->query('SELECT version FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN));
    }

    public function testMissingHistoryIsRejectedWithoutCreatingAnyTable(): void
    {
        $db = Database::open(':memory:');
        self::assertStringContainsString('Schema migration history is missing', $this->rejectReadOnly($db));
        self::assertSame(0, (int) $db->pdo()->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table'")->fetchColumn());
    }

    public function testTamperedRecordedChecksumIsRejectedWithoutRepair(): void
    {
        $db = Database::open(':memory:');
        $db->migrate();
        $db->pdo()->prepare("UPDATE schema_migrations SET checksum=? WHERE version='001_initial.sql'")->execute([str_repeat('0', 64)]);
        self::assertStringContainsString('checksum does not match required source', $this->rejectReadOnly($db));
        self::assertSame(str_repeat('0', 64), $db->pdo()->query("SELECT checksum FROM schema_migrations WHERE version='001_initial.sql'")->fetchColumn());
    }

    public function testTamperedAppliedSourceAndMissingDefinitionsAreRejected(): void
    {
        $directory = $this->definitions();
        $db = Database::open(':memory:');
        $db->migrate($directory);
        file_put_contents($directory . '/001_initial.sql', "\n-- changed applied source\n", FILE_APPEND);
        self::assertStringContainsString('checksum does not match required source', $this->rejectReadOnly($db, $directory));
        $partial = $this->definitions(false);
        self::assertStringContainsString('Unknown schema migration version', $this->rejectReadOnly($db, $partial));
    }

    public function testUnknownFutureSchemaCannotBeOpenedByOlderCode(): void
    {
        $db = Database::open(':memory:');
        $db->migrate();
        $db->pdo()->prepare('INSERT INTO schema_migrations(version,checksum,applied_at) VALUES(?,?,?)')->execute(['999_future.sql', str_repeat('0', 64), 0]);
        self::assertStringContainsString('Unknown schema migration version', $this->rejectReadOnly($db));
        self::assertSame(1, (int) $db->pdo()->query("SELECT COUNT(*) FROM schema_migrations WHERE version='999_future.sql'")->fetchColumn());
    }
}
