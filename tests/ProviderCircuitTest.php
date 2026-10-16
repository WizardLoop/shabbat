<?php

// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);

namespace Shabbat\Tests;

use PHPUnit\Framework\TestCase;
use Shabbat\Http\{JsonClient,ProviderException,ProviderCircuitClient,SharedJsonClient,SqliteJsonClient};
use Shabbat\Storage\Database;
use Shabbat\Time\FrozenClock;

final class ProviderCircuitTest extends TestCase
{
    private function clock(): FrozenClock
    {
        return new FrozenClock(new \DateTimeImmutable('2026-10-15T09:00:00Z'));
    }
    public function testRepeatedFailureAcrossManyLocationsOpensCircuitAndRecovers(): void
    {
        $clock = $this->clock();
        $http = new class implements JsonClient {
            public int $calls = 0;
            public bool $fail = true;
            public function get(string $url, array $query = [], ?\Amp\Cancellation $cancellation = null): array
            {
                $this->calls++;
                if ($this->fail) {
                    throw new ProviderException('fixture offline');
                }
                return ['ok' => true];
            }
        };
        $client = new ProviderCircuitClient($http, $clock);
        for ($i = 0; $i < 1000; $i++) {
            try {
                $client->get('https://www.hebcal.com/zmanim', ['location' => $i]);
                self::fail('Outage was hidden.');
            } catch (ProviderException) {
                self::assertTrue(true);
            }
        }
        self::assertSame(3, $http->calls);
        $clock->set($clock->now()->modify('+30 seconds'));
        $http->fail = false;
        self::assertSame(['ok' => true], $client->get('https://www.hebcal.com/zmanim'));
        self::assertSame(4, $http->calls);
    }
    public function testCircuitLivesBelowPersistentCacheSoVerifiedStaleDataRemainsAvailable(): void
    {
        $db = Database::open(':memory:');
        $db->migrate();
        $clock = $this->clock();
        $http = new class implements JsonClient {
            public bool $fail = false;
            public int $calls = 0;
            public function get(string $url, array $query = [], ?\Amp\Cancellation $cancellation = null): array
            {
                $this->calls++;
                if ($this->fail) {
                    throw new ProviderException('fixture offline');
                }
                return ['range' => ['start' => '2026-10-01', 'end' => '2026-10-31'], 'items' => []];
            }
        };
        $client = new SqliteJsonClient(new ProviderCircuitClient($http, $clock, 1), $db, $clock, 1);
        $client->get('https://www.hebcal.com/hebcal', ['city' => 1]);
        $clock->set($clock->now()->modify('+2 seconds'));
        $http->fail = true;
        $first = $client->get('https://www.hebcal.com/hebcal', ['city' => 1]);
        $second = $client->get('https://www.hebcal.com/hebcal', ['city' => 1]);
        self::assertTrue($first['__shabbat_cache']['stale']);
        self::assertTrue($second['__shabbat_cache']['stale']);
        self::assertSame(2, $http->calls);
    }
    public function testSharedFailureIsDeduplicatedAndCallerCancellationDoesNotPoisonOthers(): void
    {
        $clock = $this->clock();
        $entered = new \Amp\DeferredFuture();
        $release = new \Amp\DeferredFuture();
        $http = new class ($entered, $release) implements JsonClient {
            public int $calls = 0;
            public function __construct(private \Amp\DeferredFuture $entered, private \Amp\DeferredFuture $release)
            {
            }
            public function get(string $url, array $query = [], ?\Amp\Cancellation $cancellation = null): array
            {
                $this->calls++;
                $this->entered->complete();
                $this->release->getFuture()->await();
                return ['ok' => true];
            }
        };
        $client = new SharedJsonClient($http, $clock);
        $cancel = new \Amp\DeferredCancellation();
        $first = \Amp\async(fn() => $client->get('https://example.invalid/calendar', [], $cancel->getCancellation()));
        $entered->getFuture()->await();
        $second = \Amp\async(fn() => $client->get('https://example.invalid/calendar'));
        $cancel->cancel();
        try {
            $first->await();
            self::fail('Caller cancellation was ignored.');
        } catch (\Amp\CancelledException) {
            self::assertTrue(true);
        }
        $release->complete();
        self::assertSame(['ok' => true], $second->await());
        self::assertSame(1, $http->calls);
        self::assertSame(['ok' => true], $client->get('https://example.invalid/calendar'));
    }
    public function testConcurrentErrorsRecordedOnceThenCooldownBoundsSequentialRetries(): void
    {
        $clock = $this->clock();
        $http = new class implements JsonClient {
            public int $calls = 0;
            public function get(string $url, array $query = [], ?\Amp\Cancellation $cancellation = null): array
            {
                $this->calls++;
                \Amp\delay(0.001);
                throw new ProviderException('fixture failure');
            }
        };
        $client = new SharedJsonClient($http, $clock);
        $futures = [];
        for ($i = 0; $i < 100; $i++) {
            $futures[] = \Amp\async(fn() => $client->get('https://example.invalid/calendar'));
        }
        [$errors, $values] = \Amp\Future\awaitAll($futures);
        self::assertCount(100, $errors);
        self::assertSame([], $values);
        self::assertSame(1, $http->calls);
        for ($i = 0; $i < 100; $i++) {
            try {
                $client->get('https://example.invalid/calendar');
            } catch (ProviderException) {
            }
        }
        self::assertSame(1, $http->calls);
        $clock->set($clock->now()->modify('+15 seconds'));
        try {
            $client->get('https://example.invalid/calendar');
        } catch (ProviderException) {
        }
        self::assertSame(2, $http->calls);
    }
}
