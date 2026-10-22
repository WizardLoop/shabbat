<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-only
require dirname(__DIR__) . '/vendor/autoload.php';
use Shabbat\Scheduling\InstanceLock;
use Shabbat\Storage\BackupService;
use Shabbat\Storage\Database;
use Shabbat\Storage\LegacyImporter;
use Shabbat\Time\SystemClock;
$options = getopt('', ['db:','legacy:','dry-run','apply','writers-stopped','backup-dir:','expect-report:']);
try {
    if (!isset($options['legacy'])) {
        throw new RuntimeException('Specify the read-only legacy source using --legacy=/path/to/old/bot or /path/to/data.');
    }
    if (isset($options['dry-run'], $options['apply'])) {
        throw new RuntimeException('Choose either --dry-run or --apply.');
    }
    $dryRun = !isset($options['apply']);
    $expectedReport = null;
    if (isset($options['expect-report'])) {
        $reportPath = (string)$options['expect-report'];
        if (!is_file($reportPath) || is_link($reportPath) || filesize($reportPath) > 16 * 1024 * 1024) {
            throw new RuntimeException('Expected report must be a readable regular JSON file of at most 16 MiB.');
        }
        $contents = file_get_contents($reportPath);
        if ($contents === false) {
            throw new RuntimeException('Cannot read the expected legacy report.');
        }
        $expectedReport = json_decode($contents, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($expectedReport)) {
            throw new RuntimeException('Expected report must contain a dry-run JSON object.');
        }
    }
    $backup = null;
    $path = (string)($options['db'] ?? \Shabbat\Runtime\RuntimePaths::resolve(dirname(__DIR__))->databasePath);
    if (!$dryRun) {
        if (!isset($options['writers-stopped'])) {
            throw new RuntimeException('Explicit import requires --writers-stopped.');
        }
        if (!is_file($path)) {
            throw new RuntimeException('Run bin/migrate.php before applying legacy import.');
        }
        if (!isset($options['backup-dir']) || !is_dir($options['backup-dir'])) {
            throw new RuntimeException('Specify --backup-dir for a verified pre-import backup.');
        }
        $lock = new InstanceLock($path . '.writer.lock');
        $db = Database::open($path);
        $db->assertCurrentSchema();
        $backup = (new BackupService())->backup($db, $options['backup-dir'] . '/shabbat-backup-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.sqlite');
    } elseif (is_file($path)) {
        $db = Database::open($path);
    } else {
        $db = Database::open(':memory:');
        $db->migrate();
    }
    $db->assertCurrentSchema();
    $report = (new LegacyImporter($db, new SystemClock()))->run((string)$options['legacy'], $dryRun, $expectedReport);
    if ($backup !== null) {
        $report['pre_import_backup'] = $backup;
    }
    echo Database::encode($report) . "\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
