<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-only
namespace Shabbat\Scheduling;

use PDO;
use Shabbat\Storage\Database;
use Shabbat\Time\Clock;

final class JobRepository
{
    public function __construct(private readonly Database $db, private readonly Clock $clock)
    {
    }
    public function enqueue(string $group, string $module, string $eventKey, int $runAt, int $expiresAt, int $settingsRevision, array $payload, int $priority = 100): int
    {
        if ($expiresAt < $runAt || $settingsRevision < 1 || strlen($eventKey) > 256 || $eventKey === '') {
            throw new \InvalidArgumentException('Invalid scheduled job.');
        }
        $random = (string)random_int(1, PHP_INT_MAX);
        $query = $this->db->pdo()->prepare("INSERT INTO scheduled_jobs(group_id,module,event_key,run_at,expires_at,settings_revision,payload_json,random_id,priority,created_at) VALUES(?,?,?,?,?,?,?,?,?,?)
            ON CONFLICT(group_id,module,event_key) DO UPDATE SET run_at=excluded.run_at,expires_at=excluded.expires_at,settings_revision=excluded.settings_revision,payload_json=excluded.payload_json,payload_prepared_at=NULL,priority=excluded.priority,
            status='pending',retry_at=NULL,lease_owner=NULL,lease_until=NULL,last_error=NULL
            WHERE scheduled_jobs.status IN ('pending','cancelled','expired','failed') AND scheduled_jobs.dispatch_started_at IS NULL AND scheduled_jobs.settings_revision<>excluded.settings_revision");
        $query->execute([$group,$module,$eventKey,$runAt,$expiresAt,$settingsRevision,Database::encode($payload),$random,$priority,$this->clock->now()->getTimestamp()]);
        $query = $this->db->pdo()->prepare('SELECT job_id FROM scheduled_jobs WHERE group_id=? AND module=? AND event_key=?');
        $query->execute([$group,$module,$eventKey]);
        return (int)$query->fetchColumn();
    }
    public function get(int $id): ?array
    {
        $query = $this->db->pdo()->prepare('SELECT * FROM scheduled_jobs WHERE job_id=?');
        $query->execute([$id]);
        $row = $query->fetch();
        return $row === false ? null : self::decode($row);
    }
    public function lease(string $owner, int $leaseSeconds = 120): ?array
    {
        if ($owner === '' || $leaseSeconds < 1 || $leaseSeconds > 300) {
            throw new \InvalidArgumentException('Invalid worker lease.');
        }
        $now = $this->clock->now()->getTimestamp();
        return $this->db->transaction(function (PDO $pdo) use ($owner, $leaseSeconds, $now): ?array {
            $pdo->prepare("UPDATE scheduled_jobs SET status='expired',last_error='delivery cutoff elapsed',lease_owner=NULL,lease_until=NULL WHERE status IN ('pending','leased') AND expires_at<=? AND (lease_until IS NULL OR lease_until<=?)")->execute([$now,$now]);
            $query = $pdo->prepare("SELECT * FROM scheduled_jobs WHERE run_at<=? AND expires_at>? AND (retry_at IS NULL OR retry_at<=?)
                AND (status='pending' OR (status='leased' AND lease_until<=?)) ORDER BY priority,CASE WHEN dispatch_started_at IS NOT NULL THEN 0 ELSE 1 END,run_at,job_id LIMIT 1");
            $row = false;
            // Each pass touches only one group. A deferred newest reminder must not starve unrelated groups.
            for ($pass = 0; $pass < 20; $pass++) {
                $query->execute([$now,$now,$now,$now]);
                $row = $query->fetch();
                if ($row === false || $row['module'] !== 'tefillin' || $row['status'] !== 'pending' || $row['dispatch_started_at'] !== null) {
                    break;
                }
                DeliverySafety::coalesce($pdo, $now, $row['group_id']);
                $current = $pdo->prepare("SELECT * FROM scheduled_jobs WHERE group_id=? AND module='tefillin' AND status='pending'
                    AND run_at<=? AND expires_at>? AND (retry_at IS NULL OR retry_at<=?)
                    ORDER BY priority,CASE WHEN dispatch_started_at IS NOT NULL THEN 0 ELSE 1 END,run_at,job_id LIMIT 1");
                $current->execute([$row['group_id'],$now,$now,$now]);
                $row = $current->fetch();
                if ($row !== false) {
                    break;
                }
            }
            if ($row === false) {
                return null;
            }
            if ($row['status'] === 'leased') {
                $pdo->prepare("UPDATE delivery_log SET outcome='uncertain',finished_at=?,error='worker lease expired before acknowledged completion' WHERE job_id=? AND outcome='attempting'")->execute([$now,$row['job_id']]);
            }
            $pdo->prepare("UPDATE scheduled_jobs SET status='leased',attempts=attempts+1,lease_owner=?,lease_until=? WHERE job_id=?")->execute([$owner,$now + $leaseSeconds,$row['job_id']]);
            $row['attempts'] = (int)$row['attempts'] + 1;
            $row['status'] = 'leased';
            $row['lease_owner'] = $owner;
            $row['lease_until'] = $now + $leaseSeconds;
            $pdo->prepare("INSERT INTO delivery_log(job_id,attempt,random_id,started_at,outcome) VALUES(?,?,?,?,'attempting')")->execute([$row['job_id'],$row['attempts'],$row['random_id'],$now]);
            return self::decode($row);
        });
    }
    public function preparePayload(int $id, string $owner, array $payload): void
    {
        $this->db->transaction(function (PDO $pdo) use ($id, $owner, $payload): void {
            $job = $this->get($id);
            $now = $this->clock->now()->getTimestamp();
            if ($job === null || !$this->owns($id, $owner) || $job['expires_at'] <= $now) {
                throw new \RuntimeException('Job ownership or delivery window was lost before payload persistence.');
            }
            if ($job['payload_prepared_at'] !== null || $job['dispatch_started_at'] !== null) {
                return;
            }
            $query = $pdo->prepare('UPDATE scheduled_jobs SET payload_json=?,payload_prepared_at=? WHERE job_id=? AND lease_owner=? AND status=\'leased\' AND payload_prepared_at IS NULL');
            $query->execute([Database::encode($payload),$now,$id,$owner]);
            if ($query->rowCount() !== 1) {
                throw new \RuntimeException('Prepared delivery payload was not persisted.');
            }
        });
    }
    /** Repair only the known pre-3.1.1 Daf serialization defect on an eligible leased retry. */
    public function repairPreparedDafPayload(int $id, string $owner): bool
    {
        return $this->db->transaction(function (PDO $pdo) use ($id, $owner): bool {
            $now = $this->clock->now()->getTimestamp();
            $query = $pdo->prepare("SELECT * FROM scheduled_jobs WHERE job_id=? AND status='leased' AND lease_owner=? AND lease_until>? AND expires_at>?");
            $query->execute([$id,$owner,$now,$now]);
            $row = $query->fetch();
            if ($row === false) {
                throw new \RuntimeException('Job ownership or delivery window was lost before Daf payload repair.');
            }
            if ($row['module'] !== 'daf' || $row['payload_prepared_at'] === null) {
                return false;
            }
            // The transport guard stamps dispatch before Madeline parses HTML.
            // A known local parse rejection can therefore have a dispatch stamp;
            // a generic transport uncertainty must retain its frozen payload.
            $error = (string)($row['last_error'] ?? '');
            if ($row['dispatch_started_at'] !== null && !(str_contains($error, 'DOMDocument::loadXML()') && str_contains($error, 'EntityRef: expecting'))) {
                return false;
            }
            $payload = \Shabbat\Content\LegacyDafPayload::repair(json_decode($row['payload_json'], true, 64, JSON_THROW_ON_ERROR));
            if ($payload === null) {
                return false;
            }
            // No provider work, re-planning or identity/history reset: only the
            // invalid URL encoding changes, under the existing lease and CAS.
            $query = $pdo->prepare("UPDATE scheduled_jobs SET payload_json=? WHERE job_id=? AND lease_owner=? AND status='leased' AND lease_until>? AND expires_at>? AND payload_json=?");
            $query->execute([Database::encode($payload),$id,$owner,$now,$now,$row['payload_json']]);
            if ($query->rowCount() !== 1) {
                throw new \RuntimeException('Prepared Daf payload changed before repair persistence.');
            }
            return true;
        });
    }
    /**
     * Lease ownership depends on mutable database state and the current clock.
     *
     * @phpstan-impure
     */
    public function owns(int $job, string $owner): bool
    {
        $row = $this->get($job);
        return $row !== null && $row['status'] === 'leased' && $row['lease_owner'] === $owner && $row['lease_until'] > $this->clock->now()->getTimestamp();
    }
    public function sent(int $job, string $owner, array $response): void
    {
        $this->finish($job, $owner, 'sent', null, $response);
    }
    public function cancel(int $job, string $owner, string $reason): void
    {
        $this->finish($job, $owner, 'cancelled', $reason);
    }
    public function fail(int $job, string $owner, string $reason): void
    {
        $this->finish($job, $owner, 'failed', $reason);
    }
    private function finish(int $id, string $owner, string $status, ?string $error, array $response = []): void
    {
        $this->db->transaction(function (PDO $pdo) use ($id, $owner, $status, $error, $response): void {
            $job = $this->get($id);
            if ($job === null || !in_array($job['status'], ['leased','cancelled'], true) || $job['lease_owner'] !== $owner) {
                throw new \RuntimeException('Job ownership was lost.');
            }
            $now = $this->clock->now()->getTimestamp();
            $pdo->prepare('UPDATE scheduled_jobs SET status=?,completed_at=?,last_error=?,lease_owner=NULL,lease_until=NULL WHERE job_id=? AND lease_owner=?')
                ->execute([$status,$now,$error,$id,$owner]);
            $outcome = $status === 'sent' ? 'sent' : 'failed';
            $pdo->prepare('UPDATE delivery_log SET outcome=?,finished_at=?,response_json=?,error=? WHERE job_id=? AND attempt=?')
                ->execute([$outcome,$now,$response === [] ? null : Database::encode($response),$error,$id,$job['attempts']]);
        });
    }
    public function defer(int $id, string $owner, string $reason, int $retryAt): void
    {
        $this->db->transaction(function (PDO $pdo) use ($id, $owner, $reason, $retryAt): void {
            $job = $this->get($id);
            if ($job === null || !$this->owns($id, $owner)) {
                throw new \RuntimeException('Job ownership was lost before eligibility deferral.');
            }
            $now = $this->clock->now()->getTimestamp();
            $retryAt = max($now + 1, $retryAt);
            $status = $retryAt >= $job['expires_at'] ? 'expired' : 'pending';
            $pdo->prepare('UPDATE scheduled_jobs SET status=?,retry_at=?,deferrals=deferrals+1,last_error=?,lease_owner=NULL,lease_until=NULL WHERE job_id=? AND lease_owner=?')
                ->execute([$status, $retryAt, substr($reason, 0, 1024), $id, $owner]);
            $pdo->prepare('UPDATE delivery_log SET outcome=?,finished_at=?,error=? WHERE job_id=? AND attempt=?')
                ->execute([$status === 'pending' ? 'retry' : 'failed', $now, substr($reason, 0, 1024), $id, $job['attempts']]);
        });
    }

    public function retry(int $id, string $owner, string $error, int $waitSeconds = 30): void
    {
        $this->db->transaction(function (PDO $pdo) use ($id, $owner, $error, $waitSeconds): void {
            $job = $this->get($id);
            if ($job === null || $job['status'] !== 'leased' || $job['lease_owner'] !== $owner) {
                throw new \RuntimeException('Job ownership was lost.');
            }
            $now = $this->clock->now()->getTimestamp();
            $retryAt = $now + max(1, $waitSeconds);
            $status = $job['attempts'] - $job['deferrals'] >= 5 ? 'failed' : ($retryAt >= $job['expires_at'] ? 'expired' : 'pending');
            $pdo->prepare('UPDATE scheduled_jobs SET status=?,retry_at=?,last_error=?,lease_owner=NULL,lease_until=NULL WHERE job_id=? AND lease_owner=?')
                ->execute([$status,$retryAt,substr($error, 0, 1024),$id,$owner]);
            $pdo->prepare("UPDATE delivery_log SET outcome=?,finished_at=?,error=? WHERE job_id=? AND attempt=?")->execute([$status === 'pending' ? 'retry' : 'failed',$now,substr($error, 0, 1024),$id,$job['attempts']]);
        });
    }
    /** Successfully sent reminders await independent pointer/cleanup bookkeeping. */
    public function pendingDeliveryBookkeeping(int $limit = 50): array
    {
        if ($limit < 1 || $limit > 100) {
            throw new \InvalidArgumentException('Invalid delivery bookkeeping batch.');
        }
        $query = $this->db->pdo()->prepare("SELECT j.*,d.response_json FROM scheduled_jobs j JOIN delivery_log d ON d.job_id=j.job_id AND d.attempt=j.attempts AND d.outcome='sent' WHERE j.status='sent' AND j.module='tefillin' AND j.module_delivery_processed_at IS NULL ORDER BY j.completed_at,j.job_id LIMIT ?");
        $query->bindValue(1, $limit, PDO::PARAM_INT);
        $query->execute();
        return array_map(static function (array $row): array {
            $response = $row['response_json'];
            unset($row['response_json']);
            $row = self::decode($row);
            $row['response'] = $response === null ? [] : json_decode($response, true, 64, JSON_THROW_ON_ERROR);
            return $row;
        }, $query->fetchAll());
    }
    /** Explicitly finish unsupported cleanup bookkeeping without changing sent status. */
    public function markDeliveryProcessed(int $jobId): void
    {
        $this->db->transaction(function (PDO $pdo) use ($jobId): void {
            $job = $this->get($jobId);
            if ($job === null || $job['status'] !== 'sent') {
                throw new \RuntimeException('Only a confirmed sent job can finish delivery bookkeeping.');
            }
            $pdo->prepare("UPDATE scheduled_jobs SET module_delivery_processed_at=? WHERE job_id=? AND status='sent' AND module_delivery_processed_at IS NULL")
                ->execute([$this->clock->now()->getTimestamp(),$jobId]);
        });
    }
    /** Local-only atomic quota check and dispatch reservation. Call after all other guards, immediately before RPC. */
    public function guardDelivery(array $job, array $settings, string $owner): bool
    {
        return (new DeliverySafety($this->db, $this->clock))->reserve($job, $settings, $owner);
    }
    /** Every actual transport attempt must retain durable uncertainty until acknowledgement. */
    public function markDispatch(int $id, string $owner): void
    {
        (new DeliverySafety($this->db, $this->clock))->mark($id, $owner);
    }
    public function queueMetrics(): array
    {
        return (new QueueMaintenance($this->db, $this->clock))->metrics();
    }
    /** Bounded cleanup; zero days disables it. Active/uncertain sends and recovery evidence are never removed. */
    public function maintain(int $retentionDays = 90, int $batch = 200): array
    {
        return (new QueueMaintenance($this->db, $this->clock))->prune($retentionDays, $batch);
    }
    public function counts(): array
    {
        $rows = $this->db->pdo()->query('SELECT status,COUNT(*) AS count FROM scheduled_jobs GROUP BY status')->fetchAll();
        return array_column($rows, 'count', 'status');
    }
    private static function decode(array $row): array
    {
        $row['payload'] = json_decode($row['payload_json'], true, 64, JSON_THROW_ON_ERROR);
        unset($row['payload_json']);
        foreach (['job_id','run_at','expires_at','settings_revision','attempts','deferrals','priority'] as $field) {
            $row[$field] = (int)$row[$field];
        }
        return $row;
    }
}
