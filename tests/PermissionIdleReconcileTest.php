<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace Shabbat\Tests;

use PHPUnit\Framework\TestCase;
use Shabbat\Scheduling\{LockCoordinator, Permissions};
use Shabbat\Storage\{Database, SettingsRepository};
use Shabbat\Time\FrozenClock;

final class PermissionIdleReconcileTest extends TestCase
{
    public function testCompletedCycleSkipsReadsAcrossThousandTicksThenRecapturesAdministratorChanges(): void
    {
        $db = Database::open(':memory:');
        $db->migrate();
        $clock = new FrozenClock(new \DateTimeImmutable('2026-10-16T12:00:00Z'));
        (new SettingsRepository($db, $clock))->ensureGroup('-1');
        $gateway = new PermissionRecoveryGateway();
        $reads = 0;
        $gateway->onRead = static function () use (&$reads): void {
            $reads++;
        };
        $locks = new LockCoordinator($db, $clock, $gateway);
        self::assertSame('locked', $locks->reconcile('-1', 'week1', true)['status']);
        self::assertSame('restored', $locks->reconcile('-1', 'week1', false)['status']);
        $before = $reads;
        $gateway->current['send_photos'] = true;
        $original = $gateway->current;
        for ($i = 0; $i < 1000; $i++) {
            self::assertSame(['status' => 'restored', 'changed' => false], $locks->reconcile('-1', 'weekday', false));
        }
        self::assertSame($before, $reads);
        self::assertSame('locked', $locks->reconcile('-1', 'week2', true)['status']);
        self::assertGreaterThan($before, $reads);
        self::assertSame('restored', $locks->reconcile('-1', 'week2', false)['status']);
        self::assertSame($original, $gateway->current);
    }
    public function testPrelockedWeekdayStateDoesNotReadOrOpenAnAdministratorsLock(): void
    {
        $db = Database::open(':memory:');
        $db->migrate();
        $clock = new FrozenClock(new \DateTimeImmutable('2026-10-16T12:00:00Z'));
        (new SettingsRepository($db, $clock))->ensureGroup('-1');
        $gateway = new PermissionRecoveryGateway();
        $gateway->current = Permissions::locked($gateway->current);
        $locks = new LockCoordinator($db, $clock, $gateway);
        self::assertSame('prelocked', $locks->reconcile('-1', 'week1', true)['status']);
        $gateway->onRead = static fn() => throw new \LogicException('No remote no-op read expected.');
        self::assertSame(['status' => 'prelocked', 'changed' => false], $locks->reconcile('-1', 'weekday', false));
        self::assertSame([], $gateway->writes);
    }
}
