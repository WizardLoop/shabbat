<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace Shabbat\Tests;

use PHPUnit\Framework\TestCase;
use Shabbat\Runtime\BroadcastJobs;
use Shabbat\Storage\{AdminAccessRepository, Database};
use Shabbat\Time\FrozenClock;

final class BroadcastDuePreflightTest extends TestCase
{
    private const ID = 'schedule_0123456789abcdef';
    private const DELETE_ID = 'selfdestruct_0123456789abcdef';
    private BroadcastJobs $jobs;
    private FrozenClock $clock;
    private AdminAccessRepository $acl;
    protected function setUp(): void
    {
        $db = Database::open(':memory:');
        $db->migrate();
        $this->clock = new FrozenClock(new \DateTimeImmutable('2026-10-16T12:00:00Z'));
        $this->acl = new AdminAccessRepository($db, $this->clock, static fn(): array => ['1']);
        $this->jobs = new BroadcastJobs($db, $this->clock, $this->acl);
    }
    private function runJobs(array $schedules, array $deletions, array &$events): array
    {
        return $this->jobs->runDue(
            static fn(): array => $schedules,
            static fn(): bool => false,
            static function () use (&$events): void {
                $events[] = 'send';
            },
            static function () use (&$events): void {
                $events[] = 'delete';
            },
            static function () use (&$events): bool {
                $events[] = 'priority';
                return true;
            },
            static fn(): bool => false,
            static fn(): array => $deletions,
        );
    }
    public function testThousandIdleAndFutureTicksPerformZeroPermissionScansOrNativeRuns(): void
    {
        $this->jobs->recordScheduled('1', self::ID);
        $events = [];
        $future = $this->clock->now()->getTimestamp() + 3600;
        for ($i = 0; $i < 1000; $i++) {
            $scheduled = $i % 2 ? [self::ID => ['id' => self::ID, 'status' => 'scheduled', 'scheduledAt' => $future]] : [];
            $destruction = $i % 2 ? [self::DELETE_ID => ['id' => self::DELETE_ID, 'status' => 'scheduled', 'deleteAt' => $future]] : [];
            self::assertSame('idle', $this->runJobs($scheduled, $destruction, $events)['reason']);
        }
        self::assertSame([], $events);
    }
    public function testDueSendHasOnePriorityPassAndNoEmptyDeletionRunner(): void
    {
        $this->jobs->recordScheduled('1', self::ID);
        $events = [];
        $result = $this->runJobs([self::ID => ['id' => self::ID, 'status' => 'scheduled', 'scheduledAt' => $this->clock->now()->getTimestamp()]], [], $events);
        self::assertTrue($result['ran']);
        self::assertSame(['priority', 'send'], $events);
    }
    public function testDueDeletionHasOnePriorityPassAndNoEmptySendRunner(): void
    {
        $events = [];
        $result = $this->runJobs([], [self::DELETE_ID => ['id' => self::DELETE_ID, 'status' => 'scheduled', 'deleteAt' => $this->clock->now()->getTimestamp()]], $events);
        self::assertTrue($result['ran']);
        self::assertSame(['priority', 'delete'], $events);
    }
    public function testShutdownTokenAndGuardArePermanentAndStopAllNewWork(): void
    {
        $token = $this->jobs->cancellation();
        self::assertFalse($token->isRequested());
        self::assertTrue($this->jobs->canContinue());
        $this->jobs->stop();
        self::assertTrue($token->isRequested());
        self::assertFalse($this->jobs->canContinue());
        self::assertTrue($this->jobs->cancellation()->isRequested());
        $events = [];
        self::assertSame('shutdown', $this->runJobs([], [], $events)['reason']);
        self::assertSame([], $events);
    }
    public function testSharedAdmissionIsFifoAndNeverMultipliesNativeOperations(): void
    {
        $active = 0;
        $maximum = 0;
        $order = [];
        $work = [];
        for ($i = 0; $i < 40; $i++) {
            $work[] = \Amp\async(function () use ($i, &$active, &$maximum, &$order): int {
                return $this->jobs->runExclusive(static function () use ($i, &$active, &$maximum, &$order): int {
                    $maximum = max($maximum, ++$active);
                    $order[] = $i;
                    \Amp\delay(0.001);
                    $active--;
                    return $i;
                });
            });
        }
        self::assertSame(range(0, 39), \Amp\Future\await($work));
        self::assertSame(range(0, 39), $order);
        self::assertSame(1, $maximum);
        self::assertSame(0, $active);
        self::assertSame([], (new \ReflectionProperty($this->jobs, 'admissionQueue'))->getValue($this->jobs));
    }
    public function testShutdownReleasesWaitingAdmissionWithoutStartingQueuedOperations(): void
    {
        $entered = new \Amp\DeferredFuture();
        $started = 0;
        $futures = [\Amp\async(function () use ($entered, &$started): void {
            $this->jobs->runExclusive(function () use ($entered, &$started): void {
                $started++;
                $entered->complete();
                \Amp\delay(60, cancellation: $this->jobs->cancellation());
            });
        })];
        $entered->getFuture()->await();
        for ($i = 0; $i < 40; $i++) {
            $futures[] = \Amp\async(function () use (&$started): void {
                $this->jobs->runExclusive(static function () use (&$started): void {
                    $started++;
                });
            });
        }
        \Amp\delay(0.001);
        $this->jobs->stop();
        $cancelled = 0;
        foreach ($futures as $future) {
            try {
                $future->await();
            } catch (\Amp\CancelledException) {
                $cancelled++;
            }
        }
        self::assertSame(41, $cancelled);
        self::assertSame(1, $started);
        self::assertSame([], (new \ReflectionProperty($this->jobs, 'admissionQueue'))->getValue($this->jobs));
        self::assertNull((new \ReflectionProperty($this->jobs, 'admitted'))->getValue($this->jobs));
    }
    public function testCreatorRevocationDuringNativeWorkIsVisibleToEveryWorkerGuard(): void
    {
        $this->acl->add('1', '2', 'full', false, []);
        $this->jobs->recordScheduled('2', self::ID);
        $list = fn(): array => [self::ID => ['id' => self::ID, 'status' => 'scheduled', 'scheduledAt' => $this->clock->now()->getTimestamp()]];
        $this->jobs->runDue($list, static fn(): bool => false, function (): void {
            self::assertTrue($this->jobs->canContinue());
            self::assertTrue($this->jobs->isRunning());
            $this->acl->remove('1', '2', 1);
            self::assertFalse($this->jobs->canContinue());
        }, static function (): void {
            self::fail('No deletion due');
        }, static fn(): bool => true, static fn(): bool => false, static fn(): array => []);
        self::assertFalse($this->jobs->isRunning());
    }
}
