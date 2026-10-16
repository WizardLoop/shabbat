<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-only
require dirname(__DIR__) . '/vendor/autoload.php';
use Shabbat\Storage\Database;
$options = getopt('', ['db:', 'deep']);
try {
    $paths = \Shabbat\Runtime\RuntimePaths::resolve(dirname(__DIR__));
    $path = (string)($options['db'] ?? $paths->databasePath);
    if (!is_file($path)) {
        throw new RuntimeException('Application database is missing; run the maintenance migration first.');
    }
    $db = Database::open($path);
    $db->assertCurrentSchema();
    // The frequent Docker probe must not rescan the entire historical database.
    // Explicit maintenance/deployment checks retain full integrity validation.
    $deep = array_key_exists('deep', $options);
    $checks = $deep ? $db->integrity() : ['connection_check' => (int) $db->pdo()->query('SELECT 1')->fetchColumn() === 1];
    $healthy = $deep ? $checks['integrity_check'] === ['ok'] && $checks['foreign_key_check'] === [] : $checks['connection_check'];
    $schema = $db->pdo()->query('SELECT version,checksum FROM schema_migrations ORDER BY version')->fetchAll();
    $jobs = $db->pdo()->query('SELECT status,COUNT(*) AS count FROM scheduled_jobs GROUP BY status')->fetchAll();
    $locks = $db->pdo()->query('SELECT status,COUNT(*) AS count FROM group_lock_state GROUP BY status')->fetchAll();
    $heartbeatPath = $paths->heartbeatPath;
    $heartbeat = is_file($heartbeatPath) ? json_decode((string)file_get_contents($heartbeatPath), true, 16, JSON_THROW_ON_ERROR) : null;
    $heartbeatAge = is_array($heartbeat) && isset($heartbeat['timestamp']) ? time() - (int)$heartbeat['timestamp'] : null;
    $notificationAge = is_array($heartbeat) && isset($heartbeat['notification_timestamp']) ? time() - (int) $heartbeat['notification_timestamp'] : null;
    $healthy = $healthy && $heartbeatAge !== null && $heartbeatAge >= 0 && $heartbeatAge <= 180
        && $notificationAge !== null && $notificationAge >= 0 && $notificationAge <= 180;
    $queue = (new \Shabbat\Scheduling\JobRepository($db, new \Shabbat\Time\SystemClock()))->queueMetrics();
    $version = trim((string)file_get_contents(dirname(__DIR__) . '/VERSION'));
    echo Database::encode(['healthy' => $healthy,'version' => $version,'php' => PHP_VERSION,'commit' => \Shabbat\Runtime\Version::commit(),
        'heartbeat_age_seconds' => $heartbeatAge,'notification_heartbeat_age_seconds' => $notificationAge,'scheduler_cycles' => $heartbeat['cycles'] ?? [],'delivery_queue' => $queue,'sqlite_version' => $db->pdo()->query('SELECT sqlite_version()')->fetchColumn(),'deep_check' => $deep,'checks' => $checks,'schema' => $schema,'jobs' => $jobs,'locks' => $locks,'geonames_configured' => (bool)getenv('GEONAMES_USERNAME')]) . "\n";
    exit($healthy ? 0 : 1);
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
