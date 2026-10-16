<?php

// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);

namespace Shabbat\Tests;

use PHPUnit\Framework\TestCase;
use Shabbat\Calendar\CalendarService;
use Shabbat\Calendar\HebcalProvider;
use Shabbat\Http\JsonClient;
use Shabbat\Runtime\Application;
use Shabbat\Runtime\Runner;
use Shabbat\Storage\AdminStateFiles;
use Shabbat\Storage\Database;
use Shabbat\Time\FrozenClock;

final class RecoveryCalendarGuardTest extends TestCase
{
    private array $adminState = [];
    protected function setUp(): void
    {
        (new \ReflectionProperty(Runner::class, 'stopping'))->setValue(null, false);
        foreach (['database', 'clock', 'root'] as $name) {
            $this->adminState[$name] = (new \ReflectionProperty(AdminStateFiles::class, $name))->getValue();
        }
    }
    protected function tearDown(): void
    {
        foreach ($this->adminState as $name => $value) {
            (new \ReflectionProperty(AdminStateFiles::class, $name))->setValue(null, $value);
        }
        (new \ReflectionProperty(Runner::class, 'stopping'))->setValue(null, false);
    }
    private function app(string $date, array $changes = [], ?JsonClient $http = null): Application
    {
        $clock = new FrozenClock(new \DateTimeImmutable($date));
        $db = Database::open(':memory:');
        $db->migrate();
        $app = new Application(new RuntimeFakeGateway(), $clock, $db, new CalendarService(new HebcalProvider($http ?? new RuntimeFakeJson()), $clock));
        $app->settings->ensureGroup('-1001', 'Recovery calendar fixture', $changes);
        return $app;
    }
    private function allowed(Application $app): bool
    {
        return (new \ReflectionMethod($app, 'permissionRecoveryAllowed'))->invoke($app, '-1001');
    }
    public function testWeekdayRecoveryIsAllowedAndExpiredManualLockDoesNotLinger(): void
    {
        $app = $this->app('2026-10-15T12:00:00+03:00', ['manual_override_mode' => 'lock', 'manual_override_until' => 1]);
        self::assertTrue($this->allowed($app));
        self::assertSame(0, $app->gateway->mutations);
    }
    public function testActiveManualLockBlocksRecoveryEvenOnWeekday(): void
    {
        $app = $this->app('2026-10-15T12:00:00+03:00', ['manual_override_mode' => 'lock', 'manual_override_until' => (new \DateTimeImmutable('2026-10-15T13:00:00+03:00'))->getTimestamp()]);
        self::assertFalse($this->allowed($app));
        self::assertSame(0, $app->gateway->mutations);
    }
    public function testManualOpenCannotBypassShabbatWhenModeIsOff(): void
    {
        $app = $this->app('2026-10-17T12:00:00+03:00', ['mode' => 'off', 'manual_override_mode' => 'open', 'manual_override_until' => (new \DateTimeImmutable('2026-10-17T13:00:00+03:00'))->getTimestamp()]);
        self::assertFalse($this->allowed($app));
        self::assertSame(0, $app->gateway->mutations);
    }
    public function testOperationalEarlyCloseAndLateOpenWindowsAlsoBlockRecovery(): void
    {
        $app = $this->app('2026-10-16T17:15:00+03:00', ['mode' => 'off', 'close_before_candles_minutes' => 10, 'open_after_havdalah_minutes' => 15]);
        self::assertFalse($this->allowed($app));
        $app->clock->set(new \DateTimeImmutable('2026-10-17T18:50:00+03:00'));
        self::assertFalse($this->allowed($app));
        $app->clock->set(new \DateTimeImmutable('2026-10-17T18:55:00+03:00'));
        self::assertTrue($this->allowed($app));
    }
    private function stillAllowed(Application $app): bool
    {
        return (new \ReflectionMethod($app, 'permissionRecoveryStillAllowed'))->invoke($app, '-1001');
    }
    public function testSynchronousFenceAllowsSlowReadOnlyUntilVerifiedReligiousBoundary(): void
    {
        $app = $this->app('2026-10-16T17:19:55+03:00', ['mode' => 'off', 'shabbat_enabled' => false]);
        self::assertTrue($this->allowed($app));
        $app->clock->set(new \DateTimeImmutable('2026-10-16T17:19:59+03:00'));
        self::assertTrue($this->stillAllowed($app));
        $app->clock->set(new \DateTimeImmutable('2026-10-16T17:20:00+03:00'));
        self::assertFalse($this->stillAllowed($app));
    }
    public function testSynchronousFenceRejectsChangedSettingsAndExpiredOrBackwardClock(): void
    {
        $app = $this->app('2026-10-15T12:00:00+03:00');
        self::assertFalse($this->stillAllowed($app));
        self::assertTrue($this->allowed($app));
        $app->clock->set(new \DateTimeImmutable('2026-10-15T12:01:59+03:00'));
        self::assertTrue($this->stillAllowed($app));
        $app->clock->set(new \DateTimeImmutable('2026-10-15T12:02:00+03:00'));
        self::assertFalse($this->stillAllowed($app));
        $app->clock->set(new \DateTimeImmutable('2026-10-15T11:59:59+03:00'));
        self::assertFalse($this->stillAllowed($app));
        $app->clock->set(new \DateTimeImmutable('2026-10-15T12:00:30+03:00'));
        $group = $app->settings->getGroup('-1001');
        $app->settings->updateGroup('-1001', $group['revision'], ['candle_minutes' => 50], '42');
        self::assertFalse($this->stillAllowed($app));
    }
    public function testSynchronousFenceDoesNotFetchProviderAgain(): void
    {
        $http = new class implements JsonClient {
            public int $calls = 0;
            public function get(string $url, array $query = [], ?\Amp\Cancellation $cancellation = null): array
            {
                $this->calls++;
                return (new RuntimeFakeJson())->get($url, $query, $cancellation);
            }
        };
        $app = $this->app('2026-10-15T12:00:00+03:00', [], $http);
        self::assertTrue($this->allowed($app));
        $calls = $http->calls;
        self::assertGreaterThan(0, $calls);
        self::assertTrue($this->stillAllowed($app));
        self::assertSame($calls, $http->calls);
    }
    public function testUnknownCalendarCannotGrantRecovery(): void
    {
        $http = new class implements JsonClient {
            public function get(string $url, array $query = [], ?\Amp\Cancellation $cancellation = null): array
            {
                throw new \RuntimeException('Offline provider fixture');
            }
        };
        $app = $this->app('2026-10-15T12:00:00+03:00', [], $http);
        $this->expectException(\Throwable::class);
        $this->allowed($app);
    }
    public function testShutdownCancellationIsNotConvertedToPermissionToRecover(): void
    {
        $app = $this->app('2026-10-15T12:00:00+03:00');
        (new \ReflectionProperty(Runner::class, 'stopping'))->setValue(null, true);
        $this->expectException(\Amp\CancelledException::class);
        $this->allowed($app);
    }
}
