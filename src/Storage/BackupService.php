<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-only
namespace Shabbat\Storage;

use PDO;
use Shabbat\Scheduling\InstanceLock;

final class BackupService
{
    /** VACUUM INTO reads committed WAL data into a separate consistent database. */
    public function backup(Database $database, string $destination): array
    {
        if (is_file($destination)) {
            throw new \RuntimeException('Backup destination already exists.');
        }
        $parent = realpath(dirname($destination));
        if ($parent === false) {
            throw new \RuntimeException('Create the backup directory before backing up.');
        }
        $destination = $parent . DIRECTORY_SEPARATOR . basename($destination);
        if (version_compare((string)$database->pdo()->query('SELECT sqlite_version()')->fetchColumn(), '3.27.0', '<')) {
            throw new \RuntimeException('SQLite VACUUM INTO requires SQLite 3.27 or later.');
        }
        $query = $database->pdo()->prepare('VACUUM INTO ?');
        $query->execute([$destination]);
        try {
            $checks = self::verifyFile($destination);
            $manifest = ['sha256' => hash_file('sha256', $destination),'created_at' => gmdate(DATE_ATOM),'sqlite_version' => $checks['sqlite_version'],
                'schema_migrations' => $checks['schema_migrations'],'size_bytes' => filesize($destination),'integrity' => 'ok'];
            if (file_put_contents($destination . '.manifest.json', Database::encode($manifest) . "\n", LOCK_EX) === false) {
                throw new \RuntimeException('Cannot write backup manifest.');
            }
            @chmod($destination, 0600);
            @chmod($destination . '.manifest.json', 0600);
            return $manifest + ['path' => $destination];
        } catch (\Throwable $error) {
            if (is_file($destination)) {
                unlink($destination);
            }
            throw $error;
        }
    }
    public static function verifyFile(string $path): array
    {
        if (!is_file($path) || is_link($path)) {
            throw new \RuntimeException('Backup database is missing or symlinked.');
        }
        $pdo = new PDO('sqlite:' . $path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $integrity = $pdo->query('PRAGMA integrity_check')->fetchAll(PDO::FETCH_COLUMN);
        $foreignKeys = $pdo->query('PRAGMA foreign_key_check')->fetchAll(PDO::FETCH_ASSOC);
        if ($integrity !== ['ok'] || $foreignKeys !== []) {
            throw new \RuntimeException('Backup failed integrity or foreign key checks.');
        }
        $migrations = $pdo->query('SELECT version,checksum FROM schema_migrations ORDER BY version')->fetchAll(PDO::FETCH_ASSOC);
        return ['sqlite_version' => $pdo->query('SELECT sqlite_version()')->fetchColumn(),'schema_migrations' => $migrations];
    }
    /** The same writer lock as the bot prevents maintenance alongside a running application. */
    public static function restore(string $backup, string $destination, bool $writersStopped): array
    {
        if (!$writersStopped) {
            throw new \RuntimeException('Restore requires explicit --writers-stopped after stopping every writer.');
        }
        self::verifyFile($backup);
        $manifestPath = $backup . '.manifest.json';
        if (is_file($manifestPath)) {
            $manifest = json_decode((string)file_get_contents($manifestPath), true, 32, JSON_THROW_ON_ERROR);
            if (!isset($manifest['sha256']) || !hash_equals($manifest['sha256'], (string)hash_file('sha256', $backup))) {
                throw new \RuntimeException('Backup manifest checksum mismatch.');
            }
        }
        $parent = realpath(dirname($destination));
        if ($parent === false) {
            throw new \RuntimeException('Restore directory does not exist.');
        }
        $destination = $parent . DIRECTORY_SEPARATOR . basename($destination);
        if (realpath($backup) === realpath($destination)) {
            throw new \RuntimeException('Source and restore destination must differ.');
        }
        if (is_link($destination)) {
            throw new \RuntimeException('Refusing symlinked restore destination.');
        }
        $lock = new InstanceLock($destination . '.writer.lock');
        $temporary = $destination . '.restore-' . bin2hex(random_bytes(8));
        try {
            if (!copy($backup, $temporary)) {
                throw new \RuntimeException('Cannot stage restored database.');
            }
            self::verifyFile($temporary);
            // A stopped writer can leave WAL state: checkpoint it before keeping the rollback copy.
            if (is_file($destination)) {
                $old = new PDO('sqlite:' . $destination);
                $old->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $old->exec('PRAGMA busy_timeout=100');
                $checkpoint = $old->query('PRAGMA wal_checkpoint(TRUNCATE)')->fetch(PDO::FETCH_NUM);
                if ($checkpoint !== false && (int)$checkpoint[0] !== 0) {
                    throw new \RuntimeException('Destination still has a live SQLite writer.');
                }
                $old = null;
            }
            foreach (['-wal','-shm'] as $suffix) {
                if (is_file($destination . $suffix) && !unlink($destination . $suffix)) {
                    throw new \RuntimeException('Cannot clear stopped SQLite sidecar.');
                }
            }
            $previous = null;
            if (is_file($destination)) {
                $previous = $destination . '.before-restore-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4));
                if (!rename($destination, $previous)) {
                    throw new \RuntimeException('Cannot retain previous database for rollback.');
                }
            }
            if (!rename($temporary, $destination)) {
                if ($previous !== null) {
                    rename($previous, $destination);
                }
                throw new \RuntimeException('Cannot install restored database.');
            }
            @chmod($destination, 0600);
            return ['restored' => $destination,'previous_database' => $previous,'verified' => self::verifyFile($destination)];
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
            $lock->release();
        }
    }
    /** Deletes only managed backup names in this one explicit directory. */
    public static function retain(string $directory, int $keep): array
    {
        if ($keep < 1) {
            throw new \InvalidArgumentException('Keep at least one backup.');
        }
        $resolved = realpath($directory);
        if ($resolved === false) {
            throw new \RuntimeException('Backup directory does not exist.');
        }
        $files = array_filter(glob($resolved . '/shabbat-backup-*.sqlite') ?: [], static fn(string $path): bool=>!is_link($path) && preg_match('/^shabbat-backup-[0-9]{8}-[0-9]{6}-[a-f0-9]{8}\.sqlite$/D', basename($path)) === 1);
        rsort($files, SORT_STRING);
        $removed = [];
        foreach (array_slice($files, $keep) as $path) {
            if (realpath(dirname($path)) !== $resolved) {
                throw new \RuntimeException('Backup retention path escaped its directory.');
            }
            if (!unlink($path)) {
                throw new \RuntimeException('Cannot delete old managed backup.');
            }
            if (is_file($path . '.manifest.json')) {
                unlink($path . '.manifest.json');
            }
            $removed[] = basename($path);
        }
        return $removed;
    }
}
