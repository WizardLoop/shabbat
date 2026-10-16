<?php

// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);

namespace Shabbat\Runtime;

/** Shares a single in-flight or ready IPC client per live event handler. */
final class RuntimeClientPool
{
    /** @var \WeakMap<object,\Amp\Future<mixed>>|null */
    private static ?\WeakMap $clients = null;
    /** @param callable(): object $factory */
    public static function get(object $owner, callable $factory): object
    {
        self::$clients ??= new \WeakMap();
        $future = self::$clients[$owner] ?? null;
        if ($future === null) {
            $future = \Amp\async(static function () use ($factory): mixed {
                return $factory();
            });
            self::$clients[$owner] = $future;
        }
        try {
            $client = $future->await();
            if (!is_object($client)) {
                throw new \UnexpectedValueException('Runtime client factory must return an object.');
            }
            return $client;
        } catch (\Throwable $error) {
            if ((self::$clients[$owner] ?? null) === $future) {
                unset(self::$clients[$owner]);
            }
            throw $error;
        }
    }
    public static function release(object $owner): void
    {
        if (self::$clients !== null) {
            unset(self::$clients[$owner]);
        }
    }
}
