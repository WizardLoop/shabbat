<?php

declare(strict_types=1);

namespace Shabbat\Tests;

use Amp;
use danog;
use DateTimeImmutable;
use FilesystemIterator;
use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;
// SPDX-License-Identifier: AGPL-3.0-only
use PHPUnit\Framework\TestCase;
use Shabbat\Runtime\Runner;
use Shabbat\Runtime\SingleInstance;

final class PatchRunnerTest extends TestCase
{
    public function testIntentionalShutdownCancellationIsHandled(): void
    {
        Runner::handleEventError(new Amp\CancelledException(new RuntimeException('shutdown')), true);
        self::assertTrue(true);
    }

    public function testCancellationDuringNormalRunPropagates(): void
    {
        $this->expectException(Amp\CancelledException::class);
        Runner::handleEventError(new Amp\CancelledException(new RuntimeException('deliberate')));
    }

    public function testTimeoutCancellationDuringShutdownIsNotSilentlySwallowed(): void
    {
        $this->expectException(Amp\CancelledException::class);
        Runner::handleEventError(new Amp\CancelledException(new Amp\TimeoutException('timeout')), true);
    }

    public function testCancellationTextCannotHideUnrelatedError(): void
    {
        $this->expectException(RuntimeException::class);
        Runner::handleEventError(new RuntimeException('The operation was cancelled'));
    }

    public function testSingleInstanceCannotAcquireSharedWriterLockTwice(): void
    {
        $path = sys_get_temp_dir() . '/shabbat-writer-' . bin2hex(random_bytes(6));
        $first = SingleInstance::acquire($path);
        try {
            try {
                SingleInstance::acquire($path);
                self::fail('Duplicate instance was permitted');
            } catch (RuntimeException) {
                self::assertTrue(true);
            }
        } finally {
            $first->release();
            unlink($path);
        }
    }
}
