<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-only
namespace Shabbat\Tests;

use Amp\CancelledException;
use Amp\DeferredFuture;
use Amp\TimeoutCancellation;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Shabbat\Calendar\CalendarService;
use Shabbat\Calendar\HebcalProvider;
use Shabbat\Runtime\Application;
use Shabbat\Runtime\Runner;
use Shabbat\Storage\Database;
use Shabbat\Time\FrozenClock;

final class PermissionConcurrencyTest extends TestCase
{
    private string $heartbeatPath;
    private string|false $previousHeartbeat;

    protected function setUp(): void
    {
        $this->resetRunner();
        $this->previousHeartbeat = getenv('HEARTBEAT_PATH');
        $verification = dirname(__DIR__) . '/dist/verification';
        if (!is_dir($verification) && !mkdir($verification, 0700, true) && !is_dir($verification)) {
            throw new \RuntimeException('Cannot create isolated permission test directory.');
        }
        $this->heartbeatPath = dirname(__DIR__) . '/dist/verification/dev9-permission-heartbeat-' . bin2hex(random_bytes(8)) . '.json';
        putenv('HEARTBEAT_PATH=' . $this->heartbeatPath);
    }

    protected function tearDown(): void
    {
        if (is_file($this->heartbeatPath)) {
            unlink($this->heartbeatPath);
        }
        putenv($this->previousHeartbeat === false ? 'HEARTBEAT_PATH' : 'HEARTBEAT_PATH=' . $this->previousHeartbeat);
        $this->resetRunner();
    }

    private function resetRunner(): void
    {
        foreach (['stopping' => false, 'restartPending' => false, 'quiesce' => null, 'handler' => null] as $name => $value) {
            (new \ReflectionProperty(Runner::class, $name))->setValue(null, $value);
        }
    }

    private function fixture(int $groups): array
    {
        $clock = new FrozenClock(new DateTimeImmutable('2026-10-16T09:00:00Z'));
        $db = Database::open(':memory:');
        $db->migrate();
        $gateway = new RuntimeFakeGateway();
        $http = new class implements \Shabbat\Http\JsonClient {
            public function get(string $url, array $query = [], ?\Amp\Cancellation $cancellation = null): array
            {
                throw new \LogicException('A current explicit override must not fetch a provider.');
            }
        };
        $app = new Application($gateway, $clock, $db, new CalendarService(new HebcalProvider($http), $clock));
        for ($i = 1; $i <= $groups; $i++) {
            $app->settings->ensureGroup('-' . $i);
        }
        $db->pdo()->prepare("UPDATE group_settings SET mode='lock',manual_override_mode='lock',manual_override_until=?")
            ->execute([$clock->now()->getTimestamp() + 3600]);
        return [$app, $gateway, $db, $clock];
    }

    private function heartbeat(Application $app): ?int
    {
        return (new \ReflectionProperty(Application::class, 'permissionHeartbeat'))->getValue($app);
    }

