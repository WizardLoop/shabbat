<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-only
namespace Shabbat\Tests;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shabbat\Runtime\MadelineGateway;
use Shabbat\Scheduling\DeliveryDeferredException;
use Shabbat\Scheduling\DispatchRejectedException;
use Shabbat\Scheduling\JobRepository;
use Shabbat\Scheduling\OutboxWorker;
use Shabbat\Storage\Database;
use Shabbat\Storage\SettingsRepository;
use Shabbat\Time\FrozenClock;

final class DispatchFenceTest extends TestCase
{
    public static function preflights(): array
    {
        return ['forum topic' => ['topic'], 'emoji catalog' => ['emoji'], 'reusable media' => ['media']];
    }

    private function clock(): FrozenClock
    {
        return new FrozenClock(new DateTimeImmutable('2026-10-16T14:19:59Z'));
    }

    private function payload(string $preflight): array
    {
        return match ($preflight) {
            'topic' => ['text' => 'Reminder', 'topic_id' => 7],
            'emoji' => ['text' => '🔔 Reminder'],
            'media' => ['text' => 'Reminder', 'media' => 'fixture-file'],
        };
    }

    private function advanceOnPreflight(DispatchFenceTransport $transport, FrozenClock $clock, string $preflight): void
    {
        $advance = static fn() => $clock->set($clock->now()->modify('+2 seconds'));
        if ($preflight === 'media') {
            $transport->onFile = $advance;
        } else {
            $transport->onCall = static function (string $method) use ($advance): void {
                if (in_array($method, ['messages.getForumTopicsByID', 'messages.getCustomEmojiDocuments'], true)) {
                    $advance();
                }
            };
        }
    }

    private function sends(DispatchFenceTransport $transport): array
    {
        return array_values(array_filter($transport->calls, static fn(array $call): bool => in_array($call[0], ['messages.sendMessage', 'messages.sendMedia'], true)));
    }

    #[DataProvider('preflights')]
    public function testNoNativeSendCanCrossBoundaryDuringPreflight(string $preflight): void
    {
        $clock = $this->clock();
        $cutoff = $clock->now()->getTimestamp() + 1;
        $transport = new DispatchFenceTransport();
        $this->advanceOnPreflight($transport, $clock, $preflight);
        $guardCalls = 0;
        try {
            (new MadelineGateway($transport))->sendGuarded('-100123', $this->payload($preflight), '42', static function () use ($clock, $cutoff, &$guardCalls): void {
                $guardCalls++;
                if ($clock->now()->getTimestamp() >= $cutoff) {
                    throw new DispatchRejectedException('holy period began');
                }
            });
            self::fail('The native send passed its cutoff.');
        } catch (DispatchRejectedException) {
            self::assertSame(1, $guardCalls);
            self::assertSame([], $this->sends($transport));
        }
    }

