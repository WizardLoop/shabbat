<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace Shabbat\Tests;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Shabbat\Storage\Database;

final class RuntimeMaintenancePathsTest extends TestCase
{
    private array $saved = [];
    private string $fixture;

    protected function setUp(): void
    {
        $this->fixture = sys_get_temp_dir() . '/shabbat-cli-' . bin2hex(random_bytes(8));
        mkdir($this->fixture, 0700);
        foreach (['DATA_DIR', 'APP_DB_PATH', 'SESSION_PATH', 'HEARTBEAT_PATH'] as $name) {
            $this->saved[$name] = getenv($name);
            putenv($name);
        }
        putenv('DATA_DIR=' . $this->fixture . '/state');
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $name => $value) {
            putenv($value === false ? $name : $name . '=' . $value);
        }
        $resolved = realpath($this->fixture);
        $allowed = realpath(sys_get_temp_dir()) . DIRECTORY_SEPARATOR . 'shabbat-cli-';
        if ($resolved === false || !str_starts_with($resolved, $allowed)) {
            throw new \RuntimeException('Test cleanup target is outside its isolated fixture.');
        }
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($resolved, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($resolved);
    }

    private function command(string $script, array $arguments = [], int $expectedExit = 0): array
    {
        $process = proc_open([PHP_BINARY, dirname(__DIR__) . '/bin/' . $script, ...$arguments], [
            0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
        ], $pipes, dirname(__DIR__));
        if (!is_resource($process)) {
            throw new \RuntimeException('Unable to launch isolated maintenance test.');
        }
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame($expectedExit, proc_close($process), (string) $errors);
        foreach (explode("\n", (string) $output) as $line) {
            if (str_starts_with(trim($line), '{')) {
                return json_decode($line, true, flags: JSON_THROW_ON_ERROR);
            }
        }
        throw new \RuntimeException('Maintenance command did not return its JSON report.');
    }

    public function testDataDirectoryOnlyDeploymentUsesOneDatabaseAcrossMaintenanceAndHealth(): void
    {
        $migration = $this->command('migrate.php', ['--writers-stopped']);
        self::assertContains('005_known_broadcast_peers.sql', $migration['migrations']);
        $database = $this->fixture . '/state/shabbat.sqlite';
        self::assertFileExists($database);
        self::assertSame(['ok'], $migration['checks']['integrity_check']);
        $this->command('backup.php', ['--directory=' . $this->fixture . '/backups', '--keep=2']);
        $backups = glob($this->fixture . '/backups/*.sqlite');
        self::assertIsArray($backups);
        self::assertCount(1, $backups);
        $restored = $this->fixture . '/restored.sqlite';
        $this->command('restore.php', ['--backup=' . $backups[0], '--db=' . $restored, '--writers-stopped']);
        self::assertFileExists($restored);
        self::assertSame(['ok'], Database::open($restored)->integrity()['integrity_check']);
        $this->command('import-legacy.php', ['--legacy=' . dirname(__DIR__) . '/tests/Fixtures/legacy', '--dry-run']);
        self::assertSame(0, Database::open($database)->pdo()->query('SELECT count(*) FROM groups')->fetchColumn());
        $health = $this->command('health.php', expectedExit: 1);
        self::assertFalse($health['healthy']);
        file_put_contents($this->fixture . '/state/scheduler-heartbeat.json', json_encode(['timestamp' => time(), 'notification_timestamp' => time()], JSON_THROW_ON_ERROR));
        $health = $this->command('health.php');
        self::assertTrue($health['healthy']);
        self::assertFalse($health['deep_check']);
        self::assertTrue($health['checks']['connection_check']);
        $deep = $this->command('health.php', ['--deep']);
        self::assertTrue($deep['healthy']);
        self::assertSame(['ok'], $deep['checks']['integrity_check']);
        self::assertSame([], $deep['checks']['foreign_key_check']);
        file_put_contents($this->fixture . '/state/scheduler-heartbeat.json', json_encode(['timestamp' => time() - 181, 'notification_timestamp' => time()], JSON_THROW_ON_ERROR));
        self::assertFalse($this->command('health.php', expectedExit: 1)['healthy']);
    }
}
