<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-only
require dirname(__DIR__) . '/vendor/autoload.php';
use Shabbat\Scheduling\InstanceLock;
use Shabbat\Storage\BackupService;
use Shabbat\Storage\Database;
use Shabbat\Storage\LegacyImporter;
use Shabbat\Time\SystemClock;
$options = getopt('', ['db:','legacy:','dry-run','apply','writers-stopped','backup-dir:']);
try {
    if (!isset($options['legacy'])) {
        throw new RuntimeException('Specify the read-only legacy source using --legacy=/path/to/old/bot or /path/to/data.');
    }
    if (isset($options['dry-run'], $options['apply'])) {
        throw new RuntimeException('Choose either --dry-run or --apply.');
    }
    $dryRun = !isset($options['apply']);
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
        (new BackupService())->backup($db, $options['backup-dir'] . '/shabbat-backup-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.sqlite');
    } elseif (is_file($path)) {
        $db = Database::open($path);
    } else {
        $db = Database::open(':memory:');
        $db->migrate();
    }
    echo Database::encode((new LegacyImporter($db, new SystemClock()))->run((string)$options['legacy'], $dryRun)) . "\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
