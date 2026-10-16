<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-only
require dirname(__DIR__) . '/vendor/autoload.php';
use Shabbat\Storage\BackupService;
use Shabbat\Storage\Database;
$options = getopt('', ['db:','directory:','keep:']);
try {
    $path = (string)($options['db'] ?? \Shabbat\Runtime\RuntimePaths::resolve(dirname(__DIR__))->databasePath);
    $directory = (string)($options['directory'] ?? dirname(__DIR__) . '/backups');
    if (!is_file($path)) {
        throw new RuntimeException('Database does not exist.');
    }
    if (!is_dir($directory) && !mkdir($directory, 0700, true)) {
        throw new RuntimeException('Cannot create backup directory.');
    }
    $service = new BackupService();
    $result = $service->backup(Database::open($path), $directory . '/shabbat-backup-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.sqlite');
    $result['removed'] = BackupService::retain($directory, (int)($options['keep'] ?? 7));
    echo Database::encode($result) . "\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
