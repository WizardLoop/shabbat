<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-only
require dirname(__DIR__) . '/vendor/autoload.php';
use Shabbat\Scheduling\InstanceLock;
use Shabbat\Storage\BackupService;
use Shabbat\Storage\Database;
$options = getopt('', ['db:','writers-stopped','backup-dir:']);
try {
    if (!isset($options['writers-stopped'])) {
        throw new RuntimeException('Stop all application writers and pass --writers-stopped.');
    }
    $path = (string)($options['db'] ?? \Shabbat\Runtime\RuntimePaths::resolve(dirname(__DIR__))->databasePath);
    $existed = is_file($path);
    $lock = new InstanceLock($path . '.writer.lock');
    $db = Database::open($path);
    $backup = null;
    if ($existed) {
        if (!isset($options['backup-dir']) || !is_dir($options['backup-dir'])) {
            throw new RuntimeException('An existing database requires --backup-dir with an existing directory.');
        }
        $backup = (new BackupService())->backup($db, $options['backup-dir'] . '/shabbat-backup-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.sqlite');
    }
    echo Database::encode(['migrations' => $db->migrate(),'backup' => $backup,'checks' => $db->integrity()]) . "\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
