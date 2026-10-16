<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace Shabbat\Tests;

use PHPUnit\Framework\TestCase;
use Shabbat\Runtime\RuntimePaths;

final class RuntimePathsTest extends TestCase
{
    private array $saved = [];
    private string $root;

    protected function setUp(): void
    {
        $this->root = str_replace('\\', '/', sys_get_temp_dir()) . '/shabbat-paths-fixture';
        foreach (['DATA_DIR', 'APP_DB_PATH', 'SESSION_PATH', 'HEARTBEAT_PATH'] as $name) {
            $this->saved[$name] = getenv($name);
            putenv($name);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $name => $value) {
            putenv($value === false ? $name : $name . '=' . $value);
        }
    }

    public function testDataDirectoryDefinesDatabaseHeartbeatAndWriterLock(): void
    {
        putenv('DATA_DIR=custom-storage');
        $paths = RuntimePaths::resolve($this->root, []);
        self::assertSame($this->root . '/custom-storage', $paths->dataDirectory);
        self::assertSame($paths->dataDirectory . '/shabbat.sqlite', $paths->databasePath);
        self::assertSame($paths->dataDirectory . '/scheduler-heartbeat.json', $paths->heartbeatPath);
        self::assertSame($paths->databasePath . '.writer.lock', $paths->writerLockPath());
        self::assertSame($this->root . '/bot.shabbat', $paths->sessionPath);
    }

    public function testEnvironmentOverridesConfigurationAndExplicitPathsStayIndependent(): void
    {
        putenv('DATA_DIR=D:\\Telegram storage');
        putenv('APP_DB_PATH=/isolated/custom.sqlite');
        $paths = RuntimePaths::resolve($this->root, [
            'DATA_DIR' => 'ignored-data',
            'APP_DB_PATH' => 'ignored.sqlite',
            'SESSION_PATH' => 'runtime/custom.session',
            'HEARTBEAT_PATH' => 'health.json',
        ]);
        self::assertSame('D:/Telegram storage', $paths->dataDirectory);
        self::assertSame('/isolated/custom.sqlite', $paths->databasePath);
        self::assertSame($this->root . '/runtime/custom.session', $paths->sessionPath);
        self::assertSame($this->root . '/health.json', $paths->heartbeatPath);
    }

    public function testExportSharesTheExactResolvedPathsWithAllServices(): void
    {
        $paths = RuntimePaths::resolve($this->root, ['DATA_DIR' => 'state']);
        $paths->export();
        self::assertSame($paths->dataDirectory, getenv('DATA_DIR'));
        self::assertSame($paths->databasePath, getenv('APP_DB_PATH'));
        self::assertSame($paths->sessionPath, getenv('SESSION_PATH'));
        self::assertSame($paths->heartbeatPath, getenv('HEARTBEAT_PATH'));
        self::assertEquals($paths, RuntimePaths::resolve($this->root, []));
    }

    public function testEmptyConfigurationPreservesLegacySessionAndRejectsInvalidPath(): void
    {
        $paths = RuntimePaths::resolve($this->root, ['DATA_DIR' => '', 'SESSION_PATH' => '']);
        self::assertSame($this->root . '/data/shabbat.sqlite', $paths->databasePath);
        self::assertSame($this->root . '/bot.shabbat', $paths->sessionPath);
        $this->expectException(\InvalidArgumentException::class);
        RuntimePaths::resolve($this->root, ['DATA_DIR' => "bad\0path"]);
    }
}
