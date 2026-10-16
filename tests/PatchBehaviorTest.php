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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PatchBehaviorTest extends TestCase
{
    public static function cases(): iterable
    {
        foreach (['generic', 'channel'] as $branch) {
            foreach (['direct', 'wrapped', 'intentional', 'shutdown-direct', 'shutdown-wrapped', 'shutdown-intentional'] as $scenario) {
                yield $branch . '-' . $scenario => [$branch, $scenario];
            }
        }
    }

    #[DataProvider('cases')]
    public function testRealUpdateLoopBehavior(string $branch, string $scenario): void
    {
        $pipes = [];
        $process = proc_open([PHP_BINARY, __DIR__ . '/PatchLoopHarness.php', $scenario, $branch], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $error . $out);
        $data = json_decode($out, true, flags: JSON_THROW_ON_ERROR);
        if ($scenario === 'intentional') {
            self::assertSame(Amp\CancelledException::class, $data['exception']);
            self::assertSame(1, $data['calls']);
            self::assertSame(0, $data['reports']);
        } elseif (str_starts_with($scenario, 'shutdown-')) {
            self::assertSame(danog\Loop\Loop::STOP, (float) $data['first']);
            self::assertSame(1, $data['calls']);
            self::assertSame(0, $data['reports']);
        } else {
            self::assertArrayHasKey('first', $data, 'The timeout harness failed: ' . json_encode($data, JSON_THROW_ON_ERROR));
            self::assertSame(10, $data['first']);
            self::assertSame(10, $data['second']);
            self::assertSame(1, $data['calls_after_first'], 'Must return to parent loop rather than retry immediately.');
            self::assertSame(2, $data['calls'], 'Next cycle must recover.');
            self::assertSame(1, $data['reports']);
        }
    }
}
