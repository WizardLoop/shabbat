<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace Shabbat\Tests;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Shabbat\Scheduling\JobRepository;
use Shabbat\Storage\Database;
use Shabbat\Storage\SettingsRepository;
use Shabbat\Time\FrozenClock;

final class QueueMaintenanceTest extends TestCase
{
    private Database $db;
    private FrozenClock $clock;
    private JobRepository $jobs;
    protected function setUp(): void
    {
        $this->db = Database::open(':memory:');
        $this->db->migrate();
        $this->clock = new FrozenClock(new DateTimeImmutable('2026-01-01T09:00:00Z'));
        (new SettingsRepository($this->db, $this->clock))->ensureGroup('-1');
        $this->jobs = new JobRepository($this->db, $this->clock);
    }
    private function add(string $event, string $module = 'daf'): int
    {
        $now = $this->clock->now()->getTimestamp();
        return $this->jobs->enqueue('-1', $module, $event, $now, $now + 3600, 1, ['text' => $event]);
    }
    public function testCleanupIsBoundedAndPreservesUncertainActiveAndCurrentPointerEvidence(): void
    {
        $safe = $this->add('safe');
        $this->jobs->lease('a');
        $this->jobs->sent($safe, 'a', ['id' => 1]);
        $uncertain = $this->add('uncertain');
        $this->jobs->lease('a');
        $this->jobs->markDispatch($uncertain, 'a');
        $this->jobs->fail($uncertain, 'a', 'lost response');
        $pointer = $this->add('pointer');
        $this->jobs->lease('a');
        $this->jobs->sent($pointer, 'a', ['id' => 3]);
        $this->db->pdo()->exec("INSERT INTO module_last_messages VALUES('-1','daf','[3]',3,'pointer',1,1,$pointer,1)");
        $pending = $this->add('pending');
        $this->clock->set($this->clock->now()->modify('+100 days'));
        $result = $this->jobs->maintain(90, 1);
        self::assertSame(1, $result['jobs']);
        self::assertSame(1, $result['delivery_log']);
        self::assertNull($this->jobs->get($safe));
        foreach ([$uncertain,$pointer,$pending] as $id) {
            self::assertNotNull($this->jobs->get($id));
        }
        self::assertSame(0, $this->jobs->maintain(90, 1)['jobs']);
        self::assertSame([], $this->db->integrity()['foreign_key_check']);
    }
    public function testOneOldJobWithManyAttemptsCannotExceedLogCleanupBatch(): void
    {
        $id = $this->add('many-deferrals');
        $this->jobs->lease('a');
        $this->jobs->sent($id, 'a', ['id' => 1]);
        $query = $this->db->pdo()->prepare("INSERT INTO delivery_log(job_id,attempt,random_id,started_at,finished_at,outcome) VALUES(?,?,?,1,2,'retry')");
        for ($attempt = 2; $attempt <= 250; $attempt++) {
            $query->execute([$id,$attempt,$this->jobs->get($id)['random_id']]);
        }
        $this->clock->set($this->clock->now()->modify('+100 days'));
        $deleted = 0;
        for ($pass = 0; $pass < 25; $pass++) {
            $result = $this->jobs->maintain(90, 10);
            self::assertSame(10, $result['delivery_log']);
            $deleted += $result['jobs'];
        }
        self::assertSame(1, $deleted);
        self::assertNull($this->jobs->get($id));
        self::assertSame([], $this->db->integrity()['foreign_key_check']);
    }
    public function testCleanupPreservesInteractiveAcknowledgementsAndRecoveryAudit(): void
    {
        $id = $this->add('interactive', 'tefillin');
        $this->jobs->lease('a');
        $this->jobs->sent($id, 'a', ['id' => 1]);
        $this->jobs->markDeliveryProcessed($id);
        $this->db->pdo()->exec("INSERT INTO tefillin_ack_rounds VALUES('123456789012345678901234',$id,'-1','Done','en',1,'[]',1)");
        $this->db->pdo()->exec("INSERT INTO audit_log(group_id,action,details_json,created_at) VALUES('-1','permission.recovery','{}',1),('-1','settings.update','{}',1),('-1','operator.broadcast.scheduled','{}',1)");
        $this->db->pdo()->exec("INSERT INTO group_lock_state(group_id,event_key,status,prepared_at) VALUES('-1','event','conflict',1)");
        $this->clock->set($this->clock->now()->modify('+100 days'));
        $result = $this->jobs->maintain();
        self::assertSame(0, $result['jobs']);
        self::assertSame(0, $result['audit_log']);
        $this->db->pdo()->exec("UPDATE group_lock_state SET status='restored'");
        self::assertSame(1, $this->jobs->maintain()['audit_log']);
        self::assertSame(2, (int)$this->db->pdo()->query('SELECT COUNT(*) FROM audit_log')->fetchColumn());
        self::assertSame(1, (int)$this->db->pdo()->query('SELECT COUNT(*) FROM tefillin_ack_rounds')->fetchColumn());
    }
    public function testCleanupDisabledAndRetentionBounds(): void
    {
        $this->db->pdo()->exec("INSERT INTO calendar_cache VALUES('expired','fixture','{}',1,2,NULL,NULL)");
        self::assertSame(0, $this->jobs->maintain(0)['calendar_cache']);
        self::assertSame(1, $this->jobs->maintain(30)['calendar_cache']);
        $this->expectException(\InvalidArgumentException::class);
        $this->jobs->maintain(1);
    }
    public function testQueueMetricsExposeRealDelayBacklogAndUncertainty(): void
    {
        $id = $this->add('delayed');
        $this->clock->set($this->clock->now()->modify('+5 minutes'));
        self::assertSame(300, $this->jobs->queueMetrics()['oldest_due_age_seconds']);
        $this->jobs->lease('a');
        $this->jobs->markDispatch($id, 'a');
        self::assertSame(1, $this->jobs->queueMetrics()['uncertain']);
        $this->jobs->sent($id, 'a', ['id' => 1]);
        $metrics = $this->jobs->queueMetrics();
        self::assertSame(0, $metrics['uncertain']);
        self::assertSame(0, $metrics['active']);
        self::assertSame(1, $metrics['sent_last_24h']);
        self::assertSame(300, $metrics['max_delivery_delay_last_24h']);
    }
    public function testOneHundredEightyDaySimulationKeepsRoutineHistoryWithinRetentionWindow(): void
    {
        $maxRows = 0;
        for ($day = 0; $day < 180; $day++) {
            for ($message = 0; $message < 40; $message++) {
                $id = $this->add("day-$day-$message");
                $this->jobs->lease('simulation');
                $this->jobs->sent($id, 'simulation', ['id' => $message + 1]);
            }
            $this->jobs->maintain(30, 200);
            $rows = (int)$this->db->pdo()->query('SELECT COUNT(*) FROM scheduled_jobs')->fetchColumn();
            $maxRows = max($maxRows, $rows);
            self::assertLessThanOrEqual(1280, $rows);
            $this->clock->set($this->clock->now()->modify('+1 day'));
        }
        self::assertGreaterThan(1000, $maxRows);
        self::assertLessThanOrEqual(1280, (int)$this->db->pdo()->query('SELECT COUNT(*) FROM delivery_log')->fetchColumn());
        self::assertSame([], $this->db->integrity()['foreign_key_check']);
        self::assertSame(['ok'], $this->db->integrity()['integrity_check']);
    }
}