    private function rpc(string $name): \danog\MadelineProto\RPCErrorException
    {
        $error = (new \ReflectionClass(\danog\MadelineProto\RPCErrorException::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(\danog\MadelineProto\RPCErrorException::class, 'rpc'))->setValue($error, $name);
        return $error;
    }

    public function testEmojiFallbackHasItsOwnGuardAndCannotCrossCutoff(): void
    {
        $clock = $this->clock();
        $cutoff = $clock->now()->getTimestamp() + 1;
        $transport = new DispatchFenceTransport();
        $error = $this->rpc('PREMIUM_ACCOUNT_REQUIRED');
        $transport->onCall = static function (string $method) use ($clock, $error): void {
            if ($method === 'messages.sendMessage') {
                $clock->set($clock->now()->modify('+2 seconds'));
                throw $error;
            }
        };
        $guardCalls = 0;
        try {
            (new MadelineGateway($transport, true, EmojiFixtureTransport::alternatives()))->sendGuarded('-100123', ['text' => '🔔 Reminder'], '42', static function () use ($clock, $cutoff, &$guardCalls): void {
                $guardCalls++;
                if ($clock->now()->getTimestamp() >= $cutoff) {
                    throw new DispatchRejectedException('fallback expired');
                }
            });
            self::fail('Fallback crossed cutoff.');
        } catch (DispatchRejectedException) {
            self::assertSame(2, $guardCalls);
            self::assertCount(1, $this->sends($transport));
        }
    }

    public function testAllowedFallbackPreservesIdentityAndPayloadWithoutGuardMetadata(): void
    {
        $transport = new DispatchFenceTransport();
        $error = $this->rpc('CUSTOM_EMOJI_INVALID');
        $attempt = 0;
        $transport->onCall = static function (string $method) use ($error, &$attempt): void {
            if ($method === 'messages.sendMessage' && ++$attempt === 1) {
                throw $error;
            }
        };
        $payload = ['text' => '⭐ Reminder', 'entities' => [['_' => 'messageEntityCustomEmoji', 'offset' => 0, 'length' => 1, 'document_id' => 42]]];
        $original = $payload;
        $guardCalls = 0;
        (new MadelineGateway($transport, true, EmojiFixtureTransport::alternatives()))->sendGuarded('-100123', $payload, '12345678', static function () use (&$guardCalls): void {
            $guardCalls++;
        });
        $sends = $this->sends($transport);
        self::assertSame(2, $guardCalls);
        self::assertCount(2, $sends);
        self::assertSame($original, $payload);
        self::assertSame($sends[0][1]['random_id'], $sends[1][1]['random_id']);
        self::assertSame($sends[0][1]['message'], $sends[1][1]['message']);
        self::assertSame([], $sends[1][1]['entities']);
        self::assertArrayNotHasKey('guard', $sends[0][1]);
    }

    public function testPrivateNavigationDuringScheduledPreflightNeverInheritsScheduledGuard(): void
    {
        $transport = new DispatchFenceTransport();
        $gateway = new MadelineGateway($transport, false);
        $transport->onCall = static function (string $method) use ($gateway): void {
            if ($method === 'messages.getForumTopicsByID') {
                $gateway->sendPrivate('42', 'Private menu');
            }
        };
        try {
            $gateway->sendGuarded('-100123', ['text' => 'Reminder', 'topic_id' => 7], '123', static function (): void {
                throw new DispatchRejectedException('scheduled blocked');
            });
            self::fail('Scheduled guard was not applied.');
        } catch (DispatchRejectedException) {
            $sends = $this->sends($transport);
            self::assertCount(1, $sends);
            self::assertSame('42', $sends[0][1]['peer']);
        }
    }

    private function context(array $payload, int $ttl = 3600): array
    {
        $db = class_exists(\Pdo\Sqlite::class) ? new Database(new \Pdo\Sqlite('sqlite::memory:')) : Database::open(':memory:');
        $db->migrate();
        $clock = $this->clock();
        $settings = new SettingsRepository($db, $clock);
        $group = $settings->ensureGroup('-100123');
        $group = $settings->updateGroup('-100123', $group['revision'], ['zmanim_enabled' => true], '42');
        $jobs = new JobRepository($db, $clock);
        $id = $jobs->enqueue('-100123', 'zmanim', 'fixture', $clock->now()->getTimestamp(), $clock->now()->getTimestamp() + $ttl, $group['revision'], $payload);
        return [$db, $clock, $settings, $jobs, $id];
    }

    #[DataProvider('preflights')]
    public function testWorkerPassesFinalFenceThroughNativePreflight(string $preflight): void
    {
        [$db, $clock, $settings, $jobs, $id] = $this->context($this->payload($preflight));
        $transport = new DispatchFenceTransport();
        $this->advanceOnPreflight($transport, $clock, $preflight);
        $cutoff = $clock->now()->getTimestamp() + 1;
        $worker = new OutboxWorker(
            $jobs,
            $settings,
            new MadelineGateway($transport),
            $clock,
            static fn(): bool => true,
            null,
            null,
            static fn(array $job, array $group, DateTimeImmutable $now): bool => $now->getTimestamp() < $cutoff
        );
        $result = $worker->runOnce('fence');
        self::assertSame('cancelled', $result['status']);
        self::assertTrue($result['dispatch_rejected']);
        self::assertSame('cancelled', $jobs->get($id)['status']);
        self::assertSame([], $this->sends($transport));
    }

    public function testWorkerDeadlineIsCheckedAgainAfterNativePreflightEvenWithoutEvidenceCallback(): void
    {
        [$db, $clock, $settings, $jobs, $id] = $this->context(['text' => 'Reminder', 'topic_id' => 7], 1);
        $transport = new DispatchFenceTransport();
        $this->advanceOnPreflight($transport, $clock, 'topic');
        $worker = new OutboxWorker($jobs, $settings, new MadelineGateway($transport), $clock, static fn(): bool => true);
        self::assertSame('cancelled', $worker->runOnce('fence')['status']);
        self::assertSame([], $this->sends($transport));
    }

    public function testStaleEligibilityResultCannotOverrideSynchronousEvidenceDeadline(): void
    {
        [$db, $clock, $settings, $jobs, $id] = $this->context(['text' => 'Reminder']);
        $cutoff = $clock->now()->getTimestamp() + 1;
        $calls = 0;
        $transport = new DispatchFenceTransport();
        $worker = new OutboxWorker($jobs, $settings, new MadelineGateway($transport), $clock, static function () use ($clock, &$calls): bool {
            if (++$calls === 2) {
                $clock->set($clock->now()->modify('+2 seconds'));
            }
            return true; // A provider's pre-await observation is stale.
        }, null, null, static fn(array $job, array $group, DateTimeImmutable $now): bool => $now->getTimestamp() < $cutoff);
        self::assertSame('cancelled', $worker->runOnce('fence')['status']);
        self::assertSame([], $this->sends($transport));
    }

    public function testFinalDeferralKeepsPayloadRandomIdentityAndDoesNotConsumeTransportBudget(): void
    {
        [$db, $clock, $settings, $jobs, $id] = $this->context(['text' => 'Initial']);
        $transport = new DispatchFenceTransport();
        $preparations = 0;
        $defer = true;
        $worker = new OutboxWorker(
            $jobs,
            $settings,
            new MadelineGateway($transport),
            $clock,
            static fn(): bool => true,
            static function () use (&$preparations): array {
                $preparations++;
                return ['text' => 'Prepared'];
            },
            null,
            static function () use (&$defer, $clock): bool {
                if ($defer) {
                    throw new DeliveryDeferredException('refresh evidence', $clock->now()->getTimestamp() + 1);
                }
                return true;
            }
        );
        $random = $jobs->get($id)['random_id'];
        for ($i = 0; $i < 6; $i++) {
            self::assertSame('deferred', $worker->runOnce('fence')['status']);
            $clock->set($clock->now()->modify('+1 second'));
        }
        self::assertSame([], $this->sends($transport));
        self::assertSame(6, $jobs->get($id)['deferrals']);
        $defer = false;
        self::assertSame('sent', $worker->runOnce('fence')['status']);
        self::assertSame(1, $preparations);
        self::assertSame((int)$random, $this->sends($transport)[0][1]['random_id']);
        self::assertSame('Prepared', $this->sends($transport)[0][1]['message']);
    }

    public static function localMutations(): array
    {
        return ['settings revision' => ['settings'], 'lease stolen' => ['lease'], 'worker stop' => ['stop']];
    }

    #[DataProvider('localMutations')]
    public function testLiveLocalStateIsRecheckedAfterPreflight(string $mutation): void
    {
        [$db, $clock, $settings, $jobs, $id] = $this->context(['text' => 'Reminder', 'topic_id' => 7]);
        $transport = new DispatchFenceTransport();
        $worker = new OutboxWorker($jobs, $settings, new MadelineGateway($transport), $clock, static fn(): bool => true);
        $transport->onCall = static function (string $method) use ($db, $settings, $worker, $mutation): void {
            if ($method !== 'messages.getForumTopicsByID') {
                return;
            }
            if ($mutation === 'settings') {
                $group = $settings->getGroup('-100123');
                $settings->updateGroup('-100123', $group['revision'], ['zmanim_enabled' => false], '42');
            } elseif ($mutation === 'lease') {
                $db->pdo()->exec("UPDATE scheduled_jobs SET lease_owner='other'");
            } else {
                $worker->stop();
            }
        };
        self::assertSame('cancelled', $worker->runOnce('fence')['status']);
        self::assertSame([], $this->sends($transport));
        if ($mutation === 'lease') {
            self::assertSame('other', $jobs->get($id)['lease_owner'], 'A stale worker must never finish another owner\'s lease.');
        }
    }

    public function testClockCrossingDuringLocalReservationStillCannotDispatch(): void
    {
        [$db, $clock, $settings, $jobs, $id] = $this->context(['text' => 'Reminder']);
        $cutoff = $clock->now()->getTimestamp() + 1;
        $advance = static function () use ($clock): int {
            $clock->set($clock->now()->modify('+2 seconds'));
            return 1;
        };
        if ($db->pdo() instanceof \Pdo\Sqlite) {
            $db->pdo()->createFunction('advance_clock', $advance);
        } else {
            $db->pdo()->sqliteCreateFunction('advance_clock', $advance);
        }
        $db->pdo()->exec('CREATE TRIGGER simulate_slow_storage AFTER UPDATE OF last_dispatch_at ON scheduled_jobs BEGIN SELECT advance_clock(); END');
        $transport = new DispatchFenceTransport();
        $worker = new OutboxWorker($jobs, $settings, new MadelineGateway($transport), $clock, static fn(): bool => true, null, null, static fn(array $job, array $group, DateTimeImmutable $now): bool => $now->getTimestamp() < $cutoff);
        self::assertSame('cancelled', $worker->runOnce('fence')['status']);
        self::assertSame([], $this->sends($transport));
        self::assertNotNull($jobs->get($id)['dispatch_started_at'], 'Never erase a conservative dispatch reservation after the final boundary closes.');
    }

    public function testWorkerEmojiFallbackKeepsOneDurablePayloadAndIdentity(): void
    {
        [$db, $clock, $settings, $jobs, $id] = $this->context(['text' => '🔔 Reminder']);
        $transport = new DispatchFenceTransport();
        $error = $this->rpc('PREMIUM_ACCOUNT_REQUIRED');
        $attempts = 0;
        $transport->onCall = static function (string $method) use ($error, &$attempts): void {
            if ($method === 'messages.sendMessage' && ++$attempts === 1) {
                throw $error;
            }
        };
        $prepared = 0;
        $worker = new OutboxWorker($jobs, $settings, new MadelineGateway($transport, true, EmojiFixtureTransport::alternatives()), $clock, static fn(): bool => true, static function (array $job) use (&$prepared): array {
            $prepared++;
            return $job['payload'];
        }, null, static fn(): bool => true);
        self::assertSame('sent', $worker->runOnce('fence')['status']);
        self::assertSame(1, $prepared);
        self::assertSame(1, $jobs->get($id)['attempts']);
        self::assertCount(2, $this->sends($transport));
        self::assertSame($this->sends($transport)[0][1]['random_id'], $this->sends($transport)[1][1]['random_id']);
    }

    public function testBroadcastNativeRateLimitIsNotHiddenOrAwaitedAndZeroLimitWins(): void
    {
        $transport = new DispatchFenceTransport();
        $native = new \danog\MadelineProto\RPCError\FloodWaitError('FLOOD_WAIT_12', 12, 420, 'messages.sendMessage');
        $transport->onCall = static function () use ($native): void {
            throw $native;
        };
        try {
            (new MadelineGateway($transport))->broadcastCall('sendMessage', ['peer' => '-100123', 'random_id' => 42, 'message' => 'Broadcast', 'floodWaitLimit' => 172800]);
            self::fail('Expected native retry classification.');
        } catch (\danog\MadelineProto\RPCError\FloodWaitError $caught) {
            self::assertSame($native, $caught);
            self::assertSame(0, $transport->calls[0][1]['floodWaitLimit']);
        }
    }
}
