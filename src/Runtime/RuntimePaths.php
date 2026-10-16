<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace Shabbat\Runtime;

final readonly class RuntimePaths
{
    private function __construct(
        public string $dataDirectory,
        public string $databasePath,
        public string $sessionPath,
        public string $heartbeatPath,
    ) {
    }

    public static function resolve(string $root, ?array $configuration = null): self
    {
        if ($configuration === null) {
            $configuration = is_file($root . '/.env') ? parse_ini_file($root . '/.env', false, INI_SCANNER_RAW) : [];
            if (!is_array($configuration)) {
                throw new \RuntimeException('Invalid .env configuration.');
            }
        }
        $value = static function (string $name, string $default) use ($configuration): string {
            $environment = getenv($name);
            $configured = is_string($environment) && $environment !== '' ? $environment : ($configuration[$name] ?? $default);
            return is_string($configured) && $configured !== '' ? $configured : $default;
        };
        $data = self::absolute($root, $value('DATA_DIR', $root . '/data'));
        return new self(
            $data,
            self::absolute($root, $value('APP_DB_PATH', $data . '/shabbat.sqlite')),
            self::absolute($root, $value('SESSION_PATH', $root . '/bot.shabbat')),
            self::absolute($root, $value('HEARTBEAT_PATH', $data . '/scheduler-heartbeat.json')),
        );
    }

    public function export(): void
    {
        foreach (
            [
            'DATA_DIR' => $this->dataDirectory,
            'APP_DB_PATH' => $this->databasePath,
            'SESSION_PATH' => $this->sessionPath,
            'HEARTBEAT_PATH' => $this->heartbeatPath,
            ] as $name => $path
        ) {
            if (!putenv($name . '=' . $path)) {
                throw new \RuntimeException('Unable to export the resolved runtime paths.');
            }
        }
    }

    public function writerLockPath(): string
    {
        return $this->databasePath . '.writer.lock';
    }

    private static function absolute(string $root, string $path): string
    {
        if (str_contains($path, "\0")) {
            throw new \InvalidArgumentException('A runtime path cannot contain NUL bytes.');
        }
        $path = str_replace('\\', '/', $path);
        $absolute = str_starts_with($path, '/')
            || (strlen($path) >= 3 && ctype_alpha($path[0]) && $path[1] === ':' && $path[2] === '/');
        return $absolute ? $path : rtrim(str_replace('\\', '/', $root), '/') . '/' . $path;
    }
}
