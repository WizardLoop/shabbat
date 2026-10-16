<?php

declare(strict_types=1);

namespace Shabbat\Storage;

// SPDX-License-Identifier: AGPL-3.0-only

use Shabbat\Time\Clock;

/**
 * Compatibility boundary for explicitly ported legacy admin handlers.
 * No method reads, writes or deletes a real legacy filesystem path.
 */
final class AdminStateFiles
{
    private static ?Database $database = null;
    private static ?Clock $clock = null;
    private static string $root = '';
    private const USER_KEYS = ['grs1.txt', 'messagetodelete.txt', 'txt.txt', 'ent.txt', 'media.txt', 'BUTTONS.txt', 'broadcastsend.txt', 'pinmessage.txt', 'page_var.txt'];
    private const DATA_KEYS = ['BUTTONS.txt', 'broadcastsend.txt', 'pinmessage.txt', 'startline_cat.txt', 'endline_cat.txt', 'page_var.txt'];
    private const ROOT_KEYS = ['ids.txt', 'idsnew.txt'];

    public static function configure(Database $database, Clock $clock, string $root): void
    {
        $normalized = rtrim(str_replace('\\', '/', $root), '/');
        if (
            !preg_match('~^(?:[a-zA-Z]:/|/)~', $normalized) || str_contains($normalized, "\0")
            || preg_match('~(?:^|/)\.\.?(?:/|$)~', $normalized)
        ) {
            throw new \InvalidArgumentException('Admin state root must be a canonical absolute application path.');
        }
        $database->pdo()->query('SELECT user_id FROM admin_state LIMIT 0');
        self::$root = $normalized;
        self::$database = $database;
        self::$clock = $clock;
    }

    private static function database(): Database
    {
        return self::$database ?? throw new \LogicException('SQLite admin state is not configured.');
    }

    /** @return array{user_id:string,key:string,directory:bool,virtual:bool} */
    private static function resolve(string $path): array
    {
        self::database();
        $path = str_replace('\\', '/', $path);
        if (
            $path === '' || str_contains($path, "\0") || str_contains($path, '://') || str_contains($path, '//')
            || preg_match('~(?:^|/)\.\.?(?:/|$)~', $path)
        ) {
            throw new \InvalidArgumentException('Invalid admin state path.');
        }
        if (preg_match('~^(?:[a-zA-Z]:/|/)~', $path)) {
            $prefix = self::$root . '/';
            $matches = PHP_OS_FAMILY === 'Windows'
                ? strncasecmp($path, $prefix, strlen($prefix)) === 0
                : str_starts_with($path, $prefix);
            if (!$matches) {
                throw new \InvalidArgumentException('Admin state path is outside the application root.');
            }
            $path = substr($path, strlen($prefix));
        }
        $path = rtrim($path, '/');
        if ($path === 'data') {
            return ['user_id' => '0', 'key' => '', 'directory' => true, 'virtual' => false];
        }
        if ($path === 'data/DBgroups.txt') {
            return ['user_id' => '0', 'key' => 'DBgroups.txt', 'directory' => false, 'virtual' => true];
        }
        if (in_array($path, self::ROOT_KEYS, true)) {
            return ['user_id' => '0', 'key' => $path, 'directory' => false, 'virtual' => false];
        }
        if (str_starts_with($path, 'data/') && in_array(substr($path, 5), self::DATA_KEYS, true)) {
            return ['user_id' => '0', 'key' => substr($path, 5), 'directory' => false, 'virtual' => false];
        }
        if (preg_match('~^data/([1-9][0-9]{0,18})(?:/([^/]+))?$~', $path, $match)) {
            $userId = $match[1];
            $max = (string) PHP_INT_MAX;
            if (strlen($userId) > strlen($max) || (strlen($userId) === strlen($max) && strcmp($userId, $max) > 0)) {
                throw new \InvalidArgumentException('Telegram user ID is outside supported int64 range.');
            }
            $key = $match[2] ?? '';
            if ($key === '' || in_array($key, self::USER_KEYS, true)) {
                return ['user_id' => $userId, 'key' => $key, 'directory' => $key === '', 'virtual' => false];
            }
        }
        throw new \InvalidArgumentException('Unknown legacy admin state path: ' . $path);
    }

