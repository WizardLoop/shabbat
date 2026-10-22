<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace Shabbat\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shabbat\Runtime\PhpRuntime;

final class PhpRuntimeTest extends TestCase
{
    public static function versions(): iterable
    {
        foreach (
            [
            [80299, 8, false], [80300, 8, false], [80335, 8, false],
            [80336, 8, true], [80337, 8, true], [80399, 8, true],
            [80400, 8, false], [80426, 8, false], [80427, 8, true],
            [80428, 8, true], [80499, 8, true], [80500, 8, false],
            [80511, 8, false], [80512, 8, true], [80513, 8, true],
            [80599, 8, true], [80600, 8, false], [90000, 8, false],
            [80336, 4, false], [80427, 4, false], [80512, 4, false],
            [0, 8, false], [-1, 8, false],
            ] as [$version, $size, $supported]
        ) {
            yield $version . '-' . $size => [$version, $size, $supported];
        }
    }

    #[DataProvider('versions')]
    public function testSecurityFloorsAndSupportedBranches(int $version, int $size, bool $supported): void
    {
        self::assertSame($supported, PhpRuntime::supports($version, $size));
    }

    public static function releaseForms(): iterable
    {
        foreach (
            [
            ['8.3.36', true], ['8.4.27', true], ['8.5.12', true], ['8.5.13', true],
            ['8.4.27-1+deb13u1', true], ['8.4.27-0ubuntu0.24.04.1', true],
            ['8.4.27-1.fc43', true], ['8.4.27+vendor.2', true],
            ['8.4.27RC1', false], ['8.4.28RC1', false], ['8.5.12RC2', false],
            ['8.5.12-dev', false], ['8.5.13-dev', false], ['8.3.36alpha1', false],
            ['8.4.27-beta2', false], ['8.5.12~rc1', false], ['8.5.12+dev', false],
            ['8.5.12-rc1+deb13u1', false], ['8.5.12-nightly', false],
            ['8.5.12-preview1', false], ['nightly', false], ['8.5', false],
            ] as [$version, $stable]
        ) {
            yield $version => [$version, $stable];
        }
    }

    #[DataProvider('releaseForms')]
    public function testUpstreamPrereleasesAreRejectedWithoutRejectingDistroSuffixes(string $version, bool $stable): void
    {
        self::assertSame($stable, PhpRuntime::isStableRelease($version));
    }

    public function testActualRuntimeAssertionCannotBeDisabledByEnvironment(): void
    {
        $previous = getenv('COMPOSER_IGNORE_PLATFORM_REQS');
        putenv('COMPOSER_IGNORE_PLATFORM_REQS=1');
        try {
            if (!PhpRuntime::supports(PHP_VERSION_ID, PHP_INT_SIZE) || !PhpRuntime::isStableRelease(PHP_VERSION)) {
                $this->expectException(\RuntimeException::class);
                $this->expectExceptionMessage('Unsupported PHP runtime');
            }
            PhpRuntime::assertSupported();
            self::assertTrue(PhpRuntime::supports(PHP_VERSION_ID, PHP_INT_SIZE));
        } finally {
            $previous === false ? putenv('COMPOSER_IGNORE_PLATFORM_REQS') : putenv('COMPOSER_IGNORE_PLATFORM_REQS=' . $previous);
        }
    }

    public static function entrypoints(): iterable
    {
        yield 'bot' => ['bot.php'];
        yield 'self-check' => ['bin/self-check.php'];
        yield 'direct-runner' => ['runner.php'];
    }

    #[DataProvider('entrypoints')]
    public function testProductionEntryPointsRejectBeforeAutoloadOrSessionWork(string $entrypoint): void
    {
        $this->withFixture($entrypoint, true, static function (string $root, string $path): void {
            [$exit, $stdout, $stderr] = self::execute($path);
            self::assertSame(1, $exit, $stdout . $stderr);
            self::assertStringContainsString('fixture PHP floor rejection', $stdout . $stderr);
            self::assertFileDoesNotExist($root . '/vendor-reached');
            self::assertFileDoesNotExist($root . '/patches-reached');
        });
    }

    #[DataProvider('entrypoints')]
    public function testRealGuardUsesActualBinaryBeforeVendorIsAllowed(string $entrypoint): void
    {
        $this->withFixture($entrypoint, false, static function (string $root, string $path): void {
            [$exit, $stdout, $stderr] = self::execute($path);
            if (PhpRuntime::supports(PHP_VERSION_ID, PHP_INT_SIZE) && PhpRuntime::isStableRelease(PHP_VERSION)) {
                self::assertSame(77, $exit, $stdout . $stderr);
                self::assertFileExists($root . '/vendor-reached');
            } else {
                self::assertSame(1, $exit, $stdout . $stderr);
                self::assertStringContainsString('Unsupported PHP runtime', $stdout . $stderr);
                self::assertFileDoesNotExist($root . '/vendor-reached');
                self::assertFileDoesNotExist($root . '/patches-reached');
            }
        });
    }

    private function withFixture(string $entrypoint, bool $reject, callable $test): void
    {
        $root = sys_get_temp_dir() . '/shabbat-php-floor-' . bin2hex(random_bytes(12));
        mkdir($root . '/src/Runtime', 0700, true);
        mkdir($root . '/bin', 0700);
        mkdir($root . '/vendor', 0700);
        mkdir($root . '/scripts', 0700);
        $project = dirname(__DIR__);
        $guard = $reject
            ? '<?php namespace Shabbat\Runtime; final class PhpRuntime { public static function assertSupported(): void { throw new \RuntimeException("fixture PHP floor rejection"); } }'
            : (string) file_get_contents($project . '/src/Runtime/PhpRuntime.php');
        file_put_contents($root . '/src/Runtime/PhpRuntime.php', $guard);
        file_put_contents($root . '/vendor/autoload.php', '<?php file_put_contents(__DIR__ . "/../vendor-reached", "1"); exit(77);');
        file_put_contents($root . '/scripts/apply-patches.php', '<?php file_put_contents(__DIR__ . "/../patches-reached", "1"); exit(78);');
        if ($entrypoint === 'runner.php') {
            copy($project . '/src/Runtime/Runner.php', $root . '/src/Runtime/Runner.php');
            file_put_contents($root . '/runner.php', '<?php require __DIR__ . "/src/Runtime/PhpRuntime.php"; require __DIR__ . "/src/Runtime/Runner.php"; $code = \Shabbat\Runtime\Runner::run("UnusedHandler", __DIR__); exit($code);');
            // A supported direct runner must reach the explicit next boundary, never a real SDK.
            file_put_contents($root . '/scripts/apply-patches.php', '<?php file_put_contents(__DIR__ . "/../vendor-reached", "1"); exit(77);');
        } else {
            copy($project . '/' . $entrypoint, $root . '/' . $entrypoint);
        }
        try {
            $test($root, $root . '/' . $entrypoint);
        } finally {
            $this->removeFixture($root);
        }
    }

    private static function execute(string $path): array
    {
        $process = proc_open([PHP_BINARY, '-d', 'opcache.enable_cli=0', $path], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname($path));
        if (!is_resource($process)) {
            throw new \RuntimeException('Cannot launch isolated PHP floor fixture.');
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($process), $stdout, $stderr];
    }

    private function removeFixture(string $root): void
    {
        foreach (scandir($root) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = $root . '/' . $name;
            if (is_dir($path) && !is_link($path)) {
                $this->removeFixture($path);
            } else {
                unlink($path);
            }
        }
        rmdir($root);
    }
}
