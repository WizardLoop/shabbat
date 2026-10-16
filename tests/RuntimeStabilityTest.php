<?php

// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);

namespace Shabbat\Tests;

use PHPUnit\Framework\TestCase;
use Shabbat\Runtime\{Application,GroupRegistration,MadelineGateway,ManualGroupReport,PermissionFailure,Runner,RuntimeClientPool};
use Shabbat\Calendar\{CalendarService,HebcalProvider};
use Shabbat\Storage\Database;
use Shabbat\Time\FrozenClock;
use Shabbat\Scheduling\Permissions;

final class RuntimeStabilityTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        Runner::settings();
    }
    protected function setUp(): void
    {
        foreach (['stopping' => false, 'restartPending' => false, 'quiesce' => null, 'handler' => null] as $name => $value) {
            (new \ReflectionProperty(Runner::class, $name))->setValue(null, $value);
        }
    }
    protected function tearDown(): void
    {
        $this->setUp();
    }
    private function app(?callable $authorize = null): array
    {
        $clock = new FrozenClock(new \DateTimeImmutable('2026-10-15T09:00:00Z'));
        $db = Database::open(':memory:');
        $db->migrate();
        $http = new class implements \Shabbat\Http\JsonClient {
            public int $calls = 0;
            public function get(string $url, array $query = [], ?\Amp\Cancellation $cancellation = null): array
            {
                $this->calls++;
                throw new \Shabbat\Http\ProviderException('provider offline');
            }
        };
        $gateway = new RuntimeFakeGateway();
        $app = new Application($gateway, $clock, $db, new CalendarService(new HebcalProvider($http), $clock), authorizeOperator: $authorize ?? static fn(string $id): bool => $id === '42');
        return [$app, $gateway, $http, $clock];
    }
    private function rpc(string $name): \danog\MadelineProto\RPCErrorException
    {
        $error = (new \ReflectionClass(\danog\MadelineProto\RPCErrorException::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty($error, 'rpc'))->setValue($error, $name);
        return $error;
    }
    public function testManualCloseAndOpenWorkWhenCalendarProviderIsOffline(): void
    {
        [$app, $gateway, $http] = $this->app();
        $app->settings->ensureGroup('-1', 'Basic group');
        $original = $gateway->getPermissions('-1');
        self::assertSame(1, $app->manualGroups->apply(true, '42')['changed']);
        self::assertTrue($gateway->rights['-1']['send_reactions']);
        self::assertTrue($gateway->rights['-1']['edit_rank']);
        self::assertSame(1, $app->manualGroups->apply(false, '42')['changed']);
        self::assertSame($original, $gateway->rights['-1']);
        self::assertSame(0, $http->calls);
    }
    public function testManyGroupsCrossPagesFailureIsolationAndExactRestoration(): void
    {
        [$app, $gateway, $http] = $this->app();
        for ($i = 1; $i <= 250; $i++) {
            $app->settings->ensureGroup('-' . $i, 'Group ' . $i);
        }
        $gateway->fail = '-125';
        $closed = $app->manualGroups->apply(true, '42');
        self::assertCount(250, $closed['groups']);
        self::assertSame(249, $closed['changed']);
        self::assertSame(1, $closed['failed']);
        $gateway->fail = null;
        $opened = $app->manualGroups->apply(false, '42');
        self::assertSame(249, $opened['changed']);
        self::assertSame(0, $opened['failed']);
        self::assertSame(498, $gateway->mutations);
        foreach ($gateway->rights as $rights) {
            self::assertFalse($rights['send_messages']);
            self::assertFalse($rights['send_media']);
            self::assertArrayNotHasKey('send_reactions', $rights);
        }
        self::assertSame(0, $http->calls);
    }
    public function testShutdownDuringReadStopsBatchWithoutMutationAndRetainsRecoveryRecord(): void
    {
        [$app, $gateway] = $this->app();
        $app->settings->ensureGroup('-1');
        $app->settings->ensureGroup('-2');
        $gateway->beforeGetPermissions = Runner::requestShutdown(...);
        try {
            $app->manualGroups->apply(true, '42');
            self::fail('Shutdown continued manual work.');
        } catch (\Amp\CancelledException) {
            self::assertSame(0, $gateway->mutations);
            self::assertNotNull($app->locks->state('-1')['snapshot_json']);
            self::assertNull($app->locks->state('-2'));
        }
    }
    public function testExpiredOverrideUsesCalendarInsteadOfKeepingManualLock(): void
    {
        [$app, $gateway, $http, $clock] = $this->app();
        $app->settings->ensureGroup('-1');
        $gateway->beforeGetPermissions = static function () use ($clock): void {
            $clock->set($clock->now()->modify('+2 seconds'));
        };
        $result = $app->manualGroups->apply(true, '42', 1);
        self::assertSame(1, $result['failed']);
        self::assertSame(0, $gateway->mutations);
        self::assertGreaterThan(0, $http->calls);
    }
    public function testKnownRpcFailureIsActionableAndArbitraryTextIsNotLeaked(): void
    {
        [$app, $gateway] = $this->app();
        $app->settings->ensureGroup('-1', '<b>Group</b>');
        $gateway->beforeSetPermissions = function (): void {
            throw $this->rpc('CHAT_ADMIN_REQUIRED');
        };
        $result = $app->manualGroups->apply(true, '42');
        self::assertSame('CHAT_ADMIN_REQUIRED', $result['groups'][0]['error_code']);
        foreach (\Shabbat\UI\Lang::CODES as $language) {
            $text = ManualGroupReport::format($result, $language, 'Asia/Jerusalem');
            self::assertStringContainsString('CHAT_ADMIN_REQUIRED', $text);
            self::assertStringContainsString('&lt;b&gt;Group&lt;/b&gt;', $text);
            self::assertStringContainsString((new \Shabbat\UI\Lang())->text($language, 'permission_error_admin'), $text);
        }
        self::assertSame('OPERATION_UNCONFIRMED', PermissionFailure::code(new \RuntimeException('secret-token')));
        $result['groups'][0]['error'] = 'secret-token';
        $result['groups'][0]['error_code'] = 'OPERATION_UNCONFIRMED';
        self::assertStringNotContainsString('secret-token', ManualGroupReport::format($result, 'en', 'UTC'));
    }
    public function testGroupSettingsRegistersOnlyFreshlyAuthorizedGroupsAndDoesNotReactivate(): void
    {
        [$app] = $this->app();
        $info = static fn(): array => ['type' => 'chat', 'Chat' => ['title' => 'A basic group']];
        self::assertFalse(GroupRegistration::refresh($app, '7', '-1', $info));
        self::assertNull($app->settings->getGroup('-1'));
        self::assertTrue(GroupRegistration::refresh($app, '42', '-1', $info));
        self::assertSame('A basic group', $app->settings->getGroup('-1')['title']);
        $app->settings->deactivateGroup('-1', '42');
        self::assertTrue(GroupRegistration::refresh($app, '42', '-1', $info));
        self::assertSame(0, $app->settings->getGroup('-1')['active']);
        self::assertFalse(GroupRegistration::refresh($app, '42', '-2', static fn(): array => ['type' => 'channel', 'Chat' => []]));
        self::assertNull($app->settings->getGroup('-2'));
    }
    public function testNativeNoChangeResponseIsAllowedButOtherRpcErrorsPropagate(): void
    {
        $transport = new GatewayFixtureTransport();
        $gateway = new MadelineGateway($transport);
        $transport->error = $this->rpc('CHAT_NOT_MODIFIED');
        $gateway->setPermissions('-123', ['_' => 'chatBannedRights', 'until_date' => 0]);
        self::assertCount(1, $transport->calls);
        $transport->error = $this->rpc('CHAT_ADMIN_REQUIRED');
        $this->expectException(\danog\MadelineProto\RPCErrorException::class);
        $gateway->setPermissions('-123', ['_' => 'chatBannedRights', 'until_date' => 0]);
    }
    public function testUnsupportedGlobalViewRestrictionNeverReachesTelegram(): void
    {
        $transport = new GatewayFixtureTransport();
        try {
            (new MadelineGateway($transport))->setPermissions('-123', ['_' => 'chatBannedRights', 'view_messages' => true]);
            self::fail('Forbidden global flag was accepted.');
        } catch (\InvalidArgumentException) {
            self::assertSame([], $transport->calls);
        }
        $this->expectException(\InvalidArgumentException::class);
        Permissions::validateBlockFlags(['view_messages']);
    }
    public function testOneSharedIpcInitializationUnderConcurrentWorkAndRetryAfterFailure(): void
    {
        $owner = new \stdClass();
        $calls = 0;
        $factory = static function () use (&$calls): object {
            $calls++;
            \Amp\delay(0.001);
            return new \stdClass();
        };
        $futures = [];
        for ($i = 0; $i < 100; $i++) {
            $futures[] = \Amp\async(static fn(): object => RuntimeClientPool::get($owner, $factory));
        }
        $results = \Amp\Future\await($futures);
        self::assertSame(1, $calls);
        foreach ($results as $result) {
            self::assertSame($results[0], $result);
        }
        RuntimeClientPool::release($owner);
        self::assertNotSame($results[0], RuntimeClientPool::get($owner, $factory));
        self::assertSame(2, $calls);
        RuntimeClientPool::release($owner);
        try {
            RuntimeClientPool::get($owner, static fn() => throw new \RuntimeException('fixture'));
        } catch (\RuntimeException) {
        }
        self::assertInstanceOf(\stdClass::class, RuntimeClientPool::get($owner, $factory));
        self::assertSame(3, $calls);
        RuntimeClientPool::release($owner);
    }
    public function testInvalidClientFactoryReturnIsRejectedAndDoesNotPoisonSharedCache(): void
    {
        $owner = new \stdClass();
        try {
            RuntimeClientPool::get($owner, static fn(): int => 42);
            self::fail('Invalid runtime client factory result was accepted.');
        } catch (\UnexpectedValueException $error) {
            self::assertSame('Runtime client factory must return an object.', $error->getMessage());
        }
        $client = new \stdClass();
        self::assertSame($client, RuntimeClientPool::get($owner, static fn(): object => $client));
        RuntimeClientPool::release($owner);
    }
    public function testRestartQuiescesBeforeSdkExitAndRepeatedRequestDoesNothing(): void
    {
        $events = [];
        (new \ReflectionProperty(Runner::class, 'quiesce'))->setValue(null, static function () use (&$events): void {
            self::assertTrue(Runner::isStopping());
            $events[] = 'quiesce';
        });
        $ack = static function () use (&$events): void {
            self::assertFalse(Runner::isStopping());
            $events[] = 'ack';
        };
        $restart = static function () use (&$events): void {
            self::assertTrue(Runner::isStopping());
            $events[] = 'sdk-restart';
        };
        self::assertTrue(Runner::restart($ack, $restart));
        self::assertFalse(Runner::restart($ack, $restart));
        self::assertSame(['ack','quiesce','sdk-restart'], $events);
    }
    public function testConcurrentRestartCannotIssueSecondSdkRestartWhileAcknowledgementIsPending(): void
    {
        $gate = new \Amp\DeferredFuture();
        $entered = new \Amp\DeferredFuture();
        $restarts = 0;
        $first = \Amp\async(static function () use ($gate, $entered, &$restarts): bool {
            return Runner::restart(
                static function () use ($gate, $entered): void {
                    $entered->complete();
                    $gate->getFuture()->await();
                },
                static function () use (&$restarts): void {
                    $restarts++;
                }
            );
        });
        $entered->getFuture()->await();
        self::assertFalse(Runner::restart(static fn() => null, static fn() => throw new \LogicException('duplicate')));
        $gate->complete();
        self::assertTrue($first->await());
        self::assertSame(1, $restarts);
    }
    public function testRestartAcknowledgementTimeoutDoesNotBlockButIntentionalCancellationPropagates(): void
    {
        self::assertTrue(Runner::restart(
            static fn() => throw new \Amp\CancelledException(new \Amp\TimeoutException()),
            static fn() => null
        ));
        self::assertTrue(Runner::isStopping());
        $this->setUp();
        try {
            Runner::restart(static fn() => throw new \Amp\CancelledException(), static fn() => self::fail('Restarted after intentional cancellation'));
            self::fail('Cancellation was swallowed.');
        } catch (\Amp\CancelledException) {
            self::assertFalse(Runner::isStopping());
        }
    }
    public function testPinnedSdkRestartCompletesItsMainLoopWithoutReenteringOnStart(): void
    {
        $logger = new class {
            public array $lines = [];
            public function logger(string $line, int $level = 0): void
            {
                $this->lines[] = $line;
            }
        };
        $sdk = new class ($logger) {
            use \danog\MadelineProto\Wrappers\Loop;

            public function __construct(public object $logger)
            {
            }
            public function getAuthorization(): bool
            {
                return true;
            }
            public function hasEventHandler(): bool
            {
                return true;
            }
            public function initSelfRestart(): void
            {
            }
        };
        $loop = \Amp\async($sdk->loop(...));
        \Amp\delay(0.001);
        self::assertFalse($loop->isComplete());
        self::assertTrue(Runner::restart(static fn() => null, $sdk->restart(...)));
        $loop->await();
        self::assertContains('Exiting update loop!', $logger->lines);
        self::assertSame(1, count(array_filter($logger->lines, static fn(string $line): bool => $line === 'Started update loop')));
        self::assertTrue(Runner::isStopping());
    }
    public function testScheduledManualQuotaRetryCanCloseWithoutCalendarAndExpiryDoesNotGuess(): void
    {
        [$app, $gateway, $http, $clock] = $this->app();
        $app->settings->ensureGroup('-1');
        $gateway->beforeSetPermissions = static function (): void {
            throw new \Shabbat\Telegram\RetryableTelegramException('fixture quota', 10);
        };
        $result = $app->manualGroups->apply(true, '42', 60);
        self::assertSame('retry_wait', $result['groups'][0]['status']);
        self::assertSame(0, $gateway->mutations);
        $snapshot = $app->locks->state('-1')['snapshot_json'];
        $gateway->beforeSetPermissions = null;
        $heartbeat = tempnam(sys_get_temp_dir(), 'shabbat-retry-heartbeat-');
        $previous = getenv('HEARTBEAT_PATH');
        putenv('HEARTBEAT_PATH=' . $heartbeat);
        try {
            $clock->set($clock->now()->modify('+11 seconds'));
            $app->tick();
            self::assertSame('locked', $app->locks->state('-1')['status']);
            self::assertSame(1, $gateway->mutations);
            self::assertSame($snapshot, $app->locks->state('-1')['snapshot_json']);
            self::assertSame(0, $http->calls);
            self::assertSame([], $gateway->sent);
            $clock->set($clock->now()->modify('+61 seconds'));
            $app->tick();
            self::assertGreaterThan(0, $http->calls);
            self::assertSame(1, $gateway->mutations);
            self::assertSame('locked', $app->locks->state('-1')['status']);
            self::assertSame($snapshot, $app->locks->state('-1')['snapshot_json']);
            self::assertSame([], $gateway->sent);
        } finally {
            unlink($heartbeat);
            putenv($previous === false ? 'HEARTBEAT_PATH' : 'HEARTBEAT_PATH=' . $previous);
        }
    }
    public function testShutdownAtCooperativeYieldStopsBeforeNextGroupWrite(): void
    {
        [$app, $gateway] = $this->app();
        for ($i = 1; $i <= 40; $i++) {
            $app->settings->ensureGroup('-' . $i);
        }
        \Revolt\EventLoop::defer(Runner::requestShutdown(...));
        try {
            $app->manualGroups->apply(true, '42');
            self::fail('Queued shutdown was ignored after the yield.');
        } catch (\Amp\CancelledException) {
            self::assertSame(20, $gateway->mutations);
            self::assertSame(20, (int)$app->db->pdo()->query("SELECT COUNT(*) FROM group_settings WHERE manual_override_mode='lock'")->fetchColumn());
        }
    }
    public function testFinalizationKeepsWriterLockUntilSuccessAndReleasesItAfterFailure(): void
    {
        foreach ([false,true] as $fail) {
            $path = tempnam(sys_get_temp_dir(), 'shabbat-finalize-');
            $lock = \Shabbat\Runtime\SingleInstance::acquire($path);
            $flushed = false;
            try {
                try {
                    Runner::finalizeSession($lock, static function () use ($path, $fail, &$flushed): void {
                        try {
                            \Shabbat\Runtime\SingleInstance::acquire($path);
                            self::fail('Writer lock released before final session flush.');
                        } catch (\RuntimeException) {
                            self::assertTrue(true);
                        }
                        if ($fail) {
                            throw new \RuntimeException('fixture finalization failure');
                        }
                        $flushed = true;
                    });
                    self::assertFalse($fail);
                } catch (\RuntimeException $error) {
                    self::assertTrue($fail);
                    self::assertSame('fixture finalization failure', $error->getMessage());
                }
                self::assertSame(!$fail, $flushed);
                $next = \Shabbat\Runtime\SingleInstance::acquire($path);
                $next->release();
            } finally {
                $lock->release();
                unlink($path);
            }
        }
    }
    public function testNativeSdkFinalizerCanBeCalledAgainAfterPendingDestructorCompletes(): void
    {
        $property = new \ReflectionProperty(\danog\MadelineProto\API::class, 'destructors');
        $previous = $property->getValue();
        $calls = 0;
        try {
            $property->setValue(null, [314159 => \Amp\async(static function () use ($property, &$calls): void {
                $calls++;
                $property->setValue(null, []);
            })]);
            \danog\MadelineProto\API::finalize();
            \danog\MadelineProto\API::finalize();
            self::assertSame(1, $calls);
            self::assertSame([], $property->getValue());
        } finally {
            $property->setValue(null, $previous);
        }
    }
    public function testStartupNoticeHasOneSharedDeadlineAndIntentionalCancellationIsPreserved(): void
    {
        self::assertFalse(Runner::notifyStartup(static function (\Amp\Cancellation $cancellation): void {
            \Amp\delay(1, cancellation: $cancellation);
        }, 0.001));
        self::assertFalse(Runner::isStopping());
        self::assertTrue(Runner::notifyStartup(static function (\Amp\Cancellation $cancellation): void {
            $cancellation->throwIfRequested();
        }));
        $this->expectException(\Amp\CancelledException::class);
        Runner::notifyStartup(static fn() => throw new \Amp\CancelledException());
    }
    public function testCleanupFailureStillCompletesSdkRestartAndPropagatesFailure(): void
    {
        $stopped = false;
        (new \ReflectionProperty(Runner::class, 'quiesce'))->setValue(null, static fn() => throw new \RuntimeException('fixture cleanup failure'));
        try {
            Runner::restart(static fn() => null, static function () use (&$stopped): void {
                $stopped = true;
            });
            self::fail('Cleanup failure was reported as a clean restart.');
        } catch (\RuntimeException $error) {
            self::assertSame('fixture cleanup failure', $error->getMessage());
            self::assertTrue($stopped);
            self::assertTrue(Runner::isStopping());
        }
    }
    public function testAllNativeRestrictionBitsRoundTripThroughLockedSerializerIncludingReactionAndRank(): void
    {
        $previousLogger = \danog\MadelineProto\Logger::$default;
        $adapter = (new \ReflectionClass(\danog\MadelineProto\MTProto::class))->newInstanceWithoutConstructor();
        $adapter->settings = new \danog\MadelineProto\Settings();
        $adapter->logger = new \danog\MadelineProto\Logger((new \danog\MadelineProto\Settings\Logger())->setType(\danog\MadelineProto\Logger::CALLABLE_LOGGER)->setExtra(static function (): void {
        }));
        try {
            $tl = new \danog\MadelineProto\TL\TL($adapter);
            $tl->init(new \danog\MadelineProto\Settings\TLSchema());
            $rights = Permissions::locked(['_' => 'chatBannedRights', 'until_date' => 0]);
            $wire = $tl->serializeObject(['type' => 'ChatBannedRights'], $rights, 'rights-test');
            $stream = fopen('php://memory', 'w+b');
            fwrite($stream, $wire);
            rewind($stream);
            $actual = $tl->deserialize($stream, ['type' => 'ChatBannedRights', 'connection' => null, 'encrypted' => false]);
            fclose($stream);
            self::assertTrue(Permissions::equal($rights, $actual));
            self::assertTrue($actual['edit_rank']);
            self::assertTrue($actual['send_reactions']);
            self::assertFalse($actual['view_messages']);
        } finally {
            $tl = null;
            $adapter = null;
            \danog\MadelineProto\Logger::$default = $previousLogger;
        }
    }
}