    public static function read(string $path): string
    {
        $target = self::resolve($path);
        if ($target['directory']) {
            throw new \InvalidArgumentException('Cannot read a virtual directory as a file.');
        }
        if ($target['virtual']) {
            return implode("\n", self::database()->pdo()->query('SELECT group_id FROM groups WHERE active=1 ORDER BY group_id')->fetchAll(\PDO::FETCH_COLUMN));
        }
        $statement = self::database()->pdo()->prepare('SELECT value_text FROM admin_state WHERE user_id=? AND state_key=?');
        $statement->execute([$target['user_id'], $target['key']]);
        $value = $statement->fetchColumn();
        if ($value === false) {
            throw new \RuntimeException('Admin draft state does not exist.');
        }
        return (string) $value;
    }

    public static function write(string $path, string $contents): int
    {
        $target = self::resolve($path);
        if ($target['directory'] || $target['virtual']) {
            throw new \InvalidArgumentException('This virtual path is read-only or is a directory.');
        }
        if (strlen($contents) > 1048576) {
            throw new \InvalidArgumentException('Admin state exceeds the supported size.');
        }
        $kind = match ($target['key']) {
            'ent.txt', 'BUTTONS.txt' => 'json',
            'media.txt' => 'media',
            'ids.txt', 'idsnew.txt' => 'ids',
            default => 'text',
        };
        $timestamp = self::$clock?->now()->getTimestamp() ?? throw new \LogicException('Admin clock is not configured.');
        self::database()->transaction(static function (\PDO $pdo) use ($target, $kind, $contents, $timestamp): void {
            $pdo->prepare('INSERT INTO admin_directories(user_id,created_at) VALUES(?,?) ON CONFLICT(user_id) DO NOTHING')
                ->execute([$target['user_id'], $timestamp]);
            $pdo->prepare('INSERT INTO admin_state(user_id,state_key,value_kind,value_text,updated_at) VALUES(?,?,?,?,?) ON CONFLICT(user_id,state_key) DO UPDATE SET value_kind=excluded.value_kind,value_text=excluded.value_text,updated_at=excluded.updated_at')
                ->execute([$target['user_id'], $target['key'], $kind, $contents, $timestamp]);
        });
        return strlen($contents);
    }

    public static function exists(string $path): bool
    {
        $target = self::resolve($path);
        if ($target['virtual'] || ($target['directory'] && $target['user_id'] === '0')) {
            return true;
        }
        $query = $target['directory']
            ? self::database()->pdo()->prepare('SELECT 1 FROM admin_directories WHERE user_id=?')
            : self::database()->pdo()->prepare('SELECT 1 FROM admin_state WHERE user_id=? AND state_key=?');
        $query->execute($target['directory'] ? [$target['user_id']] : [$target['user_id'], $target['key']]);
        return $query->fetchColumn() !== false;
    }

    public static function delete(string $path): void
    {
        $target = self::resolve($path);
        if ($target['directory'] || $target['virtual']) {
            throw new \InvalidArgumentException('Cannot delete a virtual directory or the typed groups view.');
        }
        self::database()->pdo()->prepare('DELETE FROM admin_state WHERE user_id=? AND state_key=?')->execute([$target['user_id'], $target['key']]);
    }

    public static function mkdir(string $path, int $mode = 0700, bool $recursive = true): bool
    {
        $target = self::resolve($path);
        if (!$target['directory']) {
            throw new \InvalidArgumentException('Only reviewed virtual admin directories can be created.');
        }
        $timestamp = self::$clock?->now()->getTimestamp() ?? throw new \LogicException('Admin clock is not configured.');
        self::database()->pdo()->prepare('INSERT INTO admin_directories(user_id,created_at) VALUES(?,?) ON CONFLICT(user_id) DO NOTHING')->execute([$target['user_id'], $timestamp]);
        return true;
    }

    public static function isDirectory(string $path): bool
    {
        $target = self::resolve($path);
        return $target['directory'] && self::exists($path);
    }

    /** Equivalent to legacy file() for the explicitly ported pagination paths. */
    public static function lines(string $path): array
    {
        $value = self::read($path);
        if ($value === '') {
            return [];
        }
        preg_match_all('/[^\n]*\n|[^\n]+$/', $value, $matches);
        return $matches[0];
    }
}
