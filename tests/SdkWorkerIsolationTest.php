<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace Shabbat\Tests;

use danog\MadelineProto\Ipc\Runner\ProcessRunner;
use PHPUnit\Framework\TestCase;

/** Exercises official worker descriptors with a local PHP child; no Telegram or SDK session is started. */
final class SdkWorkerIsolationTest extends TestCase
{
    public function testStandardWorkerPipesRemainAvailable(): void
    {
        $descriptors = ProcessRunner::getWorkerDescriptors();
        self::assertSame([['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], array_slice($descriptors, 0, 3, true));
        foreach (array_slice($descriptors, 3, null, true) as $number => $specification) {
            self::assertGreaterThan(2, $number);
            self::assertSame(['null'], $specification);
        }
    }

    public function testWorkerCannotRetainParentLockOrListeningSocketAfterParentClosesThem(): void
    {
        if (PHP_OS_FAMILY === 'Windows' || !is_dir('/dev/fd')) {
            self::markTestSkipped('Requires POSIX descriptor inheritance; mandatory Linux CI exercises this path.');
        }
        $path = tempnam(sys_get_temp_dir(), 'shabbat-worker-fd-');
        self::assertIsString($path);
        $file = fopen($path, 'r+');
        self::assertIsResource($file);
        self::assertTrue(flock($file, LOCK_EX | LOCK_NB));
        $listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        self::assertIsResource($listener, $error);
        $address = stream_socket_get_name($listener, false);
        $pipes = [];
        $process = null;
        $replacement = null;
        $nextFile = null;
        try {
            $process = proc_open(
                [PHP_BINARY, '-n', '-r', 'echo "ready\n"; fgets(STDIN);'],
                ProcessRunner::getWorkerDescriptors(),
                $pipes
            );
            self::assertIsResource($process);
            stream_set_timeout($pipes[1], 5);
            self::assertSame("ready\n", fgets($pipes[1]));
            // Close without LOCK_UN: an inherited duplicate would keep the flock alive.
            fclose($file);
            $file = null;
            fclose($listener);
            $listener = null;
            self::assertTrue(proc_get_status($process)['running']);
            $nextFile = fopen($path, 'r+');
            self::assertIsResource($nextFile);
            self::assertTrue(flock($nextFile, LOCK_EX | LOCK_NB), 'A worker retained the parent writer lock.');
            $replacement = stream_socket_server('tcp://' . $address, $errno, $error);
            self::assertIsResource($replacement, 'A worker retained the parent listening socket: ' . $error);
            fwrite($pipes[0], "stop\n");
        } finally {
            foreach ([$file, $listener, $nextFile, $replacement, ...$pipes] as $resource) {
                if (is_resource($resource)) {
                    fclose($resource);
                }
            }
            if (is_resource($process)) {
                proc_close($process);
            }
            unlink($path);
        }
    }
}
