<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace Shabbat\Tests;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

final class RuntimePermissionsTest extends TestCase
{
    private string $fixture;

    protected function setUp(): void
    {
        $this->fixture = sys_get_temp_dir() . '/shabbat-permissions-test-' . bin2hex(random_bytes(8));
        foreach (['', '/data', '/runtime'] as $directory) {
            mkdir($this->fixture . $directory, 0700);
        }
    }

    protected function tearDown(): void
    {
        chmod($this->fixture, 0700);
        $resolved = realpath($this->fixture);
        $temporary = realpath(sys_get_temp_dir());
        if (
            $resolved === false
            || $temporary === false
            || !str_starts_with($resolved, $temporary . DIRECTORY_SEPARATOR . 'shabbat-permissions-test-')
        ) {
            throw new RuntimeException('Permission smoke fixture cleanup target escaped its fixture.');
        }
        foreach (
            new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($resolved, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST,
            ) as $file
        ) {
            $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($resolved);
    }

    private function runProbe(array $arguments = [], array $overrides = []): array
    {
        $environment = getenv();
        if (!is_array($environment)) {
            throw new RuntimeException('The subprocess environment is unavailable.');
        }
        $environment = array_replace($environment, [
            'DATA_DIR' => $this->fixture . '/data',
            'APP_DB_PATH' => $this->fixture . '/data/never-created.sqlite',
            'SESSION_PATH' => $this->fixture . '/runtime/never-created.session',
            'HEARTBEAT_PATH' => $this->fixture . '/data/never-created-heartbeat.json',
            'API_HASH' => 'DO_NOT_PRINT_SECRET',
            'BOT_TOKEN' => 'DO_NOT_PRINT_SECRET',
        ], $overrides);
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__) . '/bin/runtime-permissions.php', ...$arguments],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->fixture,
            $environment,
            ['bypass_shell' => true],
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start the offline permission smoke subprocess.');
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $result = ['exit' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
        self::assertStringNotContainsString('DO_NOT_PRINT_SECRET', $stdout . $stderr);
        self::assertStringNotContainsString($this->fixture, $stdout . $stderr);
        self::assertFileDoesNotExist($this->fixture . '/data/never-created.sqlite');
        self::assertFileDoesNotExist($this->fixture . '/runtime/never-created.session');
        self::assertFileDoesNotExist($this->fixture . '/data/never-created-heartbeat.json');
        foreach ([$this->fixture, $this->fixture . '/data', $this->fixture . '/runtime'] as $directory) {
            self::assertSame([], glob($directory . '/.shabbat-permissions-*'));
        }
        return $result;
    }

    public function testActualWorkerLoggerAndPrimeCopiesUseTheProcessWorkingDirectory(): void
    {
        file_put_contents($this->fixture . '/.prime.py', 'operator prime sentinel');
        file_put_contents($this->fixture . '/.alt_prime.py', 'operator alternate sentinel');
        $result = $this->runProbe();
        self::assertSame(0, $result['exit'], $result['stderr']);
        self::assertSame('', $result['stderr']);
        self::assertStringContainsString('Offline runtime permissions verified:', $result['stdout']);
        self::assertFileDoesNotExist($this->fixture . '/MadelineProto.log');
        self::assertSame('operator prime sentinel', file_get_contents($this->fixture . '/.prime.py'));
        self::assertSame('operator alternate sentinel', file_get_contents($this->fixture . '/.alt_prime.py'));
        self::assertEqualsCanonicalizing(['.', '..', 'data', 'runtime', '.prime.py', '.alt_prime.py'], scandir($this->fixture));
    }

    public function testExistingLargeOperatorLogIsAppendedAndNeverTruncatedOrDeleted(): void
    {
        $before = str_repeat('operator log retained; ', 60000);
        $log = $this->fixture . '/MadelineProto.log';
        file_put_contents($log, $before);
        $result = $this->runProbe();
        self::assertSame(0, $result['exit'], $result['stderr']);
        $after = file_get_contents($log);
        self::assertStringStartsWith($before, $after);
        self::assertStringContainsString('MadelineProto ', substr($after, strlen($before)));
        self::assertStringContainsString('Shabbat offline runtime permission probe.', substr($after, strlen($before)));
        $again = $this->runProbe();
        self::assertSame(0, $again['exit'], $again['stderr']);
        self::assertStringStartsWith($after, file_get_contents($log));
    }

