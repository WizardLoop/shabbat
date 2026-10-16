<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace Shabbat\Tests;

use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;
use Shabbat\Scheduling\DeliveryDeferredException;
use Shabbat\Scheduling\JobRepository;
use Shabbat\Storage\Database;
use Shabbat\Storage\SettingsRepository;
use Shabbat\Time\FrozenClock;

final class DeliverySafetyTest extends TestCase
{
    private Database $db;
    private FrozenClock $clock;
    private SettingsRepository $settings;
    private JobRepository $jobs;
    protected function setUp(): void
    {
        $this->db = Database::open(':memory:');
        $this->db->migrate();
        $this->clock = new FrozenClock(new DateTimeImmutable('2026-10-15T08:30:00Z'));
        $this->settings = new SettingsRepository($this->db, $this->clock);
        $this->settings->ensureGroup('-1');
        $this->jobs = new JobRepository($this->db, $this->clock);
    }
    private function add(string $event, int $ago = 0, string $group = '-1', string $module = 'tefillin'): int
    {
        $now = $this->clock->now()->getTimestamp();
        return $this->jobs->enqueue($group, $module, $event, $now - $ago, $now + 14400, 1, ['text' => $event]);
    }
    private function reserve(array $job, string $owner = 'worker', array $changes = []): bool
    {
        return $this->jobs->guardDelivery($job, array_replace($this->settings->getGroup($job['group_id']), $changes), $owner);
    }
    public function testPersistedOverdueRemindersCollapseToOnlyLatestAfterRestart(): void
    {
        $a = $this->add('09:00', 9000);
        $b = $this->add('10:00', 5400);
        $c = $this->add('11:00', 1800);
        $restarted = new JobRepository($this->db, $this->clock);
        $job = $restarted->lease('worker');
        self::assertSame($c, $job['job_id']);
        self::assertSame('cancelled', $restarted->get($a)['status']);
        self::assertSame('cancelled', $restarted->get($b)['status']);
        self::assertTrue($this->reserve($job));
        $restarted->sent($c, 'worker', ['id' => 12]);
        self::assertNull($restarted->lease('worker'));
    }
    public function testActualDeliverySpacingSurvivesRestartAndUsesCurrentSettings(): void
    {
        $id = $this->add('first', 3600);
        $job = $this->jobs->lease('worker');
        self::assertTrue($this->reserve($job));
        $this->jobs->sent($id, 'worker', ['id' => 1]);
        $this->clock->set($this->clock->now()->modify('+10 minutes'));
        $id2 = $this->add('second', 3500);
        $job2 = (new JobRepository($this->db, $this->clock))->lease('worker');
        try {
            $this->reserve($job2, changes: ['tefillin_min_spacing_minutes' => 45]);
            self::fail('Should defer actual delivery spacing.');
        } catch (DeliveryDeferredException $error) {
            self::assertSame($this->clock->now()->getTimestamp() + 35 * 60, $error->retryAt);
            $this->jobs->defer($id2, 'worker', $error->getMessage(), $error->retryAt);
        }
        self::assertNull($this->jobs->get($id2)['dispatch_started_at']);
        $this->clock->set($this->clock->now()->modify('+35 minutes'));
        self::assertTrue($this->reserve($this->jobs->lease('worker'), changes: ['tefillin_min_spacing_minutes' => 45]));
    }
    public function testDailyMaximumCountsSuccessfulDeliveryAcrossRevisionsAndLocalMidnight(): void
    {
        $this->clock->set(new DateTimeImmutable('2026-10-15T20:55:00Z'));
        $id = $this->add('one');
        self::assertTrue($this->reserve($this->jobs->lease('worker'), changes: ['tefillin_max_per_day' => 1]));
        $this->jobs->sent($id, 'worker', ['id' => 1]);
        $this->clock->set($this->clock->now()->modify('+1 minute'));
        $this->add('two');
        $job = $this->jobs->lease('worker');
        self::assertFalse($this->reserve($job, changes: ['tefillin_max_per_day' => 1]));
        $this->jobs->cancel($job['job_id'], 'worker', 'daily limit');
        $this->clock->set(new DateTimeImmutable('2026-10-15T21:30:00Z'));
        $this->add('next-local-day');
        self::assertTrue($this->reserve($this->jobs->lease('worker'), changes: ['tefillin_max_per_day' => 1]));
    }
    public function testUncertainRetryPreservesPayloadRandomIdAndBlocksNewReminder(): void
    {
        $id = $this->add('uncertain');
        $job = $this->jobs->lease('first');
        $this->jobs->preparePayload($id, 'first', ['text' => 'frozen']);
        self::assertTrue($this->reserve($job, 'first'));
        $this->jobs->markDispatch($id, 'first');
        $this->jobs->retry($id, 'first', 'lost response', 120);
        $this->clock->set($this->clock->now()->modify('+1 second'));
        $newId = $this->add('later');
        $new = $this->jobs->lease('second');
        self::assertSame($newId, $new['job_id']);
        try {
            $this->reserve($new, 'second');
            self::fail('Uncertain predecessor should defer fresh logical reminder.');
        } catch (DeliveryDeferredException $error) {
            $this->jobs->defer($newId, 'second', $error->getMessage(), $error->retryAt);
        }
        $this->clock->set($this->clock->now()->modify('+120 seconds'));
        $retry = $this->jobs->lease('first');
        self::assertSame($id, $retry['job_id']);
        self::assertSame($job['random_id'], $retry['random_id']);
        self::assertSame(['text' => 'frozen'], $retry['payload']);
        self::assertTrue($this->reserve($retry, 'first', ['tefillin_max_per_day' => 1]));
        $this->jobs->sent($id, 'first', ['id' => 1]);
        self::assertSame('pending', $this->jobs->get($newId)['status']);
    }
    public function testParallelOwnersCannotReserveTwoSlotsAndUnrelatedGroupCanSend(): void
    {
        $this->settings->ensureGroup('-2');
        $first = $this->add('first');
        $a = $this->jobs->lease('a');
        self::assertTrue($this->reserve($a, 'a'));
        $this->add('second');
        $b = $this->jobs->lease('b');
        try {
            $this->reserve($b, 'b');
            self::fail('Parallel logical reminder must not pass an unresolved reservation.');
        } catch (DeliveryDeferredException $error) {
            $this->jobs->defer($b['job_id'], 'b', $error->getMessage(), $error->retryAt);
        }
        $other = $this->add('other', group: '-2');
        $c = $this->jobs->lease('c');
        self::assertSame($other, $c['job_id']);
        self::assertTrue($this->reserve($c, 'c'));
        self::assertSame('leased', $this->jobs->get($first)['status']);
    }
    public function testAlreadyDispatchedExpiredIdentityCannotBeRewrittenBySettingsUpdate(): void
    {
        $id = $this->add('identity');
        $job = $this->jobs->lease('worker');
        $this->jobs->preparePayload($id, 'worker', ['text' => 'immutable']);
        self::assertTrue($this->reserve($job));
        $this->jobs->fail($id, 'worker', 'unknown response');
        $before = $this->jobs->get($id);
        $now = $this->clock->now()->getTimestamp();
        self::assertSame($id, $this->jobs->enqueue('-1', 'tefillin', 'identity', $now, $now + 15000, 2, ['text' => 'new']));
        self::assertSame($before, $this->jobs->get($id));
    }
    public function testAlreadyDispatchedRawLegacyPayloadCannotBeRegenerated(): void
    {
        $id = $this->add('frozen-original');
        $job = $this->jobs->lease('worker');
        self::assertTrue($this->reserve($job));
        $this->jobs->preparePayload($id, 'worker', ['text' => 'different']);
        self::assertSame(['text' => 'frozen-original'], $this->jobs->get($id)['payload']);
        self::assertSame($job['random_id'], $this->jobs->get($id)['random_id']);
    }
    public function testDeadlineOwnershipAndCancelledJobsCannotReserve(): void
    {
        $id = $this->add('owner');
        $job = $this->jobs->lease('worker', 1);
        self::assertFalse($this->reserve($job, 'wrong'));
        $this->clock->set($this->clock->now()->modify('+1 second'));
        self::assertFalse($this->reserve($job));
        self::assertNull($this->jobs->get($id)['dispatch_started_at']);
    }
    public function testUpgradeBackfillsOldUncertainHistoryConservativelyWithoutChangingPayload(): void
    {
        $db = Database::open(':memory:');
        $pdo = $db->pdo();
        $files = glob(dirname(__DIR__) . '/migrations/*.sql');
        sort($files);
        foreach ($files as $file) {
            if (basename($file) === '016_delivery_safety.sql') {
                break;
            }
            $pdo->exec(file_get_contents($file));
        }
        $pdo->exec("INSERT INTO groups(group_id,created_at,updated_at) VALUES('-1',10,10)");
        $pdo->exec("INSERT INTO scheduled_jobs(group_id,module,event_key,run_at,expires_at,settings_revision,payload_json,random_id,status,attempts,created_at) VALUES('-1','tefillin','old',10,1000,1,'{\"text\":\"original\"}','123','pending',1,10)");
        $pdo->exec("INSERT INTO delivery_log(job_id,attempt,random_id,started_at,outcome) VALUES(1,1,'123',100,'retry')");
        $pdo->exec(file_get_contents(dirname(__DIR__) . '/migrations/016_delivery_safety.sql'));
        $row = $pdo->query('SELECT * FROM scheduled_jobs')->fetch();
        self::assertSame(100, $row['dispatch_started_at']);
        self::assertSame(100, $row['last_dispatch_at']);
        self::assertSame('123', $row['random_id']);
        self::assertSame('{"text":"original"}', $row['payload_json']);
    }
    public function testMultipleLegacyUncertainRetriesReconcileInOrderWithoutBurst(): void
    {
        $first = $this->add('first-old', 60);
        $second = $this->add('second-old', 30);
        $now = $this->clock->now()->getTimestamp();
        $this->db->pdo()->exec("UPDATE scheduled_jobs SET dispatch_started_at=$now-3600,last_dispatch_at=$now-3600");
        $job = $this->jobs->lease('worker');
        self::assertSame($first, $job['job_id']);
        self::assertTrue($this->reserve($job));
        $this->jobs->sent($first, 'worker', ['id' => 1]);
        $retry = $this->jobs->lease('worker');
        self::assertSame($second, $retry['job_id']);
        try {
            $this->reserve($retry);
            self::fail('Multiple old uncertain identities must not replay in a burst.');
        } catch (DeliveryDeferredException $error) {
            self::assertSame($now + 1800, $error->retryAt);
        }
        self::assertNotNull($this->jobs->get($second)['dispatch_started_at']);
    }
    public function testOneThousandGroupsWithThreeDueJobsEachSendOnlyLatestPerGroup(): void
    {
        for ($i = 1; $i <= 1000; $i++) {
            $group = (string)(-$i);
            $this->settings->ensureGroup($group);
            $this->add('old', 7200, $group);
            $this->add('middle', 3600, $group);
            $this->add('latest', 1, $group);
        }
        $seen = [];
        while (($job = $this->jobs->lease('worker')) !== null) {
            self::assertSame('latest', $job['event_key']);
            self::assertArrayNotHasKey($job['group_id'], $seen);
            self::assertTrue($this->reserve($job));
            $this->jobs->sent($job['job_id'], 'worker', ['id' => 1]);
            $seen[$job['group_id']] = true;
        }
        self::assertCount(1000, $seen);
        self::assertSame(['cancelled' => 2000,'sent' => 1000], $this->jobs->counts());
        self::assertSame([], $this->db->integrity()['foreign_key_check']);
    }
}
