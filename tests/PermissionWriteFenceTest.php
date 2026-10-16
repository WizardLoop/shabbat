<?php

// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);

namespace Shabbat\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shabbat\Calendar\{CalendarService,HebcalProvider};
use Shabbat\Http\JsonClient;
use Shabbat\Runtime\{Application,Runner};
use Shabbat\Scheduling\PermissionRecoveryJournal;
use Shabbat\Storage\{AdminStateFiles,Database};
use Shabbat\Time\FrozenClock;

final class PermissionWriteFenceTest extends TestCase
{
    private array $adminState = [];
    protected function setUp(): void
    {
        (new \ReflectionProperty(Runner::class, 'stopping'))->setValue(null, false);
        foreach (['database','clock','root'] as $name) {
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
    private function app(string $date = '2026-10-15T12:00:00+03:00', ?JsonClient $http = null): Application
    {
        $clock = new FrozenClock(new \DateTimeImmutable($date));
        $db = Database::open(':memory:');
        $db->migrate();
        $gateway = new RuntimeFakeGateway();
        $gateway->rights['-1'] = ['_' => 'chatBannedRights','until_date' => 2147483647,'send_photos' => true,'edit_rank' => true];
        $app = new Application($gateway, $clock, $db, new CalendarService(new HebcalProvider($http ?? new RuntimeFakeJson()), $clock), authorizeOperator: static fn(): bool => true);
        $app->settings->ensureGroup('-1', 'Permission fence', ['mode' => 'lock_messages']);
        return $app;
    }
    public static function directions(): array
    {
        return [['lock'],['open']];
    }
    #[DataProvider('directions')]
    public function testFinalReadCrossingManualOverrideExpiryNeverWrites(string $mode): void
    {
        $app = $this->app();
        if ($mode === 'open') {
            self::assertSame(1, $app->manualGroups->apply(true, '42')['changed']);
        }
        $writes = $app->gateway->mutations;
        $reads = 0;
        $app->gateway->beforeGetPermissions = static function () use ($app, &$reads): void {
            if (++$reads === 2) {
                $app->clock->set($app->clock->now()->modify('+2 seconds'));
            }
        };
        $result = $app->manualGroups->apply($mode === 'lock', '42', 1);
        self::assertSame('deferred', $result['groups'][0]['status']);
        self::assertSame($writes, $app->gateway->mutations);
        self::assertSame('not_dispatched', (new PermissionRecoveryJournal($app->db, $app->clock))->latest('-1')['outcome']);
    }
    #[DataProvider('directions')]
    public function testSettingsChangeDuringFinalReadNeverWrites(string $mode): void
    {
        $app = $this->app();
        if ($mode === 'open') {
            $app->manualGroups->apply(true, '42');
        }
        $writes = $app->gateway->mutations;
        $reads = 0;
        $app->gateway->beforeGetPermissions = static function () use ($app, &$reads): void {
            if (++$reads === 2) {
                $settings = $app->settings->getGroup('-1');
                $app->settings->updateGroup('-1', $settings['revision'], ['candle_minutes' => 45], '42');
            }
        };
        $result = $app->manualGroups->apply($mode === 'lock', '42');
        self::assertSame('deferred', $result['groups'][0]['status']);
        self::assertSame($writes, $app->gateway->mutations);
    }
    public function testAdministratorMediaChangeDuringProviderWaitIsNotOverwrittenAndFreshNextCycleRecovers(): void
    {
        $http = new class implements JsonClient {
            public ?\Closure $onGet = null;
            public function get(string $url, array $query = [], ?\Amp\Cancellation $cancellation = null): array
            {
                ($this->onGet)?->__invoke();
                return (new RuntimeFakeJson())->get($url, $query, $cancellation);
            }
        };
        $app = $this->app('2026-10-16T17:30:00+03:00', $http);
        $before = $app->gateway->rights['-1'];
        $http->onGet = static function () use ($app): void {
            $app->gateway->rights['-1']['send_videos'] = true;
        };
        self::assertSame('deferred', $app->locks->reconcile('-1', 'shabbat', true)['status']);
        self::assertSame(0, $app->gateway->mutations);
        self::assertSame(Database::encode($before), $app->locks->state('-1')['snapshot_json']);
        $http->onGet = null;
        self::assertSame('locked', $app->locks->reconcile('-1', 'shabbat', true)['status']);
        self::assertTrue(json_decode($app->locks->state('-1')['snapshot_json'], true)['send_videos']);
        $app->clock->set(new \DateTimeImmutable('2026-10-18T10:00:00+03:00'));
        self::assertSame('restored', $app->locks->reconcile('-1', 'shabbat', false)['status']);
        self::assertTrue($app->gateway->rights['-1']['send_photos']);
        self::assertTrue($app->gateway->rights['-1']['send_videos']);
        self::assertTrue($app->gateway->rights['-1']['edit_rank']);
    }
    public function testAdministratorEditWhileRestoreProviderAwaitsNeverOverwritesCurrentOrOriginal(): void
    {
        $entered = new \Amp\DeferredFuture();
        $release = new \Amp\DeferredFuture();
        $http = new class ($entered, $release) implements JsonClient {
            private bool $waited = false;
            public function __construct(private \Amp\DeferredFuture $entered, private \Amp\DeferredFuture $release)
            {
            }
            public function get(string $url, array $query = [], ?\Amp\Cancellation $cancellation = null): array
            {
                if (!$this->waited) {
                    $this->waited = true;
                    $this->entered->complete();
                    $this->release->getFuture()->await();
                }
                return (new RuntimeFakeJson())->get($url, $query, $cancellation);
            }
        };
        $app = $this->app('2026-10-15T12:00:00+03:00', $http);
        $app->manualGroups->apply(true, '42', 1);
        $original = $app->locks->state('-1')['snapshot_json'];
        $app->clock->set($app->clock->now()->modify('+2 seconds'));
        $opening = \Amp\async(fn(): array => $app->locks->reconcile('-1', 'opening', false));
        $entered->getFuture()->await();
        self::assertFalse($app->db->pdo()->inTransaction());
        $app->gateway->rights['-1']['send_photos'] = false;
        $release->complete();
        self::assertSame('deferred', $opening->await()['status']);
        self::assertSame(1, $app->gateway->mutations);
        self::assertFalse($app->gateway->rights['-1']['send_photos']);
        self::assertSame($original, $app->locks->state('-1')['snapshot_json']);
        self::assertSame('not_dispatched', (new PermissionRecoveryJournal($app->db, $app->clock))->latest('-1')['outcome']);
        self::assertSame('conflict', $app->locks->reconcile('-1', 'opening', false)['status']);
        self::assertSame(1, $app->gateway->mutations);
    }
    public function testAutomaticOpeningCrossingNextCloseBoundaryRetainsLockedState(): void
    {
        $app = $this->app('2026-10-15T12:00:00+03:00');
        $app->manualGroups->apply(true, '42', 1);
        $app->clock->set(new \DateTimeImmutable('2026-10-16T17:19:59+03:00'));
        $reads = 0;
        $app->gateway->beforeGetPermissions = static function () use ($app, &$reads): void {
            if (++$reads === 2) {
                $app->clock->set(new \DateTimeImmutable('2026-10-16T17:20:00+03:00'));
            }
        };
        self::assertSame('deferred', $app->locks->reconcile('-1', 'opening', false)['status']);
        self::assertSame(1, $app->gateway->mutations);
        self::assertTrue($app->gateway->rights['-1']['send_plain']);
    }
    public function testAutomaticClosingCrossingOpeningBoundaryDoesNotApplyExpiredLock(): void
    {
        $app = $this->app('2026-10-17T18:39:59+03:00');
        $reads = 0;
        $app->gateway->beforeGetPermissions = static function () use ($app, &$reads): void {
            if (++$reads === 2) {
                $app->clock->set(new \DateTimeImmutable('2026-10-17T18:40:00+03:00'));
            }
        };
        self::assertSame('deferred', $app->locks->reconcile('-1', 'closing', true)['status']);
        self::assertSame(0, $app->gateway->mutations);
    }
    public function testShutdownAtFinalReadPropagatesCancellationWithoutMutation(): void
    {
        $app = $this->app();
        $reads = 0;
        $app->gateway->beforeGetPermissions = static function () use (&$reads): void {
            if (++$reads === 2) {
                Runner::requestShutdown();
            }
        };
        try {
            $app->manualGroups->apply(true, '42');
            self::fail('Shutdown must propagate cancellation.');
        } catch (\Amp\CancelledException) {
            self::assertSame(0, $app->gateway->mutations);
            self::assertSame('not_dispatched', (new PermissionRecoveryJournal($app->db, $app->clock))->latest('-1')['outcome']);
        }
    }
}
