<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-only
namespace Shabbat\Storage;

use PDO;
use Throwable;

final class Database
{
    private bool $inTransaction = false;
    public function __construct(private readonly PDO $connection)
    {
        if (PHP_INT_SIZE < 8) {
            throw new \RuntimeException('64-bit PHP is required for Telegram identifiers.');
        }
        $connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $connection->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $connection->exec('PRAGMA foreign_keys=ON');
        $connection->exec('PRAGMA busy_timeout=100');
        $connection->exec('PRAGMA journal_mode=WAL');
        $connection->exec('PRAGMA synchronous=FULL');
    }
    public static function open(string $path): self
    {
        if ($path !== ':memory:' && !is_dir(dirname($path)) && !mkdir(dirname($path), 0700, true) && !is_dir(dirname($path))) {
            throw new \RuntimeException('Cannot create application database directory.');
        }
        return new self(new PDO('sqlite:' . $path));
    }
    public function pdo(): PDO
    {
        return $this->connection;
    }
    /** PDO_SQLite before PHP 8.4 does not report transactions opened with BEGIN IMMEDIATE. */
    public function ownsTransaction(PDO $connection): bool
    {
        return $this->inTransaction && $connection === $this->connection;
    }
    /** Database work only: never yield, call a remote API or change transaction control in this callback. */
    public function transaction(callable $callback): mixed
    {
        if ($this->inTransaction) {
            throw new \LogicException('Nested/shared transactions are forbidden.');
        }
        $this->connection->exec('BEGIN IMMEDIATE');
        $this->inTransaction = true;
        try {
            $result = $callback($this->connection);
            $this->connection->exec('COMMIT');
            return $result;
        } catch (Throwable $error) {
            $this->connection->exec('ROLLBACK');
            throw $error;
        } finally {
            $this->inTransaction = false;
        }
    }
    public function migrate(?string $directory = null): array
    {
        $directory ??= dirname(__DIR__, 2) . '/migrations';
        $this->connection->exec('CREATE TABLE IF NOT EXISTS schema_migrations (version TEXT PRIMARY KEY, checksum TEXT NOT NULL, applied_at INTEGER NOT NULL)');
        $files = glob($directory . '/*.sql') ?: [];
        sort($files, SORT_STRING);
        $applied = [];
        foreach ($files as $file) {
            $version = basename($file);
            $sql = file_get_contents($file);
            if ($sql === false) {
                throw new \RuntimeException('Cannot read migration: ' . $version);
            }
            $checksum = hash('sha256', $sql);
            $query = $this->connection->prepare('SELECT checksum FROM schema_migrations WHERE version=?');
            $query->execute([$version]);
            $existing = $query->fetchColumn();
            if ($existing !== false) {
                if (!hash_equals((string) $existing, $checksum)) {
                    throw new \RuntimeException('Applied migration was changed: ' . $version);
                }
                continue;
            }
            $this->transaction(function (PDO $pdo) use ($sql, $version, $checksum): void {
                $pdo->exec($sql);
                $pdo->prepare('INSERT INTO schema_migrations VALUES (?,?,?)')->execute([$version, $checksum, time()]);
            });
            $applied[] = $version;
        }
        return $applied;
    }
    /** Read-only startup gate; maintenance is the only place that applies migrations. */
    public function assertCurrentSchema(?string $directory = null): void
    {
        $directory ??= dirname(__DIR__, 2) . '/migrations';
        $files = glob($directory . '/*.sql') ?: [];
        sort($files, SORT_STRING);
        if ($files === []) {
            throw new \RuntimeException('Required migration source files are missing.');
        }
        $required = [];
        foreach ($files as $file) {
            $version = basename($file);
            $contents = file_get_contents($file);
            if ($contents === false) {
                throw new \RuntimeException('Cannot read required migration source: ' . $version);
            }
            $required[$version] = hash('sha256', $contents);
        }
        try {
            $applied = $this->connection->query('SELECT version,checksum FROM schema_migrations ORDER BY version')->fetchAll();
        } catch (\PDOException $error) {
            throw new \RuntimeException('Schema migration history is missing or unreadable. Run the stopped-writer maintenance migration before startup.', 0, $error);
        }
        $seen = [];
        foreach ($applied as $record) {
            $version = $record['version'] ?? null;
            $checksum = $record['checksum'] ?? null;
            if (!is_string($version) || !isset($required[$version])) {
                throw new \RuntimeException('Unknown schema migration version; use the application version matching this database.');
            }
            if (isset($seen[$version]) || !is_string($checksum) || !hash_equals($required[$version], $checksum)) {
                throw new \RuntimeException('Applied migration checksum does not match required source: ' . $version);
            }
            $seen[$version] = true;
        }
        $missing = array_diff(array_keys($required), array_keys($seen));
        if ($missing !== []) {
            throw new \RuntimeException('Missing required schema migrations: ' . implode(', ', $missing) . '. Run the stopped-writer maintenance migration before startup.');
        }
    }
    public function integrity(): array
    {
        return ['integrity_check' => $this->connection->query('PRAGMA integrity_check')->fetchAll(PDO::FETCH_COLUMN),
            'foreign_key_check' => $this->connection->query('PRAGMA foreign_key_check')->fetchAll(),
            'sqlite_version' => $this->connection->query('SELECT sqlite_version()')->fetchColumn()];
    }
    public static function encode(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
