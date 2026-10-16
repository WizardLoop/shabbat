<?php

// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);

namespace Shabbat\Tests;

use Amp\CancelledException;
use PHPUnit\Framework\TestCase;
use Shabbat\Runtime\BroadcastJobs;
use Shabbat\Storage\{AdminAccessRepository, Database};
use Shabbat\Time\FrozenClock;

final class BroadcastJobsTest extends TestCase
{
    private Database $db;
    private AdminAccessRepository $acl;
    private BroadcastJobs $jobs;
    private FrozenClock $clock;
    private const ID = 'schedule_0123456789abcdef';
    private const OTHER = 'schedule_fedcba9876543210';
    protected function setUp(): void
    {
        $this->db = Database::open(':memory:');
        $this->db->migrate();
        $this->clock = new FrozenClock(new \DateTimeImmutable('2026-10-15T12:00:00Z'));
        $this->acl = new AdminAccessRepository($this->db, $this->clock, static fn(): array => ['1']);
        $this->jobs = new BroadcastJobs($this->db, $this->clock, $this->acl);
    }
    private function scheduled(string $id = self::ID, int $offset = -1): array
    {
        return ['id' => $id, 'status' => 'scheduled', 'scheduledAt' => $this->clock->now()->getTimestamp() + $offset];
    }
    public function testCreatorIsPersistentIdempotentAndCannotBeReassigned(): void
    {
        $this->jobs->recordScheduled('1', self::ID);
        $this->jobs->recordScheduled('1', self::ID);
        self::assertSame('1', $this->jobs->actorForSchedule(self::ID));
        self::assertSame(1, (int) $this->db->pdo()->query("SELECT COUNT(*) FROM audit_log WHERE action='operator.broadcast.scheduled'")->fetchColumn());
        $this->acl->add('1', '2', 'full', false, []);
        $this->expectException(\RuntimeException::class);
        $this->jobs->recordScheduled('2', self::ID);
    }
    public function testRecordingRequiresCurrentMutationCapability(): void
    {
        $this->acl->add('1', '2', 'limited', true, ['broadcast' => true]);
        $this->expectException(\RuntimeException::class);
        $this->jobs->recordScheduled('2', self::ID);
    }
    public function testAuthorizedSchedulesAndDestructionRunAfterPriority(): void
    {
        $this->jobs->recordScheduled('1', self::ID);
        $events = [];
        $result = $this->jobs->runDue(
            fn(): array => [self::ID => $this->scheduled()],
            static fn(): bool => false,
            function () use (&$events): void {
                $events[] = 'send';
            },
            function () use (&$events): void {
                $events[] = 'destroy';
            },
            function () use (&$events): bool {
                $events[] = 'priority';
                return true;
            },
            static fn(): bool => false,
        );
        self::assertSame(['priority', 'send', 'priority', 'destroy'], $events);
        self::assertSame(['ran' => true, 'cancelled' => [], 'reason' => 'ok'], $result);
    }
    public function testUnattributedDueJobsAreCancelledBeforeAnyRunner(): void
    {
        $native = [self::ID => $this->scheduled()];
        $ran = 0;
        $result = $this->jobs->runDue(
            static function () use (&$native): array {
                return $native;
            },
            static function (string $id) use (&$native): bool {
                $native[$id]['status'] = 'cancelled';
                return true;
            },
            static function () use (&$ran): void {
                $ran++;
            },
            static function () use (&$ran): void {
                $ran++;
            },
            static fn(): bool => true,
            static fn(): bool => false,
        );
        self::assertSame([self::ID], $result['cancelled']);
        self::assertSame(1, $ran);
    }
    public function testRevokedAndViewOnlyCreatorsAreCancelled(): void
    {
        foreach ([false, true] as $viewOnly) {
            $this->acl->add('1', '2', 'limited', false, ['broadcast' => true]);
            $id = $viewOnly ? self::OTHER : self::ID;
            $this->jobs->recordScheduled('2', $id);
            if ($viewOnly) {
                $this->acl->update('1', '2', 'limited', true, ['broadcast' => true], 1);
            } else {
                $this->acl->remove('1', '2', 1);
            }
            $native = [$id => $this->scheduled($id)];
            $result = $this->jobs->runDue(
                static function () use (&$native): array {
                    return $native;
                },
                static function (string $key) use (&$native): bool {
                    $native[$key]['status'] = 'cancelled';
                    return true;
                },
                static function (): void {
                },
                static function (): void {
                },
                static fn(): bool => true,
                static fn(): bool => false,
            );
            self::assertSame([$id], $result['cancelled']);
            if ($viewOnly) {
                $this->acl->remove('1', '2', 2);
            }
        }
    }
    public function testUnconfirmedCancellationBlocksBothNativeRunners(): void
    {
        $ran = 0;
        $r = $this->jobs->runDue(fn(): array => [self::ID => $this->scheduled()], static fn(): bool => false, static function () use (&$ran): void {
            $ran++;
        }, static function () use (&$ran): void {
            $ran++;
        }, static fn(): bool => true, static fn(): bool => false);
        self::assertSame('cancellation_unconfirmed', $r['reason']);
        self::assertSame(0, $ran);
    }
    public function testCancellationAckMustMatchFreshNativeState(): void
    {
        $ran = 0;
        $r = $this->jobs->runDue(fn(): array => [self::ID => $this->scheduled()], static fn(): bool => true, static function () use (&$ran): void {
            $ran++;
        }, static function () use (&$ran): void {
            $ran++;
        }, static fn(): bool => true, static fn(): bool => false);
        self::assertSame('authorization_changed', $r['reason']);
        self::assertSame(0, $ran);
    }
    public function testUnknownFutureScheduleCannotCrossNativeBulkDeadline(): void
    {
        $ran = 0;
        $r = $this->jobs->runDue(fn(): array => [self::ID => $this->scheduled(self::ID, 1)], static fn(): bool => false, static function () use (&$ran): void {
            $ran++;
        }, static function () use (&$ran): void {
            $ran++;
        }, static fn(): bool => true, static fn(): bool => false);
        self::assertSame('unattributed_future', $r['reason']);
        self::assertSame(0, $ran);
    }
    public function testPriorityAndShutdownSkipAllNativeSideEffects(): void
    {
        foreach ([true, false] as $shutdown) {
            $r = $this->jobs->runDue(static function (): array {
                return [];
            }, static fn(): bool => false, static function (): void {
                self::fail('Sent during stop');
            }, static function (): void {
                self::fail('Destroyed during stop');
            }, static fn(): bool => $shutdown, static fn(): bool => $shutdown);
            self::assertFalse($r['ran']);
            self::assertSame($shutdown ? 'shutdown' : 'permission_priority', $r['reason']);
        }
    }
    public function testRoleRevokedWhilePriorityRunsIsRecheckedBeforeSending(): void
    {
        $this->acl->add('1', '2', 'full', false, []);
        $this->jobs->recordScheduled('2', self::ID);
        $priorities = 0;
        $r = $this->jobs->runDue(
            fn(): array => [self::ID => $this->scheduled()],
            static fn(): bool => false,
            static function (): void {
                self::fail('Sent after revocation');
            },
            static function (): void {
                self::fail('Destroyed after revocation');
            },
            function () use (&$priorities): bool {
                if (++$priorities === 1) {
                    $this->acl->remove('1', '2', 1);
                } return true;
            },
            static fn(): bool => false,
        );
        self::assertSame('authorization_changed', $r['reason']);
    }
    public function testReentrantTickCannotDuplicateNativeRuns(): void
    {
        $this->jobs->recordScheduled('1', self::ID);
        $nested = null;
        $r = $this->jobs->runDue(
            fn(): array => [self::ID => $this->scheduled()],
            static fn(): bool => false,
            function () use (&$nested): void {
                $nested = $this->jobs->runDue(static fn(): array => [], static fn(): bool => false, static function (): void {
                    self::fail('Nested run');
                }, static function (): void {
                }, static fn(): bool => true, static fn(): bool => false);
            },
            static function (): void {
            },
            static fn(): bool => true,
            static fn(): bool => false,
        );
        self::assertTrue($r['ran']);
        self::assertSame('running', $nested['reason']);
    }
    public function testIntentionalCancellationPropagatesAndReleasesReentrancyGuard(): void
    {
        $error = new CancelledException(new \RuntimeException('fixture'));
        try {
            $this->jobs->runDue(static function () use ($error): array {
                throw $error;
            }, static fn(): bool => false, static function (): void {
            }, static function (): void {
            }, static fn(): bool => true, static fn(): bool => false);
            self::fail('Cancellation swallowed');
        } catch (CancelledException $caught) {
            self::assertSame($error, $caught);
        }
        self::assertTrue($this->jobs->runDue(static fn(): array => [], static fn(): bool => false, static function (): void {
        }, static function (): void {
        }, static fn(): bool => true, static fn(): bool => false)['ran']);
    }
    public function testMalformedMetadataAndIdentityFailClosed(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->jobs->runDue(fn(): array => [self::OTHER => $this->scheduled()], static fn(): bool => false, static function (): void {
            self::fail('Malformed schedule ran');
        }, static function (): void {
        }, static fn(): bool => true, static fn(): bool => false);
    }
}