    public function testUnknownArgumentsFailBeforeTouchingRuntimeFiles(): void
    {
        $result = $this->runProbe(['--unsupported']);
        self::assertSame(1, $result['exit']);
        self::assertSame('', $result['stdout']);
        self::assertSame("Offline runtime permission check failed: arguments.\n", $result['stderr']);
        self::assertFileDoesNotExist($this->fixture . '/MadelineProto.log');
    }

    public function testMissingDataDirectoryFailsAndIsNotCreated(): void
    {
        $result = $this->runProbe([], ['DATA_DIR' => $this->fixture . '/missing-data']);
        self::assertSame(1, $result['exit']);
        self::assertStringContainsString('configured data directory', $result['stderr']);
        self::assertDirectoryDoesNotExist($this->fixture . '/missing-data');
        self::assertFileDoesNotExist($this->fixture . '/MadelineProto.log');
    }

    public function testDataPathPointingToAFileIsRejectedWithoutChangingIt(): void
    {
        file_put_contents($this->fixture . '/operator.txt', 'operator data sentinel');
        $result = $this->runProbe([], ['DATA_DIR' => $this->fixture . '/operator.txt']);
        self::assertSame(1, $result['exit']);
        self::assertStringContainsString('configured data directory', $result['stderr']);
        self::assertSame('operator data sentinel', file_get_contents($this->fixture . '/operator.txt'));
    }

    public function testMissingSessionParentIsRejectedAndNotCreated(): void
    {
        $result = $this->runProbe([], ['SESSION_PATH' => $this->fixture . '/missing-runtime/session']);
        self::assertSame(1, $result['exit']);
        self::assertStringContainsString('configured session parent', $result['stderr']);
        self::assertDirectoryDoesNotExist($this->fixture . '/missing-runtime');
        self::assertFileDoesNotExist($this->fixture . '/MadelineProto.log');
    }

    public function testDirectoryAtTheDefaultLogPathIsRejected(): void
    {
        mkdir($this->fixture . '/MadelineProto.log', 0700);
        $result = $this->runProbe();
        self::assertSame(1, $result['exit']);
        self::assertStringContainsString('default MadelineProto worker logger', $result['stderr']);
        self::assertDirectoryExists($this->fixture . '/MadelineProto.log');
    }

    public function testRequiredNonRootIdentityFailsClosedWhenNotVerifiableOrRoot(): void
    {
        $result = $this->runProbe(['--require-non-root']);
        if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
            self::assertSame(0, $result['exit'], $result['stderr']);
        } else {
            self::assertSame(1, $result['exit']);
            self::assertStringContainsString('non-root identity', $result['stderr']);
            self::assertFileDoesNotExist($this->fixture . '/MadelineProto.log');
        }
    }

    public function testReadOnlyRuntimePathsFailForANonRootPosixUser(): void
    {
        if (PHP_OS_FAMILY === 'Windows' || !function_exists('posix_geteuid') || posix_geteuid() === 0) {
            self::markTestSkipped('POSIX non-root identity is required to enforce directory mode bits.');
        }
        $log = $this->fixture . '/MadelineProto.log';
        file_put_contents($log, 'operator log retained');
        foreach (
            [
            [$this->fixture, 0555, 0700, 'working directory and PrimeModule copies'],
            [$this->fixture . '/data', 0555, 0700, 'configured data directory'],
            [$this->fixture . '/runtime', 0555, 0700, 'configured session parent'],
            [$log, 0444, 0600, 'default MadelineProto worker logger'],
            ] as [$path, $readOnlyMode, $restoreMode, $stage]
        ) {
            chmod($path, $readOnlyMode);
            try {
                $result = $this->runProbe();
                self::assertSame(1, $result['exit']);
                self::assertStringContainsString($stage, $result['stderr']);
                self::assertSame('operator log retained', file_get_contents($log));
            } finally {
                chmod($path, $restoreMode);
            }
        }
    }
}
