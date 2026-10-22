<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace Shabbat\Tests;

use Amp\CancelledException;
use Amp\TimeoutException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Executes the official UpdateLoop and real parent Loop; only RPC/storage boundaries are fixtures. */
final class UpdateLoopCompatibilityTest extends TestCase
{
    public static function cases(): iterable
    {
        foreach (['generic', 'channel'] as $branch) {
            foreach (
                ['direct', 'server-timeout', 'wrapped', 'intentional',
                'shutdown-direct', 'shutdown-server-timeout', 'shutdown-wrapped', 'shutdown-intentional'] as $scenario
            ) {
                yield $branch . '-' . $scenario => [$branch, $scenario];
            }
        }
    }

    #[DataProvider('cases')]
    public function testOfficialRecoveryCancellationAndParentLoopStop(string $branch, string $scenario): void
    {
        $output = tempnam(sys_get_temp_dir(), 'shabbat-loop-out-');
        $errors = tempnam(sys_get_temp_dir(), 'shabbat-loop-err-');
        self::assertIsString($output);
        self::assertIsString($errors);
        $process = null;
        try {
            $pipes = [];
            $process = proc_open([PHP_BINARY, '-d', 'opcache.enable_cli=0', __DIR__ . '/UpdateLoopHarness.php',
                $scenario, $branch], [0 => ['pipe', 'r'], 1 => ['file', $output, 'w'],
                2 => ['file', $errors, 'w']], $pipes);
            self::assertIsResource($process);
            fclose($pipes[0]);
            $deadline = microtime(true) + 15;
            do {
                $status = proc_get_status($process);
                if (!$status['running']) {
                    break;
                }
                usleep(10_000);
            } while (microtime(true) < $deadline);
            self::assertFalse($status['running'], 'Official updater exceeded the independent process deadline.');
            $out = (string) file_get_contents($output);
            $error = (string) file_get_contents($errors);
            self::assertSame(0, $status['exitcode'], $error . $out);
        } finally {
            if (is_resource($process)) {
                if (proc_get_status($process)['running']) {
                    proc_terminate($process, 9);
                }
                proc_close($process);
            }
            unlink($output);
            unlink($errors);
        }
        $data = json_decode($out, true, flags: JSON_THROW_ON_ERROR);
        self::assertFalse($data['timed_out'], 'Parent loop did not settle within the fixture deadline.');
        self::assertFalse($data['running']);
        self::assertTrue($data['paused']);
        if (str_ends_with($scenario, 'intentional') || str_ends_with($scenario, 'direct')) {
            $exception = str_ends_with($scenario, 'intentional') ? CancelledException::class : TimeoutException::class;
            self::assertSame([$exception], $data['errors'], 'Non-retry exceptions must remain visible.');
            self::assertSame(1, $data['calls']);
            self::assertSame(0, $data['reports']);
        } else {
            self::assertSame([], $data['errors']);
            if (!str_starts_with($scenario, 'shutdown-')) {
                self::assertSame(2, $data['calls'], 'A genuine RPC/timeout cancellation must recover.');
                self::assertSame($scenario === 'wrapped' ? 1 : 0, $data['reports']);
                if ($branch === 'channel' || $scenario === 'server-timeout') {
                    self::assertGreaterThanOrEqual(0.9, $data['elapsed'], 'Official delayed recovery must not spin.');
                }
            }
        }
        if (str_starts_with($scenario, 'shutdown-')) {
            // Loop::stop ends scheduling; it does not cancel an in-flight loop() body.
            // A single internal recovery is permitted before the real parent settles.
            // Transport disconnection and writer ownership are tested independently.
            self::assertLessThanOrEqual(1, $data['calls_after_stop']);
            self::assertLessThanOrEqual(2, $data['calls']);
            self::assertLessThanOrEqual(1, $data['reports']);
        }
    }
}