    public function testOneHeldPeerCannotBlockOtherSlotsAcrossPagination(): void
    {
        [$app, $gateway, $db, $clock] = $this->fixture(150);
        $release = new DeferredFuture();
        $entered = new DeferredFuture();
        $othersDone = new DeferredFuture();
        $active = 0;
        $peak = 0;
        $seen = [];
        $held = false;
        $writes = 0;
        $gateway->beforeGetPermissions = static function (string $group) use ($release, $entered, &$active, &$peak, &$seen, &$held): void {
            $active++;
            $peak = max($peak, $active);
            $seen[$group] = true;
            try {
                if ($group === '-1' && !$held) {
                    $held = true;
                    $entered->complete();
                    $release->getFuture()->await();
                } else {
                    \Amp\delay(0.001);
                }
            } finally {
                $active--;
            }
        };
        $gateway->beforeSetPermissions = static function (string $group) use (&$writes, $othersDone): void {
            if ($group !== '-1' && ++$writes === 149) {
                $othersDone->complete();
            }
        };
        $tick = \Amp\async($app->permissionTick(...));
        try {
            $entered->getFuture()->await(new TimeoutCancellation(30));
            $othersDone->getFuture()->await(new TimeoutCancellation(30));
            self::assertFalse($tick->isComplete(), 'Full pass must still join the held peer.');
            self::assertNull($this->heartbeat($app), 'A partial pass must not refresh health.');
            self::assertSame(149, $gateway->mutations);
            self::assertCount(150, $seen, 'Free workers must pass the 100-peer pagination boundary.');
            self::assertSame(4, $peak);
            $app->permissionTick(); // A concurrent trigger cannot start a second pool.
            self::assertSame(149, $gateway->mutations);
        } finally {
            $release->complete();
            $tick->await(new TimeoutCancellation(30));
        }
        self::assertSame(0, $active);
        self::assertSame(150, $gateway->mutations);
        self::assertSame(150, (int)$db->pdo()->query("SELECT COUNT(*) FROM group_lock_state WHERE status='locked'")->fetchColumn());
        self::assertSame($clock->now()->getTimestamp(), $this->heartbeat($app));
        self::assertSame(150, (new \ReflectionProperty(Application::class, 'cycleMetrics'))->getValue($app)['permissions']['groups']);
    }

    public function testCancellationStopsNewClaimsAndJoinsTheAlreadyRunningPeer(): void
    {
        [$app, $gateway] = $this->fixture(150);
        $release = new DeferredFuture();
        $cancelled = new DeferredFuture();
        $error = new CancelledException();
        $seen = [];
        $held = false;
        $gateway->beforeGetPermissions = static function (string $group) use ($release, $cancelled, $error, &$seen, &$held): void {
            $seen[$group] = true;
            if ($group === '-1' && !$held) {
                $held = true;
                $release->getFuture()->await();
            } elseif ($group === '-10') {
                $cancelled->complete();
                throw $error;
            }
        };
        $tick = \Amp\async($app->permissionTick(...));
        $tick->ignore();
        try {
            $cancelled->getFuture()->await(new TimeoutCancellation(30));
            self::assertFalse($tick->isComplete(), 'Cancellation must not abandon an in-flight peer.');
            self::assertCount(2, $seen, 'A cancelled worker must stop all new peer claims.');
            self::assertNull($this->heartbeat($app));
        } finally {
            $release->complete();
            try {
                $tick->await(new TimeoutCancellation(30));
                self::fail('Cancellation was swallowed.');
            } catch (CancelledException $caught) {
                self::assertSame($error, $caught);
            }
        }
        self::assertNull($this->heartbeat($app));
        self::assertFalse((new \ReflectionProperty(Application::class, 'ticking'))->getValue($app));
        self::assertNull((new \ReflectionProperty(Application::class, 'tickCompletion'))->getValue($app));
    }

    public function testShutdownDuringAReadDoesNotStartRemainingPeersOrPublishHealth(): void
    {
        [$app, $gateway] = $this->fixture(150);
        $seen = [];
        $gateway->beforeGetPermissions = static function (string $group) use (&$seen): void {
            $seen[$group] = true;
            Runner::requestShutdown();
        };
        try {
            $app->permissionTick();
        } catch (CancelledException) {
            // An explicit shutdown can propagate after joining started workers.
        }
        self::assertCount(1, $seen);
        self::assertSame(0, $gateway->mutations);
        self::assertNull($this->heartbeat($app));
        self::assertFalse((new \ReflectionProperty(Application::class, 'ticking'))->getValue($app));
    }

    public function testEmptyScanCompletesWithoutInventingGroupsOrLeavingWorkers(): void
    {
        [$app, $gateway, $db, $clock] = $this->fixture(0);
        $app->permissionTick();
        self::assertSame(0, $gateway->mutations);
        self::assertSame($clock->now()->getTimestamp(), $this->heartbeat($app));
        self::assertSame(0, (new \ReflectionProperty(Application::class, 'cycleMetrics'))->getValue($app)['permissions']['groups']);
        self::assertFalse((new \ReflectionProperty(Application::class, 'ticking'))->getValue($app));
    }
}
