<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace Shabbat\Tests;

use BroadcastTool\BroadcastManager;
use PHPUnit\Framework\TestCase;
use Shabbat\Scheduling\LockCoordinator;
use Shabbat\Storage\{Database, SettingsRepository};
use Shabbat\Time\FrozenClock;

final class BroadcastLifecycleTest extends TestCase
{
    private function manager(): BroadcastManager
    {
        $manager = (new \ReflectionClass(BroadcastManager::class))->newInstanceWithoutConstructor();
        $manager->setRuntimeGuard(static fn(): bool => true);
        return $manager;
    }
    private function state(BroadcastManager $manager, int $count): array
    {
        $state = (new \ReflectionMethod($manager, 'createState'))->invoke($manager, 'test', 'send', $count);
        for ($i = 0; $i < $count; $i++) {
            $state['queue']->enqueue(['peer' => (string)$i, 'attempts' => 0, 'availableAt' => 0]);
        }
        return $state;
    }
    private function start(BroadcastManager $manager, array &$state, callable $handler, int $concurrency = 4): void
    {
        (new \ReflectionMethod($manager, 'startQueueWorkers'))->invokeArgs($manager, [&$state, $concurrency, $handler]);
    }
    private function wait(BroadcastManager $manager, array &$state): void
    {
        (new \ReflectionMethod($manager, 'waitForCompletion'))->invokeArgs($manager, [&$state]);
    }
    public function testSuspendedNativeBroadcastDoesNotBlockIndependentPermissionClose(): void
    {
        $manager = $this->manager();
        $state = $this->state($manager, 1);
        $entered = new \Amp\DeferredFuture();
        $release = new \Amp\DeferredFuture();
        $this->start($manager, $state, static function () use ($entered, $release): void {
            $entered->complete();
            $release->getFuture()->await();
        }, 1);
        $broadcast = \Amp\async(function () use ($manager, &$state): void {
            $this->wait($manager, $state);
        });
        $entered->getFuture()->await();
        $db = Database::open(':memory:');
        $db->migrate();
        $clock = new FrozenClock(new \DateTimeImmutable('2026-10-16T14:20:00Z'));
        (new SettingsRepository($db, $clock))->ensureGroup('-1');
        $gateway = new PermissionRecoveryGateway();
        $locks = new LockCoordinator($db, $clock, $gateway);
        try {
            self::assertFalse($broadcast->isComplete());
            self::assertSame('locked', $locks->reconcile('-1', 'shabbat-boundary', true)['status']);
            self::assertFalse($broadcast->isComplete());
        } finally {
            $release->complete();
            $broadcast->await();
        }
        self::assertTrue($state['done']);
        self::assertArrayNotHasKey('runtimeWorkers', $state);
    }
    public function testShutdownCancelsThousandQueuedTargetsAndDrainsEveryWorker(): void
    {
        $manager = $this->manager();
        $shutdown = new \Amp\DeferredCancellation();
        $manager->setRuntimeGuard(static fn(): bool => true, $shutdown->getCancellation());
        $state = $this->state($manager, 1000);
        $sent = 0;
        $reason = new \RuntimeException('test shutdown');
        $this->start($manager, $state, static function () use (&$sent, $shutdown, $reason): void {
            if (++$sent === 3) {
                $shutdown->cancel($reason);
            }
        });
        try {
            $this->wait($manager, $state);
            self::fail('Shutdown cancellation was swallowed.');
        } catch (\Amp\CancelledException $error) {
            self::assertSame($reason, $error->getPrevious());
        }
        self::assertSame(3, $sent);
        self::assertTrue($state['done']);
        self::assertTrue($state['cancel']);
        self::assertTrue($state['queue']->isEmpty());
        self::assertSame([], $state['inFlight']);
        self::assertArrayNotHasKey('runtimeWorkers', $state);
    }
    public function testIntentionalCancellationFromWorkerPropagatesAfterAllChildrenJoin(): void
    {
        $manager = $this->manager();
        $state = $this->state($manager, 40);
        $error = new \Amp\CancelledException(new \RuntimeException('intentional'));
        $this->start($manager, $state, static fn() => throw $error);
        try {
            $this->wait($manager, $state);
            self::fail('Intentional cancellation swallowed.');
        } catch (\Amp\CancelledException $caught) {
            self::assertSame($error, $caught);
        }
        self::assertTrue($state['cancel']);
        self::assertSame([], $state['inFlight']);
        self::assertArrayNotHasKey('runtimeWorkers', $state);
    }
    public function testRevokedAuthorizationStopsRemainingTargets(): void
    {
        $manager = $this->manager();
        $allowed = true;
        $manager->setRuntimeGuard(static function () use (&$allowed): bool {
            return $allowed;
        });
        $state = $this->state($manager, 100);
        $calls = 0;
        $this->start($manager, $state, static function () use (&$allowed, &$calls): void {
            $calls++;
            $allowed = false;
        }, 1);
        $this->wait($manager, $state);
        self::assertSame(1, $calls);
        self::assertTrue($state['cancel']);
        self::assertTrue($state['queue']->isEmpty());
    }
    public function testLongProgressSleepIsWokenAndJoinedOnCompletion(): void
    {
        $manager = $this->manager();
        $state = $this->state($manager, 0);
        $edits = 0;
        $manager->setMessageTransport(static function () use (&$edits): array {
            $edits++;
            return [];
        });
        (new \ReflectionMethod($manager, 'startProgressLoop'))->invokeArgs($manager, [123, 10, &$state, 'Test']);
        \Amp\delay(0.001);
        self::assertSame(1, $edits);
        $started = hrtime(true);
        $this->wait($manager, $state);
        self::assertLessThan(1.0, (hrtime(true) - $started) / 1_000_000_000);
        self::assertSame(1, $edits);
        self::assertArrayNotHasKey('runtimeWorkers', $state);
    }
    public function testConcurrentAuthorityLossCannotMaskAnIndependentWorkerCancellation(): void
    {
        $manager = $this->manager();
        $allowed = true;
        $manager->setRuntimeGuard(static function () use (&$allowed): bool {
            return $allowed;
        });
        $state = $this->state($manager, 2);
        $error = new \Amp\CancelledException(new \RuntimeException('independent cancellation'));
        $this->start($manager, $state, static function () use (&$allowed, $error): void {
            $allowed = false;
            throw $error;
        }, 1);
        try {
            $this->wait($manager, $state);
            self::fail('Independent cancellation hidden by the authority guard.');
        } catch (\Amp\CancelledException $caught) {
            self::assertSame($error, $caught);
        }
        self::assertArrayNotHasKey('runtimeWorkers', $state);
    }
    public function testEveryActualNativeOperationUsesOptionalAdmissionAndNullRestoresDefault(): void
    {
        $manager = $this->manager();
        $count = 0;
        $result = 'admitted';
        $manager->setRuntimeAdmission(static function (callable $work) use (&$count, &$result): mixed {
            $count++;
            return $result;
        });
        self::assertSame('admitted', $manager->broadcastWithProgress([], []));
        self::assertSame('admitted', $manager->editLastBroadcastForAll([], 'test'));
        self::assertSame('admitted', $manager->deleteLastBroadcastForAll([]));
        self::assertSame('admitted', $manager->deleteAllBroadcastsForAll([]));
        self::assertSame('admitted', $manager->unpinAllMessagesForAll([]));
        $result = [];
        self::assertSame([], (new \ReflectionMethod($manager, 'runSelfDestructJob'))->invoke($manager, 'selfdestruct_0123456789abcdef'));
        self::assertSame(6, $count);
        $manager->setRuntimeAdmission(null);
        self::assertSame('plain', (new \ReflectionMethod($manager, 'withAdmission'))->invoke($manager, static fn(): string => 'plain'));
    }
    public function testOnlyFullyDrainedCompletedProgressIsPrunedBeyondLatest128(): void
    {
        $manager = $this->manager();
        $shared = new \ReflectionProperty(BroadcastManager::class, 'sharedBroadcastState');
        $old = $shared->getValue();
        $states = [];
        for ($i = 0; $i < 250; $i++) {
            $states['done-' . $i] = ['done' => true, 'inFlight' => []];
        }
        $states['active'] = ['done' => false, 'inFlight' => []];
        $states['in-flight'] = ['done' => true, 'inFlight' => ['peer']];
        $states['joining'] = ['done' => true, 'inFlight' => [], 'runtimeWorkers' => []];
        try {
            $shared->setValue(null, $states);
            (new \ReflectionProperty($manager, 'currentBroadcastState'))->setValue($manager, $states);
            (new \ReflectionMethod($manager, 'pruneCompletedRuntimeStates'))->invoke($manager);
            $kept = $shared->getValue();
            self::assertCount(131, $kept);
            foreach (['active', 'in-flight', 'joining', 'done-249', 'done-122'] as $key) {
                self::assertArrayHasKey($key, $kept);
            }
            self::assertArrayNotHasKey('done-121', $kept);
            self::assertCount(131, (new \ReflectionProperty($manager, 'currentBroadcastState'))->getValue($manager));
        } finally {
            $shared->setValue(null, $old);
        }
    }
    public function testCronIntegrationSeparatesPermissionNotificationAndBroadcastWork(): void
    {
        $source = file_get_contents(dirname(__DIR__) . '/bot.php');
        self::assertMatchesRegularExpression('/#\[Cron\(period: 15\.0\)\]\s+public function shabatCronClose\(\): void \{\s*\$this->v3App\(\)->permissionTick\(\);\s*return;/', $source);
        self::assertMatchesRegularExpression('/#\[Cron\(period: 1\.0\)\]\s+public function shabatNotifications\(\): void \{\s*\$this->v3App\(\)->notificationTick\(\);/', $source);
        self::assertMatchesRegularExpression('/#\[Cron\(period: 15\.0\)\]\s+public function shabbatBroadcastCron\(\): void \{\s*\$this->v4RunBroadcastJobs\(\);\s*\}/', $source);
    }
}
