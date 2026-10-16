<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace Shabbat\Tests;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Shabbat\Scheduling\DeliveryDeferredException;
use Shabbat\Scheduling\JobRepository;
use Shabbat\Scheduling\OutboxWorker;
use Shabbat\Storage\Database;
use Shabbat\Storage\SettingsRepository;
use Shabbat\Telegram\RetryableTelegramException;
use Shabbat\Time\FrozenClock;

final class DeliveryDeferralTest extends TestCase
{
    public function testEligibilityDeferralsDoNotConsumeTransportRetryBudgetOrSend(): void
    {
        $database = Database::open(':memory:');
        $database->migrate();
        $clock = new FrozenClock(new DateTimeImmutable('2026-10-15T09:00:00Z'));
        $settings = new SettingsRepository($database, $clock);
        $group = $settings->ensureGroup('-1001', 'Fixture');
        $group = $settings->updateGroup('-1001', $group['revision'], ['zmanim_enabled' => true], '42');
        $jobs = new JobRepository($database, $clock);
        $id = $jobs->enqueue('-1001', 'zmanim', 'fixture-event', $clock->now()->getTimestamp(), $clock->now()->getTimestamp() + 3600, $group['revision'], ['text' => 'fixture']);
        $gateway = new SchedulerFakeGateway();
        $defer = true;
        $worker = new OutboxWorker($jobs, $settings, $gateway, $clock, static function () use (&$defer, $clock): bool {
            if ($defer) {
                throw new DeliveryDeferredException('fixture restoration debt', $clock->now()->getTimestamp() + 60);
            }
            return true;
        });
        for ($count = 0; $count < 6; $count++) {
            self::assertSame('deferred', $worker->runOnce('fixture-owner')['status']);
            self::assertSame('pending', $jobs->get($id)['status']);
            self::assertSame([], $gateway->sends);
            $clock->set($clock->now()->modify('+60 seconds'));
        }
        self::assertSame(6, $jobs->get($id)['deferrals']);
        $defer = false;
        $gateway->onSend = static function (): array {
            throw new RetryableTelegramException('fixture timeout', 1);
        };
        self::assertSame('retry', $worker->runOnce('fixture-owner')['status']);
        self::assertSame('pending', $jobs->get($id)['status']);
        $clock->set($clock->now()->modify('+1 second'));
        $gateway->onSend = null;
        self::assertSame('sent', $worker->runOnce('fixture-owner')['status']);
        self::assertSame('sent', $jobs->get($id)['status']);
        self::assertSame($gateway->sends[0][2], $gateway->sends[1][2]);
    }

    public function testDeferredDeadlineBeyondCutoffExpiresWithoutTelegramRequest(): void
    {
        $database = Database::open(':memory:');
        $database->migrate();
        $clock = new FrozenClock(new DateTimeImmutable('2026-10-15T09:00:00Z'));
        $settings = new SettingsRepository($database, $clock);
        $group = $settings->ensureGroup('-1001', 'Fixture');
        $group = $settings->updateGroup('-1001', $group['revision'], ['zmanim_enabled' => true], '42');
        $jobs = new JobRepository($database, $clock);
        $id = $jobs->enqueue('-1001', 'zmanim', 'fixture-cutoff', $clock->now()->getTimestamp(), $clock->now()->getTimestamp() + 30, $group['revision'], ['text' => 'fixture']);
        $gateway = new SchedulerFakeGateway();
        $worker = new OutboxWorker($jobs, $settings, $gateway, $clock, static function () use ($clock): bool {
            throw new DeliveryDeferredException('fixture after cutoff', $clock->now()->getTimestamp() + 60);
        });
        self::assertSame('deferred', $worker->runOnce('fixture-owner')['status']);
        self::assertSame('expired', $jobs->get($id)['status']);
        self::assertSame([], $gateway->sends);
    }
}
